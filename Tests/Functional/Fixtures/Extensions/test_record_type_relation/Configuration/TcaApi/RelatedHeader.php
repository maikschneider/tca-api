<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table'        => 'tt_content',
        'resourceName' => 'related-header',
        'resourceType' => 'RelatedHeader',
        'operations'   => ['list', 'show', 'create'],
        'storagePid'   => 1,
        'recordTypes'  => ['header'],
    ],
    'security' => [
        'list'   => AccessRole::PUBLIC,
        'show'   => AccessRole::PUBLIC,
        'create' => AccessRole::FE_USER,
    ],
];
