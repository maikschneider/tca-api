<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for inline (foreign_field) relation write via POST / PATCH.
 *
 * related_items_inline is type=inline with foreign_field='foreign_article_id'
 * and foreign_table='tx_myext_domain_model_color'.
 *
 * Child records carry a back-pointer (foreign_article_id) to the parent article.
 * The resolver defers creation of new inline objects until after the parent UID
 * is known, then injects foreign_field = parentUid into each child.
 *
 * Fixtures:
 *   Article 300 → 2 inline colors: InlineRed (10), InlineBlue (11)
 *   Article 302 → 0 inline colors
 */
final class WriteInlineRelationsTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';
    private const COLOR_TABLE   = 'tx_myext_domain_model_color';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_inline.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_inline.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->registerResources();
    }

    private function registerResources(bool $colorWithOwnership = false): void
    {
        $colorConfig = [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'inline-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show', 'create'],
                'storagePid'   => 1,
            ],
            'columns' => ['name' => ['groups' => ['list', 'show', 'create']]],
            'security' => [
                'create' => AccessRole::FE_USER,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ];

        if ($colorWithOwnership) {
            $colorConfig['ownership'] = ['column' => 'foreign_article_id'];
        }

        $this->registerResource('inline-colors', $colorConfig);

        $this->registerResource('inline-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'inline-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show', 'create', 'update'],
                'storagePid'   => 1,
            ],
            'columns' => [
                'title'                => ['groups' => ['list', 'show', 'create', 'update'], 'required' => true],
                'related_items_inline' => ['groups' => ['list', 'show', 'create', 'update'], 'resourceName' => 'inline-colors'],
            ],
            'security' => [
                'create' => AccessRole::FE_USER,
                'update' => AccessRole::FE_USER,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── POST: create parent with inline child objects ─────────────────────────

    public function testPostWithInlineObjectCreatesParentAndChild(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/inline-articles', 1, [
            'title'                => 'New With Inline',
            'related_items_inline' => [['name' => 'InlineNew']],
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testPostInlineChildHasBackPointerToParent(): void
    {
        $response   = $this->executeApiWriteRequestAs('POST', '/_api/inline-articles', 1, [
            'title'                => 'Back Pointer Article',
            'related_items_inline' => [['name' => 'BackChild']],
        ]);
        $parentUid = $this->decodeResponseBody($response)['uid'];

        // Fetch the parent and check it exposes the inline child
        $getBody = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/inline-articles/' . $parentUid),
        );

        self::assertCount(1, $getBody['related_items_inline']);
    }

    public function testPostInlineChildHasCorrectPid(): void
    {
        $response  = $this->executeApiWriteRequestAs('POST', '/_api/inline-articles', 1, [
            'title'                => 'Pid Check Article',
            'related_items_inline' => [['name' => 'PidChild']],
        ]);
        $parentUid = $this->decodeResponseBody($response)['uid'];

        // Verify inline child exists with pid=1 (defaultPid from config)
        $getBody = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/inline-articles/' . $parentUid),
        );
        $childUid = (int)basename($getBody['related_items_inline'][0]);

        // Load color directly to check pid
        $colorRow = $this->getConnectionPool()
            ->getConnectionForTable(self::COLOR_TABLE)
            ->select(['pid'], self::COLOR_TABLE, ['uid' => $childUid])
            ->fetchAssociative() ?: [];

        self::assertSame(1, (int)$colorRow['pid']);
    }

    public function testPostWithMultipleInlineObjectsCreatesAllChildren(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/inline-articles', 1, [
            'title'                => 'Multi Inline Article',
            'related_items_inline' => [['name' => 'ChildA'], ['name' => 'ChildB']],
        ]);
        $parentUid = $this->decodeResponseBody($response)['uid'];

        $getBody = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/inline-articles/' . $parentUid),
        );

        self::assertCount(2, $getBody['related_items_inline']);
    }

    // ── Security enforcement: child security['create'] is checked ────────────

    public function testPostWithInlineChildForbiddenBySecurityReturns422(): void
    {
        $this->rerouteInlineColorsFirst([
            'security' => [
                'list'   => AccessRole::PUBLIC,
                'show'   => AccessRole::PUBLIC,
                'create' => AccessRole::BE_ADMIN,
            ],
        ]);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/inline-articles', 1, [
            'title'                => 'Security Blocked Inline',
            'related_items_inline' => [['name' => 'ForbiddenChild']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        $codes = array_column($body['violations'], 'code');
        self::assertContains('CHILD_FORBIDDEN', $codes);
    }

    public function testPatchWithInlineChildForbiddenBySecurityReturns422(): void
    {
        $this->rerouteInlineColorsFirst([
            'security' => [
                'list'   => AccessRole::PUBLIC,
                'show'   => AccessRole::PUBLIC,
                'create' => AccessRole::BE_ADMIN,
            ],
        ]);

        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/inline-articles/302', 1, [
            'related_items_inline' => [['name' => 'ForbiddenPatch']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        $codes = array_column($this->decodeResponseBody($response)['violations'], 'code');
        self::assertContains('CHILD_FORBIDDEN', $codes);
    }

    // ── Validation enforcement: child required fields are checked ─────────────

    public function testPostWithInlineChildMissingRequiredFieldReturns422(): void
    {
        $this->rerouteInlineColorsFirst([
            'columns' => [
                'name' => ['groups' => ['list', 'show', 'create'], 'required' => true],
            ],
        ]);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/inline-articles', 1, [
            'title'                => 'Validation Inline Article',
            'related_items_inline' => [['name' => '']],  // empty required 'name' field
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertNotEmpty($body['violations']);
        $codes = array_column($body['violations'], 'code');
        self::assertContains('REQUIRED', $codes);
        // propertyPath should be prefixed with the column name
        $paths = array_column($body['violations'], 'propertyPath');
        foreach ($paths as $path) {
            self::assertStringStartsWith('related_items_inline.', $path);
        }
    }

    /**
     * Re-register 'inline-colors' with overrides as the FIRST registry entry for
     * tx_myext_domain_model_color, ensuring ApiRegistry::getByTable() returns it
     * before the file-based 'colors' resource.
     *
     * Without this, Bootstrap::init() runs ApiDefinitionLoader::load() in the sub-request
     * which re-registers 'colors' (file-based), and since 'colors' was registered before
     * 'inline-colors', getByTable() returns the file-based config instead of the test one.
     */
    private function rerouteInlineColorsFirst(array $overrides): void
    {
        $baseConfig = [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'inline-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show', 'create'],
            ],
            'columns' => ['name' => ['groups' => ['list', 'show', 'create']]],
            'security' => [
                'create' => AccessRole::FE_USER,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ];

        $registry = $this->getApiRegistry();
        $snapshot = $registry->getAll();
        unset($snapshot['inline-colors']);
        // Place inline-colors FIRST so getByTable() returns it before 'colors'
        $registry->replaceAll(
            ['inline-colors' => ApiDefinition::fromArray(array_replace_recursive($baseConfig, $overrides))] + $snapshot,
        );
    }

    // ── PATCH: append inline children to existing parent ─────────────────────

    public function testPatchWithInlineObjectAppendsChildToExistingParent(): void
    {
        // Article 302 has 0 inline colors
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/inline-articles/302', 1, [
            'related_items_inline' => [['name' => 'Appended']],
        ]);

        self::assertSame(200, $response->getStatusCode());

        $getBody = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/inline-articles/302'),
        );

        self::assertCount(1, $getBody['related_items_inline']);
    }

    public function testPatchWithInlineObjectDoesNotRemoveExistingChildren(): void
    {
        // Article 300 has 2 inline colors → patch appends 1 more
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/inline-articles/300', 1, [
            'related_items_inline' => [['name' => 'ThirdChild']],
        ]);

        self::assertSame(200, $response->getStatusCode());

        $getBody = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/inline-articles/300'),
        );

        self::assertCount(3, $getBody['related_items_inline']);
    }
}
