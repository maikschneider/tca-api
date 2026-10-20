<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Serializer\RelationSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RelationSerializer's pure static helpers and resolveRelatedConfig().
 *
 * DataRepository and GroupFieldSerializer depend on TYPO3 infrastructure, so
 * constructor injection is bypassed via reflection for the resolveRelatedConfig tests.
 * The static helpers (buildStub, buildDefaultConfig) require no instance at all.
 */
final class RelationSerializerTest extends TestCase
{
    protected function setUp(): void
    {
        // ApiRegistry stores resources in a static array; reset before every test
        // so registrations from other tests (or functional bootstrapping) cannot
        // leak in and cause intermittent failures when tests run in random order.
        (new ApiRegistry())->reset();
    }

    // ── buildStub ────────────────────────────────────────────────────────────

    #[Test]
    public function buildStubUsesDefaultPrefix(): void
    {
        $stub = RelationSerializer::buildStub('articles', 'Article', 7);

        self::assertSame('/_api/articles/7', $stub['@id']);
        self::assertSame('Article', $stub['@type']);
        self::assertSame(7, $stub['uid']);
    }

    #[Test]
    public function buildStubUsesCustomPrefix(): void
    {
        $stub = RelationSerializer::buildStub('news', 'NewsItem', 42, '/api/v2');

        self::assertSame('/api/v2/news/42', $stub['@id']);
        self::assertSame('NewsItem', $stub['@type']);
        self::assertSame(42, $stub['uid']);
    }

    #[Test]
    public function buildStubUidIsInt(): void
    {
        $stub = RelationSerializer::buildStub('pages', 'Page', 1);

        self::assertIsInt($stub['uid']);
    }

    #[Test]
    public function buildStubContainsExactlyThreeKeys(): void
    {
        $stub = RelationSerializer::buildStub('resources', 'Resource', 99);

        self::assertCount(3, $stub);
        self::assertArrayHasKey('@id', $stub);
        self::assertArrayHasKey('@type', $stub);
        self::assertArrayHasKey('uid', $stub);
    }

    // ── hasOneForeignTable ───────────────────────────────────────────────────

    /** @return iterable<string, array{array<string, mixed>, ?string}> */
    public static function hasOneFieldConfigs(): iterable
    {
        yield 'foreign_table wins over allowed' => [
            ['type' => 'group', 'foreign_table' => 'tt_content', 'allowed' => 'pages'],
            'tt_content',
        ];
        yield 'group with a single allowed table' => [['type' => 'group', 'allowed' => 'pages'], 'pages'];
        yield 'group with several allowed tables' => [['type' => 'group', 'allowed' => 'pages,tt_content'], null];
        yield 'group with a wildcard resolving to one table' => [
            ['type' => 'group', 'allowed' => '*', 'MM_oppositeUsage' => ['pages' => ['categories']]],
            null,
        ];
        yield 'allowed is ignored outside group fields' => [['type' => 'select', 'allowed' => 'pages'], null];
    }

    /** @param array<string, mixed> $fieldConfig */
    #[Test]
    #[DataProvider('hasOneFieldConfigs')]
    public function hasOneForeignTableResolvesTheSingleTargetTable(array $fieldConfig, ?string $expected): void
    {
        self::assertSame($expected, RelationSerializer::hasOneForeignTable($fieldConfig));
    }

    // ── buildDefaultConfig ───────────────────────────────────────────────────

    #[Test]
    public function buildDefaultConfigWithoutColumnDefDerivesResourceNameAndTypeFromTable(): void
    {
        $config = RelationSerializer::buildDefaultConfig('tx_news_domain_model_news');

        self::assertSame('tx_news_domain_model_news', $config->table);
        self::assertSame('tx_news_domain_model_news', $config->resourceName);
        self::assertSame('TxNewsDomainModelNews', $config->resourceType);
        self::assertFalse($config->isAddressable());
    }

    #[Test]
    public function buildDefaultConfigWithColumnDefResourceNameOverridesTableName(): void
    {
        $colDef = new ColumnDefinition(groups: null, resourceName: 'articles');

        $config = RelationSerializer::buildDefaultConfig('tx_myext_domain_model_article', $colDef);

        self::assertSame('tx_myext_domain_model_article', $config->table);
        self::assertSame('articles', $config->resourceName);
    }

    #[Test]
    public function buildDefaultConfigWithColumnDefResourceTypeOverridesTableName(): void
    {
        $colDef = new ColumnDefinition(groups: null, resourceType: 'Article');

        $config = RelationSerializer::buildDefaultConfig('tx_myext_domain_model_article', $colDef);

        self::assertSame('Article', $config->resourceType);
    }

    #[Test]
    public function buildDefaultConfigResultIsInDefaultMode(): void
    {
        $config = RelationSerializer::buildDefaultConfig('tx_test');

        // Default mode = no explicit columns with groups, isExplicitMode = false.
        self::assertFalse($config->isExplicitMode);
        self::assertSame([], $config->columns);
    }

