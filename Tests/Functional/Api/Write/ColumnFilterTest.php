<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for ColumnFilterTrait write-path coverage.
 * Covers the !$config->isExplicitMode branch (default mode) and verifies
 * that password columns are excluded from writes in both default and explicit mode.
 */
final class ColumnFilterTest extends ApiFunctionalTestCase
{
    /** Default-mode color resource: no columns key → isExplicitMode = false */
    private const DEFAULT_MODE_CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_color',
            'resourceName' => 'colors-default-write',
            'resourceType' => 'Color',
            'operations' => ['list', 'show', 'create', 'update'],
            'storagePid' => 1,
        ],
        'security' => [
            'list' => AccessRole::PUBLIC,
            'show' => AccessRole::PUBLIC,
            'create' => AccessRole::FE_USER,
            'update' => AccessRole::FE_USER,
        ],
    ];

    /** Explicit-mode config with secret_column listed in groups — must still be excluded */
    private const EXPLICIT_PASSWORD_CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_color',
            'resourceName' => 'colors-explicit-password',
            'resourceType' => 'Color',
            'operations' => ['list', 'show', 'create', 'update'],
            'storagePid' => 1,
        ],
        'columns' => [
            'name' => ['groups' => ['list', 'show', 'create', 'update']],
            'secret_column' => ['groups' => ['create', 'update']],
        ],
        'security' => [
            'list' => AccessRole::PUBLIC,
            'show' => AccessRole::PUBLIC,
            'create' => AccessRole::FE_USER,
            'update' => AccessRole::FE_USER,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
    }

    // ── Default mode (isExplicitMode = false) — basic write operations ────────

    public function testDefaultModePostReturns201(): void
    {
        $this->registerResource('colors-default-write', self::DEFAULT_MODE_CONFIG);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/colors-default-write', 1, [
            'name' => 'Green',
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testDefaultModePostPersistsFieldInDatabase(): void
    {
        $this->registerResource('colors-default-write', self::DEFAULT_MODE_CONFIG);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/colors-default-write', 1, [
            'name' => 'Persisted Green',
        ]);
        $uid = $this->decodeResponseBody($response)['uid'];

        $row = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['name'], 'tx_myext_domain_model_color', ['uid' => $uid])
            ->fetchAssociative() ?: [];

        self::assertSame('Persisted Green', $row['name']);
    }

    public function testDefaultModePatchReturns200(): void
    {
        $this->registerResource('colors-default-write', self::DEFAULT_MODE_CONFIG);

        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/colors-default-write/2', 1, [
            'name' => 'Updated Blue',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testDefaultModePatchUpdatesFieldInDatabase(): void
    {
        $this->registerResource('colors-default-write', self::DEFAULT_MODE_CONFIG);

        $this->executeApiWriteRequestAs('PATCH', '/_api/colors-default-write/2', 1, [
            'name' => 'Deep Blue',
        ]);

        $row = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['name'], 'tx_myext_domain_model_color', ['uid' => 2])
            ->fetchAssociative() ?: [];

        self::assertSame('Deep Blue', $row['name']);
    }

    // ── Password column omission — default mode ───────────────────────────────

    public function testDefaultModePasswordColumnNotWrittenOnPatch(): void
    {
        $this->registerResource('colors-default-write', self::DEFAULT_MODE_CONFIG);

        $original = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['secret_column'], 'tx_myext_domain_model_color', ['uid' => 1])
            ->fetchAssociative() ?: [];

        $this->executeApiWriteRequestAs('PATCH', '/_api/colors-default-write/1', 1, [
            'name' => 'Red Updated',
            'secret_column' => 'hack_attempt',
        ]);

        $after = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['secret_column'], 'tx_myext_domain_model_color', ['uid' => 1])
            ->fetchAssociative() ?: [];

        self::assertSame($original['secret_column'], $after['secret_column']);
    }

    public function testDefaultModePasswordColumnNotWrittenOnPost(): void
    {
        $this->registerResource('colors-default-write', self::DEFAULT_MODE_CONFIG);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/colors-default-write', 1, [
            'name' => 'Purple',
            'secret_column' => 'stolen_hash',
        ]);
        $uid = $this->decodeResponseBody($response)['uid'];

        $row = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['secret_column'], 'tx_myext_domain_model_color', ['uid' => $uid])
            ->fetchAssociative() ?: [];

        self::assertSame('', (string)($row['secret_column'] ?? ''));
    }

    // ── Password column omission — explicit mode ──────────────────────────────

    public function testExplicitModePasswordColumnNotWrittenEvenWhenInGroups(): void
    {
        $this->registerResource('colors-explicit-password', self::EXPLICIT_PASSWORD_CONFIG);

        $original = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['secret_column'], 'tx_myext_domain_model_color', ['uid' => 1])
            ->fetchAssociative() ?: [];

        $this->executeApiWriteRequestAs('PATCH', '/_api/colors-explicit-password/1', 1, [
            'name' => 'Red',
            'secret_column' => 'explicit_hack',
        ]);

        $after = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['secret_column'], 'tx_myext_domain_model_color', ['uid' => 1])
            ->fetchAssociative() ?: [];

        self::assertSame($original['secret_column'], $after['secret_column']);
    }
}
