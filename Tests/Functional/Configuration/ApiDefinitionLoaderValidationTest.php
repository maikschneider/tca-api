<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Configuration;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Exception\InvalidApiDefinitionException;
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\RelationPathFilter;
use MaikSchneider\TcaApi\Filter\RelationResolver;
use MaikSchneider\TcaApi\Filter\RelationSubqueryBuilder;
use MaikSchneider\TcaApi\Filter\SearchFilter;
use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use MaikSchneider\TcaApi\Loader\TcaValidatorDeriver;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Covers ISC-3 and ISC-16: the loader's boot-time validation rejects a
 * registered resource whose TCA exposes a `type=group` column with
 * `allowed='*'` and no `MM_oppositeUsage`. The exception message names the
 * resource, the offending column, the table, and points at TYPO3's
 * MM_oppositeUsage reference.
 *
 * Lives under Tests/Functional/ per the F1 task spec. The validation
 * itself only reads $GLOBALS['TCA'] (same pattern as TcaValidatorDeriver),
 * so the test does not need a real TYPO3 bootstrap — it manipulates the
 * global directly and drives validateDefinitions() via reflection, which
 * keeps the test fast and deterministic.
 */
final class ApiDefinitionLoaderValidationTest extends TestCase
{
    private ApiRegistry $registry;

