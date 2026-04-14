<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Validates the generated OpenAPI spec against the 3.1.0 specification
 * using @stoplight/spectral-cli. Skips if spectral is not installed.
 */
final class OpenApiSpecTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
    }

    public function testSpecPassesSpectralValidation(): void
    {
        $projectRoot = realpath(__DIR__ . '/../../..');
        $spectralBin = $projectRoot . '/node_modules/.bin/spectral';
        if (!is_file($spectralBin)) {
            self::markTestSkipped('spectral not installed — run npm install');
        }

        $response = $this->executeApiRequest('/_api/openapi.json');
        $tmpFile = tempnam(sys_get_temp_dir(), 'openapi-') . '.json';
        file_put_contents($tmpFile, (string)$response->getBody());

        exec(
            'cd ' . escapeshellarg($projectRoot) . ' && '
            . escapeshellarg($spectralBin) . ' lint --format pretty '
            . escapeshellarg($tmpFile) . ' 2>&1',
            $output,
            $exitCode,
        );
        unlink($tmpFile);

        self::assertSame(0, $exitCode, "Spectral validation failed:\n" . implode("\n", $output));
    }

    public function testPostToOpenApiReturns405(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/openapi.json');
        self::assertSame(405, $response->getStatusCode());
    }

    public function testArticleSchemasReflectGroupsAndValidators(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        $schemas = $body['components']['schemas'];

        $articleWriteProperties = $schemas['ArticleWrite']['properties'];
        $articleReadProperties = $schemas['ArticleRead']['properties'];

        self::assertArrayHasKey('title', $articleWriteProperties);
        self::assertSame(20, $articleWriteProperties['title']['maxLength']);
        self::assertSame(3, $articleWriteProperties['title']['minLength']);
        self::assertSame('^[\w\s]+$', $articleWriteProperties['title']['pattern']);
        self::assertContains('title', $schemas['ArticleWrite']['required']);

        self::assertArrayHasKey('color_id', $articleWriteProperties);
        self::assertArrayHasKey('categories', $articleWriteProperties);
        self::assertArrayNotHasKey('profile_photo', $articleWriteProperties);
        self::assertArrayNotHasKey('downloads', $articleWriteProperties);
        self::assertArrayNotHasKey('article_url', $articleWriteProperties);

        self::assertArrayHasKey('profile_photo', $articleReadProperties);
        self::assertArrayHasKey('downloads', $articleReadProperties);
        self::assertArrayHasKey('article_url', $articleReadProperties);
    }
}
