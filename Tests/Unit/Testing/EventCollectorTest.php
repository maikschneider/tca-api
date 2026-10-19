<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Testing;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;
use MaikSchneider\TcaApiTestEvents\EventCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class EventCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        EventCollector::reset();
    }

    protected function tearDown(): void
    {
        EventCollector::reset();
    }

    #[Test]
    public function getAllReturnsEmptyArrayAfterReset(): void
    {
        self::assertSame([], EventCollector::getAll());
    }

    #[Test]
    public function getAllReturnsCollectedEvents(): void
    {
        $collector = new EventCollector();
        $event     = new BeforeWriteEvent('tx_news', 'create', [], $this->request(), $this->config(), null);

        $collector->onBeforeWrite($event);

        self::assertCount(1, EventCollector::getAll());
        self::assertSame($event, EventCollector::getAll()[0]);
    }

    #[Test]
    public function resetClearsCollectedEvents(): void
    {
        $collector = new EventCollector();
        $collector->onAfterWrite(new AfterWriteEvent('tx_news', 'create', 1, $this->request(), $this->config()));

        EventCollector::reset();

        self::assertSame([], EventCollector::getAll());
    }

    #[Test]
    public function getByClassFiltersToRequestedClass(): void
    {
        $collector   = new EventCollector();
        $beforeWrite = new BeforeWriteEvent('tx_news', 'create', [], $this->request(), $this->config(), null);
        $afterWrite  = new AfterWriteEvent('tx_news', 'create', 42, $this->request(), $this->config());

        $collector->onBeforeWrite($beforeWrite);
        $collector->onAfterWrite($afterWrite);

        $result = EventCollector::getByClass(BeforeWriteEvent::class);

        self::assertCount(1, $result);
        self::assertSame($beforeWrite, $result[0]);
    }

    #[Test]
    public function getByClassReturnsEmptyArrayWhenNoneMatch(): void
    {
        $collector = new EventCollector();
        $collector->onAfterWrite(new AfterWriteEvent('tx_news', 'delete', 5, $this->request(), $this->config()));

        $result = EventCollector::getByClass(BeforeWriteEvent::class);

        self::assertSame([], $result);
    }

    #[Test]
    public function allFourListenerMethodsCollectEvents(): void
    {
        $collector = new EventCollector();

        // Use the AfterWrite and BeforeWrite event listeners only (don't need full stack for others)
        $collector->onBeforeWrite(new BeforeWriteEvent('t', 'create', [], $this->request(), $this->config(), null));
        $collector->onAfterWrite(new AfterWriteEvent('t', 'create', 1, $this->request(), $this->config()));

        self::assertCount(2, EventCollector::getAll());
    }

    private function request(): ServerRequestInterface
    {
        return self::createStub(ServerRequestInterface::class);
    }

    private function config(): ApiDefinition
    {
        return ApiDefinition::fromArray([
            'general' => ['table' => 'tx_test', 'resourceName' => 'tests', 'resourceType' => 'Test'],
        ]);
    }
}
