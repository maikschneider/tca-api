<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that tca_api.debugMode controls 403 error message verbosity.
 *
 * articles.create requires FE_USER — an unauthenticated POST triggers a 403.
 * With debugMode: true the error body exposes the operation name instead of the
 * generic "Access Denied". The inverse (debugMode: false → "Access Denied") is
 * covered by AccessControlTest using the default site fixture.
 */
final class SiteSettingsDebugModeTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Debug' => 'config/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
    }

    public function testDebugModeExposesForbiddenOperationInBody(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', ['title' => 'Test']);

        self::assertSame(403, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertStringContainsString('create', $body['hydra:description'] ?? '');
    }

    public function testDebugModeBodyDoesNotContainGenericAccessDenied(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', ['title' => 'Test']);
        $body = $this->decodeResponseBody($response);

        self::assertStringNotContainsString('Access Denied', $body['hydra:description'] ?? '');
    }
}