    #[Test]
    public function buildDefaultConfigHasNoOperations(): void
    {
        $config = RelationSerializer::buildDefaultConfig('tx_test');

        self::assertSame([], $config->operations);
    }

    // ── reference ────────────────────────────────────────────────────────────

    #[Test]
    public function referenceIsIriForResourceWithShow(): void
    {
        $config = ApiDefinition::fromArray([
            'general' => ['table' => 'tx_articles', 'resourceName' => 'articles', 'resourceType' => 'Article', 'operations' => ['show']],
        ]);

        self::assertSame('/_api/articles/7', RelationSerializer::reference($config, 'articles', 7, '/_api'));
    }

    #[Test]
    public function referenceIsUidWithoutItemEndpoint(): void
    {
        self::assertSame(7, RelationSerializer::reference(null, 'tx_articles', 7, '/_api'));
        self::assertSame(7, RelationSerializer::reference($this->makeConfig('tx_articles', 'articles'), 'articles', 7, '/_api'));
        self::assertSame(7, RelationSerializer::reference(RelationSerializer::buildDefaultConfig('tx_articles'), 'tx_articles', 7, '/_api'));
    }

    // ── resolveRelatedConfig ─────────────────────────────────────────────────

    #[Test]
    public function resolveRelatedConfigReturnsSameConfigForSelfReferentialTable(): void
    {
        $serializer = $this->makeSerializerWithRegistry(new ApiRegistry());
        $config     = $this->makeConfig('tx_articles', 'articles');

        $result = $serializer->resolveRelatedConfig('tx_articles', $config);

        self::assertSame($config, $result);
    }

    #[Test]
    public function resolveRelatedConfigLooksUpByResourceNameWhenColumnDefHasOne(): void
    {
        $registry = new ApiRegistry();
        $related  = $this->makeConfig('tx_tags', 'tags');
        $registry->register('tags', $related);

        $serializer = $this->makeSerializerWithRegistry($registry);
        $config     = $this->makeConfig('tx_articles', 'articles');
        $colDef     = new ColumnDefinition(groups: null, resourceName: 'tags');

        $result = $serializer->resolveRelatedConfig('tx_tags', $config, $colDef);

        self::assertSame($related, $result);
    }

    #[Test]
    public function resolveRelatedConfigLooksUpByTableWhenNoResourceNameOnColumnDef(): void
    {
        $registry = new ApiRegistry();
        $related  = $this->makeConfig('tx_tags', 'tags');
        $registry->register('tags', $related);

        $serializer = $this->makeSerializerWithRegistry($registry);
        $config     = $this->makeConfig('tx_articles', 'articles');

        $result = $serializer->resolveRelatedConfig('tx_tags', $config);

        self::assertSame($related, $result);
    }

    #[Test]
    public function resolveRelatedConfigReturnsNullWhenTableNotRegistered(): void
    {
        $serializer = $this->makeSerializerWithRegistry(new ApiRegistry());
        $config     = $this->makeConfig('tx_articles', 'articles');

        $result = $serializer->resolveRelatedConfig('tx_unknown', $config);

        self::assertNull($result);
    }

    #[Test]
    public function resolveRelatedConfigUsesResourceNameLookupEvenWhenTableMatches(): void
    {
        $registry  = new ApiRegistry();
        $byName    = $this->makeConfig('tx_tags', 'named-tags');
        $byTable   = $this->makeConfig('tx_tags', 'table-tags');
        $registry->register('named-tags', $byName);
        $registry->register('table-tags', $byTable);

        $serializer = $this->makeSerializerWithRegistry($registry);
        $config     = $this->makeConfig('tx_articles', 'articles');
        $colDef     = new ColumnDefinition(groups: null, resourceName: 'named-tags');

        $result = $serializer->resolveRelatedConfig('tx_tags', $config, $colDef);

        self::assertSame($byName, $result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a RelationSerializer with the given ApiRegistry injected via reflection,
     * bypassing the TYPO3-dependent DataRepository and GroupFieldSerializer constructors.
     */
    private function makeSerializerWithRegistry(ApiRegistry $registry): RelationSerializer
    {
        $serializer = (new \ReflectionClass(RelationSerializer::class))
            ->newInstanceWithoutConstructor();

        $prop = (new \ReflectionClass($serializer))->getProperty('apiRegistry');
        $prop->setValue($serializer, $registry);

        return $serializer;
    }

    private function makeConfig(string $table, string $resourceName): ApiDefinition
    {
        return ApiDefinition::fromArray([
            'general' => [
                'table'        => $table,
                'resourceName' => $resourceName,
                'resourceType' => ucfirst($resourceName),
                'operations'   => [],
            ],
        ]);
    }
}
