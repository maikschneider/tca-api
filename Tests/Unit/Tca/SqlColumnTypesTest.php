<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Tca;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use MaikSchneider\TcaApi\OpenApi\ColumnValueSchema;
use MaikSchneider\TcaApi\Tca\SqlColumnTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqlColumnTypesTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>, array<string, mixed>|null}> */
    public static function columnTypes(): iterable
    {
        yield 'int' => [Types::INTEGER, [], ['type' => 'integer']];
        yield 'tinyint (parsed as smallint)' => [Types::SMALLINT, [], ['type' => 'integer']];
        yield 'bigint' => [Types::BIGINT, [], ['type' => 'integer']];
        yield 'unsigned bigint' => [Types::BIGINT, ['unsigned' => true], ['oneOf' => [['type' => 'integer'], ['type' => 'string']]]];
        yield 'nullable int' => [Types::INTEGER, ['notnull' => false], ['oneOf' => [['type' => 'integer'], ['type' => 'null']]]];
        yield 'double' => [Types::FLOAT, [], ['type' => 'number']];
        yield 'decimal' => [Types::DECIMAL, [], ['type' => 'string']];
        yield 'varchar' => [Types::STRING, [], ['type' => 'string']];
        yield 'nullable text' => [Types::TEXT, ['notnull' => false], ['oneOf' => [['type' => 'string'], ['type' => 'null']]]];
        yield 'blob' => [Types::BLOB, [], null];
    }

    #[Test]
    #[DataProvider('columnTypes')]
    public function describesTheValueTheDriverReturns(string $type, array $options, ?array $expected): void
    {
        $column = new Column('field', Type::getType($type), [...['notnull' => true], ...$options]);

        self::assertSame($expected, SqlColumnTypes::schemaFor($column));
    }

    #[Test]
    public function aColumnSentAsTheDatabaseValueFollowsItsSqlDefinition(): void
    {
        $colPos = ['type' => 'select', 'renderType' => 'selectSingle', 'itemsProcFunc' => 'Foo->bar', 'items' => []];

        self::assertSame(['oneOf' => [['type' => 'string'], ['type' => 'null']]], ColumnValueSchema::forTcaConfig($colPos));
        self::assertSame(['type' => 'integer'], ColumnValueSchema::forTcaConfig($colPos, ['type' => 'integer']));
    }

    #[Test]
    public function aColumnOfAnUnknownTcaTypeFollowsItsSqlDefinition(): void
    {
        self::assertNull(ColumnValueSchema::forTcaConfig(['type' => 'custom']));
        self::assertSame(['type' => 'integer'], ColumnValueSchema::forTcaConfig(['type' => 'custom'], ['type' => 'integer']));
    }

    #[Test]
    public function aColumnTheSerializerFormatsKeepsItsFormat(): void
    {
        $schema = ColumnValueSchema::forTcaConfig(['type' => 'datetime'], ['type' => 'integer']);

        self::assertSame(['oneOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]], $schema);
    }
}
