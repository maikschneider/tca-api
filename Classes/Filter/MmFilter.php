<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

final class MmFilter implements FilterInterface, FilterPreResolvableInterface
{
    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    public function apply(QueryBuilder $qb, FilterContext $context): void
    {
        if ($context->option('mm_table') === null) {
            $context = $this->deriveMmConfigFromTca($context);
        }

        $mmTable      = $context->option('mm_table');
        $mmLocalKey   = $context->option('mm_local_key');
        $mmForeignKey = $context->option('mm_foreign_key');
        $value        = (string)$context->value;

        $parts = [sprintf('%s = %s', $qb->quoteIdentifier($mmLocalKey), $qb->createNamedParameter($value))];
        foreach ($context->option('mm_constraints', []) as $col => $val) {
            $parts[] = sprintf('%s = %s', $qb->quoteIdentifier($col), $qb->createNamedParameter($val));
        }

        $subSql = sprintf(
            'SELECT %s FROM %s WHERE %s',
            $qb->quoteIdentifier($mmForeignKey),
            $qb->quoteIdentifier($mmTable),
            implode(' AND ', $parts),
        );
        $qb->andWhere($qb->expr()->in('uid', '(' . $subSql . ')'));
    }

    public function preResolve(FilterDefinition $definition): FilterDefinition
    {
        if ($definition->option('mm_table') !== null) {
            return $definition;
        }

        // Guard for unit-test / empty-table contexts
        if ($definition->table === '' || !$this->schemaFactory->has($definition->table)) {
            return $definition;
        }

        $schema = $this->schemaFactory->get($definition->table);
        if (!$schema->hasField($definition->column)) {
            return $definition;
        }

        $config  = $schema->getField($definition->column)->getConfiguration();
        $mmTable = $config['MM'] ?? null;
        if ($mmTable === null) {
            return $definition;
        }

        $hasOppositeField = isset($config['MM_opposite_field']);

        return $definition->withOptions([
            'mm_table'       => $mmTable,
            'mm_local_key'   => $hasOppositeField ? 'uid_local' : 'uid_foreign',
            'mm_foreign_key' => $hasOppositeField ? 'uid_foreign' : 'uid_local',
            'mm_constraints' => $config['MM_match_fields'] ?? [],
        ]);
    }

    private function deriveMmConfigFromTca(FilterContext $context): FilterContext
    {
        $schema = $this->schemaFactory->get($context->table);

        if (!$schema->hasField($context->column)) {
            throw new \InvalidArgumentException(
                sprintf('Field %s.%s does not exist in TCA.', $context->table, $context->column),
            );
        }

        $config  = $schema->getField($context->column)->getConfiguration();
        $mmTable = $config['MM'] ?? null;
        if ($mmTable === null) {
            throw new \InvalidArgumentException(
                sprintf('Cannot derive MM table for %s.%s: no MM key in TCA config.', $context->table, $context->column),
            );
        }

        // MM_opposite_field is set when the MM table is owned by the related side (e.g. sys_category_record_mm).
        // In that case uid_local holds the related UID and uid_foreign holds the record UID — reversed from standard MM.
        $hasOppositeField = isset($config['MM_opposite_field']);

        return $context->withOptions([
            'mm_table'       => $mmTable,
            'mm_local_key'   => $hasOppositeField ? 'uid_local' : 'uid_foreign',
            'mm_foreign_key' => $hasOppositeField ? 'uid_foreign' : 'uid_local',
            'mm_constraints' => $config['MM_match_fields'] ?? [],
        ]);
    }
}
