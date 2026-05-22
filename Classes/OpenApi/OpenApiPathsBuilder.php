<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;

final readonly class OpenApiPathsBuilder
{
    public function __construct(private OpenApiOperationBuilder $operationBuilder)
    {
    }

    /** @param array<string, ApiDefinition> $resources */
    public function build(array $resources, RequestContext $ctx): array
    {
        $paths = [];

        foreach ($resources as $resourceName => $config) {
            $collectionPath = $ctx->prefix . '/' . $resourceName;
            $itemPath = $ctx->prefix . '/' . $resourceName . '/{uid}';

            $collectionItem = [];
            if ($config->hasOperation('list')) {
                $collectionItem['get'] = $this->operationBuilder->buildListOperation($resourceName, $config->resourceType, $config);
            }
            if ($config->hasOperation('create')) {
                $collectionItem['post'] = $this->operationBuilder->buildCreateOperation($resourceName, $config->resourceType, $config);
            }
            if ($collectionItem !== []) {
                $paths[$collectionPath] = $collectionItem;
            }

            $itemItem = [];
            if ($config->hasOperation('show')) {
                $itemItem['get'] = $this->operationBuilder->buildShowOperation($resourceName, $config->resourceType, $config);
            }
            if ($config->hasOperation('update')) {
                $itemItem['put'] = $this->operationBuilder->buildUpdateOperation($resourceName, $config->resourceType, $config, partial: false);
                $itemItem['patch'] = $this->operationBuilder->buildUpdateOperation($resourceName, $config->resourceType, $config, partial: true);
            }
            if ($config->hasOperation('delete')) {
                $itemItem['delete'] = $this->operationBuilder->buildDeleteOperation($resourceName, $config);
            }
            if ($itemItem !== []) {
                $paths[$itemPath] = array_merge(
                    ['parameters' => [['name' => 'uid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]]],
                    $itemItem,
                );
            }
        }

        return $paths;
    }
}
