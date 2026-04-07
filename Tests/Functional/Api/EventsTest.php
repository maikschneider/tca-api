<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    // ── BeforeOperationEvent ──────────────────────────────────────────────────

    public function testBeforeOperationEventFiresOnList(): void
    {
        $this->executeApiRequest('/_api/articles');

        $events = EventCollector::getByClass(BeforeOperationEvent::class);
        self::assertCount(1, $events);
    }

    public function testBeforeOperationEventCarriesListOperation(): void
    {
        $this->executeApiRequest('/_api/articles');

        $events = EventCollector::getByClass(BeforeOperationEvent::class);
        self::assertNotEmpty($events, 'No BeforeOperationEvent was dispatched.');
        self::assertSame('list', $events[0]->getOperation());
    }

    public function testBeforeOperationEventFiresOnShow(): void
    {
        $this->executeApiRequest('/_api/articles/1');

        $events = EventCollector::getByClass(BeforeOperationEvent::class);
        self::assertCount(1, $events);
    }

    public function testBeforeOperationEventCarriesShowOperation(): void
    {
        $this->executeApiRequest('/_api/articles/1');

        $events = EventCollector::getByClass(BeforeOperationEvent::class);
        self::assertNotEmpty($events, 'No BeforeOperationEvent was dispatched.');
        self::assertSame('show', $events[0]->getOperation());
    }

    public function testBeforeOperationEventFiresOnCreate(): void
    {
        $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'New']);

        $events = EventCollector::getByClass(BeforeOperationEvent::class);
        self::assertCount(1, $events);
    }

    public function testBeforeOperationEventCarriesCreateOperation(): void
    {
        $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'New']);

        $events = EventCollector::getByClass(BeforeOperationEvent::class);
        self::assertNotEmpty($events, 'No BeforeOperationEvent was dispatched.');
        self::assertSame('create', $events[0]->getOperation());
    }

    // ── AfterOperationEvent ───────────────────────────────────────────────────

    public function testAfterOperationEventFiresOnList(): void
    {
        $this->executeApiRequest('/_api/articles');

        $events = EventCollector::getByClass(AfterOperationEvent::class);
        self::assertCount(1, $events);
    }

    public function testAfterOperationEventCarriesListOperation(): void
    {
        $this->executeApiRequest('/_api/articles');

        $events = EventCollector::getByClass(AfterOperationEvent::class);
        self::assertNotEmpty($events, 'No AfterOperationEvent was dispatched.');
        self::assertSame('list', $events[0]->getOperation());
    }

    public function testAfterOperationEventFiresOnShow(): void
    {
        $this->executeApiRequest('/_api/articles/1');

        $events = EventCollector::getByClass(AfterOperationEvent::class);
        self::assertCount(1, $events);
    }

    // ── BeforeWriteEvent ──────────────────────────────────────────────────────

    public function testBeforeWriteEventFiresOnCreate(): void
    {
        $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'New']);

        $events = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertCount(1, $events);
    }

    public function testBeforeWriteEventCarriesCreateOperation(): void
    {
        $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'New']);

        $events = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertNotEmpty($events, 'No BeforeWriteEvent was dispatched.');
        self::assertSame('create', $events[0]->getOperation());
    }

    public function testBeforeWriteEventCarriesTableName(): void
    {
        $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'New']);

        $events = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertNotEmpty($events, 'No BeforeWriteEvent was dispatched.');
        self::assertSame('tx_myext_domain_model_article', $events[0]->getTable());
    }

    public function testBeforeWriteEventCarriesInputData(): void
    {
        $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'Event Data Test']);

        $events = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertNotEmpty($events, 'No BeforeWriteEvent was dispatched.');
        self::assertSame('Event Data Test', $events[0]->getData()['title']);
    }

    public function testBeforeWriteEventFiresOnUpdate(): void
    {
        $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, ['title' => 'Updated']);

        $events = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertCount(1, $events);
    }

    public function testBeforeWriteEventCarriesUpdateOperation(): void
    {
        $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, ['title' => 'Updated']);

        $events = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertNotEmpty($events, 'No BeforeWriteEvent was dispatched.');
        self::assertSame('update', $events[0]->getOperation());
    }

    public function testBeforeWriteEventFiresOnDelete(): void
    {
        $this->executeApiWriteRequestAsBackendAdmin('DELETE', '/_api/articles/3', 1);

        $events = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertCount(1, $events);
    }

    public function testBeforeWriteEventCarriesDeleteOperation(): void
    {
        $this->executeApiWriteRequestAsBackendAdmin('DELETE', '/_api/articles/3', 1);

        $events = EventCollector::getByClass(BeforeWriteEvent::class);
        self::assertNotEmpty($events, 'No BeforeWriteEvent was dispatched.');
        self::assertSame('delete', $events[0]->getOperation());
    }

    // ── AfterWriteEvent ───────────────────────────────────────────────────────

    public function testAfterWriteEventFiresOnCreate(): void
    {
        $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'New']);

        $events = EventCollector::getByClass(AfterWriteEvent::class);
        self::assertCount(1, $events);
    }

    public function testAfterWriteEventCarriesCreateOperation(): void
    {
        $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'New']);

        $events = EventCollector::getByClass(AfterWriteEvent::class);
        self::assertNotEmpty($events, 'No AfterWriteEvent was dispatched.');
        self::assertSame('create', $events[0]->getOperation());
    }

    public function testAfterWriteEventCarriesNewUid(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'New']);
        $body = $this->decodeResponseBody($response);

        $events = EventCollector::getByClass(AfterWriteEvent::class);
        self::assertNotEmpty($events, 'No AfterWriteEvent was dispatched.');
        self::assertSame($body['uid'], $events[0]->getUid());
    }

    public function testAfterWriteEventFiresOnUpdate(): void
    {
        $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, ['title' => 'Updated']);

        $events = EventCollector::getByClass(AfterWriteEvent::class);
        self::assertCount(1, $events);
    }

    public function testAfterWriteEventFiresOnDelete(): void
    {
        $this->executeApiWriteRequestAsBackendAdmin('DELETE', '/_api/articles/3', 1);

        $events = EventCollector::getByClass(AfterWriteEvent::class);
        self::assertCount(1, $events);
    }
}
