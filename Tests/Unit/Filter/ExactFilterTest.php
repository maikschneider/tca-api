<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use Doctrine\DBAL\ParameterType;
use MaikSchneider\TcaApi\Filter\ColumnTypeResolver;
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\FilterContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

final class ExactFilterTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('parameterBindings')]
    public function bindsTheResolvedValueAndDbalType(
        array $config,
        array $options,
        mixed $value,
        mixed $expectedValue,
        mixed $expectedType,
    ): void {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getConfiguration')->willReturn($config);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('hasField')->with('code')->willReturn(true);
        $schema->method('getField')->with('code')->willReturn($field);

        $schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $schemaFactory->method('has')->with('tx_test')->willReturn(true);
        $schemaFactory->method('get')->with('tx_test')->willReturn($schema);

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->expects(self::once())
            ->method(\is_array($expectedValue) ? 'in' : 'eq')
            ->with('code', ':value')
            ->willReturn('comparison');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->expects(self::once())->method('createNamedParameter')
            ->with(self::identicalTo($expectedValue), self::identicalTo($expectedType))
            ->willReturn(':value');
        $qb->expects(self::once())->method('andWhere')->with('comparison');

        $filter = new ExactFilter(new ColumnTypeResolver($schemaFactory));
        $filter->apply($qb, new FilterContext(value: $value, table: 'tx_test', column: 'code', options: $options));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, mixed, mixed, mixed}> */
    public static function parameterBindings(): iterable
    {
        yield 'TCA integer scalar' => [
            ['type' => 'number'], [], '007', 7, ParameterType::INTEGER,
        ];
        yield 'TCA integer list' => [
            ['type' => 'number'], [], ['007', '009'], [7, 9], Connection::PARAM_INT_ARRAY,
        ];
        yield 'unresolved type preserves scalar leading zeros' => [
            ['type' => 'input'], [], '007', '007', ParameterType::STRING,
        ];
        yield 'unresolved type preserves distinct list strings' => [
            ['type' => 'input'], [], ['007', '7'], ['007', '7'], Connection::PARAM_STR_ARRAY,
        ];
        yield 'explicit string overrides TCA integer for scalar' => [
            ['type' => 'number'], ['type' => 'string'], '007', '007', ParameterType::STRING,
        ];
        yield 'explicit string overrides TCA integer for list' => [
            ['type' => 'number'], ['type' => 'string'], ['007', '7'], ['007', '7'], Connection::PARAM_STR_ARRAY,
        ];
    }
}
