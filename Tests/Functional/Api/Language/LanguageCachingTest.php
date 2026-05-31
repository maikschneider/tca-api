<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Enforces that language resolution participates in API cache keys.
 */
final class LanguageCachingTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage' => 'typo3conf/sites',
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/fileadmin/user_upload' => 'fileadmin/user_upload',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_multilang.csv');

        $this->registerResource('sys-categories-cached', [
            'general' => [
                'table' => 'sys_category',
                'resourceName' => 'sys-categories-cached',
                'resourceType' => 'SysCategoryCached',
                'itemsPerPage' => 50,
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
            'cache' => [
                'enabled' => true,
                'lifetime' => 3600,
            ],
        ]);

        $this->get(\TYPO3\CMS\Core\Cache\CacheManager::class)->getCache('tca_api')->flush();
    }

    /** The first cached resource request emits a MISS header. */
    public function testFirstRequestEmitsCacheMiss(): void
    {
        $response = $this->executeApiRequest('/api/sys-categories-cached');

        self::assertSame('MISS', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    /** A second request for the same locale emits a HIT header. */
    public function testSecondRequestSameLocaleEmitsCacheHit(): void
    {
        $this->executeApiRequest('/api/sys-categories-cached');
        $response = $this->executeApiRequest('/api/sys-categories-cached');

        self::assertSame('HIT', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    /** A different URL locale produces a separate cache miss. */
    public function testDifferentLocaleProducesCacheMiss(): void
    {
        $englishResponse = $this->executeApiRequest('/api/sys-categories-cached');
        $germanResponse = $this->executeApiRequest('/de/api/sys-categories-cached');

        self::assertSame('MISS', $englishResponse->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('MISS', $germanResponse->getHeaderLine('X-TCA-API-Cache'));
    }

    /** X-Locale override requests use an isolated cache key. */
    public function testXLocaleHeaderOverrideProducesIsolatedCacheKey(): void
    {
        $englishResponse = $this->executeApiRequest('/api/sys-categories-cached');
        $localeResponse = $this->executeApiRequestWithHeaders('/api/sys-categories-cached', ['X-Locale' => '1']);

        self::assertSame('MISS', $englishResponse->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('MISS', $localeResponse->getHeaderLine('X-TCA-API-Cache'));
    }

    /** Repeated requests with the same X-Locale override emit a HIT. */
    public function testRepeatedRequestSameXLocaleEmitsCacheHit(): void
    {
        $firstResponse = $this->executeApiRequestWithHeaders('/api/sys-categories-cached', ['X-Locale' => '1']);
        $secondResponse = $this->executeApiRequestWithHeaders('/api/sys-categories-cached', ['X-Locale' => '1']);

        self::assertSame('MISS', $firstResponse->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('HIT', $secondResponse->getHeaderLine('X-TCA-API-Cache'));
    }
}
