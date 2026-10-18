<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Configuration;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\StoragePidResolver;
use MaikSchneider\TcaApi\Exception\StoragePidResolutionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

final class StoragePidResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $site = new Site('tenant', 1, ['base' => '/', 'settings' => ['tenant' => [
            'storagePid' => 801,
            'sharedPid'  => '5',
            'emptyPid'   => '',
            'namedPid'   => 'folder',
            'negativePid' => -1,
            'booleanPid' => true,
        ]]]);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('http://localhost/'))->withAttribute('site', $site);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    private static function definition(mixed $storagePid, mixed $readStoragePids = null): ApiDefinition
    {
        return ApiDefinition::fromArray(['general' => array_filter([
            'table'           => 'tx_test',
            'resourceName'    => 'items',
            'resourceType'    => 'Item',
            'storagePid'      => $storagePid,
            'readStoragePids' => $readStoragePids,
        ], static fn (mixed $value): bool => $value !== null)]);
    }

    #[Test]
    public function integerPidsAreUsedAsConfigured(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $definition = self::definition(42, [42, 7]);

        self::assertSame(42, StoragePidResolver::writePid($definition));
        self::assertSame([42, 7], StoragePidResolver::readPids($definition));
    }

    #[Test]
    public function withoutStoragePidWritesGoToPageZeroAndReadsAreUnconstrained(): void
    {
        $definition = self::definition(null);

        self::assertSame(0, StoragePidResolver::writePid($definition));
        self::assertNull(StoragePidResolver::readPids($definition));
    }

    #[Test]
    public function placeholdersResolveFromTheSiteSettings(): void
    {
        $definition = self::definition('{$tenant.storagePid}', ['{$tenant.storagePid}', '{$tenant.sharedPid}', 801]);

        self::assertSame(801, StoragePidResolver::writePid($definition));
        self::assertSame([801, 5], StoragePidResolver::readPids($definition));
    }

    #[Test]
    public function aPlaceholderWithoutASiteThrows(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        $this->expectException(StoragePidResolutionException::class);
        $this->expectExceptionMessage('uses the site setting "tenant.storagePid", but the request has no site');

        StoragePidResolver::writePid(self::definition('{$tenant.storagePid}'));
    }

    #[Test]
    public function anEmptySettingThrows(): void
    {
        $this->expectException(StoragePidResolutionException::class);
        $this->expectExceptionMessage('"tenant.emptyPid", which is empty on site "tenant"');

        StoragePidResolver::readPids(self::definition('{$tenant.emptyPid}'));
    }

    #[Test]
    public function aMissingSettingThrows(): void
    {
        $this->expectException(StoragePidResolutionException::class);
        $this->expectExceptionMessage('"tenant.missing", which is empty');

        StoragePidResolver::writePid(self::definition('{$tenant.missing}'));
    }

    #[Test]
    public function aSettingThatIsNoPidThrows(): void
    {
        $this->expectException(StoragePidResolutionException::class);
        $this->expectExceptionMessage('"tenant.namedPid", which is "folder" on site "tenant" instead of a pid');

        StoragePidResolver::readPids(self::definition(1, ['{$tenant.namedPid}']));
    }

    #[Test]
    public function aNegativeSettingThrows(): void
    {
        $this->expectException(StoragePidResolutionException::class);
        $this->expectExceptionMessage('"tenant.negativePid", which is "-1" on site "tenant" instead of a pid');

        StoragePidResolver::writePid(self::definition('{$tenant.negativePid}'));
    }

    #[Test]
    public function aBooleanSettingDoesNotResolveToPageOne(): void
    {
        $this->expectException(StoragePidResolutionException::class);

        StoragePidResolver::readPids(self::definition('{$tenant.booleanPid}'));
    }
}
