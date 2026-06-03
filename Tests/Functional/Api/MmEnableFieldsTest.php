<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Empirical verification: does findHasManyByMM filter hidden/deleted records on the target table?
 *
 * This test creates MM relationships pointing to both visible and hidden/deleted categories,
 * then verifies whether those hidden/deleted categories leak into API responses.
 *
 * Issue: [Audit E4] findHasManyByMM may skip enableFields on JOIN target
 *
 * Fixture data:
 *   Article 1 → categories=[1 (PHP), 2 (TYPO3), 4 (Hidden Category), 5 (Deleted Category)]
 *   Article 2 → categories=[3 (API)]
 *
 *   Category 1: visible
 *   Category 2: visible
 *   Category 3: visible
 *   Category 4: HIDDEN (hidden=1)
 *   Category 5: DELETED (deleted=1)
 *
 * Expected behavior: API responses should NOT include categories 4 or 5.
 * If they do appear, this confirms the security issue.
 */
final class MmEnableFieldsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_categories_hidden.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category_record_mm_hidden.csv');
    }

    /**
     * Verify that hidden categories do NOT appear in API responses.
     * Article 1 has MM relations to categories 1, 2, 4 (hidden), and 5 (deleted).
     * Only categories 1 and 2 should be returned.
     */
    public function testHiddenCategoryIsFilteredOutFromMmRelations(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('categories', $body);
        self::assertIsArray($body['categories']);

        // Article 1 should only have visible categories (1, 2) not hidden (4) or deleted (5)
        self::assertCount(2, $body['categories'], 'Hidden and deleted categories should be filtered out');

        // Verify the returned categories are the correct ones (1 and 2)
        $categoryIds = array_map(
            fn(string $iri) => (int) basename($iri),
            $body['categories']
        );
        sort($categoryIds);

        self::assertSame([1, 2], $categoryIds, 'Only visible categories should be returned');
    }

    /**
     * Verify that deleted categories do NOT appear in API responses.
     * Similar to the hidden test but specifically checking the deleted flag.
     */
    public function testDeletedCategoryIsFilteredOutFromMmRelations(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        // Verify no category ID 5 (deleted) in the response
        $categoryIris = $body['categories'];
        foreach ($categoryIris as $iri) {
            self::assertStringNotContainsString('/5', $iri, 'Deleted category should not appear');
        }
    }

    /**
     * Verify that the collection endpoint also filters hidden/deleted categories.
     * This tests the bulk-fetch code path used by EmbedPreloader.
     */
    public function testCollectionEndpointFiltersHiddenCategories(): void
    {
        $response = $this->executeApiRequest('/_api/articles?embed=categories');
        $body = $this->decodeResponseBody($response);

        // Find Article 1 in the response
        $article1 = null;
        foreach ($body['hydra:member'] as $article) {
            if ($article['uid'] === 1) {
                $article1 = $article;
                break;
            }
        }

        self::assertNotNull($article1, 'Article 1 should exist in collection');
        self::assertArrayHasKey('categories', $article1);

        // Article 1 should only have visible categories when embedded
        if (is_array($article1['categories']) && isset($article1['categories'][0]) && is_array($article1['categories'][0])) {
            // Embedded as objects
            $categoryIds = array_map(fn($cat) => $cat['uid'], $article1['categories']);
            sort($categoryIds);
            self::assertSame([1, 2], $categoryIds, 'Only visible categories should be embedded');
        } else {
            // Embedded as IRIs
            self::assertCount(2, $article1['categories'], 'Only visible categories should be returned');
        }
    }
}
