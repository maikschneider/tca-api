<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

final class MmFilter implements FilterInterface
{
    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    public function apply(QueryBuilder $qb, string $column, array $filterConfig): void
    {
        if (!isset($filterConfig['mm_table'])) {
            $filterConfig = $this->deriveMmConfigFromTca($filterConfig);
        }

        $mmTable      = $filterConfig['mm_table'];
        $mmLocalKey   = $filterConfig['mm_local_key'];
        $mmForeignKey = $filterConfig['mm_foreign_key'];
        $value        = (string)$filterConfig['value'];

        $parts = [sprintf('%s = %s', $qb->quoteIdentifier($mmLocalKey), $qb->createNamedParameter($value))];
        foreach ($filterConfig['mm_constraints'] ?? [] as $col => $val) {
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

    private function deriveMmConfigFromTca(array $filter): array
    {
        $table  = $filter['_table'];
        $column = $filter['_column'];
        $schema = $this->schemaFactory->get($table);

        if (!$schema->hasField($column)) {
            throw new \InvalidArgumentException(
                sprintf('Field %s.%s does not exist in TCA.', $table, $column),
            );
        }

        $config  = $schema->getField($column)->getConfiguration();
        $mmTable = $config['MM'] ?? null;
        if ($mmTable === null) {
            throw new \InvalidArgumentException(
                sprintf('Cannot derive MM table for %s.%s: no MM key in TCA config.', $table, $column),
            );
        }

        // MM_opposite_field is set when the MM table is owned by the related side (e.g. sys_category_record_mm).
        // In that case uid_local holds the related UID and uid_foreign holds the record UID — reversed from standard MM.
        $hasOppositeField = isset($config['MM_opposite_field']);

        return [
            'value'          => $filter['value'],
            'mm_table'       => $mmTable,
            'mm_local_key'   => $hasOppositeField ? 'uid_local' : 'uid_foreign',
            'mm_foreign_key' => $hasOppositeField ? 'uid_foreign' : 'uid_local',
            'mm_constraints' => $config['MM_match_fields'] ?? [],
        ];
    }
}
