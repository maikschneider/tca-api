<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Exception\InvalidApiDefinitionException;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * general.recordTypes scopes a resource to record types of the table's ctrl.type field.
 *
 * Fixture records (tt_content_record_types.csv), all on pid 1:
 *   uid=500 → textmedia
 *   uid=501 → header
 *   uid=502 → text       (outside both resources)
 */
final class RecordTypesTest extends ApiFunctionalTestCase
{
    private const SECURITY = [
        'list'   => AccessRole::PUBLIC,
        'show'   => AccessRole::PUBLIC,
        'create' => AccessRole::FE_USER,
        'update' => AccessRole::FE_USER,
        'delete' => AccessRole::FE_USER,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_record_types.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_record_types.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');

        $this->registerResource('content', self::contentConfig('content', 'Content', ['textmedia', 'header']));
        $this->registerResource('content-header', self::contentConfig('content-header', 'ContentHeader', 'header'));
        $this->registerResource('standard-pages', [
            'general' => [
                'table'        => 'pages',
                'resourceName' => 'standard-pages',
                'resourceType' => 'StandardPage',
                'recordTypes'  => [1],
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']], 'doktype' => ['groups' => ['list', 'show']]],
        ]);
    }

    private static function contentConfig(string $resourceName, string $resourceType, array|string $recordTypes): array
    {
        return [
            'general' => [
                'table'        => 'tt_content',
                'resourceName' => $resourceName,
                'resourceType' => $resourceType,
                'operations'   => ['list', 'show', 'create', 'update', 'delete'],
                'storagePid'   => 1,
                'recordTypes'  => $recordTypes,
            ],
            'columns' => [
                'header' => ['groups' => ['list', 'show', 'create', 'update']],
                'CType'  => ['groups' => ['list', 'show', 'update']],
            ],
            'security' => self::SECURITY,
            'order'    => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ];
    }

    // ── Reads ─────────────────────────────────────────────────────────────────

    #[Test]
    public function collectionOnlyContainsAllowedTypes(): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/content'));

        self::assertSame([500, 501], array_column($body['hydra:member'], 'uid'));
        self::assertSame(2, $body['hydra:totalItems']);
    }

    #[Test]
    public function showOfAnAllowedTypeReturns200(): void
    {
        $response = $this->executeApiRequest('/_api/content/501');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('header', $this->decodeResponseBody($response)['CType']);
    }

    #[Test]
    public function showOfAnotherTypeReturns404(): void
    {
        self::assertSame(404, $this->executeApiRequest('/_api/content/502')->getStatusCode());
        self::assertSame(404, $this->executeApiRequest('/_api/content-header/500')->getStatusCode());
    }

    #[Test]
    public function integerRecordTypesScopePages(): void
    {
        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/standard-pages'));

        $uids = array_column($body['hydra:member'], 'uid');
        self::assertContains(500, $uids);
        self::assertNotContains(501, $uids);
        self::assertSame(404, $this->executeApiRequest('/_api/standard-pages/501')->getStatusCode());
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    #[Test]
    public function updateOfAnotherTypeReturns404(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/content/502', 1, ['header' => 'Changed']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Text element', $this->fetchContentRow(502)['header']);
    }

