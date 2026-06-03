<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer\Processing;

use MaikSchneider\TcaApi\Serializer\Processing\PlaceholderResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class PlaceholderResolverTest extends TestCase
{
    private PlaceholderResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PlaceholderResolver();
    }

    /** Build a SiteSettings instance keyed by dotted identifiers. */
    private function siteSettings(array $values): SiteSettings
    {
        return new SiteSettings(new Settings($values), [], []);
    }

    // ── Literals / non-strings ────────────────────────────────────────────

    #[Test]
    public function literalStringPassesThrough(): void
    {
        self::assertSame('hello', $this->resolver->resolve('hello', [], null));
    }

    #[Test]
    public function integerPassesThroughUnchanged(): void
    {
        self::assertSame(42, $this->resolver->resolve(42, ['uid' => 99], null));
    }

    #[Test]
    public function boolPassesThroughUnchanged(): void
    {
        self::assertTrue($this->resolver->resolve(true, [], null));
        self::assertFalse($this->resolver->resolve(false, [], null));
    }

    #[Test]
    public function nullPassesThroughUnchanged(): void
    {
        self::assertNull($this->resolver->resolve(null, [], null));
    }

    // ── Single-placeholder column references ──────────────────────────────

    #[Test]
    public function singleColumnPlaceholderReturnsRawTypedValue(): void
    {
        // Underlying value is int — should be returned as int, not stringified.
        $result = $this->resolver->resolve('{uid}', ['uid' => 42], null);
        self::assertSame(42, $result);
    }

    #[Test]
    public function singleColumnPlaceholderPreservesStringValue(): void
    {
        $result = $this->resolver->resolve('{title}', ['title' => 'Hello World'], null);
        self::assertSame('Hello World', $result);
    }

    #[Test]
    public function missingColumnPlaceholderReturnsNull(): void
    {
        $result = $this->resolver->resolve('{does_not_exist}', ['uid' => 42], null);
        self::assertNull($result);
    }

    #[Test]
    public function emptyStringColumnValueIsTreatedAsMissing(): void
    {
        // Empty string is treated as unresolved — same semantics as missing.
        $result = $this->resolver->resolve('{title}', ['title' => ''], null);
        self::assertNull($result);
    }

    #[Test]
    public function zeroColumnValueIsKept(): void
    {
        // 0 is a valid resolved value (matters for pid placeholders).
        $result = $this->resolver->resolve('{count}', ['count' => 0], null);
        self::assertSame(0, $result);
    }

    // ── Single-placeholder site-setting references ────────────────────────

    #[Test]
    public function singleSettingPlaceholderReturnsTypedValue(): void
    {
        $settings = $this->siteSettings(['tca_api.news.detailPid' => 42]);

        $result = $this->resolver->resolve('{$tca_api.news.detailPid}', [], $settings);
        self::assertSame(42, $result);
    }

    #[Test]
    public function settingPlaceholderWithoutSettingsReturnsNull(): void
    {
        $result = $this->resolver->resolve('{$tca_api.news.detailPid}', [], null);
        self::assertNull($result);
    }

    #[Test]
    public function missingSettingReturnsNull(): void
    {
        $settings = $this->siteSettings(['something.else' => 'value']);

        $result = $this->resolver->resolve('{$missing.setting}', [], $settings);
        self::assertNull($result);
    }

    #[Test]
    public function emptyStringSettingIsTreatedAsMissing(): void
    {
        $settings = $this->siteSettings(['some.key' => '']);

        $result = $this->resolver->resolve('{$some.key}', [], $settings);
        self::assertNull($result);
    }

    // ── Interpolation ─────────────────────────────────────────────────────

    #[Test]
    public function interpolationReplacesPlaceholdersInString(): void
    {
        $result = $this->resolver->resolve('rec-{uid}', ['uid' => 42], null);
        self::assertSame('rec-42', $result);
    }

    #[Test]
    public function interpolationStringifiesPlaceholderValues(): void
    {
        // Mixed text + placeholder always returns a string, even for int placeholders.
        $result = $this->resolver->resolve('id-{uid}-x', ['uid' => 42], null);
        self::assertSame('id-42-x', $result);
    }

    #[Test]
    public function interpolationWithMultiplePlaceholdersResolvesAll(): void
    {
        $result = $this->resolver->resolve(
            '{prefix}-{uid}',
            ['prefix' => 'news', 'uid' => 42],
            null,
        );
        self::assertSame('news-42', $result);
    }

    #[Test]
    public function interpolationWithMissingPlaceholderReturnsNull(): void
    {
        // Any unresolved placeholder in interpolation poisons the whole string.
        $result = $this->resolver->resolve('rec-{missing}', ['uid' => 42], null);
        self::assertNull($result);
    }

    #[Test]
    public function interpolationMixesColumnAndSettingPlaceholders(): void
    {
        $settings = $this->siteSettings(['app.prefix' => 'news']);

        $result = $this->resolver->resolve(
            '{$app.prefix}-{uid}',
            ['uid' => 42],
            $settings,
        );
        self::assertSame('news-42', $result);
    }

    // ── Recursive array walk ──────────────────────────────────────────────

    #[Test]
    public function nestedArrayValuesAreResolved(): void
    {
        $result = $this->resolver->resolve(
            ['news' => '{uid}', 'lang' => 'en'],
            ['uid' => 42],
            null,
        );

        self::assertSame(['news' => 42, 'lang' => 'en'], $result);
    }

    #[Test]
    public function deeplyNestedArrayValuesAreResolved(): void
    {
        $result = $this->resolver->resolve(
            ['outer' => ['inner' => '{uid}', 'static' => 'ok']],
            ['uid' => 42],
            null,
        );

        self::assertSame(['outer' => ['inner' => 42, 'static' => 'ok']], $result);
    }

    #[Test]
    public function unresolvedNestedPlaceholderPoisonsEntireArray(): void
    {
        // A single unresolvable placeholder anywhere in the tree → null overall.
        $result = $this->resolver->resolve(
            ['ok' => '{uid}', 'broken' => '{missing}'],
            ['uid' => 42],
            null,
        );

        self::assertNull($result);
    }

    // ── Edge cases ────────────────────────────────────────────────────────

    #[Test]
    public function stringWithNoPlaceholdersIsUnchanged(): void
    {
        $result = $this->resolver->resolve('/path/to/thing', ['uid' => 42], null);
        self::assertSame('/path/to/thing', $result);
    }

    #[Test]
    public function bracesWithoutValidIdentifierAreLiteral(): void
    {
        // The regex requires [A-Za-z0-9_.] inside the braces — punctuation/spaces
        // do not match, so the original string passes through unchanged.
        $result = $this->resolver->resolve('a{ }b', ['x' => 1], null);
        self::assertSame('a{ }b', $result);
    }

    #[Test]
    public function emptyArrayReturnsEmptyArray(): void
    {
        self::assertSame([], $this->resolver->resolve([], ['uid' => 42], null));
    }
}
