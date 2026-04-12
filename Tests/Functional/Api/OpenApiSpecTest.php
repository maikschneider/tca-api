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
}
