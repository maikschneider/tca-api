<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Command\OpenApiSpecCommand;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\CountingDriverMiddleware;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\QueryCounter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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

    public function testWritesTheSpecToAFile(): void
    {
        $file   = $this->instancePath . '/openapi.json';
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute(['site' => 'main', '--output' => $file]));
        self::assertSame('', $tester->getDisplay());
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame((string)$response->getBody(), file_get_contents($file));
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
