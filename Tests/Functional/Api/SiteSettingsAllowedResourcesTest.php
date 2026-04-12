<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that tca_api.allowedResources limits which resources are accessible.
 *
 * Site is configured with allowedResources: 'articles' — only articles are exposed.
 */
final class SiteSettingsAllowedResourcesTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Restricted' => 'config/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/colors.csv');
    }

    public function testAllowedResourceReturns200(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBlockedResourceReturns404(): void
    {
        $response = $this->executeApiRequest('/_api/colors');

        self::assertSame(404, $response->getStatusCode());
    }
}
