<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Security;

use MaikSchneider\TcaApi\Security\WriteAuditLogger;
use MaikSchneider\TcaApi\Security\WriteContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WriteAuditLoggerTest extends TestCase
{
    #[Test]
    public function logWriteLogsCorrectData(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('TCA API write operation', [
                'operation' => 'create',
                'table' => 'tx_myext_domain_model_article',
                'uid' => 'NEW_primary',
                'actor_type' => 'fe_user',
                'actor_uid' => 42,
                'actor_username' => 'johndoe',
                'credential' => '',
                'write_mode' => 'acting_user',
                'workspace' => 0,
            ]);

        $auditLogger = new WriteAuditLogger($logger);
        $context = WriteContext::forFrontendUser(42, 'johndoe');

        $auditLogger->logWrite('create', 'tx_myext_domain_model_article', 'NEW_primary', $context);
    }

    #[Test]
    public function logDeniedLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('TCA API write denied', [
                'operation' => 'write',
                'table' => 'be_users',
                'actor_type' => 'fe_user',
                'actor_uid' => 42,
                'actor_username' => 'johndoe',
                'credential' => '',
                'write_mode' => 'acting_user',
                'workspace' => 0,
                'reason' => 'Table blocked by access control policy',
            ]);

        $auditLogger = new WriteAuditLogger($logger);
        $context = WriteContext::forFrontendUser(42, 'johndoe');

        $auditLogger->logDenied('write', 'be_users', $context, 'Table blocked by access control policy');
    }

    #[Test]
    public function logWriteWithSystemContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('TCA API write operation', [
                'operation' => 'delete',
                'table' => 'tx_myext_domain_model_article',
                'uid' => 5,
                'actor_type' => 'system',
                'actor_uid' => 0,
                'actor_username' => '_tca_api_system',
                'credential' => '',
                'write_mode' => 'system_admin',
                'workspace' => 0,
            ]);

        $auditLogger = new WriteAuditLogger($logger);
        $context = WriteContext::forSystem();

        $auditLogger->logWrite('delete', 'tx_myext_domain_model_article', 5, $context);
    }

    #[Test]
    public function logWriteRecordsTheWorkspace(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('TCA API write operation', self::callback(
                static fn (array $context): bool => $context['workspace'] === 3,
            ));

        $auditLogger = new WriteAuditLogger($logger);
        $context = WriteContext::forFrontendUser(42, 'johndoe')->withWorkspace(3);

        $auditLogger->logWrite('create', 'tx_myext_domain_model_article', 'NEW_primary', $context);
    }
}
