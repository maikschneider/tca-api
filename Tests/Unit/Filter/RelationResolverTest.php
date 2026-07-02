<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\RelationHop;
use MaikSchneider\TcaApi\Filter\RelationResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RelationResolver — every resolution outcome, success and failure.
 *
 * The resolver reads $GLOBALS['TCA'] directly, so each test seeds the minimal column
 * config it needs and restores the global afterwards.
 */
final class RelationResolverTest extends TestCase
{
    private RelationResolver $resolver;

    /** @var array<string, mixed> */
    private array $tcaBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver  = new RelationResolver();
        $this->tcaBackup = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA']  = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
        parent::tearDown();
    }

    #[Test]
    public function resolveThrowsForUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"t.missing" is not a known TCA column');

        $this->resolver->resolve('t', 'missing');
    }

    #[Test]
    public function resolveThrowsForNonRelationColumn(): void
    {
        $GLOBALS['TCA']['t']['columns']['title']['config'] = ['type' => 'input'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"t.title" (type=input) is not a filterable relation');

        $this->resolver->resolve('t', 'title');
    }

    #[Test]
    public function resolveThrowsForMmRelationWithoutResolvableTarget(): void
    {
        // MM set, but neither foreign_table nor a usable allowed list → target unknown.
        $GLOBALS['TCA']['t']['columns']['rel']['config'] = [
            'type' => 'group',
            'MM'   => 'some_mm',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot determine the related table for MM relation "t.rel"');

        $this->resolver->resolve('t', 'rel');
    }

    #[Test]
    public function resolveReturnsFkHopForSingleValueSelect(): void
    {
        $GLOBALS['TCA']['t']['columns']['color_id']['config'] = [
            'type'          => 'select',
            'foreign_table' => 'tx_color',
        ];

        $hop = $this->resolver->resolve('t', 'color_id');

        self::assertSame(RelationHop::KIND_FK, $hop->kind);
        self::assertSame('t', $hop->sourceTable);
        self::assertSame('tx_color', $hop->targetTable);
        self::assertSame('color_id', $hop->fkColumn);
    }

    #[Test]
    public function resolveReturnsMmHopForCategoryRelation(): void
    {
        $GLOBALS['TCA']['t']['columns']['categories']['config'] = [
            'type'              => 'category',
            'MM'                => 'sys_category_record_mm',
            'foreign_table'     => 'sys_category',
            'MM_opposite_field' => 'items',
            'MM_match_fields'   => ['tablenames' => 't', 'fieldname' => 'categories'],
        ];

        $hop = $this->resolver->resolve('t', 'categories');

        self::assertSame(RelationHop::KIND_MM, $hop->kind);
        self::assertSame('sys_category', $hop->targetTable);
        self::assertSame('sys_category_record_mm', $hop->mmTable);
        // On the opposite side, the related record sits in uid_local.
        self::assertSame('uid_foreign', $hop->mmSourceKey);
        self::assertSame('uid_local', $hop->mmTargetKey);
        self::assertSame(['tablenames' => 't', 'fieldname' => 'categories'], $hop->mmMatch);
    }
}
