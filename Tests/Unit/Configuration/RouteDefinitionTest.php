<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Configuration;

use MaikSchneider\TcaApi\Configuration\RouteDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteDefinitionTest extends TestCase
{
    // ── pid (required) ───────────────────────────────────────────────────

    #[Test]
    public function missingPidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"pid"');
        RouteDefinition::fromArray([]);
    }

    #[Test]
    public function integerPidIsAccepted(): void
    {
        $def = RouteDefinition::fromArray(['pid' => 42]);
        self::assertSame(42, $def->pid);
    }

    #[Test]
    public function stringPlaceholderPidIsAccepted(): void
    {
        $def = RouteDefinition::fromArray(['pid' => '{detail_pid}']);
        self::assertSame('{detail_pid}', $def->pid);
    }

    #[Test]
    public function settingPlaceholderPidIsAccepted(): void
    {
        $def = RouteDefinition::fromArray(['pid' => '{$tca_api.news.detailPid}']);
        self::assertSame('{$tca_api.news.detailPid}', $def->pid);
    }

    #[Test]
    public function zeroPidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"pid"');
        RouteDefinition::fromArray(['pid' => 0]);
    }

    #[Test]
    public function negativePidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"pid"');
        RouteDefinition::fromArray(['pid' => -5]);
    }

    #[Test]
    public function emptyStringPidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"pid"');
        RouteDefinition::fromArray(['pid' => '']);
    }

    #[Test]
    public function arrayPidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"pid"');
        RouteDefinition::fromArray(['pid' => [42]]);
    }

    #[Test]
    public function boolPidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"pid"');
        RouteDefinition::fromArray(['pid' => true]);
    }

    // ── extension / plugin / controller / action ─────────────────────────

    #[Test]
    public function extensionAndPluginTogetherAreAccepted(): void
    {
        $def = RouteDefinition::fromArray([
            'pid'       => 42,
            'extension' => 'News',
            'plugin'    => 'Pi1',
        ]);

        self::assertSame('News', $def->extension);
        self::assertSame('Pi1', $def->plugin);
        self::assertTrue($def->isExtbase());
    }

    #[Test]
    public function extensionWithoutPluginThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"extension" and "plugin"');
        RouteDefinition::fromArray([
            'pid'       => 42,
            'extension' => 'News',
        ]);
    }

    #[Test]
    public function pluginWithoutExtensionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"extension" and "plugin"');
        RouteDefinition::fromArray([
            'pid'    => 42,
            'plugin' => 'Pi1',
        ]);
    }

    #[Test]
    public function emptyExtensionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"extension"');
        RouteDefinition::fromArray([
            'pid'       => 42,
            'extension' => '',
            'plugin'    => 'Pi1',
        ]);
    }

    #[Test]
    public function nonStringPluginThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"plugin"');
        RouteDefinition::fromArray([
            'pid'       => 42,
            'extension' => 'News',
            'plugin'    => 1,
        ]);
    }

    #[Test]
    public function controllerAndActionAreAccepted(): void
    {
        $def = RouteDefinition::fromArray([
            'pid'        => 42,
            'extension'  => 'News',
            'plugin'     => 'Pi1',
            'controller' => 'News',
            'action'     => 'detail',
        ]);

        self::assertSame('News', $def->controller);
        self::assertSame('detail', $def->action);
    }

    #[Test]
    public function emptyControllerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"controller"');
        RouteDefinition::fromArray([
            'pid'        => 42,
            'controller' => '',
        ]);
    }

    // ── arguments / parameters ────────────────────────────────────────────

    #[Test]
    public function argumentsWithExtbaseAreAccepted(): void
    {
        $def = RouteDefinition::fromArray([
            'pid'       => 42,
            'extension' => 'News',
            'plugin'    => 'Pi1',
            'arguments' => ['news' => '{uid}'],
        ]);

        self::assertSame(['news' => '{uid}'], $def->arguments);
    }

    #[Test]
    public function argumentsWithoutExtbaseThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"arguments" requires "extension"');
        RouteDefinition::fromArray([
            'pid'       => 42,
            'arguments' => ['news' => '{uid}'],
        ]);
    }

    #[Test]
    public function emptyArgumentsWithoutExtbaseIsAccepted(): void
    {
        // Empty arguments array does not trigger the extbase requirement.
        $def = RouteDefinition::fromArray([
            'pid'       => 42,
            'arguments' => [],
        ]);

        self::assertSame([], $def->arguments);
    }

    #[Test]
    public function nonArrayArgumentsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"arguments"');
        RouteDefinition::fromArray([
            'pid'       => 42,
            'arguments' => 'not-an-array',
        ]);
    }

    #[Test]
    public function parametersAreAcceptedWithoutExtbase(): void
    {
        $def = RouteDefinition::fromArray([
            'pid'        => 42,
            'parameters' => ['ref' => '{uid}'],
        ]);

        self::assertSame(['ref' => '{uid}'], $def->parameters);
    }

    #[Test]
    public function nonArrayParametersThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"parameters"');
        RouteDefinition::fromArray([
            'pid'        => 42,
            'parameters' => 'not-an-array',
        ]);
    }

    // ── absolute / fragment ───────────────────────────────────────────────

    #[Test]
    public function absoluteDefaultsTrue(): void
    {
        $def = RouteDefinition::fromArray(['pid' => 42]);
        self::assertTrue($def->absolute);
    }

    #[Test]
    public function absoluteFalseIsAccepted(): void
    {
        $def = RouteDefinition::fromArray(['pid' => 42, 'absolute' => false]);
        self::assertFalse($def->absolute);
    }

    #[Test]
    public function nonBoolAbsoluteThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"absolute"');
        RouteDefinition::fromArray(['pid' => 42, 'absolute' => 1]);
    }

    #[Test]
    public function fragmentIsAccepted(): void
    {
        $def = RouteDefinition::fromArray(['pid' => 42, 'fragment' => 'top']);
        self::assertSame('top', $def->fragment);
    }

    #[Test]
    public function nonStringFragmentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"fragment"');
        RouteDefinition::fromArray(['pid' => 42, 'fragment' => 42]);
    }

    // ── isExtbase / extbaseNamespace ──────────────────────────────────────

    #[Test]
    public function isExtbaseFalseWhenNeitherSet(): void
    {
        $def = RouteDefinition::fromArray(['pid' => 42]);
        self::assertFalse($def->isExtbase());
    }

    #[Test]
    public function extbaseNamespaceUsesLowercase(): void
    {
        $def = RouteDefinition::fromArray([
            'pid'       => 42,
            'extension' => 'NewsAndStuff',
            'plugin'    => 'Pi1',
        ]);

        self::assertSame('tx_newsandstuff_pi1', $def->extbaseNamespace());
    }

    // ── Full happy-path config ────────────────────────────────────────────

    #[Test]
    public function fullConfigIsAccepted(): void
    {
        $def = RouteDefinition::fromArray([
            'pid'        => '{$tca_api.news.detailPid}',
            'extension'  => 'News',
            'plugin'     => 'Pi1',
            'controller' => 'News',
            'action'     => 'detail',
            'arguments'  => ['news' => '{uid}'],
            'parameters' => ['utm_source' => 'api'],
            'absolute'   => false,
            'fragment'   => 'top',
        ]);

        self::assertSame('{$tca_api.news.detailPid}', $def->pid);
        self::assertSame('News', $def->extension);
        self::assertSame('Pi1', $def->plugin);
        self::assertSame('News', $def->controller);
        self::assertSame('detail', $def->action);
        self::assertSame(['news' => '{uid}'], $def->arguments);
        self::assertSame(['utm_source' => 'api'], $def->parameters);
        self::assertFalse($def->absolute);
        self::assertSame('top', $def->fragment);
        self::assertTrue($def->isExtbase());
        self::assertSame('tx_news_pi1', $def->extbaseNamespace());
    }
}
