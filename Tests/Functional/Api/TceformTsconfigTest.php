<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * general.tsconfig = 'storagePid' applies the storage page's TCEFORM TSconfig to writes.
 *
 * The storage page 810 (pages_tsconfig.csv) sets for tt_content:
 *   layout.disabled = 1
 *   CType.removeItems = header
 *   header_layout.types.textmedia.disabled = 1
 *   frame_class.removeItems = ruler-before
 *   space_before_class.keepItems = extra-small, addItems.custom
 *
 * uid=8100 (tt_content_tsconfig.csv) is a textmedia element on page 810 with layout 2.
 */
final class TceformTsconfigTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_tsconfig.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_tsconfig.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');

        $this->registerResource('tsconfig-content', self::config('tsconfig-content', 'TsconfigContent', ['tsconfig' => 'storagePid']));
        $this->registerResource('plain-content', self::config('plain-content', 'PlainContent', []));
        $this->registerResource('typed-content', self::config('typed-content', 'TypedContent', [
            'tsconfig'    => 'storagePid',
            'recordTypes' => ['textmedia', 'header'],
        ]));
    }

    private static function config(string $resourceName, string $resourceType, array $general): array
    {
        return [
            'general' => [
                'table'        => 'tt_content',
                'resourceName' => $resourceName,
                'resourceType' => $resourceType,
                'operations'   => ['list', 'show', 'create', 'update'],
                'storagePid'   => 810,
            ] + $general,
            'security' => [
                'list'   => AccessRole::PUBLIC,
                'show'   => AccessRole::PUBLIC,
                'create' => AccessRole::FE_USER,
                'update' => AccessRole::FE_USER,
            ],
        ];
    }

    public function testADisabledFieldStaysReadableButIsIgnoredOnWrite(): void
    {
        self::assertSame(2, $this->decodeResponseBody($this->executeApiRequest('/_api/tsconfig-content/8100'))['layout']);

        $created = $this->executeApiWriteRequestAs('POST', '/_api/tsconfig-content', 1, ['CType' => 'textmedia', 'header' => 'New', 'layout' => 1]);
        $updated = $this->executeApiWriteRequestAs('PATCH', '/_api/tsconfig-content/8100', 1, ['layout' => 3]);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame(0, (int)$this->fetchRow((int)$this->decodeResponseBody($created)['uid'])['layout']);
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame(2, (int)$this->fetchRow(8100)['layout']);
    }

    public function testAFieldDisabledForOneTypeStaysWritableForOthers(): void
    {
        $textmedia = $this->executeApiWriteRequestAs('POST', '/_api/tsconfig-content', 1, ['CType' => 'textmedia', 'header' => 'Textmedia', 'header_layout' => 2]);
        $text      = $this->executeApiWriteRequestAs('POST', '/_api/tsconfig-content', 1, ['CType' => 'text', 'header' => 'Text', 'header_layout' => 2]);

        self::assertSame(0, (int)$this->fetchRow((int)$this->decodeResponseBody($textmedia)['uid'])['header_layout']);
        self::assertSame(2, (int)$this->fetchRow((int)$this->decodeResponseBody($text)['uid'])['header_layout']);

        $this->executeApiWriteRequestAs('PATCH', '/_api/tsconfig-content/8100', 1, ['header_layout' => 3]);
        self::assertSame(0, (int)$this->fetchRow(8100)['header_layout']);
    }

    public function testARemovedRecordTypeIsRejected(): void
    {
        $unscoped = $this->executeApiWriteRequestAs('POST', '/_api/tsconfig-content', 1, ['CType' => 'header', 'header' => 'New']);
        $scoped   = $this->executeApiWriteRequestAs('POST', '/_api/typed-content', 1, ['CType' => 'header', 'header' => 'New']);

        self::assertSame(422, $unscoped->getStatusCode());
        $this->assertViolation($unscoped, 'CType', 'INVALID_RECORD_TYPE');
        self::assertSame(422, $scoped->getStatusCode());
        $this->assertViolation($scoped, 'CType', 'INVALID_RECORD_TYPE');
    }

    public function testRemovedAndNotKeptSelectValuesAreRejected(): void
    {
        $removed = $this->executeApiWriteRequestAs('POST', '/_api/tsconfig-content', 1, ['CType' => 'textmedia', 'frame_class' => 'ruler-before']);
        $notKept = $this->executeApiWriteRequestAs('POST', '/_api/tsconfig-content', 1, ['CType' => 'textmedia', 'space_before_class' => 'small']);

        self::assertSame(422, $removed->getStatusCode());
        $this->assertViolation($removed, 'frame_class', 'INVALID_VALUE');
        self::assertSame(422, $notKept->getStatusCode());
        $this->assertViolation($notKept, 'space_before_class', 'INVALID_VALUE');
    }

    public function testKeptAddedAndEmptySelectValuesAreAccepted(): void
    {
        foreach (['extra-small', 'custom', ''] as $value) {
            $response = $this->executeApiWriteRequestAs('POST', '/_api/tsconfig-content', 1, ['CType' => 'textmedia', 'frame_class' => 'default', 'space_before_class' => $value]);

            self::assertSame(201, $response->getStatusCode(), 'space_before_class = "' . $value . '"');
        }
    }

    public function testIgnoreIsTheDefault(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/plain-content', 1, ['CType' => 'header', 'header' => 'New', 'layout' => 1, 'frame_class' => 'ruler-before']);

        self::assertSame(201, $response->getStatusCode());
        $row = $this->fetchRow((int)$this->decodeResponseBody($response)['uid']);
        self::assertSame(1, (int)$row['layout']);
        self::assertSame('ruler-before', $row['frame_class']);
    }

    public function testTheApiDocumentationMarksDisabledFieldsReadOnly(): void
    {
        $schemas = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas'];

        self::assertTrue($schemas['TsconfigContentRead']['properties']['layout']['readOnly'] ?? false);
        self::assertArrayNotHasKey('layout', $schemas['TsconfigContentWrite']['properties']);
        self::assertArrayHasKey('layout', $schemas['PlainContentWrite']['properties']);
        self::assertSame(['textmedia'], $schemas['TypedContentWrite']['properties']['CType']['enum']);

        $hydra = $this->decodeResponseBody($this->executeApiRequest('/_api/docs.jsonld'));
        $class = array_values(array_filter($hydra['hydra:supportedClass'], static fn (array $class): bool => $class['hydra:title'] === 'TsconfigContent'))[0];
        $layout = array_values(array_filter($class['hydra:supportedProperty'], static fn (array $property): bool => $property['hydra:title'] === 'layout'))[0];
        self::assertFalse($layout['hydra:writeable']);
        self::assertTrue($layout['hydra:readable']);
    }

    private function fetchRow(int $uid): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->select(['*'], 'tt_content', ['uid' => $uid])->fetchAssociative();
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
