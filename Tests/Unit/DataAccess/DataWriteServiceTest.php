<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\DataAccess;

use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Unit tests for DataWriteService — verifies that update() and delete()
 * throw RuntimeException when DataHandler reports errors.
 */
final class DataWriteServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::flushInternalRuntimeCaches();
        if (isset($GLOBALS['BE_USER'])) {
            unset($GLOBALS['BE_USER']);
        }
        parent::tearDown();
    }

    #[Test]
    public function updateThrowsExceptionOnDataHandlerError(): void
    {
        $mockUser = $this->createMock(BackendUserAuthentication::class);
        GeneralUtility::addInstance(BackendUserAuthentication::class, $mockUser);

        $mockDataHandler = $this->createMock(DataHandler::class);
        $mockDataHandler->errorLog = ['Record could not be updated'];
        GeneralUtility::addInstance(DataHandler::class, $mockDataHandler);

        $service = new DataWriteService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DataHandler update failed: Record could not be updated');
        $service->update('tx_myext_domain_model_article', 1, ['title' => 'test']);
    }

    #[Test]
    public function deleteThrowsExceptionOnDataHandlerError(): void
    {
        $mockUser = $this->createMock(BackendUserAuthentication::class);
        GeneralUtility::addInstance(BackendUserAuthentication::class, $mockUser);

        $mockDataHandler = $this->createMock(DataHandler::class);
        $mockDataHandler->errorLog = ['Record could not be deleted'];
        GeneralUtility::addInstance(DataHandler::class, $mockDataHandler);

        $service = new DataWriteService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DataHandler delete failed: Record could not be deleted');
        $service->delete('tx_myext_domain_model_article', 1);
    }

    #[Test]
    public function updateDoesNotThrowWhenNoErrors(): void
    {
        $mockUser = $this->createMock(BackendUserAuthentication::class);
        GeneralUtility::addInstance(BackendUserAuthentication::class, $mockUser);

        $mockDataHandler = $this->createMock(DataHandler::class);
        $mockDataHandler->errorLog = [];
        GeneralUtility::addInstance(DataHandler::class, $mockDataHandler);

        $service = new DataWriteService();
        $service->update('tx_myext_domain_model_article', 1, ['title' => 'test']);

        // No exception means success
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function deleteDoesNotThrowWhenNoErrors(): void
    {
        $mockUser = $this->createMock(BackendUserAuthentication::class);
        GeneralUtility::addInstance(BackendUserAuthentication::class, $mockUser);

        $mockDataHandler = $this->createMock(DataHandler::class);
        $mockDataHandler->errorLog = [];
        GeneralUtility::addInstance(DataHandler::class, $mockDataHandler);

        $service = new DataWriteService();
        $service->delete('tx_myext_domain_model_article', 1);

        // No exception means success
        $this->addToAssertionCount(1);
    }
}
