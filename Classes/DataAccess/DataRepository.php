<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use TYPO3\CMS\Core\Database\ConnectionPool;

class DataRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function findById(string $table, int $uid, array $config): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $row = $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
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

        foreach ($constraints as $column => $filter) {
            $this->applyFilterConstraint($qb, $column, $filter);
        }

        return (int)$qb->executeQuery()->fetchOne();
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
}
