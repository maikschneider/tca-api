<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * general.recordTypes.split expands one resource config into one resource per
 * record type, through the loader.
 *
 * The test_record_type_split fixture extension ships:
 *   split-content: textmedia + header, split, base kept, a types.header override
 *                  making list frontend-user only, and an Overrides/ file setting
 *                  itemsPerPage on the base config
 *   split-only:    textmedia, split with namePattern '{type}-only', keepBase false
 *
 * Fixture records (tt_content_record_type_schemas.csv), all on pid 1:
 *   uid=600 → textmedia, uid=601 → header, uid=602 → tca_api_teaser
 */
final class RecordTypeSplitTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_record_type_split',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_record_type_schemas.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');
    }

    #[Test]
    public function eachGeneratedResourceIsScopedToItsType(): void
    {
        $members = $this->decodeResponseBody($this->executeApiRequest('/_api/split-content-textmedia'))['hydra:member'];

        self::assertSame([600], array_column($members, 'uid'));
        self::assertSame('SplitContentTextmedia', $members[0]['@type']);
        self::assertSame(404, $this->executeApiRequest('/_api/split-content-textmedia/601')->getStatusCode());
        self::assertSame(200, $this->executeApiRequest('/_api/split-content-textmedia/600')->getStatusCode());
    }

    #[Test]
    public function aGeneratedResourceIsShapedByItsType(): void
    {
        $header = $this->decodeResponseBody($this->executeApiRequestAs('/_api/split-content-header/601', 1));

        self::assertArrayHasKey('header_layout', $header);
        self::assertArrayNotHasKey('bodytext', $header);
    }

    #[Test]
    public function createOnAGeneratedResourceSetsItsType(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/split-content-header', 1, ['header' => 'New']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('header', $this->decodeResponseBody($response)['CType']);
    }

    #[Test]
    public function aTypesOverrideChangesOnlyItsGeneratedResource(): void
    {
        self::assertSame(200, $this->executeApiRequest('/_api/split-content-textmedia')->getStatusCode());
        self::assertSame(403, $this->executeApiRequest('/_api/split-content-header')->getStatusCode());
        self::assertSame(200, $this->executeApiRequestAs('/_api/split-content-header', 1)->getStatusCode());
        self::assertSame(200, $this->executeApiRequest('/_api/split-content')->getStatusCode());
    }

    #[Test]
    public function anOverridesFileEditingTheBaseReachesEveryGeneratedResource(): void
    {
        $registry = $this->getApiRegistry();

        self::assertSame(7, $registry->get('split-content')?->itemsPerPage);
        self::assertSame(7, $registry->get('split-content-textmedia')?->itemsPerPage);
        self::assertSame(7, $registry->get('split-content-header')?->itemsPerPage);
    }

    #[Test]
    public function relationsToTheTableResolveToTheKeptBase(): void
    {
        self::assertSame('split-content', $this->getApiRegistry()->getByTable('tt_content')?->resourceName);
    }

    #[Test]
    public function keepBaseFalseRemovesTheBaseResource(): void
    {
        self::assertNull($this->getApiRegistry()->get('split-only'));
        self::assertNotNull($this->getApiRegistry()->get('textmedia-only'));
        self::assertNotNull($this->getApiRegistry()->get('split-content'));
    }

    #[Test]
    public function generatedResourcesAppearInTheApiDocumentation(): void
    {
        $openApi = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'));
        $tags = array_column($openApi['tags'], 'description', 'name');

        self::assertArrayHasKey('/_api/split-content-textmedia', $openApi['paths']);
        self::assertArrayHasKey('/_api/split-content-header/{uid}', $openApi['paths']);
        self::assertArrayHasKey('SplitContentTextmediaRead', $openApi['components']['schemas']);
        self::assertStringNotContainsString('LLL:', $tags['SplitContentTextmedia'] ?? 'LLL: missing');
        self::assertNotSame('', $tags['SplitContentTextmedia']);

        $hydra = $this->decodeResponseBody($this->executeApiRequest('/_api/docs.jsonld'));
        self::assertContains('SplitContentTextmedia', array_column($hydra['hydra:supportedClass'], 'hydra:title'));
    }

    #[Test]
    public function aGeneratedResourceKeepsItsSchemaNameNextToTheBasesTypeSchemas(): void
    {
        $schemas = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas'];

        self::assertSame('SplitContentHeader', $schemas['SplitContentHeaderRead']['properties']['@type']['example']);
        self::assertNotContains(
            ['$ref' => '#/components/schemas/SplitContentHeaderRead'],
            $schemas['SplitContentRead']['oneOf'],
        );
    }

    #[Test]
    public function hydraLinksARelationToTheTableToTheKeptBase(): void
    {
        $this->registerResource('split-pages', [
            'general'  => ['table' => 'pages', 'resourceName' => 'split-pages', 'resourceType' => 'SplitPage'],
            'columns'  => ['tx_split_content' => ['groups' => ['show']]],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
        ]);

        $hydra = $this->decodeResponseBody($this->executeApiRequest('/_api/docs.jsonld'));
        $class = array_values(array_filter($hydra['hydra:supportedClass'], static fn (array $class): bool => $class['hydra:title'] === 'SplitPage'))[0];
        $property = array_values(array_filter($class['hydra:supportedProperty'], static fn (array $property): bool => $property['hydra:title'] === 'tx_split_content'))[0];

        self::assertSame('#SplitContent', $property['hydra:property']['range']);
    }

    #[Test]
    public function anEmbeddedRelationNamingAGeneratedResourceOfTheSameTableUsesIt(): void
    {
        $this->registerLinkingContent(['embed' => true]);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['tx_split_content' => 601], ['uid' => 600]);

        $item = $this->decodeResponseBody($this->executeApiRequest('/_api/linking-content/600'));

        self::assertSame('/_api/split-content-header/601', $item['tx_split_content']['@id']);
        self::assertSame('SplitContentHeader', $item['tx_split_content']['@type']);
    }

    #[Test]
    public function nestedCreateThroughAGeneratedResourceKeepsItsType(): void
    {
        $this->registerLinkingContent();

        $otherType = $this->executeApiWriteRequestAs('POST', '/_api/linking-content', 1, [
            'CType'            => 'textmedia',
            'tx_split_content' => ['CType' => 'textmedia', 'header' => 'Not a header'],
        ]);
        $created = $this->executeApiWriteRequestAs('POST', '/_api/linking-content', 1, [
            'CType'            => 'textmedia',
            'tx_split_content' => ['header' => 'Nested header'],
        ]);

        self::assertSame(422, $otherType->getStatusCode());
        self::assertContains('tx_split_content.CType', array_column($this->decodeResponseBody($otherType)['violations'], 'propertyPath'));
        self::assertSame(201, $created->getStatusCode(), (string)$created->getBody());
        $child = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->select(['CType'], 'tt_content', ['header' => 'Nested header'])->fetchAssociative();
        self::assertSame('header', $child['CType'] ?? null);
    }

    private function registerLinkingContent(array $relation = []): void
    {
        $this->registerResource('linking-content', [
            'general' => [
                'table'        => 'tt_content',
                'resourceName' => 'linking-content',
                'resourceType' => 'LinkingContent',
                'operations'   => ['list', 'show', 'create'],
                'storagePid'   => 1,
            ],
            'columns' => [
                'CType'            => ['groups' => ['show', 'create']],
                'tx_split_content' => ['groups' => ['show', 'create'], 'resourceName' => 'split-content-header'] + $relation,
            ],
            'security' => [
                'list'   => AccessRole::PUBLIC,
                'show'   => AccessRole::PUBLIC,
                'create' => AccessRole::FE_USER,
            ],
        ]);
    }
}
