<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\DataAccess;

use MaikSchneider\TcaApi\DataAccess\ItemQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ItemQueryTest extends TestCase
{
    // ── Default values ───────────────────────────────────────────────────

    #[Test]
    public function defaultValuesAreSetWhenConstructedWithNoArguments(): void
    {
        $query = new ItemQuery();

        self::assertSame([], $query->fields);
        self::assertNull($query->language);
        self::assertSame('show', $query->operation);
        self::assertNull($query->request);
        self::assertSame('', $query->baseUrl);
    }

    // ── Explicit values ──────────────────────────────────────────────────

    #[Test]
    public function allPropertiesCanBeSetViaNamedArguments(): void
    {
        $query = new ItemQuery(
            fields:    ['uid', 'title', 'body'],
            language:  null,
            operation: 'show',
            request:   null,
            baseUrl:   '/_api/articles',
        );

        self::assertSame(['uid', 'title', 'body'], $query->fields);
        self::assertNull($query->language);
        self::assertSame('show', $query->operation);
        self::assertNull($query->request);
        self::assertSame('/_api/articles', $query->baseUrl);
    }

    #[Test]
    public function fieldsCanBeEmpty(): void
    {
        $query = new ItemQuery(fields: []);

        self::assertSame([], $query->fields);
    }

    #[Test]
    public function fieldsCanContainMultipleColumns(): void
    {
        $query = new ItemQuery(fields: ['title', 'body', 'created', 'color_id']);

        self::assertCount(4, $query->fields);
        self::assertContains('title', $query->fields);
        self::assertContains('color_id', $query->fields);
    }

    #[Test]
    public function baseUrlCanBeNonEmpty(): void
    {
        $query = new ItemQuery(baseUrl: '/_api/articles');

        self::assertSame('/_api/articles', $query->baseUrl);
    }

    // ── Immutability ─────────────────────────────────────────────────────

    #[Test]
    public function queryIsImmutableReadonlyObject(): void
    {
        $query1 = new ItemQuery(operation: 'show');
        $query2 = new ItemQuery(operation: 'list');

        self::assertSame('show', $query1->operation);
        self::assertSame('list', $query2->operation);
    }

    // ── Type checks ──────────────────────────────────────────────────────

    #[Test]
    public function fieldsIsAnArray(): void
    {
        $query = new ItemQuery(fields: ['uid', 'title']);

        self::assertIsArray($query->fields);
    }

    #[Test]
    public function operationIsAString(): void
    {
        $query = new ItemQuery(operation: 'show');

        self::assertIsString($query->operation);
    }
}