<?php

declare(strict_types=1);

return [
    'general' => [
        'table'        => 'tt_content',
        'resourceName' => 'split-only',
        'resourceType' => 'SplitOnly',
        'recordTypes'  => [
            'include'     => ['textmedia'],
            'split'       => true,
            'namePattern' => '{type}-only',
            'keepBase'    => false,
        ],
    ],
];
