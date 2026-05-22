<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer;

use GuzzleHttp\Psr7\HttpFactory;
use MaikSchneider\TcaApi\Cache\CacheDefinition;
use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\FilterDefinition;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HydraResponseBuilderTest extends TestCase
{
    private HydraResponseBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HydraResponseBuilder(new HttpFactory());
    }

    // ── buildItem() ──────────────────────────────────────────────────────

    #[Test]
    public function buildItemReturns200WithJsonLdContentType(): void
    {
        $response = $this->builder->buildItem(['@type' => 'News', 'uid' => 1]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/ld+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function buildItemBodyContainsJsonEncodedData(): void
    {
        $data     = ['@type' => 'News', 'uid' => 1, 'title' => 'Hello'];
        $response = $this->builder->buildItem($data);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('News', $body['@type']);
        self::assertSame(1, $body['uid']);
        self::assertSame('Hello', $body['title']);
    }

    // ── buildError() ─────────────────────────────────────────────────────

    #[Test]
    public function buildErrorReturnsSpecifiedStatusCode(): void
    {
        $response = $this->builder->buildError(403, 'Access denied', 'Forbidden');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function buildErrorBodyContainsHydraTitleAndDescription(): void
    {
        $response = $this->builder->buildError(404, 'Resource not found', 'Not Found');

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('hydra:Error', $body['@type']);
        self::assertSame('Not Found', $body['hydra:title']);
        self::assertSame('Resource not found', $body['hydra:description']);
    }

    #[Test]
    public function buildErrorDefaultTitleIsError(): void
    {
        $response = $this->builder->buildError(500, 'Something went wrong');

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Error', $body['hydra:title']);
    }

    // ── buildValidationError() ───────────────────────────────────────────

    #[Test]
    public function buildValidationErrorReturns422(): void
    {
        $response = $this->builder->buildValidationError([]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function buildValidationErrorBodyContainsViolationsArray(): void
    {
        $violations = [
            ['propertyPath' => 'title', 'message' => 'Too short', 'code' => 'too_short'],
        ];

        $response = $this->builder->buildValidationError($violations);
        $body     = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('hydra:Error', $body['@type']);
        self::assertSame('Validation Failed', $body['hydra:title']);
        self::assertCount(1, $body['violations']);
        self::assertSame('title', $body['violations'][0]['propertyPath']);
    }

    // ── buildCollection() ────────────────────────────────────────────────

    #[Test]
    public function buildCollectionReturns200WithJsonLdContentType(): void
    {
        $response = $this->builder->buildCollection([], 0, '/api/news', 1, 10);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/ld+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function buildCollectionBodyContainsMembersAndTotalItems(): void
    {
        $members  = [['uid' => 1], ['uid' => 2]];
        $response = $this->builder->buildCollection($members, 50, '/api/news', 1, 10);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('hydra:Collection', $body['@type']);
        self::assertSame(50, $body['hydra:totalItems']);
        self::assertCount(2, $body['hydra:member']);
    }

    #[Test]
    public function buildCollectionViewContainsFirstAndLastPageLinks(): void
    {
        $response = $this->builder->buildCollection([], 30, '/api/news', 1, 10);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $view = $body['hydra:view'];

        self::assertSame('hydra:PartialCollectionView', $view['@type']);
        self::assertStringContainsString('page=1', $view['hydra:first']);
        self::assertStringContainsString('page=3', $view['hydra:last']);
    }

    #[Test]
    public function buildCollectionViewHasNoPreviousOnFirstPage(): void
    {
        $response = $this->builder->buildCollection([], 20, '/api/news', 1, 10);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertNull($body['hydra:view']['hydra:previous']);
    }

    #[Test]
    public function buildCollectionViewHasNoNextOnLastPage(): void
    {
        $response = $this->builder->buildCollection([], 10, '/api/news', 1, 10);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertNull($body['hydra:view']['hydra:next']);
    }

    #[Test]
    public function buildCollectionViewHasPreviousAndNextOnMiddlePage(): void
    {
        $response = $this->builder->buildCollection([], 30, '/api/news', 2, 10);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('page=1', $body['hydra:view']['hydra:previous']);
        self::assertStringContainsString('page=3', $body['hydra:view']['hydra:next']);
    }

    #[Test]
    public function buildCollectionWithItemsPerPageZeroDoesNotThrow(): void
    {
        $response = $this->builder->buildCollection([], 10, '/api/news', 1, 0);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hydra:PartialCollectionView', $body['hydra:view']['@type']);
    }

    #[Test]
    public function buildCollectionWithTotalItemsZeroReturnsEmptyMembersAndView(): void
    {
        $response = $this->builder->buildCollection([], 0, '/api/news', 1, 10);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $body['hydra:totalItems']);
        self::assertSame([], $body['hydra:member']);
        self::assertNull($body['hydra:view']['hydra:next']);
    }

    #[Test]
    public function buildCollectionJsonStructureMatchesHydraSpec(): void
    {
        $members  = [['uid' => 1, '@type' => 'News']];
        $response = $this->builder->buildCollection($members, 1, '/api/news', 1, 10);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('@context', $body);
        self::assertSame('http://www.w3.org/ns/hydra/context.jsonld', $body['@context']);
        self::assertArrayHasKey('@type', $body);
        self::assertSame('hydra:Collection', $body['@type']);
        self::assertArrayHasKey('@id', $body);
        self::assertArrayHasKey('hydra:totalItems', $body);
        self::assertArrayHasKey('hydra:member', $body);
        self::assertArrayHasKey('hydra:view', $body);
    }

    #[Test]
    public function buildCollectionViewPreservesExtraQueryParams(): void
    {
        $response = $this->builder->buildCollection([], 30, '/api/news', 1, 10, ['filters' => ['status' => 'active']]);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('filters', $body['hydra:view']['hydra:first']);
    }

    // ── buildSearchTemplate (via buildCollection with config) ────────────────

    #[Test]
    public function buildCollectionWithPublicFiltersIncludesHydraSearch(): void
    {
        $config = $this->makeConfigWithFilters([
            'color_id' => new FilterDefinition(ExactFilter::class, 'tx_test', 'color_id'),
        ]);

        $body = $this->decodeCollection($this->builder->buildCollection([], 0, '/api/test', 1, 10, [], $config));

        self::assertArrayHasKey('hydra:search', $body);
        self::assertSame('hydra:IriTemplate', $body['hydra:search']['@type']);
    }

    #[Test]
    public function buildCollectionSearchTemplateVariablesUsePlainFieldNames(): void
    {
        $config = $this->makeConfigWithFilters([
            'color_id' => new FilterDefinition(ExactFilter::class, 'tx_test', 'color_id'),
            'title'    => new FilterDefinition(ExactFilter::class, 'tx_test', 'title'),
        ]);

        $body     = $this->decodeCollection($this->builder->buildCollection([], 0, '/api/test', 1, 10, [], $config));
        $mappings = $body['hydra:search']['hydra:mapping'];
        $variables = array_column($mappings, 'variable');

        self::assertContains('color_id', $variables, 'variable must be plain field name, not filters[color_id]');
        self::assertContains('title', $variables);
        self::assertNotContains('filters[color_id]', $variables);
        self::assertNotContains('filters[title]', $variables);
    }

    #[Test]
    public function buildCollectionSearchTemplateExcludesAllPrivateFilters(): void
    {
        $config = $this->makeConfigWithFilters([
            'secret' => new FilterDefinition(ExactFilter::class, 'tx_test', 'secret', isPrivate: true),
        ]);

        $body = $this->decodeCollection($this->builder->buildCollection([], 0, '/api/test', 1, 10, [], $config));

        self::assertArrayNotHasKey('hydra:search', $body);
    }

    #[Test]
    public function buildCollectionSearchTemplatePrivateFilterNotInMappings(): void
    {
        $config = $this->makeConfigWithFilters([
            'title'  => new FilterDefinition(ExactFilter::class, 'tx_test', 'title'),
            'secret' => new FilterDefinition(ExactFilter::class, 'tx_test', 'secret', isPrivate: true),
        ]);

        $body      = $this->decodeCollection($this->builder->buildCollection([], 0, '/api/test', 1, 10, [], $config));
        $variables = array_column($body['hydra:search']['hydra:mapping'], 'variable');

        self::assertContains('title', $variables);
        self::assertNotContains('secret', $variables);
    }

    #[Test]
    public function buildCollectionWithoutConfigHasNoHydraSearch(): void
    {
        $body = $this->decodeCollection($this->builder->buildCollection([], 0, '/api/test', 1, 10));

        self::assertArrayNotHasKey('hydra:search', $body);
    }

    #[Test]
    public function buildCollectionWithEmptyFiltersConfigHasNoHydraSearch(): void
    {
        $config = $this->makeConfigWithFilters([]);

        $body = $this->decodeCollection($this->builder->buildCollection([], 0, '/api/test', 1, 10, [], $config));

        self::assertArrayNotHasKey('hydra:search', $body);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, FilterDefinition> $filters */
    private function makeConfigWithFilters(array $filters): ApiDefinition
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

    private function decodeCollection(mixed $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
