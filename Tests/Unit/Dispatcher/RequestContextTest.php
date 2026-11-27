<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Dispatcher;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class RequestContextTest extends TestCase
{
    #[Test]
    public function aDefinitionWithoutEnabledSettingIsEnabled(): void
    {
        self::assertTrue(self::context([])->isDefinitionEnabled(self::definition('articles')));
    }

    #[Test]
    #[DataProvider('enabledSettingValues')]
    public function aDefinitionWithEnabledSettingIsEnabledOnlyWhenTheSettingIsTrue(array $settings, bool $expected): void
    {
        self::assertSame($expected, self::context($settings)->isDefinitionEnabled(self::definition('articles', 'myext.enabled')));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function enabledSettingValues(): iterable
    {
        yield 'missing' => [[], false];
        yield 'false' => [['myext.enabled' => false], false];
        yield 'true' => [['myext.enabled' => true], true];
        yield 'string 1' => [['myext.enabled' => '1'], false];
        yield 'int 1' => [['myext.enabled' => 1], false];
    }

    #[Test]
    #[DataProvider('cacheEnabledValues')]
    public function theCacheIsEnabledByCacheEnabledAndTheSetting(array $cache, array $settings, bool $expected): void
    {
        $definition = ApiDefinition::fromArray([
            'general' => ['table' => 'tx_test', 'resourceName' => 'articles', 'resourceType' => 'Test'],
            'cache'   => $cache,
        ]);

        self::assertSame($expected, self::context($settings)->isCacheEnabled($definition));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool}> */
    public static function cacheEnabledValues(): iterable
    {
        $withSetting = ['enabled' => true, 'enabledSetting' => 'myext.cache'];

        yield 'no cache section' => [[], [], false];
        yield 'enabled without setting key' => [['enabled' => true], [], true];
        yield 'setting missing' => [$withSetting, [], false];
        yield 'setting false' => [$withSetting, ['myext.cache' => false], false];
        yield 'setting true' => [$withSetting, ['myext.cache' => true], true];
        yield 'setting string 1' => [$withSetting, ['myext.cache' => '1'], false];
        yield 'disabled with setting true' => [['enabled' => false, 'enabledSetting' => 'myext.cache'], ['myext.cache' => true], false];
    }

    #[Test]
    public function filterAllowedResourcesDropsDisabledDefinitions(): void
    {
        $resources = self::resources();

        self::assertSame(['articles', 'pages'], array_keys(self::context(['myext.enabled' => true])->filterAllowedResources($resources)));
        self::assertSame(['articles'], array_keys(self::context([])->filterAllowedResources($resources)));
        self::assertSame([], array_keys(self::context(['tca_api.allowedResources' => 'pages'])->filterAllowedResources($resources)));
    }

    #[Test]
    public function servedResourceNamesKeepAllowedResourcesWhileNothingIsDisabled(): void
    {
        $resources = self::resources();

        self::assertSame([], self::context(['myext.enabled' => true])->servedResourceNames($resources));
        self::assertSame(['pages', 'unknown'], self::context(['myext.enabled' => true, 'tca_api.allowedResources' => 'pages,unknown'])->servedResourceNames($resources));
    }

    #[Test]
    public function servedResourceNamesListTheEnabledResourcesWhenSomeAreDisabled(): void
    {
        $resources = self::resources() + ['colors' => self::definition('colors')];

        self::assertSame(['articles', 'colors'], self::context([])->servedResourceNames($resources));
        self::assertSame(['colors'], self::context(['tca_api.allowedResources' => 'pages,colors'])->servedResourceNames($resources));
    }

    /** @return array<string, ApiDefinition> */
    private static function resources(): array
    {
        return [
            'articles' => self::definition('articles'),
            'pages'    => self::definition('pages', 'myext.enabled'),
        ];
    }

    private static function definition(string $resourceName, ?string $enabledSetting = null): ApiDefinition
    {
        $general = ['table' => 'tx_test', 'resourceName' => $resourceName, 'resourceType' => 'Test'];
        if ($enabledSetting !== null) {
            $general['enabledSetting'] = $enabledSetting;
        }

        return ApiDefinition::fromArray(['general' => $general]);
    }

    /** @param array<string, mixed> $settings flat setting identifiers */
    private static function context(array $settings): RequestContext
    {
        return new RequestContext(new SiteSettings(new Settings($settings), [], []), new ServerRequest('https://example.org/'));
    }
}
