<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Tca;

use MaikSchneider\TcaApi\Tca\RelationCardinality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RelationCardinalityTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function relationConfigs(): iterable
    {
        yield 'selectSingle' => [['type' => 'select', 'renderType' => 'selectSingle', 'foreign_table' => 'tx_color'], true];
        yield 'selectSingle with MM' => [['type' => 'select', 'renderType' => 'selectSingle', 'foreign_table' => 'tx_color', 'MM' => 'tx_mm'], false];
        yield 'select with maxitems 1, no relationship' => [['type' => 'select', 'renderType' => 'selectMultipleSideBySide', 'foreign_table' => 'tx_color', 'maxitems' => 1], false];
        yield 'select with relationship manyToOne' => [['type' => 'select', 'foreign_table' => 'tx_color', 'relationship' => 'manyToOne'], true];
        yield 'category oneToOne' => [['type' => 'category', 'relationship' => 'oneToOne'], true];
        yield 'category with MM' => [['type' => 'category', 'MM' => 'sys_category_record_mm'], false];
        yield 'inline' => [['type' => 'inline', 'foreign_table' => 'tx_item', 'foreign_field' => 'parent'], false];
        yield 'group to one table, manyToOne' => [['type' => 'group', 'allowed' => 'pages', 'relationship' => 'manyToOne'], true];
        yield 'group to several tables, manyToOne' => [['type' => 'group', 'allowed' => 'pages,tt_content', 'relationship' => 'manyToOne'], false];
        yield 'group without relationship' => [['type' => 'group', 'allowed' => 'pages'], false];
    }

    #[Test]
    #[DataProvider('relationConfigs')]
    public function isHasOneFollowsTheRelationshipType(array $fieldConfig, bool $expected): void
    {
        self::assertSame($expected, RelationCardinality::isHasOne($fieldConfig));
    }
}
