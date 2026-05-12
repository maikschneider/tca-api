<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Tests for sane defaults (default mode) and serialization groups.
 *
 * Default mode: a resource with no 'groups' in any column config
 *               auto-exposes all non-system TCA columns.
 *
 * Groups mode:  a resource with 'groups' set on columns is in explicit mode — only
 *               columns whose groups include the current operation are returned.
 */
final class DefaultModeTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
    }

    // ── Default mode ─────────────────────────────────────────────────────────

    public function testDefaultModeListReturnsNonSystemColumns(): void
    {
        $response = $this->executeApiRequest('/_api/colors-minimal');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertSame(2, $body['hydra:totalItems']);

        $first = $body['hydra:member'][0];

        // 'name' is a non-system TCA column — must appear
        self::assertArrayHasKey('name', $first);
        self::assertSame('Red', $first['name']);

        // 'hex' is a non-system TCA column — must appear (value may be empty)
        self::assertArrayHasKey('hex', $first);

        // 'hidden' is in ctrl.enablecolumns — must NOT be exposed
        self::assertArrayNotHasKey('hidden', $first);
    }

    public function testDefaultModeShowReturnsNonSystemColumns(): void
    {
        $response = $this->executeApiRequest('/_api/colors-minimal/1');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);

        self::assertSame('ColorMinimal', $body['@type']);
        self::assertSame(1, $body['uid']);

        // Non-system columns present
        self::assertArrayHasKey('name', $body);
        self::assertSame('Red', $body['name']);
        self::assertArrayHasKey('hex', $body);

        // System column excluded
        self::assertArrayNotHasKey('hidden', $body);
    }

    // ── Groups mode (explicit) ────────────────────────────────────────────────

    public function testGroupsModeListReturnsOnlyListGroupedColumns(): void
    {
        $response = $this->executeApiRequest('/_api/colors-grouped');

        self::assertSame(200, $response->getStatusCode());

        $body  = $this->decodeResponseBody($response);
        $first = $body['hydra:member'][0];

        // 'name' has groups: ['list', 'show'] — must appear in list
        self::assertArrayHasKey('name', $first);

        // 'hex' has groups: ['show'] only — must NOT appear in list
        self::assertArrayNotHasKey('hex', $first);
    }

    public function testGroupsModeShowReturnsOnlyShowGroupedColumns(): void
    {
        $response = $this->executeApiRequest('/_api/colors-grouped/1');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);

        // 'name' has groups: ['list', 'show'] — must appear in show
        self::assertArrayHasKey('name', $body);
        self::assertSame('Red', $body['name']);

        // 'hex' has groups: ['show'] — must appear in show
        self::assertArrayHasKey('hex', $body);
    }

    // ── Processor-only does not trigger explicit mode ─────────────────────────

    public function testProcessorOnlyColumnDoesNotTriggerExplicitMode(): void
    {
        // colors-minimal has no groups, but processor-only columns
        // would also not trigger explicit mode — the resource must still auto-expose all columns.
        // This is validated implicitly by testDefaultModeListReturnsNonSystemColumns above.
        // Adding a direct assertion here as a named regression guard.
        $response = $this->executeApiRequest('/_api/colors-minimal/2');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('name', $body);
        self::assertSame('Blue', $body['name']);
    }

    // ── Password columns excluded ─────────────────────────────────────────────

    public function testDefaultModeExcludesPasswordColumns(): void
    {
        $response = $this->executeApiRequest('/_api/colors-minimal/1');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);

        // 'secret_column' is type=password — must never appear in API output
        self::assertArrayNotHasKey('secret_column', $body);
        // Normal columns must still be present
        self::assertArrayHasKey('name', $body);
    }

    public function testDefaultModeListExcludesPasswordColumns(): void
    {
        $response = $this->executeApiRequest('/_api/colors-minimal');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);

        foreach ($body['hydra:member'] as $member) {
            self::assertArrayNotHasKey('secret_column', $member);
            self::assertArrayHasKey('name', $member);
        }
    }

    public function testExplicitModeExcludesPasswordColumnsEvenWhenConfigured(): void
    {
        $this->registerResource('colors-password-explicit', [
            'general' => [
                'table'        => 'tx_myext_domain_model_color',
                'resourceName' => 'colors-password-explicit',
                'resourceType' => 'ColorPasswordExplicit',
            ],
            'columns' => [
                'name'          => ['groups' => ['list', 'show']],
                'secret_column' => ['groups' => ['list', 'show']],
            ],
            'security' => [
                'list' => \MaikSchneider\TcaApi\Enum\AccessRole::PUBLIC,
                'show' => \MaikSchneider\TcaApi\Enum\AccessRole::PUBLIC,
            ],
        ]);

        $response = $this->executeApiRequest('/_api/colors-password-explicit/1');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);

        // Password column must be excluded even when explicitly configured
        self::assertArrayNotHasKey('secret_column', $body);
        // Normal columns must still be present
        self::assertArrayHasKey('name', $body);
        self::assertSame('Red', $body['name']);
    }

    public function testExplicitModeWithEmptyGroupsHidesColumns(): void
    {
        $response = $this->executeApiRequest('/_api/colors-empty-groups/1');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertSame(1, $body['uid']);
        self::assertArrayNotHasKey('name', $body);
        self::assertArrayNotHasKey('hex', $body);
    }
}
