<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApiTestCacheParameters;

use MaikSchneider\TcaApi\Event\AfterOperationEvent;

final class QueryTitle
{
    public function __invoke(AfterOperationEvent $event): void
    {
        if ($event->getConfig()->resourceName !== 'cached-vary-articles') {
            return;
        }

        $query = $event->getRequest()->getQueryParams();
        $path = $query['path'] ?? null;
        $title = \is_string($path) ? $path : json_encode($path, JSON_THROW_ON_ERROR);
        $data = $event->getData();
        if ($event->getOperation() === 'show') {
            $data['title'] = $title;
        } else {
            foreach ($data as &$member) {
                $member['title'] = $title;
            }
            unset($member);
        }
        $event->setData($data);
    }
}
