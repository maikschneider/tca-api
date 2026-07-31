<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Regression tests for issue #172.
 *
 * A sys_file_reference whose 'crop' column holds malformed JSON must not fail the
 * whole API response. Both shapes covered here decode to a scalar rather than to a
 * map of variant ids, which is what made array_keys() yield the integer key 0 that
 * CropVariantCollection::getCropArea(string $id) then rejected with a TypeError.
 *
 * The crop payloads are written by the test rather than carried in the CSV fixture:
 * the exact bytes matter, and CSV quoting obscures them.
 */
final class MalformedCropTest extends ApiFunctionalTestCase
{
    /** Reference attached to article 413. */
    private const REF_DOUBLE_ENCODED = 11;

    /** Reference attached to article 414. */
    private const REF_SCALAR = 12;

    private const BASE_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'articles-image',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show'],
            'storagePid'   => 1,
        ],
        'columns' => [
            'profile_photo' => ['groups' => ['list', 'show']],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
        'security' => [
            'list' => AccessRole::PUBLIC,
            'show' => AccessRole::PUBLIC,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_files.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_metadata.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference_malformed_crop.csv');

        // The reported payload: a valid crop document that was json_encode()d twice,
        // so the stored value is a JSON *string* wrapping a JSON document.
        $this->setCrop(self::REF_DOUBLE_ENCODED, json_encode(
            '{"square":{"cropArea":{"x":0.1071543150465191,"y":0.028392685274302214,'
            . '"height":0.6032242540904716,"width":0.8042990054539622},'
            . '"selectedRatio":"","focusArea":null}}',
            JSON_THROW_ON_ERROR,
        ));

        // Degenerate case from the same class of defect: a bare JSON scalar.
        $this->setCrop(self::REF_SCALAR, '123');
    }

    public function testDoubleEncodedCropDecodesToAScalarInTheFixture(): void
    {
        // Guards the fixture itself: if this stops being a scalar, the tests below
        // would pass without exercising the regression at all.
        $stored = $this->getCrop(self::REF_DOUBLE_ENCODED);

        self::assertIsString(json_decode($stored, true));
    }

    public function testDoubleEncodedCropDoesNotFailTheResponse(): void
    {
        $this->registerResource('articles-image', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/articles-image/413');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testDoubleEncodedCropYieldsEmptyCropVariants(): void
    {
        $this->registerResource('articles-image', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/articles-image/413');
        $body     = $this->decodeResponseBody($response);

        self::assertIsArray($body['profile_photo']);
        self::assertSame([], $body['profile_photo']['cropVariants']);
    }

    public function testDoubleEncodedCropStillSerializesTheImageItself(): void
    {
        $this->registerResource('articles-image', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/articles-image/413');
        $body     = $this->decodeResponseBody($response);

        // Degrading the crop must not cost the file its own representation — this is
        // what distinguishes the real fix from the ProcessorGuard net catching it.
        self::assertArrayHasKey('publicUrl', $body['profile_photo']);
        self::assertIsString($body['profile_photo']['publicUrl']);
    }

    public function testScalarCropDoesNotFailTheResponse(): void
    {
        $this->registerResource('articles-image', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/articles-image/414');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['profile_photo']['cropVariants']);
        self::assertIsString($body['profile_photo']['publicUrl']);
    }

    public function testCollectionContainingAMalformedCropStillReturnsAllRecords(): void
    {
        $this->registerResource('articles-image', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/articles-image');
        $body     = $this->decodeResponseBody($response);

        // The point of the fix: one corrupt row must not take down the collection.
        self::assertSame(200, $response->getStatusCode());
        self::assertGreaterThanOrEqual(2, $body['hydra:totalItems']);
    }

    private function setCrop(int $referenceUid, string $crop): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['crop' => $crop], ['uid' => $referenceUid]);
    }

    private function getCrop(int $referenceUid): string
    {
        return (string)$this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->fetchOne('SELECT crop FROM sys_file_reference WHERE uid = ?', [$referenceUid]);
    }
}
