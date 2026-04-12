<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

final class FileUploadTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
    }

    public function testPostFileUploadReturnsUid(): void
    {
        $response = $this->executeApiUploadRequestAs(
            '/_api/files',
            1,
            'upload.txt',
            'uploaded content',
            'text/plain',
        );
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertArrayHasKey('uid', $body);
        self::assertGreaterThan(0, (int)$body['uid']);
        self::assertSame('FileUpload', $body['@type']);
    }

    public function testPostFileUploadWithoutFileReturns400(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/files', 1);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testUploadedFileUidCanBeUsedForDownloadsRelationOnCreate(): void
    {
        $uploadResponse = $this->executeApiUploadRequestAs(
            '/_api/files',
            1,
            'document.pdf',
            'pdf content',
            'application/pdf',
        );
        $uploadBody = $this->decodeResponseBody($uploadResponse);

        $articleResponse = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'Article with uploaded file',
            'downloads' => [(int)$uploadBody['uid']],
        ]);
        $articleBody = $this->decodeResponseBody($articleResponse);

        self::assertSame(201, $articleResponse->getStatusCode());
        self::assertArrayHasKey('downloads', $articleBody);
        self::assertNotEmpty($articleBody['downloads']);
        self::assertSame((int)$uploadBody['uid'], $articleBody['downloads'][0]['uid']);
    }
}
