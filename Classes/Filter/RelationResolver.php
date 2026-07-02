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
 * Supported relation kinds (v1): single-value `select` FK and MM (incl. `type=category`
 * and `type=group` with `MM`). Inline (`foreign_field`) and non-MM group relations are
 * rejected with a clear message.
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
            $target = $this->resolveTargetTable($config);
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

        // Single-value FK: a select storing the related UID directly in the column.
        if (\in_array($type, ['select', 'category', 'group'], true)
            && \is_string($config['foreign_table'] ?? null)
            && $config['foreign_table'] !== ''
        ) {
            return RelationHop::fk($table, $config['foreign_table'], $field);
        }

        throw new \InvalidArgumentException(sprintf(
            'Relation path: "%s.%s" (type=%s) is not a filterable relation. '
            . 'Supported: single-value select FK and MM/category relations.',
            $table,
            $field,
            $type === '' ? 'unknown' : $type,
        ));
    }

    /**
     * The soft-delete column of a table, or null when the table has none.
     */
    public function deletedField(string $table): ?string
    {
        $delete = $GLOBALS['TCA'][$table]['ctrl']['delete'] ?? null;

        return \is_string($delete) && $delete !== '' ? $delete : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveTargetTable(array $config): ?string
    {
        if (\is_string($config['foreign_table'] ?? null) && $config['foreign_table'] !== '') {
            return $config['foreign_table'];
        }

        return $this->groupAllowedResolver->resolveAllowedTables($config)[0] ?? null;
    }
}
