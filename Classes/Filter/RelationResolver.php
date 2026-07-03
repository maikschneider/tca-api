<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use MaikSchneider\TcaApi\Tca\GroupAllowedResolver;

/**
 * Resolves a single relation segment of a dotted filter path into a {@see RelationHop}.
 *
 * Reads from `$GLOBALS['TCA']` directly (rather than TcaSchemaFactory) because the
 * schema is not guaranteed to be compiled at the boot point where filter definitions
 * are pre-resolved — the same reason the ApiDefinitionLoader validators read raw TCA.
 * The migrated TCA already carries `MM` / `foreign_table` for `type=category`.
 *
 * Supported relation kinds: single-value `select` FK, MM (incl. `type=category` and
 * `type=group` with `MM`), and `type=inline` (`foreign_field`). Non-MM group relations
 * (comma-separated storage) are rejected with a clear message.
 */
final readonly class RelationResolver
{
    public function __construct(
        private GroupAllowedResolver $groupAllowedResolver = new GroupAllowedResolver(),
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the field is unknown or not a supported relation.
     */
    public function resolve(string $table, string $field): RelationHop
    {
        $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? null;
        if (!\is_array($config)) {
            throw new \InvalidArgumentException(
                sprintf('Relation path: "%s.%s" is not a known TCA column.', $table, $field),
            );
        }

        $type = (string)($config['type'] ?? '');

        // MM relation (type=category, select/group with MM).
        if (isset($config['MM']) && \is_string($config['MM']) && $config['MM'] !== '') {
            $target = $this->resolveTargetTable($config, $table, $field);
            if ($target === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Relation path: cannot determine the related table for MM relation "%s.%s".',
                    $table,
                    $field,
                ));
            }

            $hasOpposite = isset($config['MM_opposite_field']);

            return RelationHop::mm(
                sourceTable: $table,
                targetTable: $target,
                mmTable: $config['MM'],
                // Mirrors MmFilter: on the opposite side uid_local holds the related record.
                mmSourceKey: $hasOpposite ? 'uid_foreign' : 'uid_local',
                mmTargetKey: $hasOpposite ? 'uid_local' : 'uid_foreign',
                mmMatch: \is_array($config['MM_match_fields'] ?? null) ? $config['MM_match_fields'] : [],
            );
        }

        // Inline relation: the child table stores a back-pointer to the source UID.
        if ($type === 'inline'
            && \is_string($config['foreign_table'] ?? null) && $config['foreign_table'] !== ''
            && \is_string($config['foreign_field'] ?? null) && $config['foreign_field'] !== ''
        ) {
            return RelationHop::inline(
                sourceTable: $table,
                targetTable: $config['foreign_table'],
                foreignField: $config['foreign_field'],
                foreignTableField: \is_string($config['foreign_table_field'] ?? null) && $config['foreign_table_field'] !== ''
                    ? $config['foreign_table_field']
                    : null,
                foreignMatchFields: \is_array($config['foreign_match_fields'] ?? null) ? $config['foreign_match_fields'] : [],
            );
        }

        // Single-value FK: a `select` storing exactly one related UID directly in the
        // column. Multi-value selects and non-MM group fields use comma-separated
        // storage; resolving them as a single-value FK would match a CSV column against
        // individual UIDs, so they are intentionally unsupported — use an MM relation.
        if ($type === 'select'
            && \is_string($config['foreign_table'] ?? null)
            && $config['foreign_table'] !== ''
            && (int)($config['maxitems'] ?? 1) === 1
        ) {
            return RelationHop::fk($table, $config['foreign_table'], $field);
        }

        throw new \InvalidArgumentException(sprintf(
            'Relation path: "%s.%s" (type=%s) is not a filterable relation. '
            . 'Supported: single-value select FK, MM/category, and inline (foreign_field) relations.',
            $table,
            $field,
            $type === '' ? 'unknown' : $type,
        ));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws \InvalidArgumentException when the group relation allows more than one table,
     *                                   which leaves the filter target ambiguous.
     */
    private function resolveTargetTable(array $config, string $table, string $field): ?string
    {
        if (\is_string($config['foreign_table'] ?? null) && $config['foreign_table'] !== '') {
            return $config['foreign_table'];
        }

        $allowed = $this->groupAllowedResolver->resolveAllowedTables($config);
        if (\count($allowed) > 1) {
            throw new \InvalidArgumentException(sprintf(
                'Relation path: MM relation "%s.%s" allows multiple tables (%s); '
                . 'a relation-path filter needs a single, unambiguous target table.',
                $table,
                $field,
                implode(', ', $allowed),
            ));
        }

        return $allowed[0] ?? null;
    }
}
