<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Collection;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for the `search` filter strategy.
 *
 * The search strategy builds an OR expression across multiple columns:
 * (col1 LIKE %val% OR col2 LIKE %val%)
 *
 * Fixture records:
 *   uid=20  Alice  Smith    → matches "Ali" via first_name
 *   uid=21  Bob    Alifonso → matches "Ali" via last_name
 *   uid=22  Charlie Brown   → does NOT match "Ali"
 */
final class SearchFilterTest extends ApiFunctionalTestCase
{
    private const RESOURCE_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'search-people',
            'resourceType' => 'Person',
            'operations'   => ['list'],
            'itemsPerPage' => 20,
        ],
        'columns' => [
            'title'      => ['groups' => ['list', 'show']],
            'first_name' => ['groups' => ['list', 'show']],
            'last_name'  => ['groups' => ['list', 'show']],
        ],
        'filters' => [
            'search' => [
                'strategy' => 'search',
                'columns'  => ['first_name', 'last_name'],
                'match'    => 'partial',
            ],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
        'security' => [
            'list' => AccessRole::PUBLIC,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_search.csv');
        ApiRegistry::register('search-people', self::RESOURCE_CONFIG);
    }

    public function testSearchAliMatchesTwoRecords(): void
    {
        $response = $this->executeApiRequest('/_api/search-people', ['filters' => ['search' => 'Ali']]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(2, $body['hydra:totalItems']);
        self::assertCount(2, $body['hydra:member']);
    }

    public function testSearchAliIncludesFirstNameMatch(): void
    {
        $response = $this->executeApiRequest('/_api/search-people', ['filters' => ['search' => 'Ali']]);
        $body     = $this->decodeResponseBody($response);

        $firstNames = array_column($body['hydra:member'], 'first_name');
        self::assertContains('Alice', $firstNames);
    }

    public function testSearchAliIncludesLastNameMatch(): void
    {
        $response = $this->executeApiRequest('/_api/search-people', ['filters' => ['search' => 'Ali']]);
        $body     = $this->decodeResponseBody($response);

        $lastNames = array_column($body['hydra:member'], 'last_name');
        self::assertContains('Alifonso', $lastNames);
    }

    public function testSearchAliExcludesNonMatchingRecord(): void
    {
        $response = $this->executeApiRequest('/_api/search-people', ['filters' => ['search' => 'Ali']]);
        $body     = $this->decodeResponseBody($response);

        $firstNames = array_column($body['hydra:member'], 'first_name');
        self::assertNotContains('Charlie', $firstNames);
    }

    public function testSearchByUniqueNameReturnsOneRecord(): void
    {
        $response = $this->executeApiRequest('/_api/search-people', ['filters' => ['search' => 'Charlie']]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame('Charlie', $body['hydra:member'][0]['first_name']);
    }

    public function testSearchWithNoMatchReturnsEmptyCollection(): void
    {
        $response = $this->executeApiRequest('/_api/search-people', ['filters' => ['search' => 'Zzznotfound']]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(0, $body['hydra:totalItems']);
        self::assertCount(0, $body['hydra:member']);
    }
}
