<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for TypoLinkProcessor and ColumnProcessorInterface integration.
 * Fixture data:
 *   Article 110 → article_url = https://example.com  (external URL)
 *   Article 111 → article_url = t3://page?uid=1      (page link)
 *   Article 112 → article_url = ''                   (empty)
 *   Article 113 → article_url = https://example.com  (title column only, no processor)
 */
final class TypoLinkProcessorTest extends ApiFunctionalTestCase
{
    /**
     * Override the site config path to use the TYPO3 functional test instance's
     * actual config path (typo3conf/), not the v13 project-level config/ directory.
     * Environment::getConfigPath() returns typo3conf/ in functional tests.
     */
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    private const BASE_CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_article',
            'resourceName' => 'link-articles',
            'resourceType' => 'Article',
            'operations' => ['list', 'show'],
            'itemsPerPage' => 20,
        ],
        'columns' => [
            'title' => ['groups' => ['list', 'show']],
            'article_url' => [
                'groups' => ['list', 'show'],
                'processor' => TypoLinkProcessor::class,
            ],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
        'security' => [
            'list' => AccessRole::PUBLIC,
            'show' => AccessRole::PUBLIC,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_links.csv');
    }

    // ── External URL ─────────────────────────────────────────────────────────

    public function testExternalUrlReturnedAsIs(): void
    {
        ApiRegistry::register('link-articles', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/link-articles/110');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('article_url', $body);
        self::assertSame('https://example.com', $body['article_url']);
    }

    // ── Page link ─────────────────────────────────────────────────────────────

    public function testPageLinkResolvesToAbsoluteUrl(): void
    {
        ApiRegistry::register('link-articles', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/link-articles/111');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('article_url', $body);
        self::assertIsString($body['article_url']);
        self::assertStringStartsWith('http://localhost', $body['article_url']);
    }

    // ── Empty link ────────────────────────────────────────────────────────────

    public function testEmptyLinkFieldSerializesAsNull(): void
    {
        ApiRegistry::register('link-articles', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/link-articles/112');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('article_url', $body);
        self::assertNull($body['article_url']);
    }

    // ── Column without processor ──────────────────────────────────────────────

    public function testColumnWithoutProcessorReturnsRawValue(): void
    {
        ApiRegistry::register('link-articles', array_merge(self::BASE_CONFIG, [
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
                'article_url' => ['groups' => ['list', 'show']],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/link-articles/110');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        // Without processor, the raw stored value is returned directly
        self::assertIsString($body['article_url']);
        self::assertSame('https://example.com', $body['article_url']);
    }

    // ── Virtual property with processor key ──────────────────────────────────

    public function testVirtualPropertyWithProcessorKeyIsInvoked(): void
    {
        ApiRegistry::register('link-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'resolvedLink' => [
                    'groups' => ['show'],
                    'processor' => TypoLinkProcessor::class,
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/link-articles/110');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        // Processor receives null value → returns null (non-string guard)
        self::assertArrayHasKey('resolvedLink', $body);
        self::assertNull($body['resolvedLink']);
    }
}
