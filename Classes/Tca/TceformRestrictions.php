<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tca;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\StoragePidResolver;
use MaikSchneider\TcaApi\Exception\StoragePidResolutionException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\PageTsConfigFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * The TCEFORM Page TSconfig of a resource's storage page, applied to writes when
 * general.tsconfig is 'storagePid'.
 *
 * TCEFORM shapes the backend form and DataHandler does not enforce it, so reads
 * ignore it: a disabled field can still hold data. Supported properties, on the
 * field or under types.<type> for one record type:
 *   disabled                        the field is not writable
 *   removeItems / keepItems / addItems   values a select field may not be set to
 *
 * Read per request rather than at boot: the storage pid may be a site setting,
 * and TSconfig changes do not flush the definitions cache. The TSconfig of a page
 * is built once per request, the way the frontend builds it for typolinks.
 */
final readonly class TceformRestrictions
{
    /**
     * @param array<string, mixed> $tceform the TCEFORM.<table>. subtree of the page TSconfig
     */
    public function __construct(
        private string $table,
        private array $tceform,
    ) {
    }

    /**
     * The restrictions for writes to $config's resource; null when it ignores TSconfig.
     *
     * @throws StoragePidResolutionException when the storage pid does not resolve
     */
    public static function forConfig(ApiDefinition $config): ?self
    {
        if ($config->tsconfig !== ApiDefinition::TSCONFIG_STORAGE_PID) {
            return null;
        }

        $tceform = self::pageTsConfig(StoragePidResolver::writePid($config))['TCEFORM.'][$config->table . '.'] ?? [];

        return new self($config->table, \is_array($tceform) ? $tceform : []);
    }

    /**
     * As {@see forConfig()}, but null when the storage pid does not resolve: the API
     * documentation then describes the resource without TSconfig, while writes
     * still fail closed.
     */
    public static function forDocumentation(ApiDefinition $config): ?self
    {
        try {
            return self::forConfig($config);
        } catch (StoragePidResolutionException) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function pageTsConfig(int $pid): array
    {
        if ($pid <= 0) {
            return [];
        }

        $runtimeCache = GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime');
        $identifier = 'tca-api-page-tsconfig-' . $pid;
        $cached = $runtimeCache->get($identifier);
        if (\is_array($cached)) {
            return $cached;
        }

        try {
            $rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $pid)->get();
        } catch (PageNotFoundException) {
            $rootLine = [];
        }
        ksort($rootLine);
        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pid);
        } catch (SiteNotFoundException) {
            $site = new NullSite();
        }

        $pageTsConfig = GeneralUtility::makeInstance(PageTsConfigFactory::class)->create($rootLine, $site)->getPageTsConfigArray();
        $runtimeCache->set($identifier, $pageTsConfig);

        return $pageTsConfig;
    }

    public function isDisabled(string $column, ?string $recordType = null): bool
    {
        return (bool)(int)($this->property($column, $recordType, 'disabled') ?? 0);
    }

    /**
     * Values of a select field that a write may not set: removeItems, plus the
     * TCA items missing from keepItems, minus addItems. The empty value stays
     * allowed, so a field can always be reset.
     *
     * @return list<string>
     */
    public function deniedValues(string $column, ?string $recordType = null): array
    {
        $denied = self::csv($this->property($column, $recordType, 'removeItems'));

        $keepItems = $this->property($column, $recordType, 'keepItems');
        if ($keepItems !== null) {
            $items = RecordTypeFields::columnDefinition($this->table, $column, $recordType)['config']['items'] ?? [];
            foreach (\is_array($items) ? $items : [] as $item) {
                if (\is_array($item) && isset($item['value']) && $item['value'] !== '' && !\in_array((string)$item['value'], self::csv($keepItems), true)) {
                    $denied[] = (string)$item['value'];
                }
            }
        }

        $added = [];
        foreach ([$this->tceform[$column . '.']['addItems.'] ?? null, $this->typeProperties($column, $recordType)['addItems.'] ?? null] as $addItems) {
            if (\is_array($addItems)) {
                $added = [...$added, ...array_map('strval', array_keys($addItems))];
            }
        }

        return array_values(array_diff(array_unique($denied), $added));
    }

    /** The property set for $recordType under types.<type>, else on the field. */
    private function property(string $column, ?string $recordType, string $name): mixed
    {
        $typeProperties = $this->typeProperties($column, $recordType);
        if (\array_key_exists($name, $typeProperties)) {
            return $typeProperties[$name];
        }

        return $this->tceform[$column . '.'][$name] ?? null;
    }

    /** @return array<string, mixed> */
    private function typeProperties(string $column, ?string $recordType): array
    {
        if ($recordType === null) {
            return [];
        }
        $properties = $this->tceform[$column . '.']['types.'][$recordType . '.'] ?? [];

        return \is_array($properties) ? $properties : [];
    }

    /** @return list<string> */
    private static function csv(mixed $value): array
    {
        return \is_scalar($value) ? array_values(array_filter(array_map('trim', explode(',', (string)$value)), static fn (string $item): bool => $item !== '')) : [];
    }
}
