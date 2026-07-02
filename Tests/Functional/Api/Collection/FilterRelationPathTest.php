<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Collection;

use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for relation-path filters (dotted filter keys) over select/FK hops.
 *
 * A dotted key filters the resource by a column reached through one or more relation
 * hops. The declared filter (ExactFilter here) is the leaf comparison; RelationPathFilter
 * resolves each hop from TCA and builds the nested subqueries.
 *
 * Fixture (articles_relpath.csv / colors_relpath.csv):
 *   2200 "Parent Red"    color_id=1 (Red)   parent_id=0
 *   2201 "Child of Red"  color_id=0         parent_id=2200
 *   2202 "Parent Blue"   color_id=2 (Blue)  parent_id=0
 *   2203 "Child of Blue" color_id=0         parent_id=2202
 *   2204 "Ghost-colored" color_id=990 (GhostColor, deleted) parent_id=0
 */
final class FilterRelationPathTest extends ApiFunctionalTestCase
{
    private const RESOURCE = 'articles-relpath';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_relpath.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_relpath.csv');

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
                'color_id.name'           => ExactFilter::class,
                'parent_id.color_id.name' => ExactFilter::class,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    public function testSingleFkHopFiltersByRelatedColumn(): void
    {
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['color_id.name' => 'Red']]),
        );

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(2200, $body['hydra:member'][0]['uid']);
    }

    public function testTwoFkHopsFilterByRelationOfRelatedRecord(): void
    {
        // Articles whose parent's colour is Red → only 2201 (parent 2200 is Red).
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['parent_id.color_id.name' => 'Red']]),
        );

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(2201, $body['hydra:member'][0]['uid']);
    }

    public function testTwoFkHopsBlueBranch(): void
    {
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['parent_id.color_id.name' => 'Blue']]),
        );

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(2203, $body['hydra:member'][0]['uid']);
    }

    public function testDeletedRelatedRecordDoesNotLeak(): void
    {
        // 2204 points at colour 990 (GhostColor) which is soft-deleted → no match.
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['color_id.name' => 'GhostColor']]),
        );

        self::assertSame(0, $body['hydra:totalItems']);
    }

    public function testNoMatchReturnsEmpty(): void
    {
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . self::RESOURCE, ['filters' => ['color_id.name' => 'Nonexistent']]),
        );

        self::assertSame(0, $body['hydra:totalItems']);
    }
}
