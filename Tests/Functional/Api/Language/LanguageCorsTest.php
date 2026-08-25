<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Enforces that invalid X-Locale 400 responses still carry CORS headers when CORS is enabled,
 * so cross-origin browser clients see the hydra:Error payload instead of a CORS failure.
 */
final class LanguageCorsTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Cors_MultiLanguage' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_multilang.csv');
    }

    /** Invalid X-Locale 400 response carries Access-Control-Allow-Origin when CORS is enabled. */
    public function testInvalidLocaleResponseIncludesCorsHeaders(): void
    {
        $response = $this->executeApiRequestWithHeaders('/api/sys-categories', [
            'X-Locale' => '99',
            'Origin'   => 'https://example.com',
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
