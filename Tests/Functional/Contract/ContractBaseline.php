<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Contract;

use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * The known mismatches of the installed TYPO3 major version, whose core TCA differs.
 *
 * Remembers which entries a run matched, so UnmatchedBaselineExtension can report
 * the entries no response produces anymore.
 */
final class ContractBaseline
{
    /** Set to collect mismatches into this file instead of failing; see README.md. */
    public const COLLECT_ENV = 'TCA_API_CONTRACT_COLLECT';

    private const FILE = __DIR__ . '/openapi-contract-baseline.v%d.txt';

    /** @var array<string, bool>|null entry => matched */
    private static ?array $entries = null;

    public static function file(): string
    {
        return sprintf(self::FILE, (new Typo3Version())->getMajorVersion());
    }

    public static function isKnown(string $mismatch): bool
    {
        $entries = self::entries();
        if (!isset($entries[$mismatch])) {
            return false;
        }
        self::$entries[$mismatch] = true;

        return true;
    }

    /**
     * @return list<string>
     */
    public static function unmatched(): array
    {
        return array_keys(array_filter(self::entries(), static fn (bool $matched): bool => !$matched));
    }

    /**
     * @return array<string, bool>
     */
    private static function entries(): array
    {
        return self::$entries ??= array_fill_keys(
            array_filter(
                array_map('trim', file(self::file()) ?: []),
                static fn (string $line): bool => $line !== '' && $line[0] !== '#',
            ),
            false,
        );
    }
}
