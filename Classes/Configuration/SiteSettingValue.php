<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Reads a non-negative integer from the settings of the request's site, for the
 * {$site.setting} placeholders in a resource's general section.
 *
 * Fails closed: without a site, or when the setting is empty or not such an
 * integer, it throws the caller's exception instead of returning a fallback.
 * Reads the request from $GLOBALS['TYPO3_REQUEST'], which TcaApiMiddleware sets.
 *
 * @internal
 */
final class SiteSettingValue
{
    /**
     * @param string                          $option         the general option using the placeholder, for messages
     * @param string                          $expected       what the value should be, e.g. "a pid", for messages
     * @param class-string<\RuntimeException> $exceptionClass thrown on failure
     * @param int                             $codeBase       exception codes: +1 without a site, +2 for an invalid value
     */
    public static function nonNegativeInt(
        string $setting,
        ApiDefinition $config,
        string $option,
        string $expected,
        string $exceptionClass,
        int $codeBase,
    ): int {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $site = $request instanceof ServerRequestInterface ? $request->getAttribute('site') : null;
        if (!$site instanceof Site) {
            throw new $exceptionClass(sprintf(
                'Resource "%s": general.%s uses the site setting "%s", but the request has no site.',
                $config->resourceName,
                $option,
                $setting,
            ), $codeBase + 1);
        }

        $value = $site->getSettings()->get($setting);
        // MathUtility treats true as integer 1.
        if (!\is_bool($value) && MathUtility::canBeInterpretedAsInteger($value) && (int)$value >= 0) {
            return (int)$value;
        }

        throw new $exceptionClass(sprintf(
            'Resource "%s": general.%s uses the site setting "%s", which is %s on site "%s" instead of %s.',
            $config->resourceName,
            $option,
            $setting,
            self::describe($value),
            $site->getIdentifier(),
            $expected,
        ), $codeBase + 2);
    }

    private static function describe(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        return \is_scalar($value) ? '"' . $value . '"' : get_debug_type($value);
    }
}
