<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Relations into resources scoped to record types.
 *
 * The test_record_type_relation fixture extension adds the column
 * tx_related_content (a single tt_content relation) to tt_content and pages, and ships:
 *   related-content: textmedia + header
 *   related-header:  header only
 *
 * Fixture records (tt_content_record_type_schemas.csv), all on pid 1:
 *   uid=600 → textmedia, uid=601 → header, uid=602 → tca_api_teaser
 */
final class RecordTypeRelationTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_record_type_relation',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_record_type_schemas.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');
    }

    #[Test]
    public function anEmbeddedRelationNamingAResourceOfTheSameTableUsesIt(): void
    {
        $this->registerLinkingContent(['embed' => true]);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['tx_related_content' => 601], ['uid' => 600]);

        $item = $this->decodeResponseBody($this->executeApiRequest('/_api/linking-content/600'));

        self::assertSame('/_api/related-header/601', $item['tx_related_content']['@id']);
        self::assertSame('RelatedHeader', $item['tx_related_content']['@type']);
    }

    #[Test]
    public function nestedCreateThroughASingleTypeResourceKeepsItsType(): void
    {
        $this->registerLinkingContent();

        $otherType = $this->executeApiWriteRequestAs('POST', '/_api/linking-content', 1, [
            'CType'              => 'textmedia',
            'tx_related_content' => ['CType' => 'textmedia', 'header' => 'Not a header'],
        ]);
        $created = $this->executeApiWriteRequestAs('POST', '/_api/linking-content', 1, [
            'CType'              => 'textmedia',
            'tx_related_content' => ['header' => 'Nested header'],
        ]);

        self::assertSame(422, $otherType->getStatusCode());
        self::assertContains('tx_related_content.CType', array_column($this->decodeResponseBody($otherType)['violations'], 'propertyPath'));
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
                'CType'              => ['groups' => ['show', 'create']],
                'tx_related_content' => ['groups' => ['show', 'create'], 'resourceName' => 'related-header'] + $relation,
            ],
            'security' => [
                'list'   => AccessRole::PUBLIC,
                'show'   => AccessRole::PUBLIC,
                'create' => AccessRole::FE_USER,
            ],
        ]);
    }
}
