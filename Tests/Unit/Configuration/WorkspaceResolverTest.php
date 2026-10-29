<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Configuration;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\WorkspaceResolver;
use MaikSchneider\TcaApi\Exception\WorkspaceResolutionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

final class WorkspaceResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['tx_test'] = ['ctrl' => ['versioningWS' => true], 'columns' => []];
        $site = new Site('tenant', 1, ['base' => '/', 'settings' => ['tenant' => [
            'workspace'      => '3',
            'emptyWorkspace' => '',
            'namedWorkspace' => 'drafts',
        ]]]);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('http://localhost/'))->withAttribute('site', $site);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['TCA']['tx_test']);
        parent::tearDown();
    }

    private static function definition(mixed $workspace): ApiDefinition
    {
        return ApiDefinition::fromArray(['general' => array_filter([
            'table'        => 'tx_test',
            'resourceName' => 'items',
            'resourceType' => 'Item',
            'operations'   => ['create'],
            'workspace'    => $workspace,
        ], static fn (mixed $value): bool => $value !== null)]);
    }

    #[Test]
    public function withoutWorkspaceWritesGoLive(): void
    {
        self::assertSame(0, WorkspaceResolver::workspaceId(self::definition(null)));
    }

    #[Test]
    public function integerWorkspaceIsUsedAsConfigured(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        self::assertSame(2, WorkspaceResolver::workspaceId(self::definition(2)));
    }

    #[Test]
    public function placeholderResolvesFromTheRequestSite(): void
    {
        self::assertSame(3, WorkspaceResolver::workspaceId(self::definition('{$tenant.workspace}')));
    }

    #[Test]
    public function placeholderWithoutSiteThrows(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $this->expectException(WorkspaceResolutionException::class);
        $this->expectExceptionCode(1761700001);

        WorkspaceResolver::workspaceId(self::definition('{$tenant.workspace}'));
    }

    #[Test]
    public function emptySettingThrows(): void
    {
        $this->expectException(WorkspaceResolutionException::class);
        $this->expectExceptionMessage('"tenant.emptyWorkspace", which is empty on site "tenant" instead of a workspace uid');

        WorkspaceResolver::workspaceId(self::definition('{$tenant.emptyWorkspace}'));
    }

    #[Test]
    public function settingThatIsNoIntegerThrows(): void
    {
        $this->expectException(WorkspaceResolutionException::class);
        $this->expectExceptionCode(1761700002);

        WorkspaceResolver::workspaceId(self::definition('{$tenant.namedWorkspace}'));
    }
}
