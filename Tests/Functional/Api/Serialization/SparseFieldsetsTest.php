<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for sparse fieldsets via ?fields[]=column query parameter.
 *
 * RED phase: ResourceSerializer ignores the fields parameter and always serializes
 * all readable columns. The dispatcher/handlers do not extract or forward fields.
 *
 * Target behaviour:
 *   - ?fields[]=title        → only title (plus @type, @id, uid invariants)
 *   - ?fields[]=color_id     → only the color relation (serialized as 'color')
 *   - ?fields[]=title&fields[]=color_id → title + color relation
 *   - ?fields[]=nonexistent  → silently ignored; only invariants returned
 *   - No fields param        → all readable columns (default unchanged)
 *   - Collection endpoint    → fields applied to each hydra:member
 *
 * The fields parameter uses API column names (as declared in config['columns']),
 * not the serialized output key. Example: 'color_id' → serialized as 'color'.
 *
 * Fixture baseline:
 *   Article 1 → title=First Article, color_id=1 (Red), categories=[1,2]
 *   Article 2 → title=Second Article, color_id=2 (Blue), categories=[3]
 *   Article 3 → title=Third Article, color_id=0 (none), categories=[]
 */
final class SparseFieldsetsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');
    }

    // ── Baseline: no fields param returns all readable columns ────────────────

    public function testNoFieldsParamReturnsAllReadableColumns(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('title', $body);
        self::assertArrayHasKey('color_id', $body);
        self::assertArrayHasKey('categories', $body);
    }

    public function testCollectionNoFieldsParamReturnsAllReadableColumns(): void
    {
        $response = $this->executeApiRequest('/_api/articles');
        $body = $this->decodeResponseBody($response);

        $member = $body['hydra:member'][0];
        self::assertArrayHasKey('title', $member);
        self::assertArrayHasKey('color_id', $member);
        self::assertArrayHasKey('categories', $member);
    }

    // ── Single field restricts output ─────────────────────────────────────────

    public function testSingleFieldRestrictsColumnsButKeepsInvariants(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1', ['fields' => ['title']]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('title', $body);
        self::assertArrayNotHasKey('color', $body);
        self::assertArrayNotHasKey('categories', $body);
        self::assertArrayHasKey('@type', $body);
        self::assertSame('Article', $body['@type']);
        self::assertArrayHasKey('@id', $body);
        self::assertSame('/_api/articles/1', $body['@id']);
        self::assertArrayHasKey('uid', $body);
        self::assertSame(1, $body['uid']);
    }

    // ── Relation fields included when requested ───────────────────────────────

    public function testHasOneRelationFieldIncludedWhenRequested(): void
    {
        // 'color_id' is the config column; serialized as 'color' (strip _id suffix)
        $response = $this->executeApiRequest('/_api/articles/1', ['fields' => ['color_id']]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('color_id', $body);
        self::assertArrayNotHasKey('title', $body);
        self::assertArrayNotHasKey('categories', $body);
    }

    public function testManyToManyRelationFieldIncludedWhenRequested(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1', ['fields' => ['categories']]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('categories', $body);
        self::assertIsArray($body['categories']);
        self::assertArrayNotHasKey('title', $body);
        self::assertArrayNotHasKey('color', $body);
    }

    // ── Unknown field silently ignored ────────────────────────────────────────

    public function testUnknownFieldSilentlyIgnored(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1', ['fields' => ['nonexistent']]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayNotHasKey('title', $body);
        self::assertArrayNotHasKey('color', $body);
        self::assertArrayNotHasKey('nonexistent', $body);
        // Invariants still present
        self::assertArrayHasKey('@type', $body);
        self::assertArrayHasKey('@id', $body);
        self::assertArrayHasKey('uid', $body);
    }

    // ── Multiple fields both present ──────────────────────────────────────────

    public function testMultipleFieldsIncludedWhenRequested(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1', ['fields' => ['title', 'color_id']]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('title', $body);
        self::assertArrayHasKey('color_id', $body);
        self::assertArrayNotHasKey('categories', $body);
    }

    // ── Collection applies fields to each member ──────────────────────────────

    public function testCollectionFieldsAppliedToEachMember(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['fields' => ['title']]);
        $body = $this->decodeResponseBody($response);

        foreach ($body['hydra:member'] as $member) {
            self::assertArrayHasKey('title', $member);
            self::assertArrayNotHasKey('color', $member);
            self::assertArrayNotHasKey('categories', $member);
        }
    }

    // ── Non-API column not exposed even when requested ────────────────────────

    public function testColumnNotInApiConfigNotExposedWhenRequested(): void
    {
        // 'pid' is a valid TCA field but not declared in Articles.php columns config.
        // Requesting it via fields[] must not expose it.
        $response = $this->executeApiRequest('/_api/articles/1', ['fields' => ['pid']]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayNotHasKey('pid', $body);
    }
}
