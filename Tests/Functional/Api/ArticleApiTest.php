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
     * Phase 9 target: the first test to go green.
     * The endpoint must respond with HTTP 200.
     */
    public function testApiEndpointReturns200(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Response must carry the correct JSON-LD content type.
     */
    public function testApiEndpointReturnsJsonLdContentType(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertStringContainsString(
            'application/ld+json',
            $response->getHeaderLine('Content-Type'),
        );
    }

    /**
     * Response must be a Hydra Collection with the correct structure.
     */
    public function testCollectionResponseIsHydraCollection(): void
    {
        $response = $this->executeApiRequest('/_api/articles');
        $body = $this->decodeResponseBody($response);

        self::assertSame('hydra:Collection', $body['@type']);
        self::assertArrayHasKey('hydra:member', $body);
        self::assertArrayHasKey('hydra:totalItems', $body);
    }

    /**
     * The collection must contain all 3 seeded articles.
     */
    public function testCollectionContainsAllArticles(): void
    {
        $response = $this->executeApiRequest('/_api/articles');
        $body = $this->decodeResponseBody($response);

        self::assertSame(3, $body['hydra:totalItems']);
        self::assertCount(3, $body['hydra:member']);
    }

    /**
     * Each member must expose the `title` field as configured.
     */
    public function testCollectionMembersHaveTitleField(): void
    {
        $response = $this->executeApiRequest('/_api/articles');
        $body = $this->decodeResponseBody($response);

        $firstMember = $body['hydra:member'][0];
        self::assertArrayHasKey('title', $firstMember);
        self::assertSame('First Article', $firstMember['title']);
    }
}
