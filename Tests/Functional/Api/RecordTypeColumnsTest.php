<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestStaticValueProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * In explicit mode, general.recordTypes.typeColumns exposes per record only the
 * declared columns of its type's form. Without the option, explicit mode keeps
 * one flat column map for every type.
 *
 * Every response is validated against the generated OpenAPI spec by
 * ApiFunctionalTestCase, so the per-type Read schemas are checked against real
 * list and show responses.
 *
 * The test_record_types fixture extension adds the CType `tca_api_teaser`
 * (CType, colPos, header, bodytext).
 *
 * Fixture records (tt_content_record_type_schemas.csv), all on pid 1:
 *   uid=600 → textmedia
 *   uid=601 → header      (bodytext is set in the DB but not part of the form)
 *   uid=602 → tca_api_teaser
 */
final class RecordTypeColumnsTest extends ApiFunctionalTestCase
{
    private const READ = ['groups' => ['list', 'show']];

    private const TYPES = ['textmedia', 'header', 'tca_api_teaser'];

    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_record_types',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_record_type_schemas.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');

        $this->registerResource('flat-list', self::config('flat-list', 'FlatList', self::TYPES));
        $this->registerResource('flat-schema-type', self::config('flat-schema-type', 'FlatSchemaType', [
            'include' => self::TYPES,
            'schema'  => 'type',
        ]));
    }

    /**
     * @param list<string> $operations
     */
    private static function config(string $resourceName, string $resourceType, array $recordTypes, array $operations = ['list', 'show', 'create', 'update']): array
    {
        $write = ['groups' => ['list', 'show', 'create', 'update']];

        return [
            'general' => [
                'table'        => 'tt_content',
                'resourceName' => $resourceName,
                'resourceType' => $resourceType,
                'operations'   => $operations,
                'storagePid'   => 1,
                'recordTypes'  => $recordTypes,
            ],
            'columns' => [
                'CType'     => $write,
                'header'    => $write,
                'bodytext'  => $write,
                'subheader' => self::READ,
            ],
            'security' => [
                'list'   => AccessRole::PUBLIC,
                'show'   => AccessRole::PUBLIC,
                'create' => AccessRole::FE_USER,
                'update' => AccessRole::FE_USER,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ];
    }

    // ── typeColumns ───────────────────────────────────────────────────────────

    private function registerTypeColumnsResource(): void
    {
        $this->registerResource('typed-columns', self::config('typed-columns', 'TypedColumns', [
            'include'     => self::TYPES,
            'typeColumns' => true,
        ], ['list', 'show']));
    }

    #[Test]
    public function showExposesTheDeclaredColumnsOfTheRecordsType(): void
    {
        $this->registerTypeColumnsResource();

        $textmedia = $this->decodeResponseBody($this->executeApiRequest('/_api/typed-columns/600'));
        $header    = $this->decodeResponseBody($this->executeApiRequest('/_api/typed-columns/601'));
        $teaser    = $this->decodeResponseBody($this->executeApiRequest('/_api/typed-columns/602'));

        self::assertSame('Textmedia body', $textmedia['bodytext']);
        self::assertSame('header', $header['CType']);
        self::assertArrayHasKey('subheader', $header);
        self::assertArrayNotHasKey('bodytext', $header);
        self::assertSame(['CType', 'header', 'bodytext'], array_values(array_diff(array_keys($teaser), ['@type', '@id', 'uid'])));
    }

    #[Test]
    public function aCollectionShapesEveryItemByItsType(): void
    {
        $this->registerTypeColumnsResource();

        $members = array_column(
            $this->decodeResponseBody($this->executeApiRequest('/_api/typed-columns'))['hydra:member'],
            null,
            'uid',
        );

        self::assertSame([600, 601, 602], array_keys($members));
        self::assertArrayHasKey('bodytext', $members[600]);
        self::assertArrayNotHasKey('bodytext', $members[601]);
        self::assertArrayNotHasKey('subheader', $members[602]);
    }

    #[Test]
    public function aVirtualPropertyOverAColumnFollowsThatColumn(): void
    {
        $config = self::config('typed-columns', 'TypedColumns', [
            'include'     => self::TYPES,
            'typeColumns' => true,
        ], ['list', 'show']);
        $config['virtualProperties'] = [
            'bodyCopy' => ['column' => 'bodytext', 'processor' => TestStaticValueProcessor::class, 'groups' => ['list', 'show']],
        ];
        $this->registerResource('typed-columns', $config);

        $textmedia = $this->decodeResponseBody($this->executeApiRequest('/_api/typed-columns/600'));
        $header    = $this->decodeResponseBody($this->executeApiRequest('/_api/typed-columns/601'));
        $schemas   = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas'];

        self::assertArrayHasKey('bodyCopy', $textmedia);
        self::assertArrayNotHasKey('bodyCopy', $header);
        self::assertArrayHasKey('bodyCopy', $schemas['TypedColumnsTextmediaRead']['properties']);
        self::assertArrayNotHasKey('bodyCopy', $schemas['TypedColumnsHeaderRead']['properties']);
    }

    #[Test]
    public function openApiDescribesEachTypeAndDiscriminatesBetweenThem(): void
    {
        $this->registerTypeColumnsResource();

        $schemas = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas'];

        $read = $schemas['TypedColumnsRead'];
        self::assertSame('CType', $read['discriminator']['propertyName']);
        self::assertSame([
            'textmedia'      => '#/components/schemas/TypedColumnsTextmediaRead',
            'header'         => '#/components/schemas/TypedColumnsHeaderRead',
            'tca_api_teaser' => '#/components/schemas/TypedColumnsTcaApiTeaserRead',
        ], $read['discriminator']['mapping']);

        $header = $schemas['TypedColumnsHeaderRead'];
        self::assertSame(['CType'], $header['required']);
        self::assertSame(['header'], $header['properties']['CType']['enum']);
        self::assertArrayNotHasKey('bodytext', $header['properties']);
        self::assertSame(
            ['@type', '@id', 'uid', 'CType', 'header', 'bodytext'],
            array_keys($schemas['TypedColumnsTcaApiTeaserRead']['properties']),
        );
        self::assertArrayNotHasKey('TypedColumnsWrite', $schemas);
    }

    // ── Explicit mode without typeColumns ─────────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function flatResources(): iterable
    {
        yield 'list of types' => ['flat-list'];
        yield 'options with schema type' => ['flat-schema-type'];
    }

    #[Test]
    #[DataProvider('flatResources')]
    public function explicitModeExposesTheDeclaredColumnsOnEveryType(string $resource): void
    {
        $header = $this->decodeResponseBody($this->executeApiRequest('/_api/' . $resource . '/601'));
        $teaser = $this->decodeResponseBody($this->executeApiRequest('/_api/' . $resource . '/602'));

        self::assertSame('Stale body', $header['bodytext']);
        self::assertArrayHasKey('subheader', $teaser);
    }

    #[Test]
    #[DataProvider('flatResources')]
    public function explicitModeHasOneFlatReadSchema(string $resource): void
    {
        $name    = $resource === 'flat-list' ? 'FlatList' : 'FlatSchemaType';
        $schemas = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas'];

        self::assertArrayNotHasKey('oneOf', $schemas[$name . 'Read']);
        self::assertSame(
            ['@type', '@id', 'uid', 'CType', 'header', 'bodytext', 'subheader'],
            array_keys($schemas[$name . 'Read']['properties']),
        );
        self::assertArrayNotHasKey($name . 'HeaderRead', $schemas);
    }

    #[Test]
    #[DataProvider('flatResources')]
    public function explicitModeWritesADeclaredColumnOutsideTheTypesForm(string $resource): void
    {
        $created = $this->executeApiWriteRequestAs('POST', '/_api/' . $resource, 1, [
            'CType'    => 'header',
            'header'   => 'New header',
            'bodytext' => 'Created body',
        ]);
        $updated = $this->executeApiWriteRequestAs('PATCH', '/_api/' . $resource . '/601', 1, ['bodytext' => 'Updated body']);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame('Created body', $this->fetchBodytext($this->decodeResponseBody($created)['uid']));
        self::assertSame('Updated body', $this->fetchBodytext(601));
    }

    private function fetchBodytext(int $uid): string
    {
        return (string)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->select(['bodytext'], 'tt_content', ['uid' => $uid])->fetchOne();
    }
}
