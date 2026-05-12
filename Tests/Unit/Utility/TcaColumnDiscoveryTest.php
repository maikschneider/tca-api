<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Utility;

use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TcaColumnDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the static cache before each test
        $reflection = new \ReflectionClass(TcaColumnDiscovery::class);
        $prop = $reflection->getProperty('columnNameCache');
        $prop->setValue(null, []);
    }

    #[Test]
    public function passwordColumnsAreExcluded(): void
    {
        $GLOBALS['TCA']['test_password_table'] = [
            'ctrl' => [
                'delete' => 'deleted',
                'enablecolumns' => ['disabled' => 'hidden'],
            ],
            'columns' => [
                'hidden' => ['config' => ['type' => 'check']],
                'username' => ['config' => ['type' => 'input']],
                'password' => ['config' => ['type' => 'password']],
                'email' => ['config' => ['type' => 'email']],
            ],
        ];

        $result = TcaColumnDiscovery::getExposableColumnNames('test_password_table');

        self::assertContains('username', $result);
        self::assertContains('email', $result);
        self::assertNotContains('password', $result);
        self::assertNotContains('hidden', $result);

        unset($GLOBALS['TCA']['test_password_table']);
    }

    #[Test]
    public function nonPasswordColumnsAreIncluded(): void
    {
        $GLOBALS['TCA']['test_normal_table'] = [
            'ctrl' => [],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'description' => ['config' => ['type' => 'text']],
            ],
        ];

        $result = TcaColumnDiscovery::getExposableColumnNames('test_normal_table');

        self::assertContains('title', $result);
        self::assertContains('description', $result);

        unset($GLOBALS['TCA']['test_normal_table']);
    }
}
