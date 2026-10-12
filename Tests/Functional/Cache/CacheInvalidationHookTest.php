<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Cache;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * DataHandler calls clearCachePostProc from two places with differently shaped
 * tags: a map of tag => true after a record change, and a plain list of tags
 * from clear_cacheCmd() (TCEMAIN.clearCacheCmd, cacheTag:… commands).
 */
final class CacheInvalidationHookTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($this->setUpBackendUser(2));
    }

    public function testRecordChangeFlushesOnlyEntriesTaggedWithTheTableOrRecord(): void
    {
        $this->apiCache()->set('table', 'body', [self::ARTICLE_TABLE]);
        $this->apiCache()->set('record', 'body', [self::ARTICLE_TABLE . '_1']);
        $this->apiCache()->set('other', 'body', [self::ARTICLE_TABLE . '_2']);

        $this->saveArticleTitle(1, 'Changed');

        self::assertFalse($this->apiCache()->has('table'));
        self::assertFalse($this->apiCache()->has('record'));
        self::assertTrue($this->apiCache()->has('other'));
    }

    public function testRecordChangeOnPageWithClearCacheCmdDoesNotFail(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')
            ->update('pages', ['TSconfig' => 'TCEMAIN.clearCacheCmd = 1'], ['uid' => 1]);

        $dataHandler = $this->saveArticleTitle(1, 'Changed');

        self::assertSame([], $dataHandler->errorLog);
        self::assertSame(
            'Changed',
            $this->getConnectionPool()->getConnectionForTable(self::ARTICLE_TABLE)
                ->select(['title'], self::ARTICLE_TABLE, ['uid' => 1])->fetchOne(),
        );
    }

    public function testCacheTagCommandFlushesEntriesWithThatTag(): void
    {
        $this->apiCache()->set('tagged', 'body', ['custom_tag']);
        $this->apiCache()->set('untagged', 'body', ['other_tag']);

        $dataHandler = $this->get(DataHandler::class);
        $dataHandler->start([], []);
        $dataHandler->clear_cacheCmd('cacheTag:custom_tag');

        self::assertFalse($this->apiCache()->has('tagged'));
        self::assertTrue($this->apiCache()->has('untagged'));
    }

    private function saveArticleTitle(int $uid, string $title): DataHandler
    {
        $dataHandler = $this->get(DataHandler::class);
        $dataHandler->start([self::ARTICLE_TABLE => [$uid => ['title' => $title]]], []);
        $dataHandler->process_datamap();

        return $dataHandler;
    }

    private function apiCache(): FrontendInterface
    {
        return $this->get(CacheManager::class)->getCache('tca_api');
    }
}
