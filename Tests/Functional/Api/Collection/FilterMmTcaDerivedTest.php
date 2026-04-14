<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Collection;

use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for MM filter strategy with TCA-derived table metadata.
 *
 * RED phase: DataRepository.applyMmFilterConstraint currently requires mm_table,
 * mm_local_key, mm_foreign_key, mm_constraints to be explicit in the filter config.
 * These must be auto-derived from the TCA Schema API when omitted.
 *
 * Target simplified config:
 *   'categories' => ['strategy' => 'mm']
 *
 * The system must derive from TCA:
 *   - mm_table        → sys_category_record_mm
 *   - mm_local_key    → uid_local
 *   - mm_foreign_key  → uid_foreign
 *   - mm_constraints  → [tablenames => tx_myext_domain_model_article, fieldname => categories]
 *
 * Fixture baseline:
 *   Article 1 → categories=[1 (PHP), 2 (TYPO3)]
 *   Article 2 → categories=[3 (API)]
 *   Article 3 → categories=[]
 */
final class FilterMmTcaDerivedTest extends ApiFunctionalTestCase
{
    private const RESOURCE = 'articles-mm-derived';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');

        // Minimal MM filter config — no mm_table, mm_local_key, mm_foreign_key, mm_constraints
        ApiRegistry::register(self::RESOURCE, [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => self::RESOURCE,
                'resourceType' => 'Article',
                'operations'   => ['list'],
                'itemsPerPage' => 20,
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'filters' => [
                'categories' => ['strategy' => 'mm'],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    public function testMmFilterWithNoExplicitTableConfigReturnsMatchingArticles(): void
    {
        // Category 3 (API) is only on Article 2 — derived from TCA, no manual config
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['categories' => 3]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(2, $body['hydra:member'][0]['uid']);
    }

    public function testMmFilterWithNoExplicitTableConfigUpdatesTotalItems(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['categories' => 1]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
    }

    public function testMmFilterWithNoExplicitTableConfigReturnsEmptyForNoMatch(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['categories' => 999]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(0, $body['hydra:totalItems']);
    }

    public function testMmFilterWithNoExplicitTableConfigMatchesArticleWithMultipleCategories(): void
    {
        // Article 1 has [1 (PHP), 2 (TYPO3)] — filtering by 2 must still return it
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['categories' => 2]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(1, $body['hydra:member'][0]['uid']);
    }

    public function testExplicitConfigAndDerivedConfigProduceSameResults(): void
    {
        // The derived config must produce identical results to the explicit config in Articles.php
        $derived   = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['categories' => 1]])
        );
        $explicit = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/articles', ['filters' => ['categories' => 1]])
        );

        self::assertSame($explicit['hydra:totalItems'], $derived['hydra:totalItems']);
        self::assertSame(
            array_column($explicit['hydra:member'], 'uid'),
            array_column($derived['hydra:member'], 'uid'),
        );
    }

    // ── Articles.php simplified to bare 'strategy' => 'mm' ───────────────────
    // These tests use the main /articles endpoint after Articles.php is simplified
    // (explicit mm_table etc. removed). They will pass once Articles.php is updated.

    public function testArticlesEndpointFiltersByCategoryWithSimplifiedConfig(): void
    {
        // Temporarily register articles with simplified config to simulate Articles.php update
        ApiRegistry::register('articles-simplified', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'articles-simplified',
                'resourceType' => 'Article',
                'operations'   => ['list'],
                'itemsPerPage' => 20,
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']]],
            'filters' => [
                'categories' => ['strategy' => 'mm'],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $response = $this->executeApiRequest('/_api/articles-simplified', ['filters' => ['categories' => 3]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(2, $body['hydra:member'][0]['uid']);
    }
}
