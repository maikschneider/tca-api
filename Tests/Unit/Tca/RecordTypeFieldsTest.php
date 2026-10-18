<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Tca;

use MaikSchneider\TcaApi\Tca\RecordTypeFields;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordTypeFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['tx_test'] = [
            'columns' => [
                'title'  => ['label' => 'Title', 'config' => ['type' => 'input', 'max' => 255]],
                'teaser' => ['config' => ['type' => 'text']],
                'hidden' => ['config' => ['type' => 'check']],
            ],
            'palettes' => [
                'visibility' => ['showitem' => 'hidden, --linebreak--, missing_column'],
            ],
            'types' => [
                'news' => [
                    'showitem' => '--div--;Tab, title;Custom label, --palette--;;visibility, teaser, --palette--;;missing_palette, title',
                    'columnsOverrides' => ['title' => ['config' => ['required' => true, 'max' => 20]]],
                ],
                'empty' => [],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tx_test']);
        parent::tearDown();
    }

    #[Test]
    public function fieldNamesFollowShowitemAndUnpackPalettes(): void
    {
        self::assertSame(['title', 'hidden', 'teaser'], RecordTypeFields::fieldNames('tx_test', 'news'));
    }

    #[Test]
    public function fieldNamesOfATypeWithoutShowitemAreEmpty(): void
    {
        self::assertSame([], RecordTypeFields::fieldNames('tx_test', 'empty'));
        self::assertSame([], RecordTypeFields::fieldNames('tx_test', 'undefined'));
    }

    #[Test]
    public function columnDefinitionAppliesTheTypesColumnsOverrides(): void
    {
        self::assertSame(
            ['label' => 'Title', 'config' => ['type' => 'input', 'max' => 20, 'required' => true]],
            RecordTypeFields::columnDefinition('tx_test', 'title', 'news'),
        );
    }

    #[Test]
    public function columnDefinitionWithoutTypeIsTheTableColumn(): void
    {
        self::assertSame(
            ['label' => 'Title', 'config' => ['type' => 'input', 'max' => 255]],
            RecordTypeFields::columnDefinition('tx_test', 'title'),
        );
        self::assertSame([], RecordTypeFields::columnDefinition('tx_test', 'missing_column', 'news'));
    }
}
