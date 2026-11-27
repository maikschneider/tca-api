<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\SearchFilter;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\CountingDriverMiddleware;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\QueryCounter;
use MaikSchneider\TcaApiTestCacheLifetime\LimitCacheLifetime;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A cached list or show response expires at the next starttime or endtime of the
 * records it is built from (tt_content_time_transitions.csv, relative to self::NOW):
 * 701 starts at +600, 702 ends at +1200, the German translation 707 of 706 starts
 * at +300. 703 (other pid), 704 (other CType) and 705 (hidden) start at +60.
 * Category 700 is assigned to 700 and 701.
 */
final class CacheTimeTransitionTest extends ApiFunctionalTestCase
{
    private const NOW = 1893456000;

    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_cache_lifetime',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage' => 'typo3conf/sites',
    ];

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

    /** @var array{int, int, int, DateTimeAspect} */
    private array $clock;

    private ?string $lastCacheHeader = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_time_transitions.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_categories_time_transitions.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category_record_mm_time_transitions.csv');
        $this->get(CacheManager::class)->getCache('tca_api')->flush();
        LimitCacheLifetime::$limits = [];
        $this->clock = [
            $GLOBALS['EXEC_TIME'],
            $GLOBALS['SIM_EXEC_TIME'],
            $GLOBALS['SIM_ACCESS_TIME'],
            GeneralUtility::makeInstance(Context::class)->getAspect('date'),
        ];

        foreach (['content' => true, 'uncached-content' => false] as $name => $cached) {
            $this->registerResource($name, [
                'general' => [
                    'table' => 'tt_content',
                    'resourceName' => $name,
                    'resourceType' => ucfirst(str_replace('-', '', ucwords($name, '-'))),
                    'operations' => ['list', 'show'],
                    'storagePid' => 1,
                ],
                'columns' => [
                    'header' => ['groups' => ['list', 'show']],
                ],
                'filters' => [
                    'CType' => ExactFilter::class,
                    'categories.title' => ExactFilter::class,
                    'q' => [SearchFilter::class, ['columns' => ['categories.title']]],
                ],
                'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
                'cache' => ['enabled' => $cached, 'lifetime' => 3600],
            ]);
        }
        foreach (['cached-articles' => true, 'uncached-articles' => false] as $name => $cached) {
            $this->registerResource($name, [
                'general' => [
                    'table' => 'tx_myext_domain_model_article',
                    'resourceName' => $name,
                    'resourceType' => ucfirst(str_replace('-', '', ucwords($name, '-'))),
                    'operations' => ['list', 'show'],
                ],
                'columns' => [
                    'title' => ['type' => 'string', 'groups' => ['list', 'show']],
                ],
                'cache' => ['enabled' => $cached, 'lifetime' => 3600],
            ]);
        }
    }

    protected function tearDown(): void
    {
        [$GLOBALS['EXEC_TIME'], $GLOBALS['SIM_EXEC_TIME'], $GLOBALS['SIM_ACCESS_TIME'], $date] = $this->clock;
        GeneralUtility::makeInstance(Context::class)->setAspect('date', $date);
        LimitCacheLifetime::$limits = [];
        parent::tearDown();
    }

    #[Test]
    public function listExpiresWhenARecordStartsAndWhenOneEnds(): void
    {
        $path = '/api/content?CType=text';

        self::assertSame(['MISS', [700, 702, 706]], $this->listAt($path, 0));
        self::assertSame(['HIT', [700, 702, 706]], $this->listAt($path, 599));
        self::assertSame(['MISS', [700, 701, 702, 706]], $this->listAt($path, 601));
        self::assertSame(['HIT', [700, 701, 702, 706]], $this->listAt($path, 1199));
        self::assertSame(['MISS', [700, 701, 706]], $this->listAt($path, 1201));
        self::assertSame(['HIT', [700, 701, 706]], $this->listAt($path, 1201 + 3599));
    }

    #[Test]
    public function listIgnoresTransitionsOfRecordsTheRequestDoesNotReturn(): void
    {
        // 703 (other pid), 704 (filtered CType) and 705 (hidden) start at +60.
        self::assertSame('MISS', $this->listAt('/api/content?CType=text', 0)[0]);
        self::assertSame('HIT', $this->listAt('/api/content?CType=text', 61)[0]);

        // Without the CType filter 704 is part of the result once it starts.
        self::assertSame(['MISS', [700, 702, 706]], $this->listAt('/api/content', 0));
        self::assertSame(['HIT', [700, 702, 706]], $this->listAt('/api/content', 59));
        self::assertSame(['MISS', [700, 702, 704, 706]], $this->listAt('/api/content', 61));
    }

    #[Test]
    public function listWithARelationFilterExpiresWhenAMatchingRecordStarts(): void
    {
        foreach (['/api/content?filters[categories.title]=Scheduled', '/api/content?filters[q]=Sched'] as $path) {
            $this->get(CacheManager::class)->getCache('tca_api')->flush();

            self::assertSame(['MISS', [700]], $this->listAt($path, 0), $path);
            self::assertSame(['HIT', [700]], $this->listAt($path, 599), $path);
            self::assertSame(['MISS', [700, 701]], $this->listAt($path, 601), $path);
        }
    }

    #[Test]
    public function listExpiresWhenAnOverlaidTranslationStarts(): void
    {
        $path = '/de/api/content?CType=text';

        self::assertSame(['MISS', ['Always visible', 'Ends later', 'Translated later']], $this->headersAt($path, 0));
        self::assertSame(['HIT', ['Always visible', 'Ends later', 'Translated later']], $this->headersAt($path, 299));
        self::assertSame(['MISS', ['Always visible', 'Ends later', 'Später übersetzt']], $this->headersAt($path, 301));
    }

    #[Test]
    public function showExpiresWhenTheRecordEnds(): void
    {
        self::assertSame([200, 'MISS'], $this->showAt('/api/content/702', 0));
        self::assertSame([200, 'HIT'], $this->showAt('/api/content/702', 1199));
        self::assertSame([404, 'MISS'], $this->showAt('/api/content/702', 1201));
    }

    #[Test]
    public function showExpiresWhenItsOverlaidTranslationStarts(): void
    {
        $path = '/de/api/content/706';

        self::assertSame('MISS', $this->showAt($path, 0)[1]);
        self::assertSame('Translated later', $this->headerOf($this->requestAt($path, 299)));
        self::assertSame('HIT', $this->lastCacheHeader);
        self::assertSame('Später übersetzt', $this->headerOf($this->requestAt($path, 301)));
        self::assertSame('MISS', $this->lastCacheHeader);
    }

    #[Test]
    public function showIgnoresTransitionsOfOtherRecords(): void
    {
        self::assertSame([200, 'MISS'], $this->showAt('/api/content/700', 0));
        self::assertSame([200, 'HIT'], $this->showAt('/api/content/700', 3599));
        self::assertSame([200, 'MISS'], $this->showAt('/api/content/700', 3601));
    }

    #[Test]
    public function listenerLimitBelowTheTransitionWins(): void
    {
        LimitCacheLifetime::$limits = [300];

        self::assertSame('MISS', $this->listAt('/api/content?CType=text', 0)[0]);
        self::assertSame('HIT', $this->listAt('/api/content?CType=text', 299)[0]);
        self::assertSame('MISS', $this->listAt('/api/content?CType=text', 301)[0]);

        self::assertSame([200, 'MISS'], $this->showAt('/api/content/702', 0));
        self::assertSame([200, 'HIT'], $this->showAt('/api/content/702', 299));
        self::assertSame([200, 'MISS'], $this->showAt('/api/content/702', 301));
    }

    #[Test]
    public function transitionBelowTheListenerLimitWins(): void
    {
        LimitCacheLifetime::$limits = [3000];

        self::assertSame('MISS', $this->listAt('/api/content?CType=text', 0)[0]);
        self::assertSame('HIT', $this->listAt('/api/content?CType=text', 599)[0]);
        self::assertSame('MISS', $this->listAt('/api/content?CType=text', 601)[0]);

        self::assertSame([200, 'MISS'], $this->showAt('/api/content/702', 0));
        self::assertSame([200, 'HIT'], $this->showAt('/api/content/702', 1199));
        self::assertSame([404, 'MISS'], $this->showAt('/api/content/702', 1201));
    }

    #[Test]
    public function tableWithoutTimeColumnsKeepsTheLifetimeAndAddsNoQuery(): void
    {
        self::assertSame([200, 'MISS'], $this->showAt('/api/cached-articles/1', 0));
        self::assertSame([200, 'HIT'], $this->showAt('/api/cached-articles/1', 3599));
        self::assertSame([200, 'MISS'], $this->showAt('/api/cached-articles/1', 3601));

        // Cache bookkeeping costs the same for both tables; tt_content adds the transition query.
        $articles = $this->queriesFor('/api/cached-articles/2') - $this->queriesFor('/api/uncached-articles/2');
        $content = $this->queriesFor('/api/content/702') - $this->queriesFor('/api/uncached-content/702');

        self::assertSame($content - 1, $articles);
    }

    /**
     * @return array{string, list<int>}
     */
    private function listAt(string $path, int $secondsLater): array
    {
        $response = $this->requestAt($path, $secondsLater);
        $members = $this->members($response);

        return [$this->lastCacheHeader ?? '', array_map(static fn (array $member): int => (int)$member['uid'], $members)];
    }

    /**
     * @return array{string, list<string>}
     */
    private function headersAt(string $path, int $secondsLater): array
    {
        $response = $this->requestAt($path, $secondsLater);

        return [$this->lastCacheHeader ?? '', array_map(static fn (array $member): string => (string)$member['header'], $this->members($response))];
    }

    /**
     * @return array{int, string}
     */
    private function showAt(string $path, int $secondsLater): array
    {
        $response = $this->requestAt($path, $secondsLater);

        return [$response->getStatusCode(), $this->lastCacheHeader ?? ''];
    }

    private function queriesFor(string $path): int
    {
        QueryCounter::start();
        $this->requestAt($path, 0);

        return QueryCounter::stop();
    }

    private function requestAt(string $path, int $secondsLater): ResponseInterface
    {
        $time = self::NOW + $secondsLater;
        $GLOBALS['EXEC_TIME'] = $time;
        $GLOBALS['SIM_EXEC_TIME'] = $time;
        $GLOBALS['SIM_ACCESS_TIME'] = $time - $time % 60;
        GeneralUtility::makeInstance(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . $time)));

        $response = $this->executeApiRequest($path);
        $this->lastCacheHeader = $response->getHeaderLine('X-TCA-API-Cache');

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function members(ResponseInterface $response): array
    {
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $body['hydra:member'] ?? $body['member'];
    }

    private function headerOf(ResponseInterface $response): string
    {
        return (string)json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['header'];
    }
}
