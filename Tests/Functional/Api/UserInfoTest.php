<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for the userinfo endpoint (general.type = 'userinfo').
 *
 * Uses the existing fe_users fixture (uid=1 "editor", uid=2 "other").
 */
final class UserInfoTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');
    }

    private function registerUserinfoResource(array $columnOverrides = []): void
    {
        ApiRegistry::register('me', [
            'general' => [
                'type'         => 'userinfo',
                'table'        => 'fe_users',
                'resourceName' => 'me',
                'resourceType' => 'FeUser',
            ],
            'columns' => array_merge([
                'username' => ['readable' => true],
            ], $columnOverrides),
        ]);
    }

    public function testUnauthenticatedRequestReturnsForbidden(): void
    {
        $this->registerUserinfoResource();

        $response = $this->executeApiRequest('/_api/me');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testNonGetMethodReturnsMethodNotAllowed(): void
    {
        $this->registerUserinfoResource();

        $response = $this->executeApiWriteRequest('POST', '/_api/me');

        self::assertSame(405, $response->getStatusCode());
    }

    public function testAuthenticatedRequestReturnsCurrentUser(): void
    {
        $this->registerUserinfoResource();

        $response = $this->executeApiRequestAs('/_api/me', 1);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('FeUser', $body['@type']);
        self::assertSame(1, $body['uid']);
        self::assertSame('editor', $body['username']);
    }

    public function testResponseContainsJsonLdFields(): void
    {
        $this->registerUserinfoResource();

        $response = $this->executeApiRequestAs('/_api/me', 1);
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('@id', $body);
        self::assertArrayHasKey('@type', $body);
        self::assertStringStartsWith('/_api/me/', $body['@id']);
    }

    public function testUnreadableColumnsAreNotReturned(): void
    {
        $this->registerUserinfoResource();

        $response = $this->executeApiRequestAs('/_api/me', 1);
        $body     = $this->decodeResponseBody($response);

        // password is not configured as readable
        self::assertArrayNotHasKey('password', $body);
    }

    public function testDifferentUserSeesOwnData(): void
    {
        $this->registerUserinfoResource();

        $response = $this->executeApiRequestAs('/_api/me', 2);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $body['uid']);
        self::assertSame('other', $body['username']);
    }
}
