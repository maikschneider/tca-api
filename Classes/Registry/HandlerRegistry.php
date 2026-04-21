<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Registry;

use MaikSchneider\TcaApi\OperationHandler\OperationHandlerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class HandlerRegistry
{
    /** @var array<int, list<class-string<OperationHandlerInterface>>> */
    private static array $handlers = [];

    /** @var list<OperationHandlerInterface>|null */
    private static ?array $cachedInstances = null;

    /** @param class-string<OperationHandlerInterface> $handlerClass */
    public static function register(string $handlerClass, int $priority = 10): void
    {
        self::$handlers[$priority][] = $handlerClass;
        self::$cachedInstances = null;
    }

    /**
     * Returns all registered handler instances sorted by priority descending.
     * Instances are cached after the first call and invalidated on register().
     *
     * @return list<OperationHandlerInterface>
     */
    public static function getHandlers(): array
    {
        if (self::$cachedInstances !== null) {
            return self::$cachedInstances;
        }

        $sorted = self::$handlers;
        krsort($sorted);

        $instances = [];
        foreach ($sorted as $handlers) {
            foreach ($handlers as $class) {
                $instances[] = GeneralUtility::makeInstance($class);
            }
        }

        return self::$cachedInstances = $instances;
    }

    public static function reset(): void
    {
        self::$handlers        = [];
        self::$cachedInstances = null;
    }
}
