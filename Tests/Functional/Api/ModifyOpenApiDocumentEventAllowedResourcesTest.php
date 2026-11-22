<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApiTestOpenApiDocument\AddDocumentNote;

/**
 * The site serves only `articles` (tca_api.allowedResources), so the event
 * carries the filtered document and the fixture listener finds no `ColorRead`
 * to extend.
 */
final class ModifyOpenApiDocumentEventAllowedResourcesTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_openapi_document',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Restricted' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        AddDocumentNote::$events = [];
    }

    public function testListenerReceivesOnlyTheResourcesTheSiteServes(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame(200, $response->getStatusCode());

        self::assertNotSame([], AddDocumentNote::$events);
        foreach (AddDocumentNote::$events as $event) {
            self::assertSame(['articles'], array_keys($event->getResources()));
            self::assertSame(['/_api/articles', '/_api/articles/{uid}'], array_keys($event->getDocument()['paths']));
            self::assertArrayNotHasKey('ColorRead', $event->getDocument()['components']['schemas']);
        }

        $schemas = $this->decodeResponseBody($response)['components']['schemas'];
        self::assertArrayHasKey('DocumentNote', $schemas);
        self::assertArrayNotHasKey('ColorRead', $schemas);
    }
}
