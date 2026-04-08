<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use TYPO3\CMS\Core\Database\ConnectionPool;

class ResourceSerializer
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function serialize(array $row, array $config, string $baseUrl): array
    {
        $result = [
            '@type' => $config['general']['resourceType'],
            '@id' => $baseUrl . '/' . $row['uid'],
            'uid' => (int)$row['uid'],
        ];

        foreach ($config['columns'] as $column => $columnConfig) {
            if (!($columnConfig['readable'] ?? false)) {
                continue;
            }

            $type = $columnConfig['type'] ?? 'string';

            if ($type === 'hasOne') {
                $propertyName = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
                $result[$propertyName] = $this->resolveHasOne((int)($row[$column] ?? 0), $columnConfig);
            } elseif ($type === 'manyToMany') {
                $result[$column] = $this->resolveManyToMany((int)$row['uid'], $columnConfig);
            } else {
                $result[$column] = $row[$column] ?? null;
            }
        }

        return $result;
    }

    public function serializeCollection(array $rows, array $config, string $baseUrl): array
    {
        return array_map(fn(array $row) => $this->serialize($row, $config, $baseUrl), $rows);
    }

    private function resolveHasOne(int $fkUid, array $config): ?array
    {
        if ($fkUid <= 0) {
            return null;
        }

        return $this->buildShallowEmbed($fkUid, $config);
    }

    private function resolveManyToMany(int $localUid, array $config): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($config['mmTable']);
        $qb->select($config['mmForeignKey'])
            ->from($config['mmTable'])
            ->where($qb->expr()->eq($config['mmLocalKey'], $qb->createNamedParameter($localUid)))
            ->orderBy('sorting');

        foreach ($config['mmConstraints'] ?? [] as $column => $value) {
            $qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(
            fn(array $row) => $this->buildShallowEmbed((int)$row[$config['mmForeignKey']], $config),
            $rows,
        );
    }

    private function buildShallowEmbed(int $uid, array $config): array
    {
        return [
            '@id'   => '/_api/' . $config['foreignResourceName'] . '/' . $uid,
            '@type' => $config['foreignResourceType'],
            'uid'   => $uid,
        ];
    }
}
