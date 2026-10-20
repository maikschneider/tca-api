<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table'        => 'tt_content',
        'resourceName' => 'split-content',
        'resourceType' => 'SplitContent',
        'operations'   => ['list', 'show', 'create'],
        'storagePid'   => 1,
        'recordTypes'  => [
            'include' => ['textmedia', 'header'],
            'split'   => true,
        ],
    ],
    'security' => [
        'list'   => AccessRole::PUBLIC,
        'show'   => AccessRole::PUBLIC,
        'create' => AccessRole::FE_USER,
    ],
    'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
    'types' => [
        'header' => [
            'security' => ['list' => AccessRole::FE_USER],
        ],
    ],
];
