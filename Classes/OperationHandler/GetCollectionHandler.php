<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class GetCollectionHandler
{
    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
    ) {}

    public function supports(string $httpMethod, string $operation): bool
    {
        return $httpMethod === 'GET' && $operation === 'list';
    }

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface
    {
        $table = $config['general']['table'];
        $baseUrl = '/_api/' . $config['general']['resourceName'];
        $limit = $config['general']['itemsPerPage'] ?? 20;

        $total = $this->dataRepository->count($table, [], $config);
        $rows = $this->dataRepository->findCollection($table, [], $limit, 0, [], $config);
        $members = $this->serializer->serializeCollection($rows, $config, $baseUrl);

        return $this->hydraResponseBuilder->buildCollection($members, $total, $baseUrl, []);
    }

    public function getPriority(): int
    {
        return 10;
    }
}
