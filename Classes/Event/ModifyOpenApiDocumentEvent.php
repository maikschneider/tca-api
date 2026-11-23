<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Event;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;

/**
 * Dispatched once with the finished OpenAPI document, already reduced to the
 * resources of the site. Extensions describe output tca-api can't infer, e.g.
 * keys an AfterOperationEvent listener adds. Listeners may add schemas and
 * extend existing operations; they never add paths for resources the site
 * doesn't serve. The document only describes the API, it never changes what the
 * API serves or enforces.
 */
final class ModifyOpenApiDocumentEvent
{
    /**
     * @param array<string, mixed> $document
     * @param array<string, ApiDefinition> $resources
     */
    public function __construct(
        private array $document,
        private readonly array $resources,
        private readonly RequestContext $context,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocument(): array
    {
        return $this->document;
    }

    /**
     * @param array<string, mixed> $document
     */
    public function setDocument(array $document): void
    {
        $this->document = $document;
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