    /** @var array<string, mixed> */
    private array $tcaBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ApiRegistry();
        $this->registry->reset();
        $this->tcaBackup = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
        $this->registry->reset();
        parent::tearDown();
    }

    #[Test]
    public function loaderThrowsWhenWildcardGroupColumnHasNoOppositeUsage(): void
    {
        $GLOBALS['TCA']['sys_category']['columns']['items'] = [
            'config' => [
                'type'    => 'group',
                'allowed' => '*',
                // MM_oppositeUsage intentionally omitted — this is the bug.
                'MM'      => 'sys_category_record_mm',
            ],
        ];

        $definition = ApiDefinition::fromArray([
            'general' => [
                'table'        => 'sys_category',
                'resourceName' => 'categories',
                'resourceType' => 'Category',
            ],
            'columns' => [
                'items' => [],
            ],
        ]);
        $this->registry->register('categories', $definition);

        try {
            $this->invokeValidate(['categories' => $definition]);
            self::fail('Expected InvalidApiDefinitionException was not thrown.');
        } catch (InvalidApiDefinitionException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString("resource 'categories'", $message);
            self::assertStringContainsString("column 'items'", $message);
            self::assertStringContainsString("table 'sys_category'", $message);
            self::assertStringContainsString('MM_oppositeUsage', $message);
            self::assertStringContainsString(
                'docs.typo3.org',
                $message,
                'Exception message must point at the TYPO3 MM_oppositeUsage reference (ISC-16).',
            );
        }
    }

    #[Test]
    public function loaderDoesNotThrowWhenWildcardHasOppositeUsage(): void
    {
        $GLOBALS['TCA']['sys_category']['columns']['items'] = [
            'config' => [
                'type'             => 'group',
                'allowed'          => '*',
                'MM'               => 'sys_category_record_mm',
                'MM_oppositeUsage' => [
                    'tx_myext_domain_model_article' => ['categories'],
                    'pages'                         => ['categories'],
                ],
            ],
        ];

        $definition = ApiDefinition::fromArray([
            'general' => [
                'table'        => 'sys_category',
                'resourceName' => 'categories',
                'resourceType' => 'Category',
            ],
            'columns' => [
                'items' => [],
            ],
        ]);
        $this->registry->register('categories', $definition);

        $this->invokeValidate(['categories' => $definition]);

        self::assertNotNull($this->registry->get('categories'));
    }

    #[Test]
    public function loaderDoesNotThrowForExplicitAllowedTable(): void
    {
        $GLOBALS['TCA']['tx_myext_domain_model_article']['columns']['categories'] = [
            'config' => [
                'type'    => 'group',
                'allowed' => 'sys_category',
                'MM'      => 'sys_category_record_mm',
            ],
        ];

        $definition = ApiDefinition::fromArray([
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'articles',
                'resourceType' => 'Article',
            ],
            'columns' => [
                'categories' => [],
            ],
        ]);
        $this->registry->register('articles', $definition);

        $this->invokeValidate(['articles' => $definition]);

        self::assertNotNull($this->registry->get('articles'));
    }

    #[Test]
    public function loaderSkipsValidationForColumnsNotExposedInExplicitMode(): void
    {
        // The bad TCA column exists, but the API resource declares OTHER columns
        // in `groups` (explicit mode) and never exposes the broken one. The
        // validator must not fire for a column the API will never serialise.
        $GLOBALS['TCA']['sys_category']['columns']['items'] = [
            'config' => [
                'type'    => 'group',
                'allowed' => '*',
                'MM'      => 'sys_category_record_mm',
            ],
        ];
        $GLOBALS['TCA']['sys_category']['columns']['title'] = [
            'config' => ['type' => 'input'],
        ];

        $definition = ApiDefinition::fromArray([
            'general' => [
                'table'        => 'sys_category',
                'resourceName' => 'categories',
                'resourceType' => 'Category',
            ],
            'columns' => [
                // Only `title` is exposed — `items` is never reachable.
                'title' => ['type' => 'string', 'groups' => ['list', 'show']],
                'items' => ['groups' => []], // declared but no read-group
            ],
        ]);
        $this->registry->register('categories', $definition);

        $this->invokeValidate(['categories' => $definition]);

        self::assertNotNull($this->registry->get('categories'));
    }

    #[Test]
    public function loaderSkipsValidationWhenTcaForTableIsMissing(): void
    {
        // No $GLOBALS['TCA']['sys_category'] entry at all — the validator must
        // not crash trying to inspect a non-existent column.
        $definition = ApiDefinition::fromArray([
            'general' => [
                'table'        => 'sys_category',
                'resourceName' => 'categories',
                'resourceType' => 'Category',
            ],
            'columns' => [
                'items' => [],
            ],
        ]);
        $this->registry->register('categories', $definition);

        $this->invokeValidate(['categories' => $definition]);

        self::assertNotNull($this->registry->get('categories'));
    }

    #[Test]
    public function loaderThrowsWhenRelationPathFilterCannotBeResolved(): void
    {
        // The relation segment "nonexistent" is not a TCA column, so pre-resolution
        // records a __pathError which boot validation must promote to a hard failure.
        $GLOBALS['TCA']['tx_myext_domain_model_article']['columns']['title']['config'] = ['type' => 'input'];

        $definition = ApiDefinition::fromArray(
            [
                'general' => [
                    'table'        => 'tx_myext_domain_model_article',
                    'resourceName' => 'articles',
                    'resourceType' => 'Article',
                ],
                'columns' => ['title' => ['groups' => ['list', 'show']]],
                'filters' => ['nonexistent.title' => ExactFilter::class],
            ],
            [RelationPathFilter::class => $this->makePathFilter()],
        );
        $this->registry->register('articles', $definition);

        try {
            $this->invokeValidate(['articles' => $definition]);
            self::fail('Expected InvalidApiDefinitionException was not thrown.');
        } catch (InvalidApiDefinitionException $e) {
            self::assertStringContainsString("Filter 'nonexistent.title'", $e->getMessage());
            self::assertStringContainsString("resource 'articles'", $e->getMessage());
            self::assertStringContainsString('is not a known TCA column', $e->getMessage());
        }
    }

    #[Test]
    public function loaderDoesNotThrowForValidRelationPath(): void
    {
        $GLOBALS['TCA']['tx_myext_domain_model_article']['columns']['color_id']['config'] = [
            'type'          => 'select',
            'foreign_table' => 'tx_myext_domain_model_color',
        ];
        $GLOBALS['TCA']['tx_myext_domain_model_color']['columns']['name']['config'] = ['type' => 'input'];

        $definition = ApiDefinition::fromArray(
            [
                'general' => [
                    'table'        => 'tx_myext_domain_model_article',
                    'resourceName' => 'articles',
                    'resourceType' => 'Article',
                ],
                'columns' => ['title' => ['groups' => ['list', 'show']]],
                'filters' => ['color_id.name' => ExactFilter::class],
            ],
            [RelationPathFilter::class => $this->makePathFilter()],
        );
        $this->registry->register('articles', $definition);

        $this->invokeValidate(['articles' => $definition]);

        self::assertNotNull($this->registry->get('articles'));
    }

    #[Test]
    public function loaderThrowsWhenRelationPathLeafColumnIsUnknown(): void
    {
        // Relations resolve fine, but the leaf column "namee" is a typo — it must be
        // rejected at boot, not left to fail as a runtime SQL error.
        $GLOBALS['TCA']['tx_myext_domain_model_article']['columns']['color_id']['config'] = [
            'type'          => 'select',
            'foreign_table' => 'tx_myext_domain_model_color',
        ];
        $GLOBALS['TCA']['tx_myext_domain_model_color']['columns']['name']['config'] = ['type' => 'input'];

        $definition = ApiDefinition::fromArray(
            [
                'general' => [
                    'table'        => 'tx_myext_domain_model_article',
                    'resourceName' => 'articles',
                    'resourceType' => 'Article',
                ],
                'columns' => ['title' => ['groups' => ['list', 'show']]],
                'filters' => ['color_id.namee' => ExactFilter::class],
            ],
            [RelationPathFilter::class => $this->makePathFilter()],
        );
        $this->registry->register('articles', $definition);

        try {
            $this->invokeValidate(['articles' => $definition]);
            self::fail('Expected InvalidApiDefinitionException was not thrown.');
        } catch (InvalidApiDefinitionException $e) {
            self::assertStringContainsString("Filter 'color_id.namee'", $e->getMessage());
            self::assertStringContainsString('leaf column "tx_myext_domain_model_color.namee"', $e->getMessage());
        }
    }

    #[Test]
    public function loaderThrowsWhenSearchFilterHasInvalidRelationColumn(): void
    {
        // A dotted column in SearchFilter's `columns` with a typo'd leaf must be rejected
        // at boot, exactly like a relation-path filter key.
        $GLOBALS['TCA']['tx_myext_domain_model_article']['columns']['color_id']['config'] = [
            'type'          => 'select',
            'foreign_table' => 'tx_myext_domain_model_color',
        ];
        $GLOBALS['TCA']['tx_myext_domain_model_color']['columns']['name']['config'] = ['type' => 'input'];

        $definition = ApiDefinition::fromArray(
            [
                'general' => [
                    'table'        => 'tx_myext_domain_model_article',
                    'resourceName' => 'articles',
                    'resourceType' => 'Article',
                ],
                'columns' => ['title' => ['groups' => ['list', 'show']]],
                'filters' => [
                    'q' => [SearchFilter::class, ['columns' => ['title', 'color_id.namee']]],
                ],
            ],
            [SearchFilter::class => $this->makeSearchFilter()],
        );
        $this->registry->register('articles', $definition);

        try {
            $this->invokeValidate(['articles' => $definition]);
            self::fail('Expected InvalidApiDefinitionException was not thrown.');
        } catch (InvalidApiDefinitionException $e) {
            self::assertStringContainsString("Filter 'q'", $e->getMessage());
            self::assertStringContainsString('leaf column "tx_myext_domain_model_color.namee"', $e->getMessage());
        }
    }

    private function makePathFilter(): RelationPathFilter
    {
        // preResolve only needs the builder's resolvePath + $GLOBALS['TCA']; the
        // ConnectionPool is used at apply() time, never during pre-resolution/validation.
        return new RelationPathFilter(
            new RelationSubqueryBuilder($this->createMock(ConnectionPool::class), new RelationResolver()),
        );
    }

    private function makeSearchFilter(): SearchFilter
    {
        return new SearchFilter(
            new RelationSubqueryBuilder($this->createMock(ConnectionPool::class), new RelationResolver()),
        );
    }

    /**
     * Construct a loader and invoke its private validateDefinitions via reflection.
     *
     * @param array<string, ApiDefinition> $definitions
     */
    private function invokeValidate(array $definitions): void
    {
        $loader = new ApiDefinitionLoader(
            $this->createMock(PackageManager::class),
            $this->createMock(PhpFrontend::class),
            $this->registry,
            new TcaValidatorDeriver(),
        );

        $method = new \ReflectionMethod($loader, 'validateDefinitions');
        $method->invoke($loader, $definitions);
    }
}
