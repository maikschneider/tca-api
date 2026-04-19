<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final class HydraResponseBuilder
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function buildCollection(
        array $members,
        int $totalItems,
        string $collectionId,
        int $page,
        int $itemsPerPage,
    ): ResponseInterface {
        $body = [
            '@context' => 'http://www.w3.org/ns/hydra/context.jsonld',
            '@type' => 'hydra:Collection',
            '@id' => $collectionId,
            'hydra:totalItems' => $totalItems,
            'hydra:member' => $members,
            'hydra:view' => $this->buildView($collectionId, $page, $itemsPerPage, $totalItems),
        ];

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/ld+json');
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }

    public function buildItem(array $data): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/ld+json');
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response;
    }

    public function buildValidationError(array $violations): ResponseInterface
    {
        $body = [
            '@context'          => 'http://www.w3.org/ns/hydra/context.jsonld',
            '@type'             => 'hydra:Error',
            'hydra:title'       => 'Validation Failed',
            'hydra:description' => \count($violations) . ' validation error(s)',
            'violations'        => $violations,
        ];

        $response = $this->responseFactory->createResponse(422)
            ->withHeader('Content-Type', 'application/ld+json');
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }

    public function buildError(int $statusCode, string $message, string $title = 'Error'): ResponseInterface
    {
        $body = [
            '@context' => 'http://www.w3.org/ns/hydra/context.jsonld',
            '@type' => 'hydra:Error',
            'hydra:title' => $title,
            'hydra:description' => $message,
        ];

        $response = $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/ld+json');
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }

    private function buildView(string $collectionId, int $page, int $itemsPerPage, int $totalItems): array
    {
        $lastPage = (int)ceil($totalItems / $itemsPerPage);

        $link = static fn (int $p) => $collectionId . '?page=' . $p . '&itemsPerPage=' . $itemsPerPage;

        return [
            '@type' => 'hydra:PartialCollectionView',
            'hydra:first' => $link(1),
            'hydra:last' => $link($lastPage),
            'hydra:previous' => $page > 1 ? $link($page - 1) : null,
            'hydra:next' => $page < $lastPage ? $link($page + 1) : null,
        ];
    }
}
