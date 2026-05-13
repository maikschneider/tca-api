<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\OpenApi\OpenApiSchemasBuilder;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenApiSchemasBuilderTest extends TestCase
{
    private OpenApiSchemasBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new OpenApiSchemasBuilder();

        // Reset TcaColumnDiscovery static cache
        $reflection = new \ReflectionClass(TcaColumnDiscovery::class);
        $prop = $reflection->getProperty('columnNameCache');
        $prop->setValue(null, []);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['TCA']['test_table']);

        $reflection = new \ReflectionClass(TcaColumnDiscovery::class);
        $prop = $reflection->getProperty('columnNameCache');
        $prop->setValue(null, []);
    }

    // ── Shared component schemas ───────────────────────────────────────────────

    #[Test]
    public function buildAlwaysIncludesRelationStubSchema(): void
    {
        $schemas = $this->builder->build([]);
        self::assertArrayHasKey('RelationStub', $schemas);
        self::assertSame('object', $schemas['RelationStub']['type']);
        self::assertArrayHasKey('@id', $schemas['RelationStub']['properties']);
        self::assertArrayHasKey('@type', $schemas['RelationStub']['properties']);
        self::assertArrayHasKey('uid', $schemas['RelationStub']['properties']);
        self::assertSame('integer', $schemas['RelationStub']['properties']['uid']['type']);
        self::assertContains('@id', $schemas['RelationStub']['required']);
        self::assertContains('uid', $schemas['RelationStub']['required']);
    }

    #[Test]
    public function buildAlwaysIncludesFileObjectSchema(): void
    {
        $schemas = $this->builder->build([]);
        self::assertArrayHasKey('FileObject', $schemas);
        self::assertSame('object', $schemas['FileObject']['type']);
        $props = $schemas['FileObject']['properties'];
        self::assertArrayHasKey('publicUrl', $props);
        self::assertArrayHasKey('mimeType', $props);
        self::assertArrayHasKey('fileSize', $props);
        self::assertArrayHasKey('metadata', $props);
        self::assertSame('integer', $props['fileSize']['type']);
    }

    // ── HasOne relation schema ─────────────────────────────────────────────────

    #[Test]
    public function hasOneWithoutEmbedProducesRelationStubRef(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'author_id' => [
                    'config' => [
                        'type' => 'select',
                        'renderType' => 'selectSingle',
                        'foreign_table' => 'fe_users',
                    ],
                ],
            ],
        ];

        $config = $this->makeConfig(['author_id' => ['groups' => ['list', 'show']]]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        // Property name stripped of _id suffix
        self::assertArrayHasKey('author', $readProps);
        self::assertArrayNotHasKey('author_id', $readProps);

        // Nullable stub
        $schema = $readProps['author'];
        self::assertArrayHasKey('oneOf', $schema);
        $refs = array_column($schema['oneOf'], '$ref');
        self::assertContains('#/components/schemas/RelationStub', $refs);
        $nullTypes = array_column($schema['oneOf'], 'type');
        self::assertContains('null', $nullTypes);
    }

    #[Test]
    public function hasOneWithEmbedAndResourceTypeProducesResourceRef(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'author_id' => [
                    'config' => [
                        'type' => 'select',
                        'renderType' => 'selectSingle',
                        'foreign_table' => 'fe_users',
                    ],
                ],
            ],
        ];

        $config = $this->makeConfig([
            'author_id' => [
                'groups' => ['list', 'show'],
                'embed' => true,
                'resourceType' => 'Author',
            ],
        ]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('author', $readProps);
        $schema = $readProps['author'];
        self::assertArrayHasKey('oneOf', $schema);
        $refs = array_column($schema['oneOf'], '$ref');
        self::assertContains('#/components/schemas/AuthorRead', $refs);
    }

    #[Test]
    public function hasOneColumnNotEndingWithIdKeepsOriginalName(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'parent' => [
                    'config' => [
                        'type' => 'select',
                        'renderType' => 'selectSingle',
                        'foreign_table' => 'test_table',
                    ],
                ],
            ],
        ];

        $config = $this->makeConfig(['parent' => ['groups' => ['list', 'show']]]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('parent', $readProps);
        self::assertArrayHasKey('oneOf', $readProps['parent']);
    }

    // ── HasMany relation schema ────────────────────────────────────────────────

    #[Test]
    public function categoryFieldProducesArrayOfRelationStubs(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'categories' => ['config' => ['type' => 'category']],
            ],
        ];

        $config = $this->makeConfig(['categories' => ['groups' => ['list', 'show']]]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('categories', $readProps);
        $schema = $readProps['categories'];
        self::assertSame('array', $schema['type']);
        self::assertSame('#/components/schemas/RelationStub', $schema['items']['$ref']);
    }

    #[Test]
    public function inlineFieldProducesArraySchema(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'children' => [
                    'config' => [
                        'type' => 'inline',
                        'foreign_table' => 'child_table',
                        'foreign_field' => 'parent_id',
                    ],
                ],
            ],
        ];

        $config = $this->makeConfig(['children' => ['groups' => ['list', 'show']]]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('children', $readProps);
        self::assertSame('array', $readProps['children']['type']);
        self::assertSame('#/components/schemas/RelationStub', $readProps['children']['items']['$ref']);
    }

    #[Test]
    public function hasManyWithEmbedAndResourceTypeProducesResourceRefArray(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'tags' => [
                    'config' => [
                        'type' => 'inline',
                        'foreign_table' => 'tx_tags',
                        'foreign_field' => 'parent_id',
                    ],
                ],
            ],
        ];

        $config = $this->makeConfig([
            'tags' => [
                'groups' => ['list', 'show'],
                'embed' => true,
                'resourceType' => 'Tag',
            ],
        ]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertSame('array', $readProps['tags']['type']);
        self::assertSame('#/components/schemas/TagRead', $readProps['tags']['items']['$ref']);
    }

    // ── File field schema ──────────────────────────────────────────────────────

    #[Test]
    public function singleFileColumnProducesNullableFileObjectSchema(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'photo' => [
                    'config' => [
                        'type' => 'file',
                        'maxitems' => 1,
                    ],
                ],
            ],
        ];

        $config = $this->makeConfig(['photo' => ['groups' => ['list', 'show']]]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('photo', $readProps);
        $schema = $readProps['photo'];
        self::assertArrayHasKey('oneOf', $schema);
        $refs = array_column($schema['oneOf'], '$ref');
        self::assertContains('#/components/schemas/FileObject', $refs);
        $nullTypes = array_column($schema['oneOf'], 'type');
        self::assertContains('null', $nullTypes);
    }

    #[Test]
    public function multiFileColumnProducesArrayOfFileObjects(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'attachments' => ['config' => ['type' => 'file']],
            ],
        ];

        $config = $this->makeConfig(['attachments' => ['groups' => ['list', 'show']]]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('attachments', $readProps);
        self::assertSame('array', $readProps['attachments']['type']);
        self::assertSame('#/components/schemas/FileObject', $readProps['attachments']['items']['$ref']);
    }

    // ── FlexForm / JSON schema ─────────────────────────────────────────────────

    #[Test]
    public function flexFormColumnProducesObjectSchema(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'pi_flexform' => ['config' => ['type' => 'flex', 'ds' => []]],
            ],
        ];

        $config = $this->makeConfig(['pi_flexform' => ['groups' => ['list', 'show']]]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('pi_flexform', $readProps);
        self::assertSame('object', $readProps['pi_flexform']['type']);
    }

    #[Test]
    public function jsonFieldProducesObjectSchema(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'meta' => ['config' => ['type' => 'json']],
            ],
        ];

        $config = $this->makeConfig(['meta' => ['groups' => ['list', 'show']]]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('meta', $readProps);
        self::assertSame('object', $readProps['meta']['type']);
    }

    // ── Virtual properties ─────────────────────────────────────────────────────

    #[Test]
    public function virtualPropertiesAppearInReadSchema(): void
    {
        $GLOBALS['TCA']['test_table'] = ['ctrl' => [], 'columns' => []];

        $config = ApiDefinition::fromArray([
            'general' => ['table' => 'test_table', 'resourceName' => 'tests', 'resourceType' => 'Test'],
            'columns' => [],
            'virtualProperties' => [
                'displayName' => [
                    'groups' => ['list', 'show'],
                    'type' => 'string',
                    'callback' => ['SomeClass', 'someMethod'],
                ],
            ],
        ]);

        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('displayName', $readProps);
        self::assertSame('string', $readProps['displayName']['type']);
    }

    #[Test]
    public function virtualPropertiesNotReadableAreExcludedInExplicitMode(): void
    {
        $GLOBALS['TCA']['test_table'] = ['ctrl' => [], 'columns' => []];

        $config = ApiDefinition::fromArray([
            'general' => ['table' => 'test_table', 'resourceName' => 'tests', 'resourceType' => 'Test'],
            'columns' => [
                // At least one column with groups triggers explicit mode
                'title' => ['groups' => ['list', 'show'], 'type' => 'string'],
            ],
            'virtualProperties' => [
                'writeOnlyProp' => [
                    'groups' => ['create', 'update'],
                    'type' => 'string',
                    'callback' => ['SomeClass', 'someMethod'],
                ],
            ],
        ]);

        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayNotHasKey('writeOnlyProp', $readProps);
    }

    // ── Scalar types remain unchanged ──────────────────────────────────────────

    #[Test]
    public function scalarTypeColumnProducesCorrectJsonType(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'count' => ['config' => ['type' => 'number']],
                'flag'  => ['config' => ['type' => 'check']],
                'title' => ['config' => ['type' => 'input']],
            ],
        ];

        $config = $this->makeConfig([
            'count' => ['groups' => ['list', 'show'], 'type' => 'integer'],
            'flag'  => ['groups' => ['list', 'show'], 'type' => 'boolean'],
            'title' => ['groups' => ['list', 'show'], 'type' => 'string'],
        ]);

        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertSame('integer', $readProps['count']['type']);
        self::assertSame('boolean', $readProps['flag']['type']);
        self::assertSame('string', $readProps['title']['type']);
    }

    #[Test]
    public function writeSchemaIsNotAffectedByRelationChanges(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'author_id' => [
                    'config' => [
                        'type' => 'select',
                        'renderType' => 'selectSingle',
                        'foreign_table' => 'fe_users',
                    ],
                ],
                'title' => ['config' => ['type' => 'input']],
            ],
        ];

        $config = ApiDefinition::fromArray([
            'general' => [
                'table'        => 'test_table',
                'resourceName' => 'tests',
                'resourceType' => 'Test',
                'operations'   => ['list', 'show', 'create', 'update'],
            ],
            'columns' => [
                'author_id' => ['groups' => ['list', 'show', 'create', 'update']],
                'title'     => ['groups' => ['list', 'show', 'create', 'update'], 'type' => 'string'],
            ],
        ]);
        $schemas = $this->builder->build([$config]);

        // Write schema keeps original column name (FK field)
        self::assertArrayHasKey('author_id', $schemas['TestWrite']['properties']);
        // Write schema uses scalar type mapping (not relation schema)
        self::assertSame('string', $schemas['TestWrite']['properties']['author_id']['type']);
    }

    // ── Non-_id columns that are not hasOne keep their name ───────────────────

    #[Test]
    public function nonRelationalColumnEndingWithIdKeepsItsName(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'fe_user_id' => ['config' => ['type' => 'number']],
            ],
        ];

        $config = $this->makeConfig(['fe_user_id' => ['groups' => ['list', 'show'], 'type' => 'integer']]);
        $schemas = $this->builder->build([$config]);
        $readProps = $schemas['TestRead']['properties'];

        // fe_user_id is type=number (not a select with foreign_table) → name unchanged
        self::assertArrayHasKey('fe_user_id', $readProps);
        self::assertArrayNotHasKey('fe_user', $readProps);
        self::assertSame('integer', $readProps['fe_user_id']['type']);
    }

    // ── Helper ─────────────────────────────────────────────────────────────────

    private function makeConfig(array $columns): ApiDefinition
    {
        return ApiDefinition::fromArray([
            'general' => [
                'table'        => 'test_table',
                'resourceName' => 'tests',
                'resourceType' => 'Test',
            ],
            'columns' => $columns,
        ]);
    }
}
