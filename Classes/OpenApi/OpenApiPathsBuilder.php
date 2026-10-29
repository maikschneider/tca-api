<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\Enum\AccessRole;

final readonly class OpenApiPathsBuilder
{
    public function __construct(
        private OpenApiOperationBuilder $operationBuilder,
        private OpenApiSecurityBuilder $securityBuilder,
    ) {
    }

    /**
     * @param array<string, ApiDefinition> $resources
     * @param array<string, SecurityScheme> $schemes
     */
    public function build(array $resources, RequestContext $ctx, array $schemes = []): array
    {
        $paths = [];
        $names = new OpenApiSchemaNames($resources);

        foreach ($resources as $resourceName => $config) {
            $collectionPath = $ctx->prefix . '/' . $resourceName;
            $itemPath = $ctx->prefix . '/' . $resourceName . '/{uid}';

            $collectionItem = [];
            if ($config->hasOperation('list')) {
                $collectionItem['get'] = $this->withSecurity($this->operationBuilder->buildListOperation($resourceName, $names->of($config), $config), $config, 'list', $schemes);
            }
            if ($config->hasOperation('create')) {
                $collectionItem['post'] = $this->withSecurity($this->operationBuilder->buildCreateOperation($resourceName, $names->of($config), $config), $config, 'create', $schemes);
            }
            if ($collectionItem !== []) {
                $paths[$collectionPath] = $collectionItem;
            }

            $itemItem = [];
            if ($config->hasOperation('show')) {
                $itemItem['get'] = $this->withSecurity($this->operationBuilder->buildShowOperation($resourceName, $names->of($config), $config), $config, 'show', $schemes);
            }
            if ($config->hasOperation('update')) {
                $itemItem['put'] = $this->withSecurity($this->operationBuilder->buildUpdateOperation($resourceName, $names->of($config), $config, partial: false), $config, 'update', $schemes);
                $itemItem['patch'] = $this->withSecurity($this->operationBuilder->buildUpdateOperation($resourceName, $names->of($config), $config, partial: true), $config, 'update', $schemes);
            }
            if ($config->hasOperation('delete')) {
                $itemItem['delete'] = $this->withSecurity($this->operationBuilder->buildDeleteOperation($resourceName, $config), $config, 'delete', $schemes);
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

    /**
     * @param array<string, SecurityScheme> $schemes
     */
    private function withSecurity(array $operation, ApiDefinition $config, string $operationName, array $schemes): array
    {
        $role = $config->securityRole($operationName);
        $requirement = $this->securityBuilder->buildRequirement($role, $config->resourceName, $operationName, $schemes, $config->ownershipBeAdminBypass);
        if ($requirement !== null) {
            $operation['security'] = $requirement;
        }

        // An operation no scheme can satisfy is still protected and answers 401.
        $isProtected = $requirement === null ? $role !== AccessRole::DISABLED : $requirement !== [];
        if ($isProtected) {
            $operation['responses']['401'] = [
                'description' => 'Invalid credentials',
                'content' => ['application/ld+json' => ['schema' => ['$ref' => '#/components/schemas/HydraError']]],
            ];
            ksort($operation['responses']);
        }

        return $operation;
    }
}
