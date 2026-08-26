<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Linking an existing FAL file through a JSON write on a type=file column.
 *
 * Fixtures: sys_file 1 = profile.jpg (image), 2 = document.pdf.
 * profile_photo is maxitems=1, downloads is unbounded.
 */
final class WriteLinkExistingFileTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_files.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');

        $this->registerResource('file-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'file-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show', 'create', 'update'],
                'storagePid'   => 1,
            ],
            'columns' => [
                'title'         => ['groups' => ['list', 'show', 'create', 'update'], 'required' => true],
                'profile_photo' => ['groups' => ['list', 'show', 'create', 'update']],
                'downloads'     => ['groups' => ['list', 'show', 'create', 'update']],
            ],
            'security' => [
                'create' => AccessRole::FE_USER,
                'update' => AccessRole::FE_USER,
            ],
        ]);
    }

    public function testScalarFileUidCreatesTheReference(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/file-articles', 1, [
            'title'         => 'Article with a linked photo',
            'profile_photo' => 1,
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->countReferences($body['uid'], 'profile_photo'));
        self::assertSame(1, $this->linkedFileUid($body['uid'], 'profile_photo'));
    }

    public function testListOfFileUidsCreatesOneReferenceEach(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/file-articles', 1, [
            'title'     => 'Article with two downloads',
            'downloads' => [1, 2],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(2, $this->countReferences($body['uid'], 'downloads'));
        // The fixture reference on article 411 must not have been stolen.
        self::assertSame(1, $this->countReferences(411, 'downloads'));
    }

    public function testObjectFormCarriesReferenceOverrides(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/file-articles', 1, [
            'title'     => 'Article with a titled download',
            'downloads' => [['fileUid' => 2, 'title' => 'The handbook']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('The handbook', $body['downloads'][0]['metadata']['title'] ?? null);
    }

    public function testUpdateReplacesRatherThanAppends(): void
    {
        // Article 410 already has profile_photo → sys_file_reference 1.
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/file-articles/410', 1, [
            'profile_photo' => 2,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->countReferences(410, 'profile_photo'));
    }

    public function testEmptyListDetachesEveryReference(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/file-articles/410', 1, [
            'profile_photo' => [],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->countReferences(410, 'profile_photo'));
    }

    public function testUnknownFileIsRejected(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/file-articles', 1, [
            'title'         => 'Article pointing at nothing',
            'profile_photo' => 9999,
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('FILE_NOT_FOUND', $body['violations'][0]['code']);
    }

    public function testMaxItemsIsEnforced(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/file-articles', 1, [
            'title'         => 'Article with too many photos',
            'profile_photo' => [1, 2],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('TOO_MANY_FILES', $body['violations'][0]['code']);
    }

    public function testUnreadableInputIsRejected(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/file-articles', 1, [
            'title'         => 'Article with nonsense',
            'profile_photo' => ['not-a-uid'],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('INVALID_FILE_INPUT', $body['violations'][0]['code']);
    }

    private function linkedFileUid(int $articleUid, string $column): ?int
    {
        $row = $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->select(
                ['uid_local'],
                'sys_file_reference',
                ['uid_foreign' => $articleUid, 'tablenames' => self::ARTICLE_TABLE, 'fieldname' => $column, 'deleted' => 0],
            )
            ->fetchAssociative();

        return $row === false ? null : (int)$row['uid_local'];
    }

    private function countReferences(int $articleUid, string $column): int
    {
        $rows = $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->select(
                ['uid'],
                'sys_file_reference',
                [
                    'uid_foreign' => $articleUid,
                    'tablenames'  => self::ARTICLE_TABLE,
                    'fieldname'   => $column,
                    'deleted'     => 0,
                ],
            )
            ->fetchAllAssociative();

        return \count($rows);
    }
}
