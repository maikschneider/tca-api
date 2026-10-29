<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Functional tests for general.workspace: create writes a new version into a
 * workspace and optionally sends it to a stage, instead of a live record.
 *
 * The test_workspace_writes fixture extension makes the article table
 * workspace-aware.
 *
 * Fixtures:
 *   Workspace 1 "Test Workspace"
 *   Categories 1 "PHP", 2 …
 */
final class WorkspaceCreateTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../../Fixtures/Extensions/test_workspace_writes',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
    }

    /**
     * @param array<string, mixed> $workspaceOptions
     */
    private function registerDraftArticles(array $workspaceOptions): void
    {
        $this->registerResource('draft-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'draft-articles',
                'resourceType' => 'DraftArticle',
                'operations'   => ['create'],
                'storagePid'   => 1,
            ] + $workspaceOptions,
            'columns' => [
                'title'         => ['groups' => ['show', 'create']],
                'categories'    => ['groups' => ['show', 'create']],
                'profile_photo' => ['groups' => ['show', 'create'], 'upload' => ['folder' => '1:/user_upload/']],
            ],
            'security'  => ['create' => AccessRole::FE_USER],
            'ownership' => ['column' => 'fe_user_id'],
        ]);
    }

    public function testCreateWritesANewVersionIntoTheWorkspace(): void
    {
        $this->registerDraftArticles(['workspace' => 1]);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/draft-articles', 1, ['title' => 'Draft']);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Draft', $body['title']);

        $row = $this->fetchArticle((int)$body['uid']);
        self::assertSame(1, (int)$row['t3ver_wsid']);
        self::assertSame(0, (int)$row['t3ver_oid']);
        self::assertSame(1, (int)$row['t3ver_state'], 'new version placeholder');
        self::assertSame(0, (int)$row['t3ver_stage'], 'stays in the editing stage without workspaceStage');
        self::assertSame(1, (int)$row['pid']);
        self::assertSame(1, (int)$row['fe_user_id']);
    }

    public function testCreateSendsTheVersionToTheConfiguredStage(): void
    {
        $this->registerDraftArticles(['workspace' => 1, 'workspaceStage' => -10, 'workspaceStageComment' => 'Submitted via API']);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/draft-articles', 1, ['title' => 'Ready']);
        $uid = (int)$this->decodeResponseBody($response)['uid'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(-10, (int)$this->fetchArticle($uid)['t3ver_stage']);

        $log = $this->getConnectionPool()->getConnectionForTable('sys_log')
            ->select(['details'], 'sys_log', ['tablename' => self::ARTICLE_TABLE, 'recuid' => $uid, 'error' => 0])
            ->fetchFirstColumn();
        self::assertNotEmpty(
            array_filter($log, static fn (string $details): bool => str_starts_with($details, 'Stage for record')),
            'EXT:workspaces logs the stage change',
        );
    }

    public function testRelationsBelongToTheDraft(): void
    {
        $this->registerDraftArticles(['workspace' => 1, 'workspaceStage' => -10]);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/draft-articles', 1, [
            'title'      => 'Draft with categories',
            'categories' => [1],
        ]);
        $uid = (int)$this->decodeResponseBody($response)['uid'];

        self::assertSame(201, $response->getStatusCode());

        $mm = $this->getConnectionPool()->getConnectionForTable('sys_category_record_mm')
            ->select(['uid_local'], 'sys_category_record_mm', ['tablenames' => self::ARTICLE_TABLE, 'uid_foreign' => $uid])
            ->fetchFirstColumn();
        self::assertSame([1], array_map('intval', $mm));
    }

    public function testUploadsBelongToTheDraft(): void
    {
        $this->registerDraftArticles(['workspace' => 1, 'workspaceStage' => -10]);
        $jpegContent = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 96);

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'POST',
            path:     '/_api/draft-articles',
            feUserId: 1,
            fields:   ['title' => 'Draft with photo'],
            files:    ['profile_photo' => $this->createUploadedFile($jpegContent, 'photo.jpg', 'image/jpeg')],
        );
        $uid = (int)$this->decodeResponseBody($response)['uid'];

        self::assertSame(201, $response->getStatusCode());

        $qb = $this->getConnectionPool()->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $reference = $qb->select('t3ver_wsid')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter(self::ARTICLE_TABLE)),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($reference, 'the uploaded file is referenced by the draft');
        self::assertSame(1, (int)$reference['t3ver_wsid']);
        self::assertSame(-10, (int)$this->fetchArticle($uid)['t3ver_stage'], 'the stage survives the file-reference write');
    }

    public function testUnknownWorkspaceAnswers500WithoutWriting(): void
    {
        $this->registerDraftArticles(['workspace' => 99]);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/draft-articles', 1, ['title' => 'Nowhere']);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(self::ARTICLE_TABLE)->count('*', self::ARTICLE_TABLE, []));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchArticle(int $uid): array
    {
        $qb = $this->getConnectionPool()->getQueryBuilderForTable(self::ARTICLE_TABLE);
        $qb->getRestrictions()->removeAll();

        return $qb->select('*')
            ->from(self::ARTICLE_TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative() ?: [];
    }
}
