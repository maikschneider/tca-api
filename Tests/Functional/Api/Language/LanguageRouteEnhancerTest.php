<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Serializer\Processing\RouteEnhancerProcessor;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that RouteEnhancerProcessor respects the current SiteLanguage and
 * emits URLs anchored to the matching language base (`/de/...`).
 */
final class LanguageRouteEnhancerTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage' => 'typo3conf/sites',
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/fileadmin/user_upload' => 'fileadmin/user_upload',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_links.csv');

        $this->registerResource('route-articles', [
            'general' => [
                'table' => 'tx_myext_domain_model_article',
                'resourceName' => 'route-articles',
                'resourceType' => 'Article',
                'operations' => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'virtualProperties' => [
                'url' => [
                    'groups' => ['list', 'show'],
                    'processor' => RouteEnhancerProcessor::class,
                    'route' => ['pid' => 1],
                ],
            ],
            'order' => [
                'allowed' => ['uid'],
                'default' => ['uid' => 'asc'],
            ],
        ]);
    }

    public function testEnglishRequestProducesRootBase(): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequest('/api/route-articles/110'));

        self::assertIsString($body['url']);
        self::assertStringStartsWith('http://localhost/', $body['url']);
        self::assertStringNotContainsString('/de/', $body['url']);
    }

    public function testGermanRequestProducesGermanBase(): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequest('/de/api/route-articles/110'));

        self::assertIsString($body['url']);
        self::assertStringContainsString('/de/', $body['url']);
    }
}
