<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use TYPO3\CMS\Core\Database\ConnectionPool;

class DataRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

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
        return (int)$qb->count('uid')
            ->from($table)
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            )
            ->executeQuery()
            ->fetchOne();
    }
}
