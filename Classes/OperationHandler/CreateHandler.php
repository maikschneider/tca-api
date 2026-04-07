<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use MaikSchneider\TcaApi\Validation\FieldValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CreateHandler
{
    public function __construct(
        private readonly DataWriteService $writeService,
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly FieldValidator $fieldValidator,
    ) {}

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface
    {
        $raw = (string)$request->getBody();
        $body = $raw !== '' ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? []) : [];

        $violations = $this->fieldValidator->validate($body, $config);
        if ($violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($violations);
        }

        $data = $this->filterWritableColumns($body, $config);
        $data['pid'] = $config['general']['defaultPid'] ?? 1;

        $uid = $this->writeService->create($config['general']['table'], $data);
        $row = $this->dataRepository->findById($config['general']['table'], $uid, $config);

        $baseUrl = '/_api/' . $config['general']['resourceName'];

        $location = $baseUrl . '/' . $uid;

        return $this->hydraResponseBuilder->buildItem(
            $this->serializer->serialize($row, $config, $baseUrl),
        )->withStatus(201)->withHeader('Location', $location);
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
