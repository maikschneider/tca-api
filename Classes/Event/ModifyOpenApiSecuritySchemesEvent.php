<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Event;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\OpenApi\SecurityScheme;

/**
 * Dispatched while the OpenAPI document is built. Extensions that authenticate
 * API requests add the scheme their clients use, so the document and Swagger UI
 * describe how to authenticate.
 */
final class ModifyOpenApiSecuritySchemesEvent
{
    /**
     * @param array<string, SecurityScheme> $schemes
     * @param array<string, ApiDefinition> $resources
     */
    public function __construct(
        private array $schemes,
        private readonly array $resources,
        private readonly RequestContext $context,
    ) {
    }

    /**
     * @return array<string, SecurityScheme>
     */
    public function getSchemes(): array
    {
        return $this->schemes;
    }

    public function addScheme(SecurityScheme $scheme): void
    {
        $this->schemes[$scheme->name] = $scheme;
    }

    public function removeScheme(string $name): void
    {
        unset($this->schemes[$name]);
    }

    /**
     * @return array<string, ApiDefinition>
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }
}
