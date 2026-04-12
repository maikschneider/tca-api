<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that tca_api.defaultItemsPerPage limits collection size when no
 * itemsPerPage query parameter is supplied by the client.
 *
 * Site is configured with defaultItemsPerPage: 2. The fixture contains 3 articles,
 * so without the setting the full list would be returned in a single page.
 */
final class SiteSettingsDefaultItemsPerPageTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_LowPageSize' => 'config/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    public function testDefaultItemsPerPageLimitsCollectionWithoutQueryParam(): void
    {
        $response = $this->executeApiRequest('/_api/articles');
        $body = $this->decodeResponseBody($response);

        self::assertCount(2, $body['hydra:member']);
    }

    public function testTotalItemsStillReflectsFullCount(): void
    {
        $response = $this->executeApiRequest('/_api/articles');
        $body = $this->decodeResponseBody($response);

        self::assertSame(3, $body['hydra:totalItems']);
    }

    public function testQueryParamOverridesSiteDefault(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['itemsPerPage' => 10]);
        $body = $this->decodeResponseBody($response);

        // 3 = total visible articles in the fixture (articles.csv contains 3 non-hidden records)
        self::assertCount(3, $body['hydra:member']);
    }
}
