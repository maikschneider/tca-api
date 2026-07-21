<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\OpenApi;

use MaikSchneider\TcaApi\Cache\CacheDefinition;
use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\FilterDefinition;
use MaikSchneider\TcaApi\OpenApi\OpenApiOperationBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenApiOperationBuilderTest extends TestCase
{
    #[Test]
    public function listOperationAdvertisesDottedFilterKeysInBracketForm(): void
    {
        $config = $this->makeConfigWithFilters([
            'categories.title' => new FilterDefinition(ExactFilter::class, 'tx_test', 'categories.title'),
            'color_id'         => new FilterDefinition(ExactFilter::class, 'tx_test', 'color_id'),
        ]);

        $operation = (new OpenApiOperationBuilder())->buildListOperation('test', 'Test', $config);
        $names     = array_column($operation['parameters'], 'name');

        // A relation-path (dotted) key only matches via ?filters[…]; a plain top-level
        // parameter name such as "categories.title" would never bind (PHP mangles the dot).
        self::assertContains('filters[categories.title]', $names);
        self::assertNotContains('categories.title', $names);
        // Plain keys stay top-level.
        self::assertContains('color_id', $names);
    }

    #[Test]
    public function operationsAreTaggedWithConfiguredGroup(): void
    {
        $config  = $this->makeConfigWithFilters([], group: 'Editorial');
        $builder = new OpenApiOperationBuilder();

        self::assertSame(['Editorial'], $builder->buildListOperation('test', 'Test', $config)['tags']);
        self::assertSame(['Editorial'], $builder->buildShowOperation('test', 'Test', $config)['tags']);
        self::assertSame(['Editorial'], $builder->buildCreateOperation('test', 'Test', $config)['tags']);
        self::assertSame(['Editorial'], $builder->buildUpdateOperation('test', 'Test', $config, partial: false)['tags']);
        self::assertSame(['Editorial'], $builder->buildDeleteOperation('test', $config)['tags']);
    }

    #[Test]
    public function operationTagFallsBackToResourceTypeWhenNoGroupConfigured(): void
    {
        $config = $this->makeConfigWithFilters([]);

        self::assertSame(['Test'], (new OpenApiOperationBuilder())->buildListOperation('test', 'Test', $config)['tags']);
    }

    /** @param array<string, FilterDefinition> $filters */
    private function makeConfigWithFilters(array $filters, ?string $group = null): ApiDefinition
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
            group: $group,
        );
    }
}
