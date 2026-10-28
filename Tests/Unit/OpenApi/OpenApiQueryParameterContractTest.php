<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\OpenApi;

use MaikSchneider\TcaApi\Cache\CacheDefinition;
use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\FilterContext;
use MaikSchneider\TcaApi\Filter\FilterDefinition;
use MaikSchneider\TcaApi\Filter\MmFilter;
use MaikSchneider\TcaApi\Filter\PartialFilter;
use MaikSchneider\TcaApi\Filter\RangeFilter;
use MaikSchneider\TcaApi\Filter\RelationPathFilter;
use MaikSchneider\TcaApi\Filter\SearchFilter;
use MaikSchneider\TcaApi\Filter\ValueSet;
use MaikSchneider\TcaApi\Filter\WordStartFilter;
use MaikSchneider\TcaApi\OpenApi\OpenApiOperationBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A client generated from the spec sends each filter parameter the way the spec
 * describes it (style, explode, schema). This test serializes a sample value the
 * same way, parses the query string as PHP does, resolves it like
 * RequestDispatcher::resolveFilterParams(), and checks the filter understands it.
 */
final class OpenApiQueryParameterContractTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function filterClasses(): iterable
    {
        foreach ([ExactFilter::class, PartialFilter::class, WordStartFilter::class, SearchFilter::class, MmFilter::class, RangeFilter::class] as $filterClass) {
            yield $filterClass => [$filterClass];
        }
    }

    #[Test]
    #[DataProvider('filterClasses')]
    public function filterUnderstandsItsParameterAsDocumented(string $filterClass): void
    {
        $filters = [
            'field' => new FilterDefinition($filterClass, 'tx_test', 'field'),
            'related.field' => new FilterDefinition(RelationPathFilter::class, 'tx_test', 'related.field', options: ['__leafFilter' => $filterClass]),
        ];
        $parameters = array_column((new OpenApiOperationBuilder())->buildListOperation('test', 'Test', $this->makeConfig($filters))['parameters'], null, 'name');

        foreach (['field' => ['field', 'field[]'], 'related.field' => ['filters[related.field]', 'filters[related.field][]']] as $column => $names) {
            $documented = array_values(array_filter($names, static fn (string $name): bool => isset($parameters[$name])));
            self::assertCount(1, $documented, 'Filter "' . $column . '" must be documented once.');
            $parameter = $parameters[reset($documented)];

            parse_str(self::serialize($parameter), $query);
            $value = self::resolveFilterParams($query, array_keys($filters))[$column] ?? null;
            $context = new FilterContext($value, 'tx_test', $column);

            if ($filterClass === RangeFilter::class) {
                $range = (new \ReflectionClass(RangeFilter::class))->newInstanceWithoutConstructor();
                self::assertTrue($range->hasConstraint($context), $column . ': ' . self::serialize($parameter));
                continue;
            }
            self::assertFalse(ValueSet::fromContext($context)->isEmpty(), $column . ': ' . self::serialize($parameter));
        }
    }

    /** Serializes a sample value like a client generated from the spec (OpenAPI 3.1 style/explode). */
    private static function serialize(array $parameter): string
    {
        $name   = $parameter['name'];
        $schema = $parameter['schema'];
        $style  = $parameter['style'] ?? 'form';

        if ($style === 'deepObject') {
            self::assertSame('object', $schema['type']);
            $first = array_key_first($schema['properties']);

            return rawurlencode($name . '[' . $first . ']') . '=1';
        }

        self::assertSame('form', $style);
        if (($schema['type'] ?? null) === 'array') {
            self::assertTrue($parameter['explode'] ?? true, 'A list must be exploded to be read as repeated keys.');

            return rawurlencode($name) . '=1&' . rawurlencode($name) . '=2';
        }

        return rawurlencode($name) . '=1';
    }

    /**
     * Same as RequestDispatcher::resolveFilterParams().
     *
     * @param list<string> $columns
     */
    private static function resolveFilterParams(array $params, array $columns): array
    {
        $filters = \is_array($params['filters'] ?? null) ? $params['filters'] : [];
        foreach ($columns as $column) {
            if (!isset($filters[$column]) && isset($params[$column])) {
                $filters[$column] = $params[$column];
            }
        }

        return $filters;
    }

    /** @param array<string, FilterDefinition> $filters */
    private function makeConfig(array $filters): ApiDefinition
    {
        return new ApiDefinition(
            table: 'tx_test',
            resourceName: 'test',
            resourceType: 'Test',
            operations: ['list'],
            itemsPerPage: 20,
            maxItemsPerPage: null,
            type: null,
            storagePid: null,
            columns: [],
            security: [],
            filters: $filters,
            allowedOrder: [],
            defaultOrder: [],
            ownershipColumn: null,
            ownershipSetOnCreate: null,
            ownershipBeAdminBypass: false,
            virtualProperties: [],
            isExplicitMode: false,
            writeMode: WriteMode::ACTING_USER,
            cache: new CacheDefinition(),
        );
    }
}
