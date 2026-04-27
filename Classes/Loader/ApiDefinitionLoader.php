<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Loader;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Package\Cache\PackageDependentCacheIdentifier;
use TYPO3\CMS\Core\Package\PackageManager;

#[Autoconfigure(public: true)]
final readonly class ApiDefinitionLoader
{
    public function __construct(
        private PackageManager $packageManager,
        #[Autowire(service: 'cache.core')]
        private PhpFrontend $cache,
        private ApiRegistry $apiRegistry,
    ) {
    }

    public function load(): void
    {
        $cacheIdentifier = (new PackageDependentCacheIdentifier($this->packageManager))
            ->withPrefix('TcaApiDefinitions')
            ->toString();

        $definitions = $this->cache->require($cacheIdentifier);
        if (!is_array($definitions)) {
            $definitions = $this->collectDefinitions();
            $this->cache->set(
                $cacheIdentifier,
                'return ' . var_export($definitions, true) . ';',
            );
        }

        foreach ($definitions as $resourceName => $config) {
            $this->apiRegistry->register($resourceName, ApiDefinition::fromArray($config));
        }

        $this->validateDefinitions($definitions);
    }

    /**
     * Scans all active packages for Configuration/TcaApi/*.php files, loads them, and returns an array of raw definition arrays keyed by resourceName.
     */
    private function collectDefinitions(): array
    {
        $definitions = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $dir = $package->getPackagePath() . 'Configuration/TcaApi/';
            if (!is_dir($dir)) {
                continue;
            }
            $finder = Finder::create()->files()->name('*.php')->in($dir)->depth(0)->sortByName();
            foreach ($finder as $file) {
                $config = require $file->getPathname();
                $resourceName = $config['general']['resourceName'] ?? strtolower(basename($file->getFilename()));
                if (isset($definitions[$resourceName])) {
                    throw new \InvalidArgumentException(sprintf(
                        "Duplicate API resource name '%s' detected while loading Configuration/TcaApi definitions. Use unique 'general.resourceName' values to avoid accidental resource shadowing.",
                        $resourceName
                    ));
                }
                $definitions[$resourceName] = $config;
            }
        }
        return $definitions;
    }

    /**
     * Validates the set of definitions that were just loaded from Configuration/TcaApi/.
     * Scoped to this set only — resources registered outside load() (e.g. in tests) are
     * intentionally excluded to avoid false positives.
     * Checks that any column with an explicit resourceName actually points to a registered resource.
     *
     * @param array<string, mixed> $definitions Raw definition arrays keyed by resourceName
     */
    private function validateDefinitions(array $definitions): void
    {
        foreach (array_keys($definitions) as $resourceName) {
            $definition = $this->apiRegistry->get($resourceName);
            if ($definition === null) {
                continue;
            }

            foreach ($definition->columns as $columnName => $columnDef) {
                if ($columnDef->resourceName !== null && $this->apiRegistry->get($columnDef->resourceName) === null) {
                    throw new \InvalidArgumentException(sprintf(
                        "Column '%s' in resource '%s' sets resourceName '%s', but no resource with that name is registered.",
                        $columnName,
                        $resourceName,
                        $columnDef->resourceName,
                    ));
                }
            }
        }
    }
}
