<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApiTestHideOnWrite\HideOnWriteListener;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Functional tests for writes that leave the record hidden.
 *
 * A create or update reads the written record back for the response. That
 * read must not apply the enable fields, otherwise a record the write hid is
 * not found and the response fails although the record was saved.
 *
 * The test_hide_on_write fixture extension hides every record written through
 * the resource, as a BeforeWriteEvent listener moderating submissions would.
 *
 * Fixtures:
 *   Article 1 "First Article" (visible)
 */
final class WriteHiddenRecordTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';

    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../../Fixtures/Extensions/test_hide_on_write',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');

        $this->registerResource(HideOnWriteListener::RESOURCE_NAME, [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => HideOnWriteListener::RESOURCE_NAME,
                'resourceType' => 'Article',
                'operations'   => ['show', 'create', 'update'],
                'storagePid'   => 1,
            ],
            'columns' => [
                'title' => ['groups' => ['show', 'create', 'update']],
            ],
            'security' => [
                'create' => AccessRole::FE_USER,
                'update' => AccessRole::FE_USER,
            ],
        ]);
    }

    public function testPostOfRecordStoredHiddenReturns201WithRecord(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/' . HideOnWriteListener::RESOURCE_NAME, 1, [
            'title' => 'Hidden Article',
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Hidden Article', $body['title']);
        self::assertSame(1, $this->fetchHidden((int)$body['uid']));
    }

    public function testPatchThatHidesRecordReturns200WithRecord(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/' . HideOnWriteListener::RESOURCE_NAME . '/1', 1, [
            'title' => 'Updated Article',
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Updated Article', $body['title']);
        self::assertSame(1, $this->fetchHidden(1));
    }

    public function testFindByIdIgnoringEnableFieldsExcludesDeletedRecords(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::ARTICLE_TABLE);
        $connection->insert(self::ARTICLE_TABLE, [
            'pid' => 1,
            'title' => 'Deleted Article',
            'hidden' => 1,
            'deleted' => 1,
        ]);
        $uid = (int)$connection->lastInsertId();
        $config = $this->getApiRegistry()->get(HideOnWriteListener::RESOURCE_NAME);
        self::assertNotNull($config);

        $repository = $this->get(DataRepository::class);
        self::assertNull($repository->findById(
            table: self::ARTICLE_TABLE,
            uid: $uid,
            config: $config,
            ignoreEnableFields: true,
        ));

        $connection->update(self::ARTICLE_TABLE, ['deleted' => 0], ['uid' => $uid]);
        $record = $repository->findById(
            table: self::ARTICLE_TABLE,
            uid: $uid,
            config: $config,
            ignoreEnableFields: true,
        );
        self::assertNotNull($record);
        self::assertSame('Deleted Article', $record['title']);
    }

    private function fetchHidden(int $uid): int
    {
        // Connection::select() applies the default restrictions, which drop hidden rows.
        $qb = $this->getConnectionPool()->getQueryBuilderForTable(self::ARTICLE_TABLE);
        $qb->getRestrictions()->removeAll();

        return (int)$qb->select('hidden')
            ->from(self::ARTICLE_TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }
}
