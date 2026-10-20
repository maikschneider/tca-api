<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Event;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeOperationEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class EventTest extends TestCase
{
    // ── AfterOperationEvent ──────────────────────────────────────────────

    #[Test]
    public function afterOperationEventGettersReturnConstructorValues(): void
    {
        $request = $this->request();
        $config  = $this->config();
        $event   = new AfterOperationEvent('list', ['hydra:member' => []], $request, $config);

        self::assertSame('list', $event->getOperation());
        self::assertSame(['hydra:member' => []], $event->getData());
        self::assertSame($request, $event->getRequest());
        self::assertSame($config, $event->getConfig());
    }

    #[Test]
    public function afterOperationEventSetDataMutatesData(): void
    {
        $event = new AfterOperationEvent('list', ['original' => true], $this->request(), $this->config());
        $event->setData(['replaced' => true]);

        self::assertSame(['replaced' => true], $event->getData());
    }

    #[Test]
    public function afterOperationEventStopPropagation(): void
    {
        $event = new AfterOperationEvent('show', [], $this->request(), $this->config());

        self::assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        self::assertTrue($event->isPropagationStopped());
    }

    // ── BeforeOperationEvent ─────────────────────────────────────────────

    #[Test]
    public function beforeOperationEventGettersReturnConstructorValues(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $config  = ApiDefinition::fromArray([
            'general' => ['table' => 'tx_test', 'resourceName' => 'tests', 'resourceType' => 'Test'],
        ]);

        $event = new BeforeOperationEvent('create', $request, $config);

        self::assertSame('create', $event->getOperation());
        self::assertSame($request, $event->getRequest());
        self::assertSame($config, $event->getConfig());
    }

    #[Test]
    public function beforeOperationEventStopPropagation(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $config  = ApiDefinition::fromArray([
            'general' => ['table' => 'tx_test', 'resourceName' => 'tests', 'resourceType' => 'Test'],
        ]);

        $event = new BeforeOperationEvent('delete', $request, $config);

        self::assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        self::assertTrue($event->isPropagationStopped());
    }

    // ── AfterWriteEvent ──────────────────────────────────────────────────

    #[Test]
    public function afterWriteEventGettersReturnConstructorValues(): void
    {
        $request = $this->request();
        $config  = $this->config();
        $event   = new AfterWriteEvent('tx_news', 'create', 42, $request, $config);

        self::assertSame('tx_news', $event->getTable());
        self::assertSame('create', $event->getOperation());
        self::assertSame(42, $event->getUid());
        self::assertSame($request, $event->getRequest());
        self::assertSame($config, $event->getConfig());
    }

    #[Test]
    public function afterWriteEventStopPropagation(): void
    {
        $event = new AfterWriteEvent('tx_news', 'update', 1, $this->request(), $this->config());

        self::assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        self::assertTrue($event->isPropagationStopped());
    }

    // ── BeforeWriteEvent ─────────────────────────────────────────────────

    #[Test]
    public function beforeWriteEventGettersReturnConstructorValues(): void
    {
        $request = $this->request();
        $config  = $this->config();
        $event   = new BeforeWriteEvent('tx_news', 'update', ['title' => 'Hello'], $request, $config, 7);

        self::assertSame('tx_news', $event->getTable());
        self::assertSame('update', $event->getOperation());
        self::assertSame(['title' => 'Hello'], $event->getData());
        self::assertSame($request, $event->getRequest());
        self::assertSame($config, $event->getConfig());
        self::assertSame(7, $event->getUid());
    }

    #[Test]
    public function beforeWriteEventSetDataMutatesData(): void
    {
        $event = new BeforeWriteEvent('tx_news', 'create', ['title' => 'Old'], $this->request(), $this->config(), null);
        $event->setData(['title' => 'New']);

        self::assertSame(['title' => 'New'], $event->getData());
    }

    #[Test]
    public function beforeWriteEventUidIsNullOnCreate(): void
    {
        $event = new BeforeWriteEvent('tx_news', 'create', [], $this->request(), $this->config(), null);

        self::assertNull($event->getUid());
    }

    #[Test]
    public function beforeWriteEventStopPropagation(): void
    {
        $event = new BeforeWriteEvent('tx_news', 'delete', [], $this->request(), $this->config(), 5);

        self::assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        self::assertTrue($event->isPropagationStopped());
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
