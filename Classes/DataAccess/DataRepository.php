<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

class DataRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    public function findByIds(string $table, array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $rows = $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->in('uid', array_map(
                    fn (int $uid) => $qb->createNamedParameter($uid),
                    $uids,
                )),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['uid']] = $row;
        }

        return $indexed;
    }

    public function findById(string $table, int $uid, array $config): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            );

        $this->applyPidConstraint($qb, $config);

        return $qb->executeQuery()->fetchAssociative() ?: null;
    }

    public function findCollection(string $table, array $constraints, int $limit, int $offset, array $order, array $config): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            );

        $this->applyPidConstraint($qb, $config);

        foreach ($constraints as $column => $filter) {
            $this->applyFilterConstraint($qb, $column, $filter);
        }

        foreach ($order as $column => $direction) {
            $qb->addOrderBy($column, $direction);
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }
        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function count(string $table, array $constraints, array $config): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->count('uid')
            ->from($table)
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            );

        $this->applyPidConstraint($qb, $config);

        foreach ($constraints as $column => $filter) {
            $this->applyFilterConstraint($qb, $column, $filter);
        }

        return (int)$qb->executeQuery()->fetchOne();
    }

    private function applyPidConstraint(\TYPO3\CMS\Core\Database\Query\QueryBuilder $qb, array $config): void
    {
        $pids = $this->resolvePids($config);
        if ($pids !== []) {
            $qb->andWhere($qb->expr()->in('pid', array_map(
                fn (int $pid) => $qb->createNamedParameter($pid),
                $pids,
            )));
        }
    }

    /**
     * Normalises the 'storagePid' config value (int or int[]) to a flat int[].
     * Returns [] when no storagePid is configured.
     */
    private function resolvePids(array $config): array
    {
        $raw = $config['general']['storagePid'] ?? null;
        if ($raw === null) {
            return [];
        }

        return array_map('intval', is_array($raw) ? $raw : [$raw]);
    }

    private function applyFilterConstraint(\TYPO3\CMS\Core\Database\Query\QueryBuilder $qb, string $column, array $filter): void
    {
        $value = (string)$filter['value'];
        $strategy = $filter['strategy'] ?? 'exact';

        if ($strategy === 'mm') {
            $this->applyMmFilterConstraint($qb, $filter, $value);
            return;
        }

        match ($strategy) {
            'partial'    => $qb->andWhere($qb->expr()->like(
                $column,
                $qb->createNamedParameter('%' . $qb->escapeLikeWildcards($value) . '%'),
            )),
            'word_start' => $qb->andWhere($qb->expr()->like(
                $column,
                $qb->createNamedParameter($qb->escapeLikeWildcards($value) . '%'),
            )),
            default      => $qb->andWhere($qb->expr()->eq(
                $column,
                $qb->createNamedParameter($value),
            )),
        };
    }

    private function applyMmFilterConstraint(
        \TYPO3\CMS\Core\Database\Query\QueryBuilder $qb,
        array $filter,
        string $value,
    ): void {
        if (!isset($filter['mm_table'])) {
            $filter = $this->deriveMmConfigFromTca($filter);
        }

        $mmTable       = $filter['mm_table'];
        $mmLocalKey    = $filter['mm_local_key'];
        $mmForeignKey  = $filter['mm_foreign_key'];

        $parts = [sprintf('%s = %s', $qb->quoteIdentifier($mmLocalKey), $qb->createNamedParameter($value))];
        foreach ($filter['mm_constraints'] ?? [] as $col => $val) {
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
            'strategy'       => $filter['strategy'] ?? 'mm',
            'mm_table'       => $mmTable,
            'mm_local_key'   => $hasOppositeField ? 'uid_local' : 'uid_foreign',
            'mm_foreign_key' => $hasOppositeField ? 'uid_foreign' : 'uid_local',
            'mm_constraints' => $config['MM_match_fields'] ?? [],
        ];
    }
}
