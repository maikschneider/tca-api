<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\Security\WriteContext;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for the DataWriteService table access control (assertTableAccess).
 *
 * Verifies that writes to system-sensitive tables are blocked with a RuntimeException,
 * regardless of actor context or write mode.
 */
final class DataWriteServiceTableAccessTest extends ApiFunctionalTestCase
{
    private DataWriteService $writeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->writeService = GeneralUtility::makeInstance(DataWriteService::class);
    }

    // ── processDataMap: denied tables ────────────────────────────────────────

    public function testProcessDataMapThrowsForDeniedTable(): void
    {
        $context = WriteContext::forSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);
        $this->expectExceptionMessageMatches('/Write access denied for table "be_users"/');

        $this->writeService->processDataMap(
            ['be_users' => ['NEW_1' => ['username' => 'hacked']]],
            $context,
        );
    }

    public function testProcessDataMapThrowsForBeGroupsTable(): void
    {
        $context = WriteContext::forSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);

        $this->writeService->processDataMap(
            ['be_groups' => ['NEW_1' => ['title' => 'Injected Group']]],
            $context,
        );
    }

    public function testProcessDataMapThrowsForBeSessionsTable(): void
    {
        $context = WriteContext::forSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);

        $this->writeService->processDataMap(
            ['be_sessions' => ['NEW_1' => ['ses_id' => 'fake']]],
            $context,
        );
    }

    public function testProcessDataMapThrowsForSysLogTable(): void
    {
        $context = WriteContext::forSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);

        $this->writeService->processDataMap(
            ['sys_log' => ['NEW_1' => ['details' => 'injected']]],
            $context,
        );
    }

    public function testProcessDataMapThrowsForActingUserContextOnDeniedTable(): void
    {
        $context = WriteContext::forFrontendUser(1, 'editor');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);
        $this->expectExceptionMessageMatches('/Write access denied for table "be_users"/');

        $this->writeService->processDataMap(
            ['be_users' => ['NEW_1' => ['username' => 'hacked']]],
            $context,
        );
    }

    public function testProcessDataMapThrowsWhenAnyTableInDataMapIsDenied(): void
    {
        $context = WriteContext::forSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);

        // Mixed datamap: one allowed table, one denied table
        $this->writeService->processDataMap(
            [
                'tx_myext_domain_model_article' => ['NEW_1' => ['title' => 'OK']],
                'be_users' => ['NEW_2' => ['username' => 'hacked']],
            ],
            $context,
        );
    }

    // ── delete: denied tables ────────────────────────────────────────────────

    public function testDeleteThrowsForDeniedTable(): void
    {
        $context = WriteContext::forSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);
        $this->expectExceptionMessageMatches('/Write access denied for table "be_users"/');

        $this->writeService->delete('be_users', 1, $context);
    }

    public function testDeleteThrowsForFeSessionsTable(): void
    {
        $context = WriteContext::forSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);

        $this->writeService->delete('fe_sessions', 1, $context);
    }

    public function testDeleteThrowsForSysFilemountsTable(): void
    {
        $context = WriteContext::forSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);

        $this->writeService->delete('sys_filemounts', 1, $context);
    }

    public function testDeleteThrowsForActingUserContextOnDeniedTable(): void
    {
        $context = WriteContext::forBackendUser(1, 'admin');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);
        $this->expectExceptionMessageMatches('/Write access denied for table "be_users"/');

        $this->writeService->delete('be_users', 1, $context);
    }

    // ── Null context fallback still enforces table access ────────────────────

    public function testProcessDataMapWithNullContextStillBlocksDeniedTable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);

        $this->writeService->processDataMap(
            ['be_users' => ['NEW_1' => ['username' => 'hacked']]],
        );
    }

    public function testDeleteWithNullContextStillBlocksDeniedTable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);

        $this->writeService->delete('be_users', 1);
    }
}
