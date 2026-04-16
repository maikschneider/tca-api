<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Collection;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for the `range` filter strategy.
 *
 * The range strategy builds AND constraints from sub-operator keys:
 *   gte → column >= value
 *   lte → column <= value
 *   gt  → column >  value
 *   lt  → column <  value
 *
 * Request format: ?filters[color_id][gte]=100&filters[color_id][lte]=200
 *
 * Fixture articles (articles_range.csv):
 *   uid=400  color_id=10
 *   uid=401  color_id=50
 *   uid=402  color_id=100
 *   uid=403  color_id=150
 *   uid=404  color_id=200
 *   uid=405  color_id=300
 */
final class RangeFilterTest extends ApiFunctionalTestCase
{
    private const RESOURCE_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'range-articles',
            'resourceType' => 'Article',
            'operations'   => ['list'],
            'itemsPerPage' => 20,
        ],
        'columns' => [
            'title'    => ['groups' => ['list', 'show']],
            'color_id' => ['groups' => ['list', 'show']],
        ],
        'filters' => [
            'color_id' => ['strategy' => 'range'],
        ],
        'order' => [
            'allowed' => ['uid', 'color_id'],
            'default' => ['color_id' => 'asc'],
        ],
        'security' => [
            'list' => AccessRole::PUBLIC,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_range.csv');
        ApiRegistry::register('range-articles', self::RESOURCE_CONFIG);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function getColorIds(array $body): array
    {
        return array_column($body['hydra:member'], 'color');
    }

    private function getUids(array $body): array
    {
        return array_column($body['hydra:member'], 'uid');
    }

    // ── gte ──────────────────────────────────────────────────────────────────

    public function testGteReturnsRecordsGreaterThanOrEqual(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', ['filters' => ['color_id' => ['gte' => '100']]]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(4, $body['hydra:totalItems']);
        self::assertSame([402, 403, 404, 405], $this->getUids($body));
    }

    public function testGteIncludesBoundaryValue(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', ['filters' => ['color_id' => ['gte' => '300']]]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame([405], $this->getUids($body));
    }

    // ── lte ──────────────────────────────────────────────────────────────────

    public function testLteReturnsRecordsLessThanOrEqual(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', ['filters' => ['color_id' => ['lte' => '150']]]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(4, $body['hydra:totalItems']);
        self::assertSame([400, 401, 402, 403], $this->getUids($body));
    }

    public function testLteIncludesBoundaryValue(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', ['filters' => ['color_id' => ['lte' => '10']]]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame([400], $this->getUids($body));
    }

    // ── gt ───────────────────────────────────────────────────────────────────

    public function testGtReturnsRecordsStrictlyGreaterThan(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', ['filters' => ['color_id' => ['gt' => '100']]]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $body['hydra:totalItems']);
        self::assertSame([403, 404, 405], $this->getUids($body));
    }

    public function testGtExcludesBoundaryValue(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', ['filters' => ['color_id' => ['gt' => '300']]]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(0, $body['hydra:totalItems']);
    }

    // ── lt ───────────────────────────────────────────────────────────────────

    public function testLtReturnsRecordsStrictlyLessThan(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', ['filters' => ['color_id' => ['lt' => '150']]]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $body['hydra:totalItems']);
        self::assertSame([400, 401, 402], $this->getUids($body));
    }

    public function testLtExcludesBoundaryValue(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', ['filters' => ['color_id' => ['lt' => '10']]]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(0, $body['hydra:totalItems']);
    }

    // ── combinations ─────────────────────────────────────────────────────────

    public function testGteAndLteCombinationReturnsClosedRange(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', [
            'filters' => ['color_id' => ['gte' => '100', 'lte' => '200']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $body['hydra:totalItems']);
        self::assertSame([402, 403, 404], $this->getUids($body));
    }

    public function testGtAndLtCombinationReturnsOpenRange(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', [
            'filters' => ['color_id' => ['gt' => '50', 'lt' => '200']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(2, $body['hydra:totalItems']);
        self::assertSame([402, 403], $this->getUids($body));
    }

    // ── edge: empty result ────────────────────────────────────────────────────

    public function testImpossibleRangeReturnsEmptyCollection(): void
    {
        $response = $this->executeApiRequest('/_api/range-articles', [
            'filters' => ['color_id' => ['gte' => '200', 'lte' => '100']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $body['hydra:totalItems']);
        self::assertSame([], $body['hydra:member']);
    }
}
