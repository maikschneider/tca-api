<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CreateHandler
{
    public function __construct(
        private readonly DataWriteService $writeService,
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
    ) {}

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface
    {
        $raw = (string)$request->getBody();
        $body = $raw !== '' ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? []) : [];

        $errors = $this->validate($body, $config);
        if ($errors !== []) {
            return $this->hydraResponseBuilder->buildError(422, implode(' ', $errors));
        }

        $data = $this->filterWritableColumns($body, $config);
        $data['pid'] = $config['general']['defaultPid'] ?? 1;

        $uid = $this->writeService->create($config['general']['table'], $data);
        $row = $this->dataRepository->findById($config['general']['table'], $uid, $config);

        $baseUrl = '/_api/' . $config['general']['resourceName'];

        return $this->hydraResponseBuilder->buildItem(
            $this->serializer->serialize($row, $config, $baseUrl),
        )->withStatus(201);
    }

    private function validate(array $body, array $config): array
    {
        $errors = [];
        foreach ($config['columns'] as $column => $columnConfig) {
            if (($columnConfig['required'] ?? false) && ($columnConfig['writable'] ?? false)) {
                if (!isset($body[$column]) || $body[$column] === '') {
                    $errors[] = "Field '$column' is required.";
                }
            }
        }
        return $errors;
    }

    private function filterWritableColumns(array $body, array $config): array
    {
        $result = [];
        foreach ($config['columns'] as $column => $columnConfig) {
            if (($columnConfig['writable'] ?? false) && \array_key_exists($column, $body)) {
                $result[$column] = $body[$column];
            }
        }
        return $result;
    }
}
