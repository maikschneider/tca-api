<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Relations to records that have no item endpoint — a table without a registered
 * resource, or a resource without the show operation — must not render an IRI,
 * because it would not dereference.
 *
 * Fixture data (articles_embed.csv + articles_group.csv + colors.csv):
 *   article 50   color_id → color 1 (Red)
 *   article 200  related_colors (UID list) → colors 1, 2
 *                related_items (multi-table) → article 201, color 1
 */
final class UnaddressableRelationTest extends ApiFunctionalTestCase
{
    private const COLOR_TABLE = 'tx_myext_domain_model_color';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_embed.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_group.csv');

        $registry = $this->getApiRegistry();
        $registry->replaceAll(array_filter(
            $registry->getAll(),
            static fn (ApiDefinition $resource) => $resource->table !== self::COLOR_TABLE,
        ));
    }

    public function testRelationToUnregisteredTableRendersUids(): void
    {
        $this->registerArticleResource();

        $article = $this->fetchArticle(50);
        self::assertSame(1, $article['color_id']);

        $article = $this->fetchArticle(200);
        self::assertSame([1, 2], $article['related_colors']);
        self::assertSame(
            ['/_api/ur-articles/201', ['@type' => 'TxMyextDomainModelColor', 'uid' => 1]],
            $article['related_items'],
        );
    }

    public function testEmbeddedRecordOfUnregisteredTableHasNoId(): void
    {
        $this->registerArticleResource(['embed' => true]);

        $color = $this->fetchArticle(50)['color_id'];
        self::assertArrayNotHasKey('@id', $color);
        self::assertSame('TxMyextDomainModelColor', $color['@type']);
        self::assertSame(1, $color['uid']);
        self::assertSame('Red', $color['name']);

        $article = $this->fetchArticle(200);
        self::assertSame([1, 2], array_column($article['related_colors'], 'uid'));
        foreach ([...$article['related_colors'], ...$article['related_items']] as $related) {
            if ($related['uid'] === 201) {
                self::assertSame('/_api/ur-articles/201', $related['@id']);
                continue;
            }
            self::assertArrayNotHasKey('@id', $related);
        }
    }

    public function testRelationToResourceWithoutShowRendersUids(): void
    {
        $this->registerResource('ur-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'ur-colors',
                'resourceType' => 'Color',
                'operations'   => ['list'],
            ],
        ]);
        $this->registerArticleResource();

        self::assertSame(1, $this->fetchArticle(50)['color_id']);

        $this->registerArticleResource(['embed' => true]);

        $color = $this->fetchArticle(50)['color_id'];
        self::assertArrayNotHasKey('@id', $color);
        self::assertSame('Color', $color['@type']);
    }

    public function testRelationToAddressableResourceKeepsIri(): void
    {
        $this->registerResource('ur-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'ur-colors',
                'resourceType' => 'Color',
            ],
        ]);
        $this->registerArticleResource();

        self::assertSame('/_api/ur-colors/1', $this->fetchArticle(50)['color_id']);

        $this->registerArticleResource(['embed' => true]);

        self::assertSame('/_api/ur-colors/1', $this->fetchArticle(50)['color_id']['@id']);
    }

    /** @param array<string, mixed> $relationConfig applied to every relation column */
    private function registerArticleResource(array $relationConfig = []): void
    {
        $relation = ['groups' => ['list', 'show']] + $relationConfig;

        $this->registerResource('ur-articles', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'ur-articles',
                'resourceType' => 'Article',
            ],
            'columns' => [
                'title'          => ['groups' => ['list', 'show']],
                'color_id'       => $relation,
                'related_colors' => $relation,
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
