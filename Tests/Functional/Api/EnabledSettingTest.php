<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Command\OpenApiSpecCommand;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * general.enabledSetting switches a resource on per site.
 *
 * Sites_MultiSite sets tenant.extrasEnabled to true on site-a (/a-api/) and
 * to false on site-b (/b-api/); neither defines tenant.unsetFlag.
 */
final class EnabledSettingTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiSite' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_multisite.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles_multisite.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');

        $this->registerResource('extra-articles', self::articleConfig('extra-articles', 'tenant.extrasEnabled'));
        $this->registerResource('unset-articles', self::articleConfig('unset-articles', 'tenant.unsetFlag'));
        $this->get(CacheManager::class)->getCache('tca_api')->flush();
    }

    private static function articleConfig(string $resourceName, ?string $enabledSetting): array
    {
        return [
            'general' => [
                'table'          => self::ARTICLE_TABLE,
                'resourceName'   => $resourceName,
                'resourceType'   => 'Article',
                'operations'     => ['list', 'show', 'create', 'update', 'delete'],
                'enabledSetting' => $enabledSetting,
            ],
            'columns'  => [
                'title'    => ['groups' => ['list', 'show', 'create', 'update']],
                'color_id' => ['groups' => ['show'], 'resourceName' => 'extra-colors'],
            ],
            'security' => [
                'list'   => AccessRole::PUBLIC,
                'show'   => AccessRole::PUBLIC,
                'create' => AccessRole::FE_USER,
                'update' => AccessRole::FE_USER,
                'delete' => AccessRole::FE_USER,
            ],
        ];
    }

    private function onSite(string $site): string
    {
        $this->apiHost = 'http://site-' . $site . '.localhost';

        return '/' . $site . '-api';
    }

    public function testAnEnabledResourceIsServedAndDocumented(): void
    {
        $prefix = $this->onSite('a');

        self::assertSame(200, $this->executeApiRequest($prefix . '/extra-articles')->getStatusCode());
        self::assertSame(200, $this->executeApiRequest($prefix . '/extra-articles/7700')->getStatusCode());
        self::assertArrayHasKey('/a-api/extra-articles', $this->documentedPaths('site-a'));
        self::assertStringContainsString('extra-articles', (string)$this->executeApiRequest($prefix . '/')->getBody());
        self::assertStringContainsString('extra-articles', (string)$this->executeApiRequest($prefix . '/docs.jsonld')->getBody());
    }

    public function testADisabledResourceAnswersEveryOperationLikeAnUnknownOne(): void
    {
        $prefix   = $this->onSite('b');
        $notFound = $this->executeApiRequest($prefix . '/no-such-resource');
        self::assertSame(404, $notFound->getStatusCode());

        foreach (['extra-articles', 'unset-articles'] as $resource) {
            $responses = [
                'list'   => $this->executeApiRequest($prefix . '/' . $resource),
                'show'   => $this->executeApiRequest($prefix . '/' . $resource . '/7701'),
                'create' => $this->executeApiWriteRequestAs('POST', $prefix . '/' . $resource, 1, ['title' => 'Not created']),
                'update' => $this->executeApiWriteRequestAs('PATCH', $prefix . '/' . $resource . '/7701', 1, ['title' => 'Not updated']),
                'delete' => $this->executeApiWriteRequestAs('DELETE', $prefix . '/' . $resource . '/7701', 1),
            ];
            foreach ($responses as $operation => $response) {
                self::assertSame(404, $response->getStatusCode(), $resource . ' ' . $operation);
                self::assertSame((string)$notFound->getBody(), (string)$response->getBody(), $resource . ' ' . $operation);
            }
        }

        $row = $this->getConnectionPool()->getConnectionForTable(self::ARTICLE_TABLE)
            ->select(['title', 'deleted'], self::ARTICLE_TABLE, ['uid' => 7701])->fetchAssociative();
        self::assertSame(['title' => 'Site B Article', 'deleted' => 0], $row);
        self::assertFalse($this->getConnectionPool()->getConnectionForTable(self::ARTICLE_TABLE)
            ->select(['uid'], self::ARTICLE_TABLE, ['title' => 'Not created'])->fetchAssociative());
    }

    public function testAResourceWhoseSettingTheSiteDoesNotDefineIsDisabled(): void
    {
        $prefix = $this->onSite('a');

        self::assertSame(404, $this->executeApiRequest($prefix . '/unset-articles')->getStatusCode());
        self::assertSame(404, $this->executeApiRequest($prefix . '/unset-articles/7700')->getStatusCode());
        self::assertArrayNotHasKey('/a-api/unset-articles', $this->documentedPaths('site-a'));
    }

    public function testADisabledResourceIsMissingFromTheDocumentation(): void
    {
        $prefix = $this->onSite('b');

        $paths = $this->documentedPaths('site-b');
        self::assertArrayHasKey('/b-api/articles', $paths);
        self::assertArrayNotHasKey('/b-api/extra-articles', $paths);
        self::assertArrayNotHasKey('/b-api/unset-articles', $paths);

        $entrypoint = (string)$this->executeApiRequest($prefix . '/')->getBody();
        self::assertStringContainsString('/b-api/articles', $entrypoint);
        self::assertStringNotContainsString('extra-articles', $entrypoint);

        $docs = (string)$this->executeApiRequest($prefix . '/docs.jsonld')->getBody();
        self::assertStringNotContainsString('extra-articles', $docs);
        self::assertStringNotContainsString('unset-articles', $docs);
    }

    public function testTheCommandOutputEqualsTheSpecServedOverHttp(): void
    {
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute(['site' => 'site-b']));

        $response = $this->executeApiRequest($this->onSite('b') . '/openapi.json');
        self::assertSame((string)$response->getBody(), $tester->getDisplay());
        self::assertStringNotContainsString('extra-articles', $tester->getDisplay());
    }

    public function testADisabledResourceWritesNoCacheEntry(): void
    {
        $config          = self::articleConfig('cached-extra-articles', 'tenant.extrasEnabled');
        $config['cache'] = ['enabled' => true];
        $this->registerResource('cached-extra-articles', $config);

        self::assertSame(404, $this->executeApiRequest($this->onSite('b') . '/cached-extra-articles')->getStatusCode());
        self::assertSame(404, $this->executeApiRequest($this->onSite('b') . '/cached-extra-articles/7701')->getStatusCode());
        self::assertSame(0, $this->cacheEntryCount());

        // The same resource on the enabling site does write, so the check above can fail.
        $enabled = $this->executeApiRequest($this->onSite('a') . '/cached-extra-articles');
        self::assertSame('MISS', $enabled->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame(1, $this->cacheEntryCount());
    }

    public function testRelationsToADisabledResourceRenderTheUid(): void
    {
        $this->registerResource('extra-colors', [
            'general' => [
                'table'          => 'tx_myext_domain_model_color',
                'resourceName'   => 'extra-colors',
                'resourceType'   => 'Color',
                'enabledSetting' => 'tenant.extrasEnabled',
            ],
            'columns' => ['name' => ['groups' => ['list', 'show']]],
        ]);
        $this->registerResource('site-articles', self::articleConfig('site-articles', null));
        $connection = $this->getConnectionPool()->getConnectionForTable(self::ARTICLE_TABLE);
        $connection->update(self::ARTICLE_TABLE, ['color_id' => 1], ['uid' => 7700]);
        $connection->update(self::ARTICLE_TABLE, ['color_id' => 1], ['uid' => 7701]);

        $onA = $this->decodeResponseBody($this->executeApiRequest($this->onSite('a') . '/site-articles/7700'));
        $onB = $this->decodeResponseBody($this->executeApiRequest($this->onSite('b') . '/site-articles/7701'));

        self::assertSame('/a-api/extra-colors/1', parse_url((string)$onA['color_id'], PHP_URL_PATH));
        self::assertSame(1, $onB['color_id']);
    }

    /** @return array<string, mixed> the OpenAPI paths object of the site */
    private function documentedPaths(string $site): array
    {
        $tester = new CommandTester($this->get(OpenApiSpecCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute(['site' => $site]));

        return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['paths'];
    }

    private function cacheEntryCount(): int
    {
        return $this->getConnectionPool()->getConnectionForTable('cache_tca_api')->count('*', 'cache_tca_api', []);
    }
}
