<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Color',
        'label' => 'name',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/svgs/mimetypes/mimetypes-text-text.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        0 => [
            'showitem' => 'name, hidden',
        ],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.visible',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    [
                        'label' => '',
                        'invertStateDisplay' => true,
                    ],
                ],
            ],
        ],
        'name' => [
            'label' => 'Name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'hex' => [
            'label' => 'Hex Code',
            'config' => [
                'type' => 'input',
                'size' => 10,
                'max' => 7,
                'eval' => 'trim',
            ],
        ],
        'foreign_article_id' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'parent_tablename' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'category_id' => [
            'label' => 'Category',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'sys_category',
                'items' => [['label' => '- none -', 'value' => 0]],
                'default' => 0,
            ],
        ],
        'secret_column' => [
            'label' => 'Secret',
            'config' => [
                'type' => 'password',
            ],
        ],
        'crop_settings' => [
            'label' => 'Crop Settings',
            'config' => [
                'type' => 'imageManipulation',
            ],
        ],
    ],
];
