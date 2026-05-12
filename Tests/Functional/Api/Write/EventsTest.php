<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeOperationEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;
use MaikSchneider\TcaApi\Testing\EventCollector;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for PSR-14 event dispatching.
 *
 * Drives implementation of event dispatch in all operation handlers.
 *
 * Events under test:
 *   BeforeOperationEvent — dispatched before any operation executes
 *   AfterOperationEvent  — dispatched after any operation completes
 *   BeforeWriteEvent     — dispatched before create / update / delete
 *   AfterWriteEvent      — dispatched after create / update / delete
 *
 * RED phase: no events are dispatched yet — all tests must fail initially.
 */
final class EventsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EventCollector::reset();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
    }

    // ── GET /_api/articles (list) ────────────────────────────────────────────

    public function testEventsOnList(): void
    {
        $this->executeApiRequest('/_api/articles');

        // BeforeOperationEvent fires and carries 'list' operation
        $beforeOps = EventCollector::getByClass(BeforeOperationEvent::class);
        self::assertCount(1, $beforeOps);
        self::assertNotEmpty($beforeOps, 'No BeforeOperationEvent was dispatched.');
        self::assertSame('list', $beforeOps[0]->getOperation());

        // AfterOperationEvent fires and carries 'list' operation
        $afterOps = EventCollector::getByClass(AfterOperationEvent::class);
        self::assertCount(1, $afterOps);
        self::assertNotEmpty($afterOps, 'No AfterOperationEvent was dispatched.');
        self::assertSame('list', $afterOps[0]->getOperation());
    }

    // ── GET /_api/articles/1 (show) ──────────────────────────────────────────

    public function testEventsOnShow(): void
    {
        $this->executeApiRequest('/_api/articles/1');

        // BeforeOperationEvent fires and carries 'show' operation
        $beforeOps = EventCollector::getByClass(BeforeOperationEvent::class);
        self::assertCount(1, $beforeOps);
        self::assertNotEmpty($beforeOps, 'No BeforeOperationEvent was dispatched.');
        self::assertSame('show', $beforeOps[0]->getOperation());

        // AfterOperationEvent fires
        $afterOps = EventCollector::getByClass(AfterOperationEvent::class);
        self::assertCount(1, $afterOps);
    }

    // ── POST /_api/articles (create) ─────────────────────────────────────────

    public function testEventsOnCreate(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'Event Data Test']);
        $body = $this->decodeResponseBody($response);

        // BeforeOperationEvent fires and carries 'create' operation
        $beforeOps = EventCollector::getByClass(BeforeOperationEvent::class);
        self::assertCount(1, $beforeOps);
        self::assertNotEmpty($beforeOps, 'No BeforeOperationEvent was dispatched.');
        self::assertSame('create', $beforeOps[0]->getOperation());

        // BeforeWriteEvent fires, carries 'create' operation, table name, and input data
        $beforeWrites = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertCount(1, $beforeWrites);
        self::assertNotEmpty($beforeWrites, 'No BeforeWriteEvent was dispatched.');
        self::assertSame('create', $beforeWrites[0]->getOperation());
        self::assertSame('tx_myext_domain_model_article', $beforeWrites[0]->getTable());
        self::assertSame('Event Data Test', $beforeWrites[0]->getData()['title']);

        // AfterWriteEvent fires, carries 'create' operation and new UID
        $afterWrites = EventCollector::getByClass(AfterWriteEvent::class);
        self::assertCount(1, $afterWrites);
        self::assertNotEmpty($afterWrites, 'No AfterWriteEvent was dispatched.');
        self::assertSame('create', $afterWrites[0]->getOperation());
        self::assertSame($body['uid'], $afterWrites[0]->getUid());
    }

    // ── PUT /_api/articles/1 (update) ────────────────────────────────────────

    public function testEventsOnUpdate(): void
    {
        $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, ['title' => 'Updated']);

        // BeforeWriteEvent fires and carries 'update' operation
        $beforeWrites = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertCount(1, $beforeWrites);
        self::assertNotEmpty($beforeWrites, 'No BeforeWriteEvent was dispatched.');
        self::assertSame('update', $beforeWrites[0]->getOperation());

        // AfterWriteEvent fires
        $afterWrites = EventCollector::getByClass(AfterWriteEvent::class);
        self::assertCount(1, $afterWrites);
    }

    // ── DELETE /_api/articles/3 (delete) ─────────────────────────────────────

    public function testEventsOnDelete(): void
    {
        $this->executeApiWriteRequestAsBackendUser('DELETE', '/_api/articles/3', 2);

        // BeforeWriteEvent fires and carries 'delete' operation
        $beforeWrites = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertCount(1, $beforeWrites);
        self::assertNotEmpty($beforeWrites, 'No BeforeWriteEvent was dispatched.');
        self::assertSame('delete', $beforeWrites[0]->getOperation());

        // AfterWriteEvent fires
        $afterWrites = EventCollector::getByClass(AfterWriteEvent::class);
        self::assertCount(1, $afterWrites);
    }
}
