<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Typed item',
        'label' => 'title',
        'type'  => 'item_type',
    ],
    'columns' => [
        'item_type' => [
            'label'  => 'Type',
            'config' => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'items'      => [
                    ['label' => 'Article', 'value' => 'article'],
                    ['label' => 'Note', 'value' => 'note'],
                ],
            ],
        ],
        'title' => [
            'label'  => 'Title',
            'config' => ['type' => 'input'],
        ],
    ],
    'types' => [
        'article' => ['showitem' => 'item_type, title'],
        'note'    => ['showitem' => 'title'],
    ],
];
