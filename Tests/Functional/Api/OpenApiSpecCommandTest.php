<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Command\OpenApiSpecCommand;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use MaikSchneider\TcaApi\OpenApi\OpenApiPathsBuilder;
use MaikSchneider\TcaApi\OpenApi\OpenApiSchemasBuilder;
use MaikSchneider\TcaApi\OpenApi\OpenApiSecurityBuilder;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\CountingDriverMiddleware;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\QueryCounter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

final class OpenApiSpecCommandTest extends ApiFunctionalTestCase
{
    protected array $configurationToUseInTestInstance = [
        'DB' => [
            'Connections' => [
                'Default' => [
                    'driverMiddlewares' => [
                        'tca-api/query-counter' => ['target' => CountingDriverMiddleware::class],
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
    }

    public function testOutputEqualsTheSpecServedOverHttp(): void
    {
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute(['site' => 'main']));

        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame((string)$response->getBody(), $tester->getDisplay());
    }

    public function testSiteMayBeOmittedWhenThereIsOnlyOne(): void
    {
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('3.1.0', json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['openapi']);
    }

    public function testWithoutListenersTheDocumentIsTheOneBuiltWithoutEventDispatcher(): void
    {
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));
        $tester->execute(['site' => 'main']);

        $builder = new OpenApiBuilder(
            $this->get(ApiRegistry::class),
            $this->get(OpenApiPathsBuilder::class),
            $this->get(OpenApiSchemasBuilder::class),
            $this->get(OpenApiSecurityBuilder::class),
        );
        $site    = $this->get(SiteFinder::class)->getSiteByIdentifier('main');
        $request = (new ServerRequest($site->getDefaultLanguage()->getBase(), 'GET'))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage());
        $spec = $builder->build(new RequestContext($site->getSettings(), $request));

        self::assertSame(json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $tester->getDisplay());
    }

    public function testWritesTheSpecToAFile(): void
    {
        $file   = $this->instancePath . '/openapi.json';
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute(['site' => 'main', '--output' => $file]));
        self::assertSame('', $tester->getDisplay());
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame((string)$response->getBody(), file_get_contents($file));
    }

    public function testASpecThatCannotBeEncodedFailsInsteadOfWritingNothing(): void
    {
        $this->registerResource('broken', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'broken',
                'resourceType' => "Broken\xB1",
                'operations'   => ['list'],
            ],
        ]);
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));

        $this->expectException(\JsonException::class);
        $tester->execute(['site' => 'main']);
    }

    public function testUnknownSiteFails(): void
    {
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));

        self::assertSame(Command::INVALID, $tester->execute(['site' => 'nope']));
        self::assertStringContainsString('available: main', $tester->getDisplay());
    }

    public function testBuildingTheSpecRunsNoDatabaseQuery(): void
    {
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));

        QueryCounter::start();
        $tester->execute(['site' => 'main']);

        self::assertSame(0, QueryCounter::stop(), 'The spec must be buildable without a database, e.g. in a CI build stage.');
    }
}
