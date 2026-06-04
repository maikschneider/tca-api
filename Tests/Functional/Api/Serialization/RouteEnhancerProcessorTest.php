<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Serializer\Processing\RouteEnhancerProcessor;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for RouteEnhancerProcessor.
 *
 * Fixture data (from articles_with_links.csv):
 *   Article 110 → pid=1, title="External URL Article"
 *   Article 111 → pid=1, title="Page Link Article"
 *   Article 112 → pid=1, title="No URL Article"
 *   Article 113 → pid=1, title="No Processor Article"
 *
 * The Sites/main fixture has a single English language at /, root page uid=1.
 */
final class RouteEnhancerProcessorTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    private const BASE_CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_article',
            'resourceName' => 'route-articles',
            'resourceType' => 'Article',
            'operations' => ['list', 'show'],
        ],
        'columns' => [
            'title' => ['groups' => ['list', 'show']],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_links.csv');
    }

    private function withVirtualUrl(array $route): array
    {
        return array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'url' => [
                    'groups' => ['list', 'show'],
                    'processor' => RouteEnhancerProcessor::class,
                    'route' => $route,
                ],
            ],
        ]);
    }

    // ── Plain page link ──────────────────────────────────────────────────

    public function testPlainPageLinkProducesAbsoluteUrl(): void
    {
        $this->registerResource('route-articles', $this->withVirtualUrl([
            'pid' => 1,
        ]));

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/route-articles/110'));

        self::assertArrayHasKey('url', $body);
        self::assertIsString($body['url']);
        self::assertStringStartsWith('http://localhost', $body['url']);
    }

    public function testAbsoluteFalseProducesRelativePath(): void
    {
        $this->registerResource('route-articles', $this->withVirtualUrl([
            'pid'      => 1,
            'absolute' => false,
        ]));

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/route-articles/110'));

        self::assertIsString($body['url']);
        self::assertStringStartsNotWith('http://', $body['url']);
        self::assertStringStartsWith('/', $body['url']);
    }

    // ── Placeholder resolution ───────────────────────────────────────────

    public function testColumnPlaceholderForPidUsesRecordValue(): void
    {
        $this->registerResource('route-articles', $this->withVirtualUrl([
            'pid' => '{pid}',
        ]));

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/route-articles/110'));

        self::assertIsString($body['url']);
        self::assertStringStartsWith('http://localhost', $body['url']);
    }

    public function testParametersInterpolatePlaceholdersFromRow(): void
    {
        $this->registerResource('route-articles', $this->withVirtualUrl([
            'pid'        => 1,
            'parameters' => ['ref' => '{uid}'],
        ]));

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/route-articles/111'));

        self::assertIsString($body['url']);
        self::assertStringContainsString('ref=111', $body['url']);
    }

    // ── Extbase routing namespace ─────────────────────────────────────────

    public function testExtbaseRouteWrapsArgumentsUnderPluginNamespace(): void
    {
        $this->registerResource('route-articles', $this->withVirtualUrl([
            'pid'        => 1,
            'extension'  => 'News',
            'plugin'     => 'Pi1',
            'controller' => 'News',
            'action'     => 'detail',
            'arguments'  => ['news' => '{uid}'],
        ]));

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/route-articles/111'));

        self::assertIsString($body['url']);
        // Without an attached routeEnhancer, the router emits the raw query
        // namespace — that's enough to prove the wiring is correct.
        self::assertStringContainsString('tx_news_pi1', $body['url']);
        self::assertStringContainsString('news%5D=111', $body['url']);
        self::assertStringContainsString('action%5D=detail', $body['url']);
    }

    // ── Guards / failure modes ────────────────────────────────────────────

    public function testMissingRouteConfigReturnsNullVirtualProperty(): void
    {
        $this->registerResource('route-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'url' => [
                    'groups' => ['list', 'show'],
                    'processor' => RouteEnhancerProcessor::class,
                    // no 'route' key → processor returns null
                ],
            ],
        ]));

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/route-articles/110'));

        self::assertArrayHasKey('url', $body);
        self::assertNull($body['url']);
    }

    public function testUnresolvedColumnPlaceholderReturnsNull(): void
    {
        $this->registerResource('route-articles', $this->withVirtualUrl([
            'pid' => '{does_not_exist}',
        ]));

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/route-articles/110'));

        self::assertArrayHasKey('url', $body);
        self::assertNull($body['url']);
    }

    public function testZeroPidReturnsNull(): void
    {
        // Negative case: rely on the loader to reject pid=0 at config-load time.
        $this->expectException(\InvalidArgumentException::class);
        $this->registerResource('route-articles', $this->withVirtualUrl(['pid' => 0]));
    }
}
