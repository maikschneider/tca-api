<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\Processing;

use TYPO3\CMS\Core\Site\Entity\SiteSettings;

/**
 * Resolves `{column}` and `{$site.setting.key}` placeholders against a raw DB row
 * and the current SiteSettings.
 *
 * Grammar (single-pass, non-recursive — placeholder values are NOT re-scanned):
 *   {name}    → $rawRow[name]
 *   {$a.b.c}  → $settings->get('a.b.c')
 *   no braces → literal, returned unchanged
 *
 * Behaviour:
 *   - String values containing exactly one placeholder and nothing else are
 *     returned as the underlying typed value (preserves int pids, bool params).
 *   - String values containing a placeholder mixed with literal text are
 *     stringified and interpolated.
 *   - Non-string values pass through unchanged.
 *   - Unresolved placeholders return null (signalling "skip this URL").
 */
final readonly class PlaceholderResolver
{
    /** Matches a single `{...}` placeholder. */
    private const PLACEHOLDER = '/\{(\$?)([A-Za-z0-9_.]+)\}/';

    /**
     * Resolve a single value. Recursively descends arrays.
     *
     * @param array<string, mixed> $rawRow
     */
    public function resolve(mixed $value, array $rawRow, ?SiteSettings $settings): mixed
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $resolved = $this->resolve($v, $rawRow, $settings);
                if ($resolved === null) {
                    return null;
                }
                $out[$k] = $resolved;
            }
            return $out;
        }

        if (!\is_string($value)) {
            return $value;
        }

        // Exact-match: single placeholder filling the whole string → preserve underlying type.
        if (\preg_match('/^' . substr(self::PLACEHOLDER, 1, -1) . '$/', $value, $m) === 1) {
            return $this->lookup($m[1] === '$', $m[2], $rawRow, $settings);
        }

        // Interpolation: keep literal text around placeholders, stringify resolved values.
        $resolved = \preg_replace_callback(
            self::PLACEHOLDER,
            function (array $m) use ($rawRow, $settings): string {
                $v = $this->lookup($m[1] === '$', $m[2], $rawRow, $settings);
                if ($v === null) {
                    return "\0NULL\0";
                }
                return (string)$v;
            },
            $value,
        );

        if ($resolved === null || \str_contains($resolved, "\0NULL\0")) {
            return null;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $rawRow
     */
    private function lookup(bool $fromSettings, string $key, array $rawRow, ?SiteSettings $settings): mixed
    {
        if ($fromSettings) {
            if ($settings === null) {
                return null;
            }
            $value = $settings->get($key, null);
            return $value === '' ? null : $value;
        }

        if (!\array_key_exists($key, $rawRow)) {
            return null;
        }
        $value = $rawRow[$key];
        return $value === '' ? null : $value;
    }
}
