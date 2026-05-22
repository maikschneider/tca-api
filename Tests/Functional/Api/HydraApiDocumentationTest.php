<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

final class HydraApiDocumentationTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
    }

    public function testDocsReturns200(): void
    {
        $response = $this->executeApiRequest('/_api/docs.jsonld');
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDocsReturnsJsonLd(): void
    {
        $response = $this->executeApiRequest('/_api/docs.jsonld');
        self::assertStringContainsString('application/ld+json', $response->getHeaderLine('Content-Type'));
    }

    public function testDocsTypeIsApiDocumentation(): void
    {
        $response = $this->executeApiRequest('/_api/docs.jsonld');
        $body = $this->decodeResponseBody($response);
        self::assertSame('ApiDocumentation', $body['@type']);
    }

    public function testDocsContainsSupportedClass(): void
    {
        $response = $this->executeApiRequest('/_api/docs.jsonld');
        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('hydra:supportedClass', $body);
        self::assertIsArray($body['hydra:supportedClass']);
        self::assertNotEmpty($body['hydra:supportedClass']);
    }

    public function testDocsContainsEntrypointClass(): void
    {
        $response = $this->executeApiRequest('/_api/docs.jsonld');
        $body = $this->decodeResponseBody($response);
        $ids = array_column($body['hydra:supportedClass'], '@id');
        self::assertContains('#Entrypoint', $ids);
    }

    public function testDocsContainsArticleClass(): void
    {
        $response = $this->executeApiRequest('/_api/docs.jsonld');
        $body = $this->decodeResponseBody($response);
        $ids = array_column($body['hydra:supportedClass'], '@id');
        self::assertContains('#Article', $ids);
    }

    public function testPostToDocsReturns405(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/docs.jsonld');
        self::assertSame(405, $response->getStatusCode());
    }
}
