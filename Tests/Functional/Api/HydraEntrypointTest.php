<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

final class HydraEntrypointTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
    }

    public function testEntrypointReturns200(): void
    {
        $response = $this->executeApiRequest('/_api/');
        self::assertSame(200, $response->getStatusCode());
    }

    public function testEntrypointReturnsJsonLd(): void
    {
        $response = $this->executeApiRequest('/_api/');
        self::assertStringContainsString('application/ld+json', $response->getHeaderLine('Content-Type'));
    }

    public function testEntrypointTypeIsEntrypoint(): void
    {
        $response = $this->executeApiRequest('/_api/');
        $body = $this->decodeResponseBody($response);
        self::assertSame('Entrypoint', $body['@type']);
    }

    public function testEntrypointContainsResourceLinks(): void
    {
        $response = $this->executeApiRequest('/_api/');
        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('articles', $body);
        self::assertStringContainsString('/_api/articles', $body['articles']);
    }

    public function testEntrypointHasApiDocumentationLinkHeader(): void
    {
        $response = $this->executeApiRequest('/_api/');
        $link = $response->getHeaderLine('Link');
        self::assertStringContainsString('docs.jsonld', $link);
        self::assertStringContainsString('hydra/core#apiDocumentation', $link);
    }

    public function testPostToEntrypointReturns405(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/');
        self::assertSame(405, $response->getStatusCode());
    }
}
