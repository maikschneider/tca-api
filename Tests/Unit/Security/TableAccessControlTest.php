<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Security;

use MaikSchneider\TcaApi\Security\TableAccessControl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableAccessControlTest extends TestCase
{
    #[Test]
    public function deniedSystemTablesAreBlocked(): void
    {
        $control = new TableAccessControl();

        self::assertFalse($control->isWriteAllowed('be_users'));
        self::assertFalse($control->isWriteAllowed('be_groups'));
        self::assertFalse($control->isWriteAllowed('be_sessions'));
        self::assertFalse($control->isWriteAllowed('fe_sessions'));
        self::assertFalse($control->isWriteAllowed('sys_filemounts'));
        self::assertFalse($control->isWriteAllowed('sys_log'));
    }

    #[Test]
    public function regularTablesAreAllowedByDefault(): void
    {
        $control = new TableAccessControl();

        self::assertTrue($control->isWriteAllowed('tx_news_domain_model_news'));
        self::assertTrue($control->isWriteAllowed('tx_myext_domain_model_article'));
        self::assertTrue($control->isWriteAllowed('fe_users'));
        self::assertTrue($control->isWriteAllowed('pages'));
    }

    #[Test]
    public function customDenyListBlocksAdditionalTables(): void
    {
        $control = new TableAccessControl([], ['pages', 'tt_content']);

        self::assertFalse($control->isWriteAllowed('pages'));
        self::assertFalse($control->isWriteAllowed('tt_content'));
        self::assertTrue($control->isWriteAllowed('tx_news_domain_model_news'));
    }

    #[Test]
    public function allowListRestrictsToExplicitTables(): void
    {
        $control = new TableAccessControl(['tx_myext_domain_model_article']);

        self::assertTrue($control->isWriteAllowed('tx_myext_domain_model_article'));
        self::assertFalse($control->isWriteAllowed('tx_news_domain_model_news'));
        self::assertFalse($control->isWriteAllowed('pages'));
    }

    #[Test]
    public function denyListTakesPrecedenceOverAllowList(): void
    {
        // be_users is in the built-in deny list — even if explicitly allowed
        $control = new TableAccessControl(['be_users', 'tx_myext_domain_model_article']);

        self::assertFalse($control->isWriteAllowed('be_users'));
        self::assertTrue($control->isWriteAllowed('tx_myext_domain_model_article'));
    }

    #[Test]
    public function assertWriteAllowedThrowsOnDeniedTable(): void
    {
        $control = new TableAccessControl();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1713680400);
        $control->assertWriteAllowed('be_users');
    }

    #[Test]
    public function assertWriteAllowedPassesForRegularTable(): void
    {
        $control = new TableAccessControl();

        // Should not throw
        $control->assertWriteAllowed('tx_myext_domain_model_article');
        self::assertTrue(true);
    }
}
