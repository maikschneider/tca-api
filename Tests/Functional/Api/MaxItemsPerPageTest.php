<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for the maxItemsPerPage configuration option.
 *
 * Uses the articles fixture (3 visible records) and registers a custom
 * resource with maxItemsPerPage to verify clamping behaviour.
 */
final class MaxItemsPerPageTest extends ApiFunctionalTestCase
{
    private const RESOURCE = 'capped-articles';

    private const CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_article',
            'resourceName' => self::RESOURCE,
            'resourceType' => 'Article',
            'operations' => ['list', 'show'],
            'itemsPerPage' => 20,
            'maxItemsPerPage' => 2,
        ],
        'columns' => [
            'title' => ['readable' => true, 'writable' => false, 'required' => false],
        ],
        'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        ApiRegistry::register(self::RESOURCE, self::CONFIG);
    }

    public function testRequestedItemsPerPageIsClampedToMax(): void
    {
        // Request 50 items, but maxItemsPerPage is 2 → only 2 returned
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE, ['itemsPerPage' => 50]);
        $body = $this->decodeResponseBody($response);

        self::assertCount(2, $body['hydra:member']);
    }

    public function testTotalItemsIsUnaffectedByClamping(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE, ['itemsPerPage' => 50]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(3, $body['hydra:totalItems']);
    }

    public function testDefaultMaxIsAppliedWhenNotConfigured(): void
    {
        // Register a resource without maxItemsPerPage — default should be 100
        $config = self::CONFIG;
        unset($config['general']['maxItemsPerPage']);
        ApiRegistry::register('uncapped-articles', $config);

        $response = $this->executeApiRequest('/_api/uncapped-articles', ['itemsPerPage' => 999]);
        $body = $this->decodeResponseBody($response);

        // Fixture has only 3 visible records, all should be returned since 100 > 3
        self::assertCount(3, $body['hydra:member']);
    }

    public function testRequestBelowMaxIsNotClamped(): void
    {
        // Request 1 item when max is 2 → should return 1
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE, ['itemsPerPage' => 1]);
        $body = $this->decodeResponseBody($response);

        self::assertCount(1, $body['hydra:member']);
    }
}
