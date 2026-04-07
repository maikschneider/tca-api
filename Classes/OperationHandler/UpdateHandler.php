<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class UpdateHandler
{
    public function __construct(
        private readonly DataWriteService $writeService,
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function handle(ServerRequestInterface $request, array $config, int $uid, bool $partial = false): ResponseInterface
    {
        $table = $config['general']['table'];

        if ($this->dataRepository->findById($table, $uid, $config) === null) {
            return $this->responseFactory->createResponse(404)
                ->withHeader('Content-Type', 'application/ld+json');
        }

        $raw = (string)$request->getBody();
        $body = $raw !== '' ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? []) : [];

        if (!$partial) {
            $errors = $this->validate($body, $config);
            if ($errors !== []) {
                return $this->hydraResponseBuilder->buildError(422, implode(' ', $errors));
            }
        }

        $data = $this->filterWritableColumns($body, $config);
        $this->writeService->update($table, $uid, $data);

        $row = $this->dataRepository->findById($table, $uid, $config);
        $baseUrl = '/_api/' . $config['general']['resourceName'];

        return $this->hydraResponseBuilder->buildItem(
            $this->serializer->serialize($row, $config, $baseUrl),
        );
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
