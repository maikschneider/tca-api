<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for the articles REST API endpoint.
 *
 * These tests MUST fail initially (RED phase) — skeleton classes return 501.
 * They become green once the real implementation is in place.
 */
final class ArticleApiTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    /**
     * A single request to /_api/articles must return a valid Hydra collection
     * with correct status, content type, structure, item count, field values,
     * and hidden-record exclusion.
     */
    public function testCollectionEndpointReturnsValidHydraResponse(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        // HTTP status
        self::assertSame(200, $response->getStatusCode());

        // Content type
        self::assertStringContainsString(
            'application/ld+json',
            $response->getHeaderLine('Content-Type'),
        );

        $body = $this->decodeResponseBody($response);

        // Hydra collection structure
        self::assertSame('hydra:Collection', $body['@type']);
        self::assertArrayHasKey('hydra:member', $body);
        self::assertArrayHasKey('hydra:totalItems', $body);

        // Item count
        self::assertSame(3, $body['hydra:totalItems']);
        self::assertCount(3, $body['hydra:member']);

        // First member title field
        $firstMember = $body['hydra:member'][0];
        self::assertArrayHasKey('title', $firstMember);
        self::assertSame('First Article', $firstMember['title']);

        // Hidden records excluded
        $titles = array_column($body['hydra:member'], 'title');
        self::assertNotContains('Hidden Article', $titles);
    }
}
