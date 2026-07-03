<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
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
        array $queryParams = [],
        ?ApiDefinition $config = null,
    ): ResponseInterface {
        $body = [
            '@context' => 'http://www.w3.org/ns/hydra/context.jsonld',
            '@type' => 'hydra:Collection',
            '@id' => $collectionId,
            'hydra:totalItems' => $totalItems,
            'hydra:member' => $members,
            'hydra:view' => $this->buildView($collectionId, $page, $itemsPerPage, $totalItems, $queryParams),
        ];

        if ($config !== null) {
            $searchTemplate = $this->buildSearchTemplate($collectionId, $config);
            if ($searchTemplate !== []) {
                $body['hydra:search'] = $searchTemplate;
            }
        }

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

    private function buildSearchTemplate(string $baseUrl, ApiDefinition $config): array
    {
        $mappings  = [];
        $variables = [];

        foreach ($config->allowedOrder as $field) {
            $var         = 'order[' . $field . ']';
            $variables[] = $field;
            $variables[] = $var;
            $mappings[]  = ['@type' => 'IriTemplateMapping', 'variable' => $field, 'property' => $field, 'required' => false];
            $mappings[]  = ['@type' => 'IriTemplateMapping', 'variable' => $var, 'property' => $field, 'required' => false];
        }

        foreach ($config->filters as $field => $filterDef) {
            if ($filterDef->isPrivate) {
                continue;
            }
            // Relation-path (dotted) keys only work via the bracket form: PHP rewrites
            // dots in top-level parameter names to underscores. Plain keys accept both,
            // so keep advertising them as plain top-level variables.
            $var = str_contains($field, '.') ? 'filters[' . $field . ']' : $field;
            if (!\in_array($var, $variables, true)) {
                $variables[] = $var;
                $mappings[]  = ['@type' => 'IriTemplateMapping', 'variable' => $var, 'property' => $field, 'required' => false];
            }
        }

        if ($mappings === []) {
            return [];
        }

        return [
            '@type'                        => 'hydra:IriTemplate',
            'hydra:template'               => $baseUrl . '{?' . implode(',', $variables) . '}',
            'hydra:variableRepresentation' => 'BasicRepresentation',
            'hydra:mapping'                => $mappings,
        ];
    }

    private function buildView(string $collectionId, int $page, int $itemsPerPage, int $totalItems, array $queryParams = []): array
    {
        $lastPage = $itemsPerPage > 0 ? (int)ceil($totalItems / $itemsPerPage) : 1;

        $link = static fn (int $p) => $collectionId . '?' . http_build_query(array_merge($queryParams, ['page' => $p]));

        return [
            '@type' => 'hydra:PartialCollectionView',
            'hydra:first' => $link(1),
            'hydra:last' => $link($lastPage),
            'hydra:previous' => $page > 1 ? $link($page - 1) : null,
            'hydra:next' => $page < $lastPage ? $link($page + 1) : null,
        ];
    }
}
