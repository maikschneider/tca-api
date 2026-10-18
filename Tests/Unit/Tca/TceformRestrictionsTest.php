<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Tca;

use MaikSchneider\TcaApi\Tca\TceformRestrictions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TceformRestrictionsTest extends TestCase
{
    private TceformRestrictions $restrictions;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['tx_test'] = [
            'columns' => [
                'size' => ['config' => ['type' => 'select', 'items' => [
                    ['label' => 'Default', 'value' => ''],
                    ['label' => 'Small', 'value' => 'small'],
                    ['label' => 'Large', 'value' => 'large'],
                ]]],
            ],
            'types' => [
                'news' => ['columnsOverrides' => ['size' => ['config' => ['items' => [['label' => 'Huge', 'value' => 'huge']]]]]],
            ],
        ];
        $this->restrictions = new TceformRestrictions('tx_test', [
            'title.' => ['disabled' => '1'],
            'teaser.' => ['types.' => ['news.' => ['disabled' => '1']]],
            'subtitle.' => ['disabled' => '1', 'types.' => ['news.' => ['disabled' => '0']]],
            'size.' => [
                'keepItems' => 'small',
                'addItems.' => ['custom' => 'Custom'],
                'types.' => ['event.' => ['removeItems' => 'small, custom']],
            ],
            'color.' => ['removeItems' => 'red,green'],
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tx_test']);
        parent::tearDown();
    }

    #[Test]
    public function disabledAppliesToTheFieldAndCanBeSetPerType(): void
    {
        self::assertTrue($this->restrictions->isDisabled('title'));
        self::assertTrue($this->restrictions->isDisabled('title', 'news'));
        self::assertFalse($this->restrictions->isDisabled('teaser'));
        self::assertTrue($this->restrictions->isDisabled('teaser', 'news'));
        self::assertFalse($this->restrictions->isDisabled('subtitle', 'news'));
        self::assertFalse($this->restrictions->isDisabled('bodytext'));
    }

    #[Test]
    public function removeItemsAreDenied(): void
    {
        self::assertSame(['red', 'green'], $this->restrictions->deniedValues('color'));
        self::assertSame([], $this->restrictions->deniedValues('bodytext'));
    }

    #[Test]
    public function itemsMissingFromKeepItemsAreDeniedExceptTheEmptyValue(): void
    {
        self::assertSame(['large'], $this->restrictions->deniedValues('size'));
    }

    #[Test]
    public function keepItemsUseTheTypesItems(): void
    {
        self::assertSame(['huge', 'large'], $this->restrictions->deniedValues('size', 'news'));
    }

    #[Test]
    public function aTypeOverridesTheFieldAndAddItemsStayAllowed(): void
    {
        self::assertSame(['small', 'large'], $this->restrictions->deniedValues('size', 'event'));
    }
}
