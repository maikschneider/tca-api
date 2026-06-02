<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Loader;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use MaikSchneider\TcaApi\Loader\TcaValidatorDeriver;
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

    /**
     * Calls the private collectDefinitions() on a loader built with the given
     * packages, then registers the resulting DTOs in $this->registry.
     *
     * Uses reflection so tests never touch PackageDependentCacheIdentifier
     * (which requires a fully initialised TYPO3 Environment).
     */
    private function loadPackages(PackageInterface ...$packages): array
    {
        $pm = $this->createMock(PackageManager::class);
        $pm->method('getActivePackages')->willReturn($packages);

        $cache  = $this->createMock(PhpFrontend::class);
        $deriver = new TcaValidatorDeriver();
        $loader = new ApiDefinitionLoader($pm, $cache, $this->registry, $deriver);

        $method = new \ReflectionMethod($loader, 'collectDefinitions');
        /** @var array<string, array<mixed>> $rawConfigs */
        $rawConfigs = $method->invoke($loader);

        foreach ($rawConfigs as $resourceName => $config) {
            $this->registry->register($resourceName, ApiDefinition::fromArray($config));
        }

        return $rawConfigs;
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

        $this->loadPackages($pkg);

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

        $this->loadPackages($pkg1, $pkg2);

        $def = $this->registry->get('articles');
        self::assertNotNull($def);
        self::assertSame('tx_second', $def->table);
    }

    #[Test]
    public function testOverrideAddsColumn(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'           => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1]];\n",
            'Configuration/TcaApi/Overrides/add.php' => "<?php\n\$GLOBALS['TCA_API']['res']['columns']['extra_col'] = ['type' => 'string'];\n",
        ]);

        $this->loadPackages($pkg);

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertArrayHasKey('extra_col', $def->columns);
    }

    #[Test]
    public function testOverrideRemovesColumn(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'            => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1], 'columns' => ['title' => ['type' => 'string']]];\n",
            'Configuration/TcaApi/Overrides/drop.php' => "<?php\nunset(\$GLOBALS['TCA_API']['res']['columns']['title']);\n",
        ]);

        $this->loadPackages($pkg);

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertArrayNotHasKey('title', $def->columns);
    }

    #[Test]
    public function testOverrideChangesSecurityRole(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'           => "<?php\nuse MaikSchneider\\TcaApi\\Enum\\AccessRole;\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1, 'operations' => ['list', 'show', 'delete']], 'security' => ['delete' => AccessRole::BE_ADMIN]];\n",
            'Configuration/TcaApi/Overrides/sec.php' => "<?php\nuse MaikSchneider\\TcaApi\\Enum\\AccessRole;\n\$GLOBALS['TCA_API']['res']['security']['delete'] = AccessRole::FE_USER;\n",
        ]);

        $this->loadPackages($pkg);

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertSame(AccessRole::FE_USER, $def->security['delete']);
    }

    #[Test]
    public function testOverrideChangesOperations(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'           => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1, 'operations' => ['list', 'show', 'create', 'update', 'delete']]];\n",
            'Configuration/TcaApi/Overrides/ops.php' => "<?php\n\$GLOBALS['TCA_API']['res']['general']['operations'] = ['list', 'show'];\n",
        ]);

        $this->loadPackages($pkg);

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

        $this->loadPackages($pkg);

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

        $this->loadPackages($pkg);

        $def = $this->registry->get('res');
        self::assertNotNull($def);
        self::assertSame(['list', 'show'], $def->operations);
    }

    #[Test]
    public function testExplicitModePreservedWhenOverrideAddsColumnWithoutGroups(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/res.php'           => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'res', 'resourceType' => 'Res', 'storagePid' => 1], 'columns' => ['title' => ['type' => 'string', 'groups' => ['list']]]];\n",
            'Configuration/TcaApi/Overrides/add.php' => "<?php\n\$GLOBALS['TCA_API']['res']['columns']['no_group_col'] = ['type' => 'string'];\n",
        ]);

        $this->loadPackages($pkg);

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

        $this->loadPackages($pkg);

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

        $this->loadPackages($pkg);

        self::assertNotNull($this->registry->get('res'));
    }

    #[Test]
    public function testBaseFileAtDepthOneIsIgnored(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/subdir/deep.php' => "<?php\nreturn ['general' => ['table' => 'tx_test', 'resourceName' => 'deep-resource', 'resourceType' => 'Deep', 'storagePid' => 1]];\n",
        ]);

        $this->loadPackages($pkg);

        self::assertNull($this->registry->get('deep-resource'));
    }

    #[Test]
    public function testOverrideDirFileNotIncludedInBasePass(): void
    {
        $pkg = $this->makePackage([
            'Configuration/TcaApi/Overrides/standalone.php' => "<?php\n\$GLOBALS['TCA_API']['override-only'] = ['general' => ['table' => 'tx_test', 'resourceName' => 'override-only', 'resourceType' => 'OO', 'storagePid' => 1]];\n",
        ]);

        $this->loadPackages($pkg);

        self::assertNotNull($this->registry->get('override-only'));
        self::assertNull($this->registry->get('standalone'));
    }
}
