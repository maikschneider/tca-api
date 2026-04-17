<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for type=group relation write via POST / PATCH.
 *
 * related_colors is type=group with allowed='tx_myext_domain_model_color' (single table).
 * related_items  is type=group with allowed='tx_myext_domain_model_article,tx_myext_domain_model_color' (multi-table).
 *
 * For single-table group fields the resolver creates new child records immediately
 * and places the real UIDs in the parent's field value.
 * For multi-table group fields effectiveForeignTable() returns '' so new objects
 * are not created — the raw value is passed through unchanged.
 *
 * Fixtures:
 *   Article 200 → related_colors="1,2"
 *   Article 202 → related_colors=""
 *   Colors: 1=Red, 2=Blue
 */
final class WriteGroupRelationsTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';
    private const COLOR_TABLE   = 'tx_myext_domain_model_color';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_group.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->registerResources();
    }

    private function registerResources(): void
    {
        ApiRegistry::register('grp-write-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'grp-write-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show', 'create'],
                'itemsPerPage' => 20,
            ],
            'columns' => ['name' => ['groups' => ['list', 'show', 'create']]],
            'security' => [
                'list'   => AccessRole::PUBLIC,
                'show'   => AccessRole::PUBLIC,
                'create' => AccessRole::PUBLIC,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        ApiRegistry::register('grp-write-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'grp-write-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show', 'create', 'update'],
                'defaultPid'   => 1,
                'itemsPerPage' => 20,
            ],
            'columns' => [
                'title'          => ['groups' => ['list', 'show', 'create', 'update'], 'required' => true],
                'related_colors' => ['groups' => ['list', 'show', 'create', 'update'], 'embed' => true],
                'related_items'  => ['groups' => ['list', 'show', 'create', 'update']],
            ],
            'security' => [
                'list'   => AccessRole::PUBLIC,
                'show'   => AccessRole::PUBLIC,
                'create' => AccessRole::FE_USER,
                'update' => AccessRole::FE_USER,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── POST: single-table group with new object ──────────────────────────────

    public function testPostWithNewColorViaGroupFieldCreatesColorAndLinks(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/grp-write-articles', 1, [
            'title'          => 'Group New Color Article',
            'related_colors' => [['name' => 'GroupNew']],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('related_colors', $body);
        self::assertCount(1, $body['related_colors']);
        self::assertGreaterThan(2, $body['related_colors'][0]['uid'], 'New color UID should be > 2 (fixtures have 1,2)');
    }

    public function testPostWithNewColorViaGroupFieldColorPersistedInDatabase(): void
    {
        $response   = $this->executeApiWriteRequestAs('POST', '/_api/grp-write-articles', 1, [
            'title'          => 'Persist Group Color',
            'related_colors' => [['name' => 'PersistGroup']],
        ]);
        $articleUid = $this->decodeResponseBody($response)['uid'];

        $getBody = $this->decodeResponseBody($this->executeApiRequest('/_api/grp-write-articles/' . $articleUid));

        self::assertCount(1, $getBody['related_colors']);
        self::assertSame('Color', $getBody['related_colors'][0]['@type']);
        self::assertSame('PersistGroup', $getBody['related_colors'][0]['name']);
    }

    // ── POST: single-table group with mixed UIDs and new objects ──────────────

    public function testPostWithMixedGroupFieldLinksNewAndExisting(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/grp-write-articles', 1, [
            'title'          => 'Mixed Group Article',
            'related_colors' => [1, ['name' => 'MixedGroupNew']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(2, $body['related_colors']);

        $uids = array_column($body['related_colors'], 'uid');
        self::assertContains(1, $uids, 'Existing color uid=1 should be linked');
        foreach ($uids as $uid) {
            if ($uid !== 1) {
                self::assertGreaterThan(2, $uid, 'New color UID should be > 2');
            }
        }
    }

    // ── POST: single-table group with existing UIDs only ─────────────────────

    public function testPostWithExistingUidsOnlyViaGroupField(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/grp-write-articles', 1, [
            'title'          => 'Existing UIDs Group',
            'related_colors' => [1, 2],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(2, $body['related_colors']);

        $uids = array_column($body['related_colors'], 'uid');
        self::assertContains(1, $uids);
        self::assertContains(2, $uids);
    }

    // ── PATCH: single-table group append new object ───────────────────────────

    public function testPatchWithNewColorViaGroupFieldCreatesAndLinksColor(): void
    {
        // Article 202 has related_colors="" → patch adds one new color
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/grp-write-articles/202', 1, [
            'related_colors' => [['name' => 'PatchGroupNew']],
        ]);

        self::assertSame(200, $response->getStatusCode());

        $getBody = $this->decodeResponseBody($this->executeApiRequest('/_api/grp-write-articles/202'));

        self::assertCount(1, $getBody['related_colors']);
        self::assertGreaterThan(2, $getBody['related_colors'][0]['uid']);
    }

    public function testPatchWithMixedGroupFieldLinksNewAndExisting(): void
    {
        // Article 202 has related_colors="" → patch with uid=1 and one new color
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/grp-write-articles/202', 1, [
            'related_colors' => [2, ['name' => 'PatchMixed']],
        ]);

        self::assertSame(200, $response->getStatusCode());

        $getBody = $this->decodeResponseBody($this->executeApiRequest('/_api/grp-write-articles/202'));

        self::assertCount(2, $getBody['related_colors']);
        $uids = array_column($getBody['related_colors'], 'uid');
        self::assertContains(2, $uids);
    }

    // ── Unregistered foreign table: new objects silently skipped ─────────────

    public function testMultiTableGroupNewObjectsAreSkippedSinceEffectiveForeignTableIsEmpty(): void
    {
        // related_items is multi-table group → effectiveForeignTable() returns ''
        // so the value is passed through unchanged (assoc arrays are not created).
        // We pass existing UIDs using the group format (table_uid prefix).
        $response = $this->executeApiWriteRequestAs('POST', '/_api/grp-write-articles', 1, [
            'title'         => 'Multi-table Group Article',
            'related_items' => ['tx_myext_domain_model_color_1'],
        ]);

        // Request should succeed (201) even though no new objects are created
        self::assertSame(201, $response->getStatusCode());
    }
}
