<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

use MaikSchneider\TcaApi\Exception\StoragePidResolutionException;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Resolves the storage pids of a resource for the current request: integers as
 * configured, {$site.setting} placeholders from the settings of the request's site.
 *
 * Fails closed: a placeholder without a site, or one whose setting is empty or not
 * an integer, throws instead of yielding an unscoped query or a write to pid 0.
 * Reads the request from $GLOBALS['TYPO3_REQUEST'], which TcaApiMiddleware sets,
 * because the repository and the relation resolvers have no request of their own.
 */
final class StoragePidResolver
{
    /** The pid new records, file references and inline-created relations are stored on. */
    public static function writePid(ApiDefinition $config): int
    {
        if ($config->storagePidSetting !== null) {
            return self::resolveSetting($config->storagePidSetting, $config, 'storagePid');
        }

        return $config->storagePid ?? 0;
    }

    /**
     * The pids reads are constrained to; null = no constraint.
     *
     * @return list<int>|null
     */
    public static function readPids(ApiDefinition $config): ?array
    {
        if ($config->readStoragePids === null) {
            return null;
        }

        $pids = array_map(
            static fn (int|string $pid): int => \is_int($pid) ? $pid : self::resolveSetting($pid, $config, 'readStoragePids'),
            $config->readStoragePids,
        );

        return array_values(array_unique($pids));
    }

    private static function resolveSetting(string $setting, ApiDefinition $config, string $option): int
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $site = $request instanceof ServerRequestInterface ? $request->getAttribute('site') : null;
        if (!$site instanceof Site) {
            throw new StoragePidResolutionException(sprintf(
                'Resource "%s": general.%s uses the site setting "%s", but the request has no site.',
                $config->resourceName,
                $option,
                $setting,
            ), 1760800001);
        }

        $value = $site->getSettings()->get($setting);
        // MathUtility treats true as integer 1.
        if (!\is_bool($value) && MathUtility::canBeInterpretedAsInteger($value) && (int)$value >= 0) {
            return (int)$value;
        }

        throw new StoragePidResolutionException(sprintf(
            'Resource "%s": general.%s uses the site setting "%s", which is %s on site "%s" instead of a pid.',
            $config->resourceName,
            $option,
            $setting,
            self::describe($value),
            $site->getIdentifier(),
        ), 1760800002);
    }

    private static function describe(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        return \is_scalar($value) ? '"' . $value . '"' : get_debug_type($value);
    }
}
