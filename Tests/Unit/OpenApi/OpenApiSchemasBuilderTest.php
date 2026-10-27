<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\OpenApi\OpenApiSchemasBuilder;
use MaikSchneider\TcaApi\Serializer\Processing\RouteEnhancerProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    public function fileObjectDescribesTheImageProcessorFields(): void
    {
        $props = $this->builder->build([])['FileObject']['properties'];

        self::assertSame([['type' => 'string'], ['type' => 'null']], $props['publicUrl']['oneOf']);
        self::assertSame(['title', 'alternative', 'description', 'copyright'], array_keys($props['metadata']['properties']));
        self::assertSame('integer', $props['width']['type']);
        self::assertSame('integer', $props['height']['type']);
        self::assertSame(
            ['publicUrl', 'width', 'height'],
            array_keys($props['cropVariants']['additionalProperties']['properties']),
        );
    }

    // ── HasOne relation schema ─────────────────────────────────────────────────

    #[Test]
    public function hasOneWithoutEmbedProducesIriReferenceString(): void
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
        $schemas = $this->builder->build([$config, $this->makeTarget('fe_users', 'users', ['list', 'show'])]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('author_id', $readProps);

        // Nullable IRI string (no stub object)
        $schema = $readProps['author_id'];
        self::assertArrayHasKey('oneOf', $schema);
        $types = array_column($schema['oneOf'], 'type');
        self::assertContains('string', $types);
        self::assertContains('null', $types);
        $formats = array_column($schema['oneOf'], 'format');
        self::assertContains('iri-reference', $formats);
    }

    #[Test]
    public function relationIriNamesItsTargetResource(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'page' => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'foreign_table' => 'pages']],
            ],
        ];

        $schemas = $this->builder->build([
            $this->makeConfig(['page' => ['groups' => ['list', 'show']]]),
            self::makeTarget('pages', 'web-pages', ['list', 'show']),
        ]);
        $iri = $schemas['TestRead']['properties']['page']['oneOf'][0];

        self::assertSame('web-pages', $iri['x-resource']);
        self::assertSame('/web-pages/[0-9]+$', $iri['pattern']);
        self::assertSame(1, preg_match('#' . $iri['pattern'] . '#u', '/de/_api/web-pages/186'));
        self::assertSame(0, preg_match('#' . $iri['pattern'] . '#u', '/_api/articles/186'));
    }

    /** @return iterable<string, array{list<ApiDefinition>}> */
    public static function nonAddressableTargetProvider(): iterable
    {
        yield 'unregistered table' => [[]];
        yield 'resource without show' => [[self::makeTarget('fe_users', 'users', ['list'])]];
    }

    #[Test]
    #[DataProvider('nonAddressableTargetProvider')]
    public function hasOneWithoutEmbedToNonAddressableTargetProducesNullableInteger(array $targets): void
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
        $schemas = $this->builder->build([$config, ...$targets]);

        self::assertSame(
            ['oneOf' => [['type' => 'integer'], ['type' => 'null']]],
            $schemas['TestRead']['properties']['author_id'],
        );
    }

    #[Test]
    public function hasOneWithEmbedAndResourceTypeProducesEmbeddedObject(): void
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

        self::assertArrayHasKey('author_id', $readProps);
        $schema = $readProps['author_id'];
        self::assertArrayHasKey('oneOf', $schema);
        // An author can't embed the test record back, so it is never cut short to a reference.
        self::assertSame('object', $schema['oneOf'][0]['type']);
        self::assertSame('Author', $schema['oneOf'][0]['properties']['@type']['example']);
        self::assertSame(['type' => 'null'], $schema['oneOf'][1]);
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
    public function categoryFieldProducesArrayOfIriStrings(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'categories' => ['config' => ['type' => 'category']],
            ],
        ];

        $config = $this->makeConfig(['categories' => ['groups' => ['list', 'show']]]);
        $schemas = $this->builder->build([$config, $this->makeTarget('sys_category', 'categories', ['list', 'show'])]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('categories', $readProps);
        $schema = $readProps['categories'];
        self::assertSame('array', $schema['type']);
        self::assertSame('string', $schema['items']['type']);
        self::assertSame('iri-reference', $schema['items']['format']);
    }

    #[Test]
    public function inlineFieldProducesArrayOfIriStrings(): void
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
        $schemas = $this->builder->build([$config, $this->makeTarget('child_table', 'children', ['list', 'show'])]);
        $readProps = $schemas['TestRead']['properties'];

        self::assertArrayHasKey('children', $readProps);
        self::assertSame('array', $readProps['children']['type']);
        self::assertSame('string', $readProps['children']['items']['type']);
        self::assertSame('iri-reference', $readProps['children']['items']['format']);
    }

    #[Test]
    public function inlineFieldToUnregisteredTableProducesArrayOfIntegers(): void
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

        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'integer']],
            $schemas['TestRead']['properties']['children'],
        );
    }

    #[Test]
    public function polymorphicGroupProducesIriOrTypedNodeItems(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'related' => ['config' => ['type' => 'group', 'allowed' => 'pages,tt_content']],
            ],
        ];

        $config = $this->makeConfig(['related' => ['groups' => ['list', 'show']]]);
        $items = $this->builder->build([$config])['TestRead']['properties']['related']['items'];

        self::assertSame('iri-reference', $items['oneOf'][0]['format']);
        self::assertSame(['@type', 'uid'], $items['oneOf'][1]['required']);
    }

    #[Test]
    public function hasManyWithEmbedAndResourceTypeProducesEmbeddedObjectArray(): void
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
        self::assertSame('object', $readProps['tags']['items']['type']);
        self::assertSame('Tag', $readProps['tags']['items']['properties']['@type']['example']);
    }

    #[Test]
    public function embedThatCanHitACycleAllowsAReference(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'parent' => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'foreign_table' => 'test_table']],
                'children' => ['config' => ['type' => 'inline', 'foreign_table' => 'test_table', 'foreign_field' => 'parent']],
            ],
        ];

        $readProps = $this->builder->build([$this->makeConfig([
            'parent'   => ['groups' => ['list', 'show'], 'embed' => ['depth' => 2]],
            'children' => ['groups' => ['list', 'show'], 'embed' => true],
        ])])['TestRead']['properties'];

        // To-one: the root record isn't on the path yet, so its parent is always embedded …
        $parent = $readProps['parent']['oneOf'][0];
        self::assertSame('object', $parent['type']);
        // … but the grandparent may be the root record again, cut short to its IRI.
        $grandparent = $parent['properties']['parent']['oneOf'][0];
        self::assertSame('object', $grandparent['oneOf'][0]['type']);
        self::assertSame('iri-reference', $grandparent['oneOf'][1]['format']);

        // To-many: the record itself is on the path, so a child may be a reference.
        self::assertSame('object', $readProps['children']['items']['oneOf'][0]['type']);
        self::assertSame('iri-reference', $readProps['children']['items']['oneOf'][1]['format']);
    }

    #[Test]
    public function selectWithMaxitemsOneButNoRelationshipIsAList(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'color_id' => ['config' => ['type' => 'select', 'renderType' => 'selectMultipleSideBySide', 'foreign_table' => 'tx_color', 'maxitems' => 1]],
            ],
        ];

        $readProps = $this->builder->build([$this->makeConfig(['color_id' => ['groups' => ['list', 'show']]])])['TestRead']['properties'];

        self::assertSame('array', $readProps['color_id']['type']);
    }

    #[Test]
    public function categoryWithOneToOneRelationshipIsOneRecord(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'parent' => ['config' => ['type' => 'category', 'relationship' => 'oneToOne']],
            ],
        ];

        $readProps = $this->builder->build([$this->makeConfig(['parent' => ['groups' => ['list', 'show']]])])['TestRead']['properties'];

        self::assertArrayHasKey('oneOf', $readProps['parent']);
        self::assertContains(['type' => 'null'], $readProps['parent']['oneOf']);
    }

    #[Test]
    public function selectSingleWithMmIsAList(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => [],
            'columns' => [
                'color_id' => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'foreign_table' => 'tx_color', 'MM' => 'tx_test_color_mm']],
            ],
        ];

        $readProps = $this->builder->build([$this->makeConfig(['color_id' => ['groups' => ['list', 'show']]])])['TestRead']['properties'];

        self::assertSame('array', $readProps['color_id']['type']);
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
    public function flexFormColumnProducesNullableObjectSchema(): void
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
        self::assertSame([['type' => 'object'], ['type' => 'null']], $readProps['pi_flexform']['oneOf']);
    }

    #[Test]
    public function jsonFieldProducesNullableObjectOrArraySchema(): void
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
        self::assertEquals([['type' => 'object'], ['type' => 'array', 'items' => new \stdClass()], ['type' => 'null']], $readProps['meta']['oneOf']);
    }

    // ── Scalar columns without a declared type ─────────────────────────────────

    #[Test]
    public function untypedScalarColumnsAreDescribedAsTheyAreSent(): void
    {
        $GLOBALS['TCA']['test_table'] = ['ctrl' => [], 'columns' => [
            'istopnews' => ['config' => ['type' => 'check']],
            'count'     => ['config' => ['type' => 'number']],
            'price'     => ['config' => ['type' => 'number', 'format' => 'decimal', 'nullable' => true]],
            'event_at'  => ['config' => ['type' => 'datetime']],
            'website'   => ['config' => ['type' => 'link']],
            'bodytext'  => ['config' => ['type' => 'text']],
            'title'     => ['config' => ['type' => 'input']],
            'subtitle'  => ['config' => ['type' => 'input', 'nullable' => true]],
            'layout'    => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => [['label' => 'A', 'value' => 0], ['label' => 'B', 'value' => 1]]]],
            'ctype'     => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => [['label' => 'Text', 'value' => 'text']]]],
            'orient'    => ['config' => ['type' => 'radio', 'items' => [['label' => 'A', 'value' => 0], ['label' => 'B', 'value' => 8]]]],
            'crop'      => ['config' => ['type' => 'imageManipulation']],
        ]];

        $columns = array_fill_keys(array_keys($GLOBALS['TCA']['test_table']['columns']), ['groups' => ['list', 'show']]);
        $readProps = $this->builder->build([$this->makeConfig($columns)])['TestRead']['properties'];
        $nullable = static fn (array $schema): array => ['oneOf' => [$schema, ['type' => 'null']]];

        self::assertSame(['type' => 'integer'], $readProps['istopnews']);
        self::assertSame(['type' => 'integer'], $readProps['count']);
        self::assertSame($nullable(['type' => 'string']), $readProps['price']);
        self::assertSame($nullable(['type' => 'string', 'format' => 'date-time']), $readProps['event_at']);
        self::assertSame($nullable(['type' => 'string']), $readProps['website']);
        self::assertSame($nullable(['type' => 'string']), $readProps['bodytext']);
        self::assertSame(['type' => 'string'], $readProps['title']);
        self::assertSame($nullable(['type' => 'string']), $readProps['subtitle']);
        self::assertSame(['type' => 'integer'], $readProps['layout']);
        self::assertSame(['type' => 'string'], $readProps['ctype']);
        self::assertSame(['type' => 'integer'], $readProps['orient']);
        self::assertEquals(['oneOf' => [['type' => 'object'], ['type' => 'array', 'items' => new \stdClass()], ['type' => 'null']]], $readProps['crop']);
    }

    #[Test]
    public function declaredTypeAndProcessorKeepTheConfiguredSchema(): void
    {
        $GLOBALS['TCA']['test_table'] = ['ctrl' => [], 'columns' => [
            'istopnews' => ['config' => ['type' => 'check']],
            'bodytext'  => ['config' => ['type' => 'text']],
        ]];

        $readProps = $this->builder->build([$this->makeConfig([
            'istopnews' => ['groups' => ['list', 'show'], 'type' => 'boolean'],
            'bodytext'  => ['groups' => ['list', 'show'], 'processor' => 'SomeProcessor'],
        ])])['TestRead']['properties'];

        self::assertSame(['type' => 'boolean'], $readProps['istopnews']);
        self::assertSame(['type' => 'string'], $readProps['bodytext']);
    }

    #[Test]
    public function passthroughColumnIsUnconstrainedAndRouteVirtualPropertyANullableString(): void
    {
        $GLOBALS['TCA']['test_table'] = ['ctrl' => [], 'columns' => ['raw' => ['config' => ['type' => 'passthrough']]]];

        $config = ApiDefinition::fromArray([
            'general' => ['table' => 'test_table', 'resourceName' => 'tests', 'resourceType' => 'Test'],
            'columns' => ['raw' => ['groups' => ['list', 'show']]],
            'virtualProperties' => [
                'url' => ['groups' => ['list', 'show'], 'processor' => RouteEnhancerProcessor::class],
            ],
        ]);
        $readProps = $this->builder->build([$config])['TestRead']['properties'];

        self::assertArrayNotHasKey('type', $readProps['raw']);
        self::assertSame(['oneOf' => [['type' => 'string'], ['type' => 'null']]], $readProps['url']);
    }

    #[Test]
    public function typoLinkProcessorColumnIsANullableString(): void
    {
        $GLOBALS['TCA']['test_table'] = ['ctrl' => [], 'columns' => ['website' => ['config' => ['type' => 'input']]]];

        $readProps = $this->builder->build([$this->makeConfig([
            'website' => ['groups' => ['list', 'show'], 'processor' => TypoLinkProcessor::class],
        ])])['TestRead']['properties'];

        self::assertSame(['oneOf' => [['type' => 'string'], ['type' => 'null']]], $readProps['website']);
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
    public function virtualPropertyOverAFileColumnIsDescribedLikeTheColumn(): void
    {
        $GLOBALS['TCA']['test_table'] = ['ctrl' => [], 'columns' => [
            'media' => ['config' => ['type' => 'file']],
            'photo' => ['config' => ['type' => 'file', 'maxitems' => 1]],
        ]];

        $config = ApiDefinition::fromArray([
            'general' => ['table' => 'test_table', 'resourceName' => 'tests', 'resourceType' => 'Test'],
            'columns' => [],
            'virtualProperties' => [
                'teaserImage' => ['groups' => ['list', 'show'], 'column' => 'media', 'image' => ['cropVariant' => 'teaser']],
                'teaserPhoto' => ['groups' => ['list', 'show'], 'column' => 'photo'],
                'mediaCount'  => ['groups' => ['list', 'show'], 'column' => 'media', 'type' => 'integer', 'callback' => ['SomeClass', 'someMethod']],
            ],
        ]);

        $readProps = $this->builder->build([$config])['TestRead']['properties'];

        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileObject']], $readProps['teaserImage']);
        self::assertSame([['$ref' => '#/components/schemas/FileObject'], ['type' => 'null']], $readProps['teaserPhoto']['oneOf']);
        // A callback replaces the value, so its declared type wins.
        self::assertSame(['type' => 'integer'], $readProps['mediaCount']);
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

    // ── Collection schema ──────────────────────────────────────────────────────

    #[Test]
    public function collectionPagingLinksAreNullable(): void
    {
        $view = $this->builder->build([$this->makeConfig([])])['TestCollection']['properties']['hydra:view']['properties'];

        foreach (['hydra:previous', 'hydra:next'] as $link) {
            self::assertSame([['type' => 'string'], ['type' => 'null']], $view[$link]['oneOf'], $link);
        }
        self::assertSame('string', $view['hydra:first']['type']);
        self::assertSame('string', $view['hydra:last']['type']);
    }

    #[Test]
    public function collectionDescribesTheSearchTemplate(): void
    {
        $search = $this->builder->build([$this->makeConfig([])])['TestCollection']['properties']['hydra:search'];

        self::assertSame('object', $search['type']);
        self::assertSame(['@type', 'hydra:template', 'hydra:variableRepresentation', 'hydra:mapping'], array_keys($search['properties']));
        self::assertSame(
            ['@type', 'variable', 'property', 'required'],
            array_keys($search['properties']['hydra:mapping']['items']['properties']),
        );
    }

    // ── Schema names ───────────────────────────────────────────────────────────

    #[Test]
    public function resourcesSharingAResourceTypeGetSeparateSchemas(): void
    {
        $public = ApiDefinition::fromArray([
            'general' => ['table' => 'test_table', 'resourceName' => 'tests', 'resourceType' => 'Test', 'operations' => ['list', 'show', 'update']],
            'columns' => ['title' => ['groups' => ['list', 'show']]],
        ]);
        $internal = ApiDefinition::fromArray([
            'general' => ['table' => 'test_table', 'resourceName' => 'internal-tests', 'resourceType' => 'Test', 'operations' => ['list', 'show', 'update']],
            'columns' => ['title' => ['groups' => ['list', 'show']], 'secret' => ['groups' => ['list', 'show']]],
        ]);

        $schemas = $this->builder->build([$public, $internal]);

        // The first resource keeps the plain name, so its clients' types don't change.
        self::assertArrayNotHasKey('secret', $schemas['TestRead']['properties']);
        self::assertArrayHasKey('secret', $schemas['TestInternalTestsRead']['properties']);
        self::assertArrayHasKey('TestInternalTestsWrite', $schemas);
        self::assertSame(
            '#/components/schemas/TestInternalTestsRead',
            $schemas['TestInternalTestsCollection']['properties']['hydra:member']['items']['$ref'],
        );
        self::assertSame('#/components/schemas/TestRead', $schemas['TestCollection']['properties']['hydra:member']['items']['$ref']);
    }

    #[Test]
    public function generatedSchemaNameNeverTakesALaterResourcesPlainName(): void
    {
        $make = static fn (string $name, string $type): ApiDefinition => ApiDefinition::fromArray([
            'general' => ['table' => 'test_table', 'resourceName' => $name, 'resourceType' => $type, 'operations' => ['list', 'show']],
            'columns' => ['title' => ['groups' => ['list', 'show']]],
        ]);

        $schemas = $this->builder->build([
            $make('tests', 'Test'),
            $make('internal-tests', 'Test'),
            $make('specials', 'TestInternalTests'),
        ]);

        // The later resource keeps its declared name; the duplicate gets a counter.
        self::assertSame('#/components/schemas/TestInternalTestsRead', $schemas['TestInternalTestsCollection']['properties']['hydra:member']['items']['$ref']);
        self::assertSame('#/components/schemas/TestInternalTests2Read', $schemas['TestInternalTests2Collection']['properties']['hydra:member']['items']['$ref']);
    }

    #[Test]
    public function recordTypeSchemaNamesAreUniqueAcrossResources(): void
    {
        $GLOBALS['TCA']['test_table'] = [
            'ctrl' => ['type' => 'record_type'],
            'columns' => ['record_type' => ['config' => ['type' => 'select']], 'title' => ['config' => ['type' => 'input']]],
            'types' => ['foo-bar' => ['showitem' => 'title'], 'bar' => ['showitem' => 'title'], 'baz' => ['showitem' => 'title']],
        ];
        $make = static fn (string $name, array $recordTypes): ApiDefinition => ApiDefinition::fromArray([
            'general' => ['table' => 'test_table', 'resourceName' => $name, 'resourceType' => 'Item', 'recordTypes' => $recordTypes],
        ]);

        // "Item" + "FooBar" and "Item" + "Foo" (resource name) + "Bar" would both be ItemFooBarRead.
        $schemas = $this->builder->build([$make('items', ['foo-bar', 'baz']), $make('foo', ['bar', 'baz'])]);

        $mapping = static fn (string $read): array => (array)$schemas[$read]['discriminator']['mapping'];
        $refs = [...array_values($mapping('ItemRead')), ...array_values($mapping('ItemFooRead'))];
        self::assertCount(4, array_unique($refs));
        foreach ($refs as $ref) {
            self::assertArrayHasKey(substr($ref, \strlen('#/components/schemas/')), $schemas);
        }
        self::assertSame(['bar'], $schemas[substr($mapping('ItemFooRead')['bar'], \strlen('#/components/schemas/'))]['properties']['record_type']['enum']);
    }

    // ── Helper ─────────────────────────────────────────────────────────────────

    /** @param list<string> $operations */
    private static function makeTarget(string $table, string $resourceName, array $operations): ApiDefinition
    {
        return ApiDefinition::fromArray([
            'general' => [
                'table'        => $table,
                'resourceName' => $resourceName,
                'resourceType' => ucfirst($resourceName),
                'operations'   => $operations,
            ],
            'columns' => ['uid' => ['groups' => ['show']]],
        ]);
    }

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
