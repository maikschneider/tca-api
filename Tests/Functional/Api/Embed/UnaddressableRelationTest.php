<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Relations to records that have no item endpoint — a table without a registered
 * resource, or a resource without the show operation — must not render an IRI,
 * because it would not dereference.
 *
 * No fixture resource covers tx_myext_domain_model_note. setUp() points two group
 * columns at it and adds the records:
 *   article 50   note_id (select) → note 1
 *   article 200  related_pages (UID list, retargeted) → notes 1, 2
 *                related_items (multi-table, note allowed) → article 201, note 1
 */
final class UnaddressableRelationTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';
    private const NOTE_TABLE    = 'tx_myext_domain_model_note';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA'][self::ARTICLE_TABLE]['columns']['related_pages']['config']['allowed'] = self::NOTE_TABLE;
        $GLOBALS['TCA'][self::ARTICLE_TABLE]['columns']['related_items']['config']['allowed'] = self::ARTICLE_TABLE . ',' . self::NOTE_TABLE;
        $this->get(TcaSchemaFactory::class)->load($GLOBALS['TCA'], true);

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_embed.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_group.csv');

        $notes = $this->getConnectionPool()->getConnectionForTable(self::NOTE_TABLE);
        $notes->insert(self::NOTE_TABLE, ['uid' => 1, 'pid' => 1, 'title' => 'Note A']);
        $notes->insert(self::NOTE_TABLE, ['uid' => 2, 'pid' => 1, 'title' => 'Note B']);

        $articles = $this->getConnectionPool()->getConnectionForTable(self::ARTICLE_TABLE);
        $articles->update(self::ARTICLE_TABLE, ['note_id' => 1], ['uid' => 50]);
        $articles->update(self::ARTICLE_TABLE, [
            'related_pages' => '1,2',
            'related_items' => self::ARTICLE_TABLE . '_201,' . self::NOTE_TABLE . '_1',
        ], ['uid' => 200]);
    }

    public function testRelationToUnregisteredTableRendersUids(): void
    {
        $this->registerArticleResource();

        $article = $this->fetchArticle(50);
        self::assertSame(1, $article['note_id']);

        $article = $this->fetchArticle(200);
        self::assertSame([1, 2], $article['related_pages']);
        self::assertSame(
            ['/_api/ur-articles/201', ['@type' => 'TxMyextDomainModelNote', 'uid' => 1]],
            $article['related_items'],
        );
    }

    public function testEmbeddedRecordOfUnregisteredTableHasNoId(): void
    {
        $this->registerArticleResource(['embed' => true]);

        $note = $this->fetchArticle(50)['note_id'];
        self::assertArrayNotHasKey('@id', $note);
        self::assertSame('TxMyextDomainModelNote', $note['@type']);
        self::assertSame(1, $note['uid']);
        self::assertSame('Note A', $note['title']);

        $article = $this->fetchArticle(200);
        self::assertSame([1, 2], array_column($article['related_pages'], 'uid'));
        foreach ([...$article['related_pages'], ...$article['related_items']] as $related) {
            if ($related['uid'] === 201) {
                self::assertSame('/_api/ur-articles/201', $related['@id']);
                continue;
            }
            self::assertArrayNotHasKey('@id', $related);
        }
    }

    public function testRelationToResourceWithoutShowRendersUids(): void
    {
        $this->registerResource('ur-notes', [
            'general' => [
                'table'        => self::NOTE_TABLE,
                'resourceName' => 'ur-notes',
                'resourceType' => 'Note',
                'operations'   => ['list'],
            ],
        ]);
        $this->registerArticleResource();

        self::assertSame(1, $this->fetchArticle(50)['note_id']);

        $this->registerArticleResource(['embed' => true]);

        $note = $this->fetchArticle(50)['note_id'];
        self::assertArrayNotHasKey('@id', $note);
        self::assertSame('Note', $note['@type']);
    }

    public function testRelationToAddressableResourceKeepsIri(): void
    {
        $this->registerResource('ur-notes', [
            'general' => [
                'table'        => self::NOTE_TABLE,
                'resourceName' => 'ur-notes',
                'resourceType' => 'Note',
            ],
        ]);
        $this->registerArticleResource();

        self::assertSame('/_api/ur-notes/1', $this->fetchArticle(50)['note_id']);

        $this->registerArticleResource(['embed' => true]);

        self::assertSame('/_api/ur-notes/1', $this->fetchArticle(50)['note_id']['@id']);
    }

    /** @param array<string, mixed> $relationConfig applied to every relation column */
    private function registerArticleResource(array $relationConfig = []): void
    {
        $relation = ['groups' => ['list', 'show']] + $relationConfig;

        $this->registerResource('ur-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'ur-articles',
                'resourceType' => 'Article',
            ],
            'columns' => [
                'title'          => ['groups' => ['list', 'show']],
                'note_id'        => $relation,
                'related_pages'  => $relation,
                'related_items'  => $relation,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function fetchArticle(int $uid): array
    {
        return $this->decodeResponseBody($this->executeApiRequest('/_api/ur-articles/' . $uid));
    }
}
