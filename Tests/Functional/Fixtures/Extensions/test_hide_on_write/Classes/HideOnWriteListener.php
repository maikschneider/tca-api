<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApiTestHideOnWrite;

use MaikSchneider\TcaApi\Event\BeforeWriteEvent;

/**
 * Test helper: stores every record created or updated through the
 * "hidden-write-articles" resource hidden, the way an extension moderates
 * submitted records. Clients cannot write the hidden column themselves.
 */
final class HideOnWriteListener
{
    public const RESOURCE_NAME = 'hidden-write-articles';

    public function __invoke(BeforeWriteEvent $event): void
    {
        if ($event->getConfig()->resourceName !== self::RESOURCE_NAME || $event->getOperation() === 'delete') {
            return;
        }

        $event->setData([...$event->getData(), 'hidden' => 1]);
    }
}
