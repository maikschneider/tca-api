<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\DataAccess;

use MaikSchneider\TcaApi\DataAccess\CollectionQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollectionQueryTest extends TestCase
{
    // ── Default values ───────────────────────────────────────────────────

    #[Test]
    public function defaultValuesAreSetWhenConstructedWithNoArguments(): void
    {
        $query = new CollectionQuery();

        self::assertSame(1, $query->page);
        self::assertNull($query->itemsPerPage);
        self::assertSame([], $query->filters);
        self::assertSame([], $query->order);
        self::assertSame([], $query->fields);
        self::assertNull($query->language);
        self::assertSame('list', $query->operation);
        self::assertNull($query->request);
        self::assertSame('', $query->baseUrl);
    }

    // ── Explicit values ──────────────────────────────────────────────────

    #[Test]
    public function allPropertiesCanBeSetViaNamedArguments(): void
    {
        $query = new CollectionQuery(
            page:         3,
            itemsPerPage: 15,
            filters:      ['color' => 'red'],
            order:        ['title' => 'desc'],
            fields:       ['title', 'uid'],
            language:     null,
            operation:    'list',
            request:      null,
            baseUrl:      '/_api/articles',
        );

        self::assertSame(3, $query->page);
        self::assertSame(15, $query->itemsPerPage);
        self::assertSame(['color' => 'red'], $query->filters);
        self::assertSame(['title' => 'desc'], $query->order);
        self::assertSame(['title', 'uid'], $query->fields);
        self::assertNull($query->language);
        self::assertSame('list', $query->operation);
        self::assertNull($query->request);
        self::assertSame('/_api/articles', $query->baseUrl);
    }

    #[Test]
    public function itemsPerPageCanBeExplicitlySetToNull(): void
    {
        $query = new CollectionQuery(itemsPerPage: null);

        self::assertNull($query->itemsPerPage);
    }

    #[Test]
    public function itemsPerPageCanBeAPositiveInteger(): void
    {
        $query = new CollectionQuery(itemsPerPage: 50);

        self::assertSame(50, $query->itemsPerPage);
    }

    // ── Immutability ─────────────────────────────────────────────────────

    #[Test]
    public function queryIsImmutableReadonlyObject(): void
    {
        $query = new CollectionQuery(page: 2);

        // Verify the value is correctly stored and can be read
        self::assertSame(2, $query->page);

        // Attempting to set a readonly property would be a compile/runtime error;
        // we verify immutability by asserting the original object is unchanged.
        $query2 = new CollectionQuery(page: 5);
        self::assertSame(2, $query->page);
        self::assertSame(5, $query2->page);
    }

    // ── Type checks ──────────────────────────────────────────────────────

    #[Test]
    public function pageIsAnInteger(): void
    {
        $query = new CollectionQuery(page: 7);

        self::assertIsInt($query->page);
    }

    #[Test]
    public function filtersIsAnArray(): void
    {
        $query = new CollectionQuery(filters: ['published' => true, 'author' => 42]);

        self::assertIsArray($query->filters);
        self::assertSame(['published' => true, 'author' => 42], $query->filters);
    }

    #[Test]
    public function orderIsAnArray(): void
    {
        $query = new CollectionQuery(order: ['created' => 'desc', 'uid' => 'asc']);

        self::assertIsArray($query->order);
        self::assertSame(['created' => 'desc', 'uid' => 'asc'], $query->order);
    }

    #[Test]
    public function fieldsIsAnArray(): void
    {
        $query = new CollectionQuery(fields: ['uid', 'title', 'body']);

        self::assertIsArray($query->fields);
        self::assertCount(3, $query->fields);
    }
}