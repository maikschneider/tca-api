<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Collection;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for relation-path filters over an MM hop, exercised against the real
 * /articles endpoint (Articles.php declares `categories.title` and `color_id.name`).
 *
 * Fixture baseline (sys_category_record_mm.csv):
 *   Article 1 → categories [1 PHP, 2 TYPO3]   colour Red
 *   Article 2 → categories [3 API]            colour Blue
 *   Article 3 → categories []                 colour none
 */
final class FilterRelationPathMmTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');
    }

    public function testMmHopFiltersByCategoryTitle(): void
    {
        // Category 3 (API) is only on Article 2.
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/articles', ['filters' => ['categories.title' => 'API']]),
        );

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(2, $body['hydra:member'][0]['uid']);
    }

    public function testMmHopMatchesArticleWithMultipleCategories(): void
    {
        // Article 1 has [PHP, TYPO3]; filtering by TYPO3's title still returns it.
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/articles', ['filters' => ['categories.title' => 'TYPO3']]),
        );

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(1, $body['hydra:member'][0]['uid']);
    }

    public function testFkHopOnArticlesEndpoint(): void
    {
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/articles', ['filters' => ['color_id.name' => 'Blue']]),
        );

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(2, $body['hydra:member'][0]['uid']);
    }

    public function testNoMatchingCategoryTitleReturnsEmpty(): void
    {
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/articles', ['filters' => ['categories.title' => 'DoesNotExist']]),
        );

        self::assertSame(0, $body['hydra:totalItems']);
    }
}
