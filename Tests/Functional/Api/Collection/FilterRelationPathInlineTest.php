<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Collection;

use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for relation-path filters over inline (foreign_field) hops.
 *
 * The child (colour) rows back-point to the parent article via foreign_article_id,
 * optionally discriminated by parent_tablename (foreign_table_field) and
 * field_to_match (foreign_match_fields). Article 2300 owns all children below;
 * article 2301 owns none.
 *
 * Children of 2300 (colors_relpath_inline.csv):
 *   2310 InlinePlain        field_to_match=''       parent_tablename=''
 *   2311 InlineTypeA        field_to_match=type_a   parent_tablename=''
 *   2312 InlineTypeB        field_to_match=type_b   parent_tablename=''
 *   2313 InlineFromArticle  field_to_match=''       parent_tablename=tx_..._article
 *   2314 InlineFromColor    field_to_match=''       parent_tablename=tx_..._color
 *   2315 InlineDeleted      deleted=1
 */
final class FilterRelationPathInlineTest extends ApiFunctionalTestCase
{
    private const RESOURCE = 'articles-relpath-inline';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_relpath_inline.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_relpath_inline.csv');

        $this->registerResource(self::RESOURCE, [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => self::RESOURCE,
                'resourceType' => 'Article',
                'operations'   => ['list'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'filters' => [
                'related_items_inline.name' => ExactFilter::class,
                'related_items_fmf_a.name'  => ExactFilter::class,  // foreign_match_fields: field_to_match=type_a
                'related_items_ftf.name'    => ExactFilter::class,  // foreign_table_field: parent_tablename
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    public function testInlineHopMatchesByChildColumn(): void
    {
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['related_items_inline.name' => 'InlinePlain']]),
        );

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(2300, $body['hydra:member'][0]['uid']);
    }

    public function testInlineHopReturnsEmptyForUnknownChild(): void
    {
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['related_items_inline.name' => 'Nope']]),
        );

        self::assertSame(0, $body['hydra:totalItems']);
    }

    public function testInlineHopExcludesDeletedChild(): void
    {
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['related_items_inline.name' => 'InlineDeleted']]),
        );

        self::assertSame(0, $body['hydra:totalItems']);
    }

    public function testForeignMatchFieldsDiscriminatorIsApplied(): void
    {
        // related_items_fmf_a only matches children with field_to_match=type_a.
        $match = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['related_items_fmf_a.name' => 'InlineTypeA']]),
        );
        self::assertSame(1, $match['hydra:totalItems']);
        self::assertSame(2300, $match['hydra:member'][0]['uid']);

        // A type_b child must not match the type_a filter.
        $noMatch = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['related_items_fmf_a.name' => 'InlineTypeB']]),
        );
        self::assertSame(0, $noMatch['hydra:totalItems']);
    }

    public function testForeignTableFieldDiscriminatorIsApplied(): void
    {
        // related_items_ftf only matches children whose parent_tablename is the article table.
        $match = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['related_items_ftf.name' => 'InlineFromArticle']]),
        );
        self::assertSame(1, $match['hydra:totalItems']);
        self::assertSame(2300, $match['hydra:member'][0]['uid']);

        // A child pointing at a different parent table (impostor) must not match.
        $noMatch = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['related_items_ftf.name' => 'InlineFromColor']]),
        );
        self::assertSame(0, $noMatch['hydra:totalItems']);
    }
}
