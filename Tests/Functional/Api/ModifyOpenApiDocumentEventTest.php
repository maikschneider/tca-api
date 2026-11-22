<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Command\OpenApiSpecCommand;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApiTestOpenApiDocument\AddDocumentNote;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The `test_openapi_document` fixture adds a `DocumentNote` schema and extends
 * `ColorRead` through ModifyOpenApiDocumentEvent.
 */
final class ModifyOpenApiDocumentEventTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_openapi_document',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        AddDocumentNote::$events = [];
    }

    public function testListenerChangesTheDocumentServedOverHttp(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame(200, $response->getStatusCode());

        $schemas = $this->decodeResponseBody($response)['components']['schemas'];
        self::assertSame(['type' => 'object', 'properties' => ['text' => ['type' => 'string']]], $schemas['DocumentNote']);
        self::assertSame(['$ref' => '#/components/schemas/DocumentNote'], $schemas['ColorRead']['properties']['note']);
    }

    public function testCommandOutputEqualsTheModifiedDocumentServedOverHttp(): void
    {
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute(['site' => 'main']));
        self::assertCount(1, AddDocumentNote::$events);
        self::assertStringContainsString('"DocumentNote"', $tester->getDisplay());

        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame((string)$response->getBody(), $tester->getDisplay());
    }

    public function testListenerReceivesTheResourcesOfTheSite(): void
    {
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));
        $tester->execute(['site' => 'main']);

        $event = AddDocumentNote::$events[0];
        self::assertContains('colors', array_keys($event->getResources()));
        self::assertArrayHasKey('/_api/colors', $event->getDocument()['paths']);
        self::assertArrayNotHasKey('DocumentNote', $event->getDocument()['components']['schemas']);
        self::assertSame('/_api', $event->getContext()->prefix);
    }
}
