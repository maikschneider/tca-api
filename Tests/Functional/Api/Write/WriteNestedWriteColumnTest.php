<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * `nestedWrite` on a parent column declares who may create through that relation,
 * without the child table having to be reachable as a resource of its own.
 *
 * note_id points at tx_myext_domain_model_note, which no fixture resource covers.
 */
final class WriteNestedWriteColumnTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_group.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
    }

    public function testColumnWithNestedWriteCreatesTheChildOnAnUnregisteredTable(): void
    {
        $this->registerArticles(AccessRole::FE_USER);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/nw-articles', 1, [
            'title'   => 'Article creating a note',
            'note_id' => ['title' => 'A brand new note'],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $created = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_note')
            ->select(['uid'], 'tx_myext_domain_model_note', ['title' => 'A brand new note'])
            ->fetchAssociative();

        self::assertIsArray($created, 'the nested note was not created');
    }

    public function testNestedWriteRoleIsEnforced(): void
    {
        $this->registerArticles(AccessRole::BE_ADMIN);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/nw-articles', 1, [
            'title'   => 'Article creating a note',
            'note_id' => ['title' => 'Another note'],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('CHILD_FORBIDDEN', $body['violations'][0]['code']);
    }

    public function testColumnWithoutNestedWriteStillRejectsTheUnregisteredTable(): void
    {
        $this->registerArticles(null);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/nw-articles', 1, [
            'title'   => 'Article creating a note',
            'note_id' => ['title' => 'Yet another note'],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('UNRESOLVABLE_RELATION', $body['violations'][0]['code']);
    }

    private function registerArticles(mixed $nestedWrite): void
    {
        $noteColumn = ['groups' => ['list', 'show', 'create']];
        if ($nestedWrite !== null) {
            $noteColumn['nestedWrite'] = $nestedWrite;
        }

        $this->registerResource('nw-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'nw-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show', 'create'],
                'storagePid'   => 1,
            ],
            'columns' => [
                'title'   => ['groups' => ['list', 'show', 'create'], 'required' => true],
                'note_id' => $noteColumn,
            ],
            'security' => ['create' => AccessRole::FE_USER],
        ]);
    }
}
