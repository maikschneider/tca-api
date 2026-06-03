<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Configuration;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Exception\InvalidApiDefinitionException;
use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use MaikSchneider\TcaApi\Loader\TcaValidatorDeriver;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
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
