<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

// Let DefaultTcaSchema create these columns, without ext_tables.sql overrides.
$columns = [
    'slug' => [
        'label' => 'Slug',
        'config' => ['type' => 'slug', 'generatorOptions' => ['fields' => ['uid']]],
    ],
    'nullable_choice' => [
        'label' => 'Choice',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => 'None', 'value' => null],
                ['label' => 'One', 'value' => 1],
            ],
        ],
    ],
    'radio_choice' => [
        'label' => 'Radio choice',
        'config' => [
            'type' => 'radio',
            'items' => [
                ['label' => 'None', 'value' => null],
                ['label' => 'One', 'value' => 1],
            ],
        ],
    ],
];

if ((new Typo3Version())->getMajorVersion() >= 14) {
    $columns['country'] = ['label' => 'Country', 'config' => ['type' => 'country']];
}

return [
    'ctrl' => ['title' => 'Scalar item', 'label' => 'slug'],
    'columns' => $columns,
    'types' => ['0' => ['showitem' => implode(', ', array_keys($columns))]],
];
