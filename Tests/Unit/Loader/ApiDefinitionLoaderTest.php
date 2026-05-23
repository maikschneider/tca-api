<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Loader;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;

final class ApiDefinitionLoaderTest extends TestCase
{
    private string $tmpBase;
    private ApiRegistry $registry;

    /** @var list<string> */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBase = sys_get_temp_dir() . '/tca-api-loader-test-' . uniqid('', true);
        $this->registry = new ApiRegistry();
        $this->registry->reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDir($dir);
        }
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeLoader(PackageInterface ...$packages): ApiDefinitionLoader
    {
        $pm = $this->createMock(PackageManager::class);
        $pm->method('getActivePackages')->willReturn($packages);

        $cache = $this->createMock(PhpFrontend::class);
        $cache->method('require')->willReturn(false);
        $cache->method('set')->willReturn(true);

        return new ApiDefinitionLoader($pm, $cache, $this->registry);
    }

    /**
     * Creates a temp package directory, writes the given files (relative paths →
     * PHP content), and returns a mock PackageInterface for that directory.
     *
     * @param array<string, string> $files  relative path → file content
     */
    private function makePackage(array $files): PackageInterface
    {
        $dir = $this->tmpBase . '/' . uniqid('pkg-', true);
        $this->tmpDirs[] = $dir;

        foreach ($files as $relPath => $content) {
            $abs = $dir . '/' . $relPath;
            if (!is_dir(dirname($abs))) {
                mkdir(dirname($abs), 0777, true);
            }
            file_put_contents($abs, $content);
        }

        $pkg = $this->createMock(PackageInterface::class);
        $pkg->method('getPackagePath')->willReturn($dir . '/');
        return $pkg;
    }

    private function baseConfig(string $resourceName, array $extra = []): string
    {
        $columns = isset($extra['columns']) ? var_export($extra['columns'], true) : '[]';
        $security = isset($extra['security']) ? var_export($extra['security'], true) : '[]';
        $operations = isset($extra['operations'])
            ? var_export($extra['operations'], true)
            : "['list', 'show', 'create', 'update', 'delete']";

        $parts = [];
        $parts[] = "'general' => ['table' => 'tx_test_table', 'resourceName' => '{$resourceName}', 'resourceType' => 'TestResource', 'storagePid' => 1]";
        if ($extra['columns'] ?? false) {
            $parts[] = "'columns' => {$columns}";
        }
        if ($extra['security'] ?? false) {
            $parts[] = "'security' => {$security}";
        }
        if ($extra['operations'] ?? false) {
            $parts[] = "'general' => ['table' => 'tx_test_table', 'resourceName' => '{$resourceName}', 'resourceType' => 'TestResource', 'storagePid' => 1, 'operations' => {$operations}]";
            // Replace the general entry
            $parts[0] = "'general' => ['table' => 'tx_test_table', 'resourceName' => '{$resourceName}', 'resourceType' => 'TestResource', 'storagePid' => 1, 'operations' => {$operations}]";
            array_pop($parts);
        }
        $body = implode(', ', $parts);
        return "<?php\ndeclare(strict_types=1);\nreturn [{$body}];\n";
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    #[Test]
    public function testBaseConfigPopulatesGlobalAndRegistry(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/articles.php' => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'articles', 'resourceType' => 'Article', 'storagePid' => 1]];\n",
        ]);

        $this->makeLoader($pkg)->load();

        self::assertArrayHasKey('articles', $GLOBALS['TCA_API'] ?? []);
        self::assertNotNull($this->registry->get('articles'));
    }

    #[Test]
    public function testDuplicateBaseConfigLastWriteWinsNoException(): void
    {
        $pkg1 = $this->makePackage([
            'Configuration/TcaApi/articles.php' => "<?php\nreturn ['general' => ['table' => 'tx_first', 'resourceName' => 'articles', 'resourceType' => 'First', 'storagePid' => 1]];\n",
        ]);
        $pkg2 = $this->makePackage([
            'Configuration/TcaApi/articles.php' => "<?php\nreturn ['general' => ['table' => 'tx_second', 'resourceName' => 'articles', 'resourceType' => 'Second', 'storagePid' => 1]];\n",
        ]);

        $this->makeLoader($pkg1, $pkg2)->load();

        $def = $this->registry->get('articles');
        self::assertNotNull($def);
        self::assertSame('tx_second', $def->table);
    }

    #[Test]
    public function testOverrideAddsColumn(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'            => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1]];\n",
            'Configuration/TcaApi/Overrides/add.php'  => "<?php\n\$GLOBALS['TCA_API']['res']['columns']['extra_col'] = ['type' => 'string'];\n",
        ]);

        $this->makeLoader($pkg)->load();

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertArrayHasKey('extra_col', $def->columns);
    }

    #[Test]
    public function testOverrideRemovesColumn(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'             => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1], 'columns' => ['title' => ['type' => 'string']]];\n",
            'Configuration/TcaApi/Overrides/drop.php'  => "<?php\nunset(\$GLOBALS['TCA_API']['res']['columns']['title']);\n",
        ]);

        $this->makeLoader($pkg)->load();

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertArrayNotHasKey('title', $def->columns);
    }

    #[Test]
    public function testOverrideChangesSecurityRole(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'              => "<?php\nuse MaikSchneider\\TcaApi\\Enum\\AccessRole;\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1, 'operations' => ['list', 'show', 'delete']], 'security' => ['delete' => AccessRole::BE_ADMIN]];\n",
            'Configuration/TcaApi/Overrides/sec.php'    => "<?php\nuse MaikSchneider\\TcaApi\\Enum\\AccessRole;\n\$GLOBALS['TCA_API']['res']['security']['delete'] = AccessRole::FE_USER;\n",
        ]);

        $this->makeLoader($pkg)->load();

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertSame(AccessRole::FE_USER, $def->security['delete']);
    }

    #[Test]
    public function testOverrideChangesOperations(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'             => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1, 'operations' => ['list', 'show', 'create', 'update', 'delete']]];\n",
            'Configuration/TcaApi/Overrides/ops.php'   => "<?php\n\$GLOBALS['TCA_API']['res']['general']['operations'] = ['list', 'show'];\n",
        ]);

        $this->makeLoader($pkg)->load();

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertSame(['list', 'show'], $def->operations);
    }

    #[Test]
    public function testOverrideForMissingResourceCreatesIt(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/Overrides/new.php' => "<?php\n\$GLOBALS['TCA_API']['brand-new'] = ['general' => ['table' => 'tx_test', 'resourceName' => 'brand-new', 'resourceType' => 'BrandNew', 'storagePid' => 1]];\n",
        ]);

        $this->makeLoader($pkg)->load();

        self::assertNotNull($this->registry->get('brand-new'));
    }

    #[Test]
    public function testTwoOverrideFilesRunAlphabetically(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'                  => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1, 'operations' => ['list', 'show', 'create', 'update', 'delete']]];\n",
            'Configuration/TcaApi/Overrides/a_override.php' => "<?php\n\$GLOBALS['TCA_API']['res']['general']['operations'] = ['list'];\n",
            'Configuration/TcaApi/Overrides/b_override.php' => "<?php\n\$GLOBALS['TCA_API']['res']['general']['operations'] = ['list', 'show'];\n",
        ]);

        $this->makeLoader($pkg)->load();

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertSame(['list', 'show'], $def->operations);
    }

    #[Test]
    public function testExplicitModePreservedWhenOverrideAddsColumnWithoutGroups(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'              => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1], 'columns' => ['title' => ['type' => 'string', 'groups' => ['list']]]];\n",
            'Configuration/TcaApi/Overrides/add.php'    => "<?php\n\$GLOBALS['TCA_API']['res']['columns']['no_group_col'] = ['type' => 'string'];\n",
        ]);

        $this->makeLoader($pkg)->load();

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertTrue($def->isExplicitMode);
        self::assertArrayHasKey('no_group_col', $def->columns);
        self::assertFalse($def->columns['no_group_col']->hasGroups());
    }

    #[Test]
    public function testImplicitModeSwitchesWhenOverrideAddsColumnWithGroups(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'           => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1]];\n",
            'Configuration/TcaApi/Overrides/add.php' => "<?php\n\$GLOBALS['TCA_API']['res']['columns']['title'] = ['type' => 'string', 'groups' => ['list']];\n",
        ]);

        $this->makeLoader($pkg)->load();

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertTrue($def->isExplicitMode);
    }

    #[Test]
    public function testMissingOverridesDirIsSkipped(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php' => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1]];\n",
        ]);

        // Should not throw even though Configuration/TcaApi/Overrides/ does not exist
        $this->makeLoader($pkg)->load();

        self::assertNotNull($this->registry->get('res'));
    }

    #[Test]
    public function testBaseFileAtDepthOneIsIgnored(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/subdir/deep.php' => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'deep-resource', 'resourceType' => 'Deep', 'storagePid' => 1]];\n",
        ]);

        $this->makeLoader($pkg)->load();

        self::assertNull($this->registry->get('deep-resource'));
    }

    #[Test]
    public function testOverrideDirFileNotIncludedInBasePass(): void
    {
        // The override file creates a new resource but must only be picked up in pass 2,
        // not treated as a base config that would double-register under the filename key.
        $pkg = $this->makePackage([
            'Configuration/TcaApi/Overrides/standalone.php' => "<?php\n\$GLOBALS['TCA_API']['override-only'] = ['general' => ['table' => 'tx_test', 'resourceName' => 'override-only', 'resourceType' => 'OO', 'storagePid' => 1]];\n",
        ]);

        $this->makeLoader($pkg)->load();

        // 'override-only' is registered via pass-2 side-effect
        self::assertNotNull($this->registry->get('override-only'));
        // 'standalone' (filename without .php) must NOT be a separate base entry
        self::assertNull($this->registry->get('standalone'));
    }
}
