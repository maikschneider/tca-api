<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * In default mode, a resource with general.recordTypes exposes per record only the
 * columns of its type's form, with the type's columnsOverrides applied.
 *
 * The test_record_types fixture extension adds the CType `tca_api_teaser`
 * (header, bodytext) whose columnsOverrides make bodytext required with max 20.
 *
 * Fixture records (tt_content_record_type_schemas.csv), all on pid 1:
 *   uid=600 → textmedia
 *   uid=601 → header      (bodytext is set in the DB but not part of the form)
 *   uid=602 → tca_api_teaser
 */
final class RecordTypeSchemasTest extends ApiFunctionalTestCase
{
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

        $this->registerResource('typed-content', self::config('typed-content', 'TypedContent', ['textmedia', 'header', 'tca_api_teaser']));
        $this->registerResource('teasers', self::config('teasers', 'Teaser', 'tca_api_teaser'));
        $this->registerResource('table-content', self::config('table-content', 'TableContent', [
            'include' => ['textmedia', 'header'],
            'schema'  => 'table',
        ]));
    }

    private static function config(string $resourceName, string $resourceType, array|string $recordTypes): array
    {
        return [
            'general' => [
                'table'        => 'tt_content',
                'resourceName' => $resourceName,
                'resourceType' => $resourceType,
                'operations'   => ['list', 'show', 'create', 'update'],
                'storagePid'   => 1,
                'recordTypes'  => $recordTypes,
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

    // ── Reads ─────────────────────────────────────────────────────────────────

    #[Test]
    public function eachRecordExposesOnlyTheColumnsOfItsType(): void
    {
        $textmedia = $this->decodeResponseBody($this->executeApiRequest('/_api/typed-content/600'));
        $header    = $this->decodeResponseBody($this->executeApiRequest('/_api/typed-content/601'));

        self::assertSame('Textmedia body', $textmedia['bodytext']);
        self::assertArrayHasKey('assets', $textmedia);
        self::assertArrayHasKey('header_layout', $header);
        self::assertArrayNotHasKey('bodytext', $header);
        self::assertArrayNotHasKey('assets', $header);
    }

    #[Test]
    public function systemFieldsFromPalettesStayHidden(): void
    {
        $teaser = $this->decodeResponseBody($this->executeApiRequest('/_api/typed-content/602'));

        self::assertArrayNotHasKey('hidden', $teaser);
        self::assertArrayNotHasKey('sys_language_uid', $teaser);
    }

    #[Test]
    public function columnsFollowTheShowitemOrder(): void
    {
        $teaser = $this->decodeResponseBody($this->executeApiRequest('/_api/typed-content/602'));

        $columns = array_values(array_diff(array_keys($teaser), ['@type', '@id', 'uid']));
        self::assertSame(['CType', 'colPos', 'header', 'bodytext', 'rowDescription'], $columns);
    }

    #[Test]
    public function aCollectionShapesEveryItemByItsType(): void
    {
        $members = $this->decodeResponseBody($this->executeApiRequest('/_api/typed-content'))['hydra:member'];

        self::assertSame([600, 601, 602], array_column($members, 'uid'));
        self::assertArrayHasKey('bodytext', $members[0]);
        self::assertArrayNotHasKey('bodytext', $members[1]);
        self::assertArrayHasKey('bodytext', $members[2]);
    }

    #[Test]
    public function tableSchemaExposesAllTableColumns(): void
    {
        $header = $this->decodeResponseBody($this->executeApiRequest('/_api/table-content/601'));

        self::assertSame('Stale body', $header['bodytext']);
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    #[Test]
    public function aColumnsOverridesRequirementAppliesOnlyToItsType(): void
    {
        $teaser = $this->executeApiWriteRequestAs('POST', '/_api/typed-content', 1, ['CType' => 'tca_api_teaser', 'header' => 'New']);
        $header = $this->executeApiWriteRequestAs('POST', '/_api/typed-content', 1, ['CType' => 'header', 'header' => 'New']);

        self::assertSame(422, $teaser->getStatusCode());
        $this->assertViolation($teaser, 'bodytext', 'REQUIRED');
        self::assertSame(201, $header->getStatusCode());
    }

    #[Test]
    public function aColumnsOverridesValidatorAppliesOnlyToItsType(): void
    {
        $body = str_repeat('a', 21);

        $teaser    = $this->executeApiWriteRequestAs('POST', '/_api/teasers', 1, ['header' => 'New', 'bodytext' => $body]);
        $textmedia = $this->executeApiWriteRequestAs('POST', '/_api/typed-content', 1, ['CType' => 'textmedia', 'header' => 'New', 'bodytext' => $body]);

        self::assertSame(422, $teaser->getStatusCode());
        $this->assertViolation($teaser, 'bodytext', 'MAX_LENGTH');
        self::assertSame(201, $textmedia->getStatusCode());
    }

    #[Test]
    public function aColumnOutsideTheTypeIsIgnoredOnCreate(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/typed-content', 1, ['CType' => 'header', 'header' => 'New', 'bodytext' => 'Ignored']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('', (string)$this->fetchContentRow($this->decodeResponseBody($response)['uid'])['bodytext']);
    }

    #[Test]
    public function aColumnOutsideTheTypeIsIgnoredOnUpdate(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/typed-content/601', 1, ['header' => 'Changed', 'bodytext' => 'Ignored']);

        self::assertSame(200, $response->getStatusCode());
        $row = $this->fetchContentRow(601);
        self::assertSame('Changed', $row['header']);
        self::assertSame('Stale body', $row['bodytext']);
    }

    #[Test]
    public function switchingTheTypeUsesTheColumnsOfTheNewType(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/typed-content/601', 1, ['CType' => 'tca_api_teaser', 'bodytext' => 'Now a teaser']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Now a teaser', $this->decodeResponseBody($response)['bodytext']);
    }

    // ── OpenAPI ───────────────────────────────────────────────────────────────

    #[Test]
    public function openApiDescribesEachTypeAndDiscriminatesBetweenThem(): void
    {
        $schemas = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas'];

        self::assertSame([
            'propertyName' => 'CType',
            'mapping' => [
                'textmedia'      => '#/components/schemas/TypedContentTextmediaRead',
                'header'         => '#/components/schemas/TypedContentHeaderRead',
                'tca_api_teaser' => '#/components/schemas/TypedContentTcaApiTeaserRead',
            ],
        ], $schemas['TypedContentRead']['discriminator']);
        self::assertCount(3, $schemas['TypedContentRead']['oneOf']);

        self::assertArrayHasKey('bodytext', $schemas['TypedContentTextmediaRead']['properties']);
        self::assertArrayNotHasKey('bodytext', $schemas['TypedContentHeaderRead']['properties']);
        self::assertSame(['header'], $schemas['TypedContentHeaderRead']['properties']['CType']['enum']);
        self::assertSame(['CType'], $schemas['TypedContentHeaderRead']['required']);
    }

    #[Test]
    public function openApiDescribesASingleTypeResourceByItsType(): void
    {
        $schemas = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas'];

        self::assertArrayNotHasKey('oneOf', $schemas['TeaserRead']);
        self::assertSame(['tca_api_teaser'], $schemas['TeaserRead']['properties']['CType']['enum']);
        self::assertArrayNotHasKey('assets', $schemas['TeaserWrite']['properties']);
        self::assertContains('bodytext', $schemas['TeaserWrite']['required']);
        self::assertSame(20, $schemas['TeaserWrite']['properties']['bodytext']['maxLength']);
    }

    private function fetchContentRow(int $uid): array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function assertViolation(ResponseInterface $response, string $propertyPath, string $code): void
    {
        $violations = array_map(
            static fn (array $violation): string => $violation['propertyPath'] . ':' . $violation['code'],
            $this->decodeResponseBody($response)['violations'] ?? [],
        );

        self::assertContains($propertyPath . ':' . $code, $violations);
    }
}
