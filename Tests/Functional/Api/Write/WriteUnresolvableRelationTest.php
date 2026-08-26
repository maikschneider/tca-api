<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * A nested object on a relation column whose table has no ApiRegistry entry used to be
 * dropped from the write, so the request answered 201 with the relation missing.
 * It is now an UNRESOLVABLE_RELATION violation.
 *
 * page_id and related_pages point at `pages`, which no fixture resource covers.
 */
final class WriteUnresolvableRelationTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_group.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');

        $this->registerResource('unres-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'unres-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show', 'create'],
                'storagePid'   => 1,
            ],
            'columns' => [
                'title'         => ['groups' => ['list', 'show', 'create'], 'required' => true],
                'page_id'       => ['groups' => ['list', 'show', 'create']],
                'related_pages' => ['groups' => ['list', 'show', 'create']],
            ],
            'security' => ['create' => AccessRole::FE_USER],
        ]);
    }

    public function testNestedHasOneObjectForUnregisteredTableIsRejected(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/unres-articles', 1, [
            'title'   => 'Article with an uncreatable page',
            'page_id' => ['title' => 'A brand new page'],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('UNRESOLVABLE_RELATION', $body['violations'][0]['code']);
        self::assertSame('page_id', $body['violations'][0]['propertyPath']);
        self::assertStringContainsString('pages', $body['violations'][0]['message']);
    }

    public function testNestedHasManyObjectForUnregisteredTableIsRejected(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/unres-articles', 1, [
            'title'         => 'Article with uncreatable pages',
            'related_pages' => [['title' => 'A brand new page']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('UNRESOLVABLE_RELATION', $body['violations'][0]['code']);
        self::assertSame('related_pages.0', $body['violations'][0]['propertyPath']);
    }

    public function testExistingUidsOnTheSameColumnStillWrite(): void
    {
        // Only nested *creation* needs a registered child resource; linking existing
        // records never did, and must keep working.
        $response = $this->executeApiWriteRequestAs('POST', '/_api/unres-articles', 1, [
            'title'         => 'Article linking existing pages',
            'page_id'       => 1,
            'related_pages' => [1],
        ]);

        self::assertSame(201, $response->getStatusCode());
    }
}
