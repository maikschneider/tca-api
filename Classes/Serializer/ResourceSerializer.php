<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

class ResourceSerializer
{
    public function serialize(array $row, array $config, string $baseUrl): array
    {
        $result = [
            '@type' => $config['general']['resourceType'],
            '@id' => $baseUrl . '/' . $row['uid'],
            'uid' => (int)$row['uid'],
        ];

        foreach ($config['columns'] as $column => $columnConfig) {
            if ($columnConfig['readable'] ?? false) {
                $result[$column] = $row[$column] ?? null;
            }
        }

        return $result;
    }

    public function serializeCollection(array $rows, array $config, string $baseUrl): array
    {
        return array_map(fn(array $row) => $this->serialize($row, $config, $baseUrl), $rows);
    }
}
