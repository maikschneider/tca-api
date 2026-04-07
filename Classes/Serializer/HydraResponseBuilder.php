<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class HydraResponseBuilder
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function buildCollection(array $members, int $totalItems, string $collectionId, array $paginationLinks): ResponseInterface
    {
        // TODO: assemble Hydra Collection document, encode as JSON, return 200 response
        $body = [
            '@context' => 'http://www.w3.org/ns/hydra/context.jsonld',
            '@type' => 'hydra:Collection',
            '@id' => $collectionId,
            'hydra:totalItems' => $totalItems,
            'hydra:member' => $members,
        ];

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/ld+json');
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }

    public function buildItem(array $data): ResponseInterface
    {
        // TODO: assemble Hydra item document, encode as JSON, return 200 response
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/ld+json');
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response;
    }

    public function buildError(int $statusCode, string $message): ResponseInterface
    {
        // TODO: Hydra error format
        $body = [
            '@context' => 'http://www.w3.org/ns/hydra/context.jsonld',
            '@type' => 'hydra:Error',
            'hydra:title' => 'Error',
            'hydra:description' => $message,
        ];

        $response = $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/ld+json');
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }
}
