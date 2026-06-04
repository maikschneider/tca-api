<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Color',
        'label' => 'name',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
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
        'sys_language_uid' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
            'config' => [
                'type' => 'language',
            ],
        ],
        'l10n_parent' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [['label' => '', 'value' => 0]],
                'foreign_table' => 'tx_myext_domain_model_color',
                'default' => 0,
            ],
        ],
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
        'field_to_match' => [
            'config' => [
                'type' => 'input',
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
