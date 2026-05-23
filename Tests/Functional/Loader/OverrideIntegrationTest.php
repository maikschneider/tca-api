<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Loader;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class OverrideIntegrationTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    #[Test]
    public function testLoaderTestResourceIsRegisteredAfterLoad(): void
    {
        $def = $this->getApiRegistry()->get('loader-test-resource');

        self::assertNotNull($def);
        self::assertSame('tx_myext_domain_model_article', $def->table);
    }

    #[Test]
    public function testLoaderTestResourceApiEndpointReturnsHydraCollection(): void
    {
        $response = $this->executeApiRequest('/_api/loader-test-resource');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('@context', $body);
        self::assertArrayHasKey('hydra:member', $body);
    }

    #[Test]
    public function testGlobalsPopulatedAfterLoad(): void
    {
        self::assertArrayHasKey('articles', $GLOBALS['TCA_API'] ?? []);
        self::assertArrayHasKey('loader-test-resource', $GLOBALS['TCA_API'] ?? []);
    }
}
