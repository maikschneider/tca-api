<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\ColumnTypeResolver;
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\FilterDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

final class ColumnTypeResolverTest extends TestCase
{
    // ── preResolveType() ─────────────────────────────────────────────────

    #[Test]
    public function anExplicitTypeIsKeptAndSkipsTheTcaLookup(): void
    {
        $schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $schemaFactory->expects(self::never())->method('has');

        $definition = $this->definition('tx_test', 'sku', ['type' => 'string']);
        $resolved   = (new ColumnTypeResolver($schemaFactory))->preResolveType($definition);

        self::assertSame($definition, $resolved);
        self::assertSame('string', $resolved->option('type'));
    }

    #[Test]
    public function anEmptyTableSkipsTheTcaLookup(): void
    {
        $schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $schemaFactory->expects(self::never())->method('has');

        $definition = $this->definition('', 'sku');
        $resolved   = (new ColumnTypeResolver($schemaFactory))->preResolveType($definition);

        self::assertSame($definition, $resolved);
        self::assertNull($resolved->option('type'));
    }

    #[Test]
    public function aDetectedTypeIsBakedIntoTheDefinition(): void
    {
        $resolver = new ColumnTypeResolver($this->schemaFactory('tx_test', 'year', ['type' => 'number']));

        $resolved = $resolver->preResolveType($this->definition('tx_test', 'year'));

        self::assertSame('int', $resolved->option('type'));
    }

    #[Test]
    public function anUndetectableTypeLeavesTheDefinitionUntouched(): void
    {
        $resolver   = new ColumnTypeResolver($this->schemaFactory('tx_test', 'title', ['type' => 'input']));
        $definition = $this->definition('tx_test', 'title');

        self::assertSame($definition, $resolver->preResolveType($definition));
    }

    // ── detect() ─────────────────────────────────────────────────────────

    #[Test]
    public function anUnknownTableHasNoType(): void
    {
        $schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $schemaFactory->method('has')->willReturn(false);
        $schemaFactory->expects(self::never())->method('get');

        self::assertNull((new ColumnTypeResolver($schemaFactory))->detect('tx_missing', 'year'));
    }

    #[Test]
    public function aColumnAbsentFromTheSchemaHasNoType(): void
    {
        $resolver = new ColumnTypeResolver($this->schemaFactory('tx_test', 'year', ['type' => 'number']));

        self::assertNull($resolver->detect('tx_test', 'not_a_column'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function definition(string $table, string $column, array $options = []): FilterDefinition
    {
        return new FilterDefinition(
            filterClass: ExactFilter::class,
            table:       $table,
            column:      $column,
            options:     $options,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function schemaFactory(string $table, string $column, array $config): TcaSchemaFactory
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getConfiguration')->willReturn($config);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('hasField')->willReturnCallback(static fn (string $c): bool => $c === $column);
        $schema->method('getField')->willReturnCallback(static fn (string $c): FieldTypeInterface => $field);

        $schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $schemaFactory->method('has')->willReturnCallback(static fn (string $t): bool => $t === $table);
        $schemaFactory->method('get')->with($table)->willReturn($schema);

        return $schemaFactory;
    }
}
