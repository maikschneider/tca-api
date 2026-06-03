<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tca;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the allowed-tables set for a TCA `type=group` field configuration
 * across the three dispatch shapes used by the API:
 *
 *  1. Single-table:        `allowed = 'tx_my_table'`
 *  2. Explicit multi-table: `allowed = 'tx_a,tx_b'`
 *  3. Wildcard reverse-MM:  `allowed = '*'` + `MM_oppositeUsage = [tx_a => [field], ...]`
 *
 * The wildcard form is the polymorphic reverse side of an MM relation
 * (the convention used by `sys_category.items`). TYPO3's TCA reference:
 * https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/Type/Group/Index.html#confval-group-mm-opposite-usage
 *
 * This helper centralises the resolution so {@see \MaikSchneider\TcaApi\DataAccess\EmbedPreloader}
 * and {@see \MaikSchneider\TcaApi\Serializer\GroupFieldSerializer} can dispatch
 * by intent rather than re-parsing `allowed` ad-hoc.
 */
final readonly class GroupAllowedResolver
{
    /**
     * Returns the list of allowed forward-side tables for the given group field
     * configuration.
     *
     *  - Wildcard + `MM_oppositeUsage` set → `array_keys($MM_oppositeUsage)`.
     *  - Explicit list (`allowed` != '*' and not empty) → trimmed list of table names.
     *  - Wildcard without `MM_oppositeUsage` → `[]` (caller decides how to handle;
     *    the loader rejects this combination at boot).
     *  - Empty / missing `allowed` → `[]`.
     *
     * @param array<string, mixed> $fieldConfig The inner TCA `config` array for the column.
     * @return list<string>
     */
    public function resolveAllowedTables(array $fieldConfig): array
    {
        if ($this->isWildcard($fieldConfig)) {
            $oppositeUsage = $this->resolveOppositeUsage($fieldConfig);
            if ($oppositeUsage === []) {
                return [];
            }

            return array_keys($oppositeUsage);
        }

        $allowed = $fieldConfig['allowed'] ?? '';
        if (!\is_string($allowed) || trim($allowed) === '') {
            return [];
        }

        return GeneralUtility::trimExplode(',', $allowed, true);
    }

    /**
     * True when `allowed` is the literal wildcard `*` (after trimming).
     */
    public function isWildcard(array $fieldConfig): bool
    {
        $allowed = $fieldConfig['allowed'] ?? '';
        if (!\is_string($allowed)) {
            return false;
        }

        return trim($allowed) === '*';
    }

    /**
     * True when the column sits on the reverse side of an MM relation — either
     * the legacy `MM_opposite_field` form (forward-side declares which field on
     * the reverse table holds the opposite) or the polymorphic `MM_oppositeUsage`
     * form (reverse-side enumerates all forward tables/fields that point at it).
     */
    public function isReverseMmSide(array $fieldConfig): bool
    {
        return isset($fieldConfig['MM_opposite_field'])
            || isset($fieldConfig['MM_oppositeUsage']);
    }

    /**
     * Returns the normalised `MM_oppositeUsage` map: `tableName => list<fieldName>`.
     *
     * TYPO3 accepts the value as `[table => [field1, field2]]`. Non-array values
     * and non-string field names are coerced/skipped so callers can rely on the
     * declared shape.
     *
     * @param array<string, mixed> $fieldConfig
     * @return array<string, list<string>>
     */
    public function resolveOppositeUsage(array $fieldConfig): array
    {
        $raw = $fieldConfig['MM_oppositeUsage'] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        $normalised = [];
        foreach ($raw as $table => $fields) {
            if (!\is_string($table) || $table === '') {
                continue;
            }
            if (!\is_array($fields)) {
                continue;
            }
            $cleanFields = [];
            foreach ($fields as $field) {
                if (\is_string($field) && $field !== '') {
                    $cleanFields[] = $field;
                }
            }
            $normalised[$table] = $cleanFields;
        }

        return $normalised;
    }
}
