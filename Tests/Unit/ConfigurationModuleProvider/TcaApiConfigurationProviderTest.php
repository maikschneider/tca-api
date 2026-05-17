<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\ConfigurationModuleProvider;

use MaikSchneider\TcaApi\Cache\CacheDefinition;
use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\ConfigurationModuleProvider\TcaApiConfigurationProvider;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Filter\FilterDefinition;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TcaApiConfigurationProviderTest extends TestCase
{
    protected function setUp(): void
    {
        (new ApiRegistry())->reset();
    }

    #[Test]
    public function emptyRegistryReturnsEmptyArray(): void
    {
        $config = $this->makeProvider($this->makeRegistry())->getConfiguration();

        self::assertSame([], $config);
    }

    private function makeProvider(ApiRegistry $registry): TcaApiConfigurationProvider
    {
        return new TcaApiConfigurationProvider($registry);
    }

    private function makeRegistry(array $definitions = []): ApiRegistry
    {
        $registry = new ApiRegistry();
        foreach ($definitions as $name => $definition) {
            $registry->register($name, $definition);
        }
        return $registry;
    }

    #[Test]
    public function resourceNameBecomesTopLevelKey(): void
    {
        $config = $this->makeProvider($this->makeRegistry([
            'articles' => $this->makeDefinition('articles'),
        ]))->getConfiguration();

        self::assertArrayHasKey('articles', $config);
    }

    private function makeDefinition(
        string $resourceName = 'articles',
        string $table = 'tx_test_table',
        array $columns = [],
        array $security = [],
        array $filters = [],
        WriteMode $writeMode = WriteMode::ACTING_USER,
        CacheDefinition $cache = new CacheDefinition(),
    ): ApiDefinition {
        return new ApiDefinition(
            table: $table,
            resourceName: $resourceName,
            resourceType: 'Article',
            operations: ['list', 'show'],
            itemsPerPage: null,
            maxItemsPerPage: null,
            type: null,
            storagePid: null,
            columns: $columns,
            security: $security,
            filters: $filters,
            allowedOrder: [],
            defaultOrder: [],
            ownershipColumn: null,
            ownershipSetOnCreate: null,
            ownershipBeAdminBypass: false,
            virtualProperties: [],
            isExplicitMode: false,
            writeMode: $writeMode,
            cache: $cache,
        );
    }

    #[Test]
    public function scalarPropertiesArePassedThrough(): void
    {
        $config = $this->makeProvider($this->makeRegistry([
            'articles' => $this->makeDefinition(table: 'tx_custom'),
        ]))->getConfiguration();

        self::assertSame('tx_custom', $config['articles']['table']);
        self::assertSame('Article', $config['articles']['resourceType']);
        self::assertSame(['list', 'show'], $config['articles']['operations']);
    }

    #[Test]
    public function backedEnumIsSerializedAsItsValue(): void
    {
        $config = $this->makeProvider($this->makeRegistry([
            'articles' => $this->makeDefinition(writeMode: WriteMode::SYSTEM_ADMIN),
        ]))->getConfiguration();

        self::assertSame('system_admin', $config['articles']['writeMode']);
    }

    #[Test]
    public function securityEnumValuesAreSerializedAsStrings(): void
    {
        $config = $this->makeProvider($this->makeRegistry([
            'articles' => $this->makeDefinition(security: [
                'create' => AccessRole::FE_USER,
                'delete' => AccessRole::BE_ADMIN,
            ]),
        ]))->getConfiguration();

        self::assertSame('FE_USER', $config['articles']['security']['create']);
        self::assertSame('BE_ADMIN', $config['articles']['security']['delete']);
    }

    #[Test]
    public function nestedCacheDefinitionIsSerializedAsArray(): void
    {
        $config = $this->makeProvider($this->makeRegistry([
            'articles' => $this->makeDefinition(
                cache: new CacheDefinition(enabled: true, lifetime: 7200, parametersToIgnore: ['preview']),
            ),
        ]))->getConfiguration();

        self::assertIsArray($config['articles']['cache']);
        self::assertTrue($config['articles']['cache']['enabled']);
        self::assertSame(7200, $config['articles']['cache']['lifetime']);
        self::assertSame(['preview'], $config['articles']['cache']['parametersToIgnore']);
    }

    #[Test]
    public function columnDefinitionsInArrayAreSerializedViaReflection(): void
    {
        $config = $this->makeProvider($this->makeRegistry([
            'articles' => $this->makeDefinition(columns: [
                'title' => new ColumnDefinition(groups: ['list', 'show'], type: 'string'),
                'body' => new ColumnDefinition(groups: null),
            ]),
        ]))->getConfiguration();

        self::assertIsArray($config['articles']['columns']['title']);
        self::assertSame(['list', 'show'], $config['articles']['columns']['title']['groups']);
        self::assertSame('string', $config['articles']['columns']['title']['type']);
        self::assertNull($config['articles']['columns']['body']['groups']);
    }

    #[Test]
    public function filterDefinitionsAreSerializedViaReflection(): void
    {
        $config = $this->makeProvider($this->makeRegistry([
            'articles' => $this->makeDefinition(filters: [
                'color_id' => new FilterDefinition(
                    filterClass: 'ExactFilter',
                    table: 'tx_test_table',
                    column: 'color_id',
                    options: ['default' => '1'],
                    isPrivate: true,
                ),
            ]),
        ]))->getConfiguration();

        $filter = $config['articles']['filters']['color_id'];
        self::assertSame('ExactFilter', $filter['filterClass']);
        self::assertSame(['default' => '1'], $filter['options']);
        self::assertTrue($filter['isPrivate']);
    }

    #[Test]
    public function multipleResourcesAreSortedAlphabetically(): void
    {
        $config = $this->makeProvider($this->makeRegistry([
            'zebra' => $this->makeDefinition('zebra'),
            'alpha' => $this->makeDefinition('alpha'),
            'mustang' => $this->makeDefinition('mustang'),
        ]))->getConfiguration();

        self::assertSame(['alpha', 'mustang', 'zebra'], array_keys($config));
    }
}
