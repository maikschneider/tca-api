<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Testing;

use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeOperationEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;

/**
 * Test helper: collects dispatched API events for assertion in functional tests.
 *
 * Registered as a PSR-14 listener for all four API event types via Services.yaml.
 * Uses a static bag so tests can assert collected events without DI container access.
 *
 * Always call EventCollector::reset() in test setUp() to isolate test state.
 */
final class EventCollector
{
    /** @var list<object> */
    private static array $events = [];

    public function onBeforeWrite(BeforeWriteEvent $event): void
    {
        self::$events[] = $event;
    }

    public function onAfterWrite(AfterWriteEvent $event): void
    {
        self::$events[] = $event;
    }

    public function onBeforeOperation(BeforeOperationEvent $event): void
    {
        self::$events[] = $event;
    }

    public function onAfterOperation(AfterOperationEvent $event): void
    {
        self::$events[] = $event;
    }

    /** Reset collected events between tests. */
    public static function reset(): void
    {
        self::$events = [];
    }

    /** @return list<object> */
    public static function getAll(): array
    {
        return self::$events;
    }

    /**
     * Return all collected events of the given class.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    public static function getByClass(string $class): array
    {
        return array_values(array_filter(self::$events, fn(object $e) => $e instanceof $class));
    }
}