    #[Test]
    public function deleteOfAnotherTypeReturns404(): void
    {
        $response = $this->executeApiWriteRequestAs('DELETE', '/_api/content-header/500', 1);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, (int)$this->fetchContentRow(500)['deleted']);
    }

    #[Test]
    public function createOnASingleTypeResourceAssignsTheType(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/content-header', 1, ['header' => 'New header']);

        self::assertSame(201, $response->getStatusCode());
        $uid = $this->decodeResponseBody($response)['uid'];
        self::assertSame('header', $this->fetchContentRow($uid)['CType']);
    }

    #[Test]
    public function createOnASingleTypeResourceRejectsAnotherType(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/content-header', 1, ['header' => 'New', 'CType' => 'textmedia']);

        self::assertSame(422, $response->getStatusCode());
        $this->assertViolation($response, 'CType', 'INVALID_RECORD_TYPE');
    }

    #[Test]
    public function createOnAMultiTypeResourceRequiresTheType(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/content', 1, ['header' => 'New']);

        self::assertSame(422, $response->getStatusCode());
        $this->assertViolation($response, 'CType', 'REQUIRED');
    }

    #[Test]
    public function createOnAMultiTypeResourceRejectsATypeOutsideTheList(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/content', 1, ['header' => 'New', 'CType' => 'text']);

        self::assertSame(422, $response->getStatusCode());
        $this->assertViolation($response, 'CType', 'INVALID_RECORD_TYPE');
    }

    #[Test]
    public function createOnAMultiTypeResourceUsesTheSubmittedType(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/content', 1, ['header' => 'New', 'CType' => 'textmedia']);

        self::assertSame(201, $response->getStatusCode());
        $uid = $this->decodeResponseBody($response)['uid'];
        self::assertSame('textmedia', $this->fetchContentRow($uid)['CType']);
    }

    #[Test]
    public function updateCannotMoveARecordOutOfTheAllowedTypes(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/content/500', 1, ['CType' => 'text']);

        self::assertSame(422, $response->getStatusCode());
        $this->assertViolation($response, 'CType', 'INVALID_RECORD_TYPE');
        self::assertSame('textmedia', $this->fetchContentRow(500)['CType']);
    }

    #[Test]
    public function updateRejectsAnEmptyOrNullType(): void
    {
        foreach (['', null] as $type) {
            $response = $this->executeApiWriteRequestAs('PATCH', '/_api/content/500', 1, ['CType' => $type]);

            self::assertSame(422, $response->getStatusCode());
            $this->assertViolation($response, 'CType', 'INVALID_RECORD_TYPE');
        }
        self::assertSame('textmedia', $this->fetchContentRow(500)['CType']);
    }

    #[Test]
    public function updateCanSwitchBetweenAllowedTypes(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/content/500', 1, ['CType' => 'header']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('header', $this->fetchContentRow(500)['CType']);
    }

    // ── OpenAPI ───────────────────────────────────────────────────────────────

    #[Test]
    public function openApiDescribesTheTypeFieldAsEnum(): void
    {
        $schemas = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas'];

        self::assertSame(['textmedia', 'header'], $schemas['ContentRead']['properties']['CType']['enum']);
        self::assertSame(['textmedia', 'header'], $schemas['ContentWrite']['properties']['CType']['enum']);
        self::assertContains('CType', $schemas['ContentWrite']['required']);
        self::assertSame(['header'], $schemas['ContentHeaderWrite']['properties']['CType']['enum']);
        self::assertNotContains('CType', $schemas['ContentHeaderWrite']['required'] ?? []);
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    #[Test]
    public function wildcardScopesToEveryDefinedType(): void
    {
        $definition = ApiDefinition::fromArray(self::contentConfig('all-content', 'AllContent', '*'));

        self::assertSame(
            array_map('strval', array_keys($GLOBALS['TCA']['tt_content']['types'])),
            $definition->recordTypes,
        );
    }

    #[Test]
    public function unknownRecordTypeFailsAtBoot(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('"no_such_type"');

        ApiDefinition::fromArray(self::contentConfig('broken', 'Broken', ['textmedia', 'no_such_type']));
    }

    #[Test]
    public function recordTypesOnATableWithoutTypeFieldFailsAtBoot(): void
    {
        $this->expectException(InvalidApiDefinitionException::class);
        $this->expectExceptionMessage('no TCA ctrl.type field');

        ApiDefinition::fromArray([
            'general' => [
                'table'        => 'tx_myext_domain_model_color',
                'resourceName' => 'typed-colors',
                'resourceType' => 'TypedColor',
                'recordTypes'  => ['1'],
            ],
        ]);
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
