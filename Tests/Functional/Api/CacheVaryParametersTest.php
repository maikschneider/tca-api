<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Dispatcher\RequestDispatcher;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;

final class CacheVaryParametersTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_cache_parameters',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    private function registerCachedResource(array $cache = []): void
    {
        $this->registerResource('cached-vary-articles', [
            'general' => [
                'table' => 'tx_myext_domain_model_article',
                'resourceName' => 'cached-vary-articles',
                'resourceType' => 'VaryArticle',
                'operations' => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['type' => 'string', 'groups' => ['list', 'show']],
            ],
            'cache' => ['enabled' => true] + $cache,
        ]);
    }

    #[Test]
    public function nestedMapOrderIsNormalised(): void
    {
        $this->registerCachedResource(['varyByParameters' => ['path', 'depth']]);
        $miss = $this->executeApiRequest('/_api/cached-vary-articles/1', [
            'path' => ['b' => ['y' => '2', 'x' => '1'], 'a' => '0'], 'depth' => '2',
        ]);
        $hit = $this->executeApiRequest('/_api/cached-vary-articles/1', [
            'depth' => '2', 'path' => ['a' => '0', 'b' => ['x' => '1', 'y' => '2']],
        ]);

        self::assertSame('MISS', $miss->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('HIT', $hit->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame((string)$miss->getBody(), (string)$hit->getBody());
    }

    #[Test]
    public function numericLookingMapKeysAreNormalised(): void
    {
        $this->registerCachedResource(['varyByParameters' => ['path']]);
        $miss = $this->executeApiRequest('/_api/cached-vary-articles/1', [
            'path' => ['01' => 'a', 1 => ['01' => 'b', 1 => 'c']],
        ]);
        $hit = $this->executeApiRequest('/_api/cached-vary-articles/1', [
            'path' => [1 => [1 => 'c', '01' => 'b'], '01' => 'a'],
        ]);

        self::assertSame('MISS', $miss->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('HIT', $hit->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame((string)$miss->getBody(), (string)$hit->getBody());
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function distinctValues(): iterable
    {
        yield 'missing versus empty' => [[], ['path' => '']];
        yield 'missing versus zero' => [[], ['path' => '0']];
        yield 'scalar versus array' => [['path' => 'a'], ['path' => ['a']]];
        yield 'list order' => [['path' => ['a', 'b']], ['path' => ['b', 'a']]];
        yield 'nested map value' => [['path' => ['x' => 'a']], ['path' => ['x' => 'b']]];
    }

    #[Test]
    #[DataProvider('distinctValues')]
    public function distinctParameterValuesDoNotShareEntries(array $first, array $second): void
    {
        $this->registerCachedResource(['varyByParameters' => ['path']]);
        $miss = $this->executeApiRequest('/_api/cached-vary-articles/1', $first);
        $other = $this->executeApiRequest('/_api/cached-vary-articles/1', $second);
        self::assertSame('MISS', $miss->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('MISS', $other->getHeaderLine('X-TCA-API-Cache'));
        self::assertNotSame((string)$miss->getBody(), (string)$other->getBody());
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function rawByteParameters(): iterable
    {
        yield 'values' => ["\xFF", "\xFE"];
        yield 'map keys' => [["\xFF" => 'a'], ["\xFE" => 'a']];
        yield 'nested values' => [['nested' => ["\xFF"]], ['nested' => ["\xFE"]]];
    }

    #[Test]
    #[DataProvider('rawByteParameters')]
    public function rawBytesRemainDistinctAndCacheable(mixed $first, mixed $second): void
    {
        $this->registerCachedResource(['varyByParameters' => ['depth']]);
        foreach ([$first, $second] as $value) {
            $miss = $this->executeApiRequest('/_api/cached-vary-articles/1', ['depth' => $value]);
            $hit = $this->executeApiRequest('/_api/cached-vary-articles/1', ['depth' => $value]);
            self::assertSame(200, $miss->getStatusCode());
            self::assertSame('MISS', $miss->getHeaderLine('X-TCA-API-Cache'));
            self::assertSame('HIT', $hit->getHeaderLine('X-TCA-API-Cache'));
            self::assertSame((string)$miss->getBody(), (string)$hit->getBody());
        }
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('cache_tca_api');
        self::assertSame(2, (int)$connection->count('*', 'cache_tca_api', []));
    }

    #[Test]
    public function unlistedParametersStillShareAnEntry(): void
    {
        $this->registerCachedResource(['varyByParameters' => ['path']]);
        $miss = $this->executeApiRequest('/_api/cached-vary-articles/1', ['path' => '/about', 'extra' => 'a']);
        $hit = $this->executeApiRequest('/_api/cached-vary-articles/1', ['extra' => 'b', 'path' => '/about']);
        self::assertSame('MISS', $miss->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('HIT', $hit->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame((string)$miss->getBody(), (string)$hit->getBody());
    }

    #[Test]
    public function unsetAndEmptyConfigurationKeepTheSameKey(): void
    {
        $this->registerCachedResource();
        $miss = $this->executeApiRequest('/_api/cached-vary-articles/1', ['path' => '/about']);
        $this->registerCachedResource(['varyByParameters' => []]);
        $hit = $this->executeApiRequest('/_api/cached-vary-articles/1', ['path' => '/access']);
        self::assertSame('MISS', $miss->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('HIT', $hit->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame((string)$miss->getBody(), (string)$hit->getBody());
    }

    #[Test]
    public function omittedOptionPreservesTheLegacyKey(): void
    {
        $this->registerCachedResource();
        $definition = $this->getApiRegistry()->get('cached-vary-articles');
        $request = (new ServerRequest('http://localhost/_api/cached-vary-articles/1'))
            ->withAttribute('tca_api.uid', 1)
            ->withAttribute('tca_api.language_id', 0)
            ->withQueryParams(['path' => '/about', 'page' => '2']);
        $dispatcher = $this->get(RequestDispatcher::class);
        $method = new \ReflectionMethod($dispatcher, 'buildCacheKey');

        self::assertSame(
            md5('show:cached-vary-articles:1:language:0:pids:null:allowed:[]:{"page":"2"}'),
            $method->invoke($dispatcher, 'show', $definition, $request),
        );
    }

    #[Test]
    public function ignoredParametersStillBypassCachingWhenListed(): void
    {
        $this->registerCachedResource(['varyByParameters' => ['path'], 'parametersToIgnore' => ['path']]);
        foreach (['/about', '/about'] as $path) {
            $response = $this->executeApiRequest('/_api/cached-vary-articles/1', ['path' => $path]);
            self::assertSame('', $response->getHeaderLine('X-TCA-API-Cache'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function readPaths(): iterable
    {
        yield 'list' => ['/_api/cached-vary-articles'];
        yield 'show' => ['/_api/cached-vary-articles/1'];
    }

    #[Test]
    #[DataProvider('readPaths')]
    public function listedParameterVariesEntriesAndBodies(string $endpoint): void
    {
        $this->registerCachedResource(['varyByParameters' => ['path']]);

        foreach (['/about', '/access'] as $path) {
            $miss = $this->executeApiRequest($endpoint, ['path' => $path]);
            self::assertSame('MISS', $miss->getHeaderLine('X-TCA-API-Cache'));
            $data = $this->decodeResponseBody($miss);
            self::assertSame($path, ($data['hydra:member'][0] ?? $data)['title']);

            $hit = $this->executeApiRequest($endpoint, ['path' => $path]);
            self::assertSame('HIT', $hit->getHeaderLine('X-TCA-API-Cache'));
            self::assertSame((string)$miss->getBody(), (string)$hit->getBody());
        }

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('cache_tca_api');
        self::assertSame(2, (int)$connection->count('*', 'cache_tca_api', []));
    }
}
