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
        if (!$projectRoot || !is_file($spectralBin)) {
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
        // profile_photo and downloads are now writable (upload config added)
        self::assertArrayHasKey('profile_photo', $articleWriteProperties);
        self::assertArrayHasKey('downloads', $articleWriteProperties);
        // article_url remains read-only (no 'create'/'update' in groups)
        self::assertArrayNotHasKey('article_url', $articleWriteProperties);

        self::assertArrayHasKey('profile_photo', $articleReadProperties);
        self::assertArrayHasKey('downloads', $articleReadProperties);
        self::assertArrayHasKey('article_url', $articleReadProperties);

        // Multipart schema is generated for resources with uploadable columns
        self::assertArrayHasKey('ArticleWriteMultipart', $schemas);
        $multipartProperties = $schemas['ArticleWriteMultipart']['properties'];
        self::assertArrayHasKey('profile_photo', $multipartProperties);
        self::assertSame('string', $multipartProperties['profile_photo']['type']);
        self::assertSame('binary', $multipartProperties['profile_photo']['format']);
        self::assertArrayHasKey('downloads', $multipartProperties);
        self::assertSame('binary', $multipartProperties['downloads']['format']);
    }

    public function testSpecContainsHydraErrorSchema(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        $schemas = $body['components']['schemas'];

        self::assertArrayHasKey('HydraError', $schemas);
        self::assertSame('object', $schemas['HydraError']['type']);
        self::assertArrayHasKey('hydra:title', $schemas['HydraError']['properties']);
        self::assertArrayHasKey('hydra:description', $schemas['HydraError']['properties']);
        self::assertContains('@type', $schemas['HydraError']['required']);
        self::assertContains('hydra:title', $schemas['HydraError']['required']);
        self::assertContains('hydra:description', $schemas['HydraError']['required']);
    }

    public function testSpecContainsValidationErrorSchema(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        $schemas = $body['components']['schemas'];

        self::assertArrayHasKey('ValidationError', $schemas);
        self::assertArrayHasKey('violations', $schemas['ValidationError']['properties']);
        self::assertSame('array', $schemas['ValidationError']['properties']['violations']['type']);
    }

    public function testErrorResponsesReferenceHydraErrorSchema(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        $paths = $body['paths'];

        // Check list operation has error responses
        $listResponses = $paths['/_api/articles']['get']['responses'];
        self::assertArrayHasKey('400', $listResponses);
        self::assertArrayHasKey('403', $listResponses);
        self::assertArrayHasKey('500', $listResponses);
        self::assertSame(
            '#/components/schemas/HydraError',
            $listResponses['403']['content']['application/ld+json']['schema']['$ref'],
        );

        // Check create operation has error responses
        $createResponses = $paths['/_api/articles']['post']['responses'];
        self::assertArrayHasKey('400', $createResponses);
        self::assertArrayHasKey('403', $createResponses);
        self::assertArrayHasKey('409', $createResponses);
        self::assertArrayHasKey('422', $createResponses);
        self::assertArrayHasKey('500', $createResponses);
    }

    public function testReadOnlyResourceHasNoWriteSchema(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        $schemas = $body['components']['schemas'];

        // Color resource has only list/show operations — no write schemas should be generated
        self::assertArrayNotHasKey('ColorWrite', $schemas);
        self::assertArrayNotHasKey('ColorWriteMultipart', $schemas);
    }

    public function testFieldsQueryParamUsesArraySchema(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);

        // Check the show operation fields parameter
        $showParams = $body['paths']['/_api/articles/{uid}']['get']['parameters'];
        $fieldsParam = null;
        foreach ($showParams as $param) {
            if ($param['name'] === 'fields') {
                $fieldsParam = $param;
                break;
            }
        }

        self::assertNotNull($fieldsParam, 'fields parameter should exist on show operation');
        self::assertSame('array', $fieldsParam['schema']['type']);
        self::assertSame('string', $fieldsParam['schema']['items']['type']);
        self::assertArrayNotHasKey('style', $fieldsParam);
    }
}
