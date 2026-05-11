<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Registry;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiRegistryTest extends TestCase
{
    private ApiRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ApiRegistry();
        $this->registry->reset();
    }

    protected function tearDown(): void
    {
        $this->registry->reset();
    }

    private static function makeDefinition(string $table, string $resourceName): ApiDefinition
    {
        return ApiDefinition::fromArray([
            'general' => [
                'table'        => $table,
                'resourceName' => $resourceName,
                'resourceType' => ucfirst($resourceName),
            ],
        ]);
    }

    // ── register() / get() ───────────────────────────────────────────────

    #[Test]
    public function registerAndGetByResourceName(): void
    {
        $def = self::makeDefinition('tx_news_domain_model_news', 'news');
        $this->registry->register('news', $def);

        self::assertSame($def, $this->registry->get('news'));
    }

    #[Test]
    public function getReturnsNullForUnknownResourceName(): void
    {
        self::assertNull($this->registry->get('unknown'));
    }

    // ── getByTable() ─────────────────────────────────────────────────────

    #[Test]
    public function getByTableReturnsMatchingDefinition(): void
    {
        $def = self::makeDefinition('tx_news_domain_model_news', 'news');
        $this->registry->register('news', $def);

        self::assertSame($def, $this->registry->getByTable('tx_news_domain_model_news'));
    }

    #[Test]
    public function getByTableReturnsNullWhenNoMatch(): void
    {
        $def = self::makeDefinition('tx_news_domain_model_news', 'news');
        $this->registry->register('news', $def);

        self::assertNull($this->registry->getByTable('tx_other_table'));
    }

    // ── getAll() ─────────────────────────────────────────────────────────

    #[Test]
    public function getAllReturnsAllRegisteredDefinitions(): void
    {
        $defA = self::makeDefinition('tx_ext_model_a', 'a');
        $defB = self::makeDefinition('tx_ext_model_b', 'b');
        $this->registry->register('a', $defA);
        $this->registry->register('b', $defB);

        $all = $this->registry->getAll();

        self::assertCount(2, $all);
        self::assertSame($defA, $all['a']);
        self::assertSame($defB, $all['b']);
    }

    #[Test]
    public function getAllReturnsEmptyArrayWhenRegistryIsEmpty(): void
    {
        self::assertSame([], $this->registry->getAll());
    }

    // ── replaceAll() ─────────────────────────────────────────────────────

    #[Test]
    public function replaceAllReplacesEntireRegistry(): void
    {
        $defOld = self::makeDefinition('tx_old', 'old');
        $this->registry->register('old', $defOld);

        $defNew = self::makeDefinition('tx_new', 'new');
        $this->registry->replaceAll(['new' => $defNew]);

        self::assertNull($this->registry->get('old'));
        self::assertSame($defNew, $this->registry->get('new'));
    }

    // ── reset() ──────────────────────────────────────────────────────────

    #[Test]
    public function resetEmptiesRegistry(): void
    {
        $def = self::makeDefinition('tx_test', 'test');
        $this->registry->register('test', $def);

        $this->registry->reset();

        self::assertSame([], $this->registry->getAll());
    }
}
