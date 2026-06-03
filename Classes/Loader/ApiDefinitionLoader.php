<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Loader;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Exception\InvalidApiDefinitionException;
use MaikSchneider\TcaApi\Filter\FilterInterface;
use MaikSchneider\TcaApi\Filter\FilterPreResolvableInterface;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tca\GroupAllowedResolver;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;
use TYPO3\CMS\Core\Package\Cache\PackageDependentCacheIdentifier;
use TYPO3\CMS\Core\Package\PackageManager;

#[Autoconfigure(public: true)]
final readonly class ApiDefinitionLoader
{
    /**
     * @param iterable<FilterInterface> $filterHandlers
     */
    public function __construct(
        private PackageManager $packageManager,
        #[Autowire(service: 'cache.core')]
        private PhpFrontend $cache,
        private ApiRegistry $apiRegistry,
        private TcaValidatorDeriver $tcaValidatorDeriver,
        #[TaggedIterator('tca_api.filter')]
        private readonly iterable $filterHandlers = [],
    ) {
    }

    public function __invoke(BootCompletedEvent $event): void
    {
        $this->load();
    }

    public function load(): void
    {
        $cacheIdentifier = (new PackageDependentCacheIdentifier($this->packageManager))
            ->withPrefix('TcaApiDefinitions')
            ->toString();

        $definitions = $this->cache->require($cacheIdentifier);
        if (!is_array($definitions)) {
            $filterMap  = $this->buildResolvableFilterMap();
            $rawConfigs = $this->collectDefinitions();
            $definitions = [];
            foreach ($rawConfigs as $resourceName => $config) {
                $config = $this->tcaValidatorDeriver->deriveForConfig($config['general']['table'] ?? '', $config);
                $definitions[$resourceName] = ApiDefinition::fromArray($config, $filterMap);
            }
            $this->cache->set(
                $cacheIdentifier,
                'return unserialize(' . var_export(serialize($definitions), true) . ');',
            );
        }

        foreach ($definitions as $resourceName => $definition) {
            $this->apiRegistry->register($resourceName, $definition);
        }

        $this->validateDefinitions($definitions);
    }

    /**
     * Builds a FQCN → filter instance map for all DI-managed handlers that implement
     * FilterPreResolvableInterface. Passed to ApiDefinition::fromArray() so that
     * pre-resolution runs inside FilterDefinition::fromRaw() at build time.
     *
     * @return array<string, FilterPreResolvableInterface>
     */
    private function buildResolvableFilterMap(): array
    {
        $map = [];
        foreach ($this->filterHandlers as $handler) {
            if ($handler instanceof FilterPreResolvableInterface) {
                $map[$handler::class] = $handler;
            }
        }
        return $map;
    }

    /**
     * Two-pass scan mirroring TYPO3's TCA + TCA/Overrides/ pattern.
     *
     * Pass 1 — loads Configuration/TcaApi/*.php from every active package and writes
     * each raw config into $GLOBALS['TCA_API'] keyed by resourceName. Duplicate keys
     * are resolved by last-write-wins (package load order).
     *
     * Pass 2 — requires Configuration/TcaApi/Overrides/*.php files as pure side-effects.
     * Override files manipulate $GLOBALS['TCA_API'] directly (unset, assign, merge).
     */
    private function collectDefinitions(): array
    {
        $GLOBALS['TCA_API'] = [];

        foreach ($this->packageManager->getActivePackages() as $package) {
            $dir = $package->getPackagePath() . 'Configuration/TcaApi/';
            if (!is_dir($dir)) {
                continue;
            }
            $finder = Finder::create()->files()->name('*.php')->in($dir)->depth(0)->sortByName();
            foreach ($finder as $file) {
                $config = require $file->getPathname();
                if (!is_array($config)) {
                    continue;
                }
                $resourceName = $config['general']['resourceName']
                    ?? strtolower(basename($file->getFilename(), '.php'));
                $GLOBALS['TCA_API'][$resourceName] = $config;
            }
        }

        foreach ($this->packageManager->getActivePackages() as $package) {
            $dir = $package->getPackagePath() . 'Configuration/TcaApi/Overrides/';
            if (!is_dir($dir)) {
                continue;
            }
            $finder = Finder::create()->files()->name('*.php')->in($dir)->depth(0)->sortByName();
            foreach ($finder as $file) {
                require $file->getPathname();
            }
        }

        return $GLOBALS['TCA_API'];
    }

    /**
     * Validates the set of definitions that were just loaded from Configuration/TcaApi/.
     * Scoped to this set only — resources registered outside load() (e.g. in tests) are
     * intentionally excluded to avoid false positives.
     *
     * Checks performed:
     *  - Columns with an explicit `resourceName` point at a registered resource.
     *  - TCA `type=group` columns with `allowed='*'` also declare `MM_oppositeUsage`.
     *    Wildcard without opposite-usage is rejected at boot rather than failing
     *    later at runtime with an invalid-SQL JOIN against a table literally named `*`.
     *
     * @param array<string, ApiDefinition> $definitions
     */
    private function validateDefinitions(array $definitions): void
    {
        $resolver = new GroupAllowedResolver();

        foreach ($definitions as $resourceName => $definition) {
            foreach ($definition->columns as $columnName => $columnDef) {
                if ($columnDef->resourceName !== null && $this->apiRegistry->get($columnDef->resourceName) === null) {
                    throw new InvalidApiDefinitionException(sprintf(
                        "Column '%s' in resource '%s' sets resourceName '%s', but no resource with that name is registered.",
                        $columnName,
                        $resourceName,
                        $columnDef->resourceName,
                    ));
                }
            }

            $this->validateWildcardGroupColumns($resourceName, $definition, $resolver);
        }
    }

    /**
     * Boot-time guard: every exposed TCA `type=group` column with `allowed='*'`
     * MUST also declare `MM_oppositeUsage`. The wildcard form is only meaningful
     * on the reverse side of an MM relation, and the runtime path needs the
     * opposite-usage map to enumerate forward tables. Without it, the runtime
     * would emit SQL referencing a table literally named `*`.
     *
     * Reads from `$GLOBALS['TCA']` directly (same pattern as TcaValidatorDeriver)
     * because `TcaSchemaFactory` compilation is not guaranteed at this boot point.
     */
    private function validateWildcardGroupColumns(
        string $resourceName,
        ApiDefinition $definition,
        GroupAllowedResolver $resolver,
    ): void {
        $tcaColumns = $GLOBALS['TCA'][$definition->table]['columns'] ?? [];
        if (!\is_array($tcaColumns) || $tcaColumns === []) {
            return;
        }

        foreach ($definition->columns as $columnName => $columnDef) {
            // In explicit mode, only columns exposed via `groups` are reachable at runtime.
            if ($definition->isExplicitMode && !$columnDef->isReadable()) {
                continue;
            }

            $tcaColumn = $tcaColumns[$columnName] ?? null;
            if (!\is_array($tcaColumn)) {
                continue;
            }
            $tcaConfig = $tcaColumn['config'] ?? [];
            if (!\is_array($tcaConfig) || ($tcaConfig['type'] ?? '') !== 'group') {
                continue;
            }

            if (!$resolver->isWildcard($tcaConfig)) {
                continue;
            }

            if ($resolver->resolveOppositeUsage($tcaConfig) !== []) {
                continue;
            }

            throw new InvalidApiDefinitionException(sprintf(
                "TcaApi resource '%s' exposes group column '%s' on table '%s' with allowed='*' "
                . 'but no MM_oppositeUsage. Wildcard group fields are only supported as the reverse '
                . 'side of an MM relation — declare MM_oppositeUsage on the TCA column to enumerate '
                . 'the forward-side tables/fields. See https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/Type/Group/Index.html#confval-group-mm-opposite-usage',
                $resourceName,
                $columnName,
                $definition->table,
            ));
        }
    }
}
