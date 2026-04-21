<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Filter\FilterInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class DataRepository
{
    /** @var array<string, FilterInterface>|null */
    private ?array $filterMap = null;

    /**
     * @param iterable<FilterInterface> $filterHandlers
     */
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        #[TaggedIterator('tca_api.filter')]
        private readonly iterable $filterHandlers,
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
                ))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['uid']] = $row;
        }

        return $indexed;
    }

    public function findById(string $table, int $uid, ApiDefinition $config): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid))
            );

        $this->applyPidConstraint($qb, $config);

        return $qb->executeQuery()->fetchAssociative() ?: null;
    }

    public function findCollection(string $table, array $constraints, int $limit, int $offset, array $order, ApiDefinition $config): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->select('*')
            ->from($table);

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

    public function count(string $table, array $constraints, ApiDefinition $config): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->count('uid')
            ->from($table);

        $this->applyPidConstraint($qb, $config);

        foreach ($constraints as $column => $filter) {
            $this->applyFilterConstraint($qb, $column, $filter);
        }

        return (int)$qb->executeQuery()->fetchOne();
    }

    /**
     * Bulk-fetch hasMany related records via an MM intermediate table.
     * Returns [parentUid => [rows]] preserving MM sorting order.
     */
    public function findHasManyByMM(
        string $foreignTable,
        array $parentUids,
        string $mmTable,
        string $mmParentKey,
        string $mmForeignKey,
        array $mmConstraints = [],
    ): array {
        if ($parentUids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($foreignTable);
        $qb->select('f.*', 'mm.' . $mmParentKey . ' AS __parent_uid')
            ->from($foreignTable, 'f')
            ->join(
                'f',
                $mmTable,
                'mm',
                $qb->expr()->eq('f.uid', $qb->quoteIdentifier('mm.' . $mmForeignKey)),
            )
            ->where($qb->expr()->in(
                'mm.' . $mmParentKey,
                array_map(fn (int $uid) => $qb->createNamedParameter($uid), $parentUids),
            ))
            ->addOrderBy('mm.sorting');

        foreach ($mmConstraints as $col => $val) {
            $qb->andWhere($qb->expr()->eq('mm.' . $col, $qb->createNamedParameter($val)));
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $grouped = [];
        foreach ($rows as $row) {
            $parentUid = (int)$row['__parent_uid'];
            unset($row['__parent_uid']);
            $grouped[$parentUid][] = $row;
        }

        return $grouped;
    }

    /**
     * Bulk-fetch hasMany related records via a back-pointer (foreign_field) on the child table.
     * Returns [parentUid => [rows]] ordered by the child table's default sorting.
     */
    public function findHasManyByForeignField(
        string $foreignTable,
        string $foreignField,
        array $parentUids,
    ): array {
        if ($parentUids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($foreignTable);
        $rows = $qb->select('*')
            ->from($foreignTable)
            ->where($qb->expr()->in(
                $foreignField,
                array_map(fn (int $uid) => $qb->createNamedParameter($uid), $parentUids),
            ))
            ->executeQuery()
            ->fetchAllAssociative();

        $grouped = [];
        foreach ($rows as $row) {
            $parentUid = (int)$row[$foreignField];
            $grouped[$parentUid][] = $row;
        }

        return $grouped;
    }

    private function applyPidConstraint(QueryBuilder $qb, ApiDefinition $config): void
    {
        if ($config->storagePid !== null) {
            $qb->andWhere($qb->expr()->eq('pid', $qb->createNamedParameter($config->storagePid, Connection::PARAM_INT)));
        }
    }

    private function applyFilterConstraint(QueryBuilder $qb, string $column, array $filter): void
    {
        $this->resolveFilter($filter['_filterClass'])->apply($qb, $column, $filter);
    }

    private function resolveFilter(string $fqcn): FilterInterface
    {
        if ($this->filterMap === null) {
            $this->filterMap = [];
            foreach ($this->filterHandlers as $filter) {
                $this->filterMap[$filter::class] = $filter;
            }
        }

        return $this->filterMap[$fqcn] ?? throw new \InvalidArgumentException(
            sprintf('No filter registered for class "%s".', $fqcn),
        );
    }
}
