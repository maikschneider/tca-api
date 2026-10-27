<?php

declare(strict_types=1);

/**
 * One column per TCA type and relation shape, so the OpenAPI contract test
 * (ContractMatrixTest) sees each serialized the way the API sends it.
 */
return [
    'ctrl' => [
        'title' => 'Contract record',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        0 => [
            'showitem' => 'title, subtitle, email_address, color_value, path_segment, record_uuid, folder_path, amount, quantity, is_active, choice, mode, level, flags, starts_at, website, notes, settings, parent, tags, main_category, images, attachment',
        ],
    ],
    'columns' => [
        'hidden' => [
            'config' => ['type' => 'check'],
        ],
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input'],
        ],
        'subtitle' => [
            'label' => 'Subtitle',
            'config' => ['type' => 'input', 'nullable' => true],
        ],
        'email_address' => [
            'label' => 'Email',
            'config' => ['type' => 'email'],
        ],
        'color_value' => [
            'label' => 'Color',
            'config' => ['type' => 'color'],
        ],
        'path_segment' => [
            'label' => 'Slug',
            'config' => ['type' => 'slug', 'generatorOptions' => ['fields' => ['title']]],
        ],
        'record_uuid' => [
            'label' => 'UUID',
            'config' => ['type' => 'uuid'],
        ],
        'folder_path' => [
            'label' => 'Folder',
            'config' => ['type' => 'folder'],
        ],
        'amount' => [
            'label' => 'Amount',
            'config' => ['type' => 'number', 'format' => 'decimal', 'nullable' => true],
        ],
        'quantity' => [
            'label' => 'Quantity',
            'config' => ['type' => 'number'],
        ],
        'is_active' => [
            'label' => 'Active',
            'config' => ['type' => 'check'],
        ],
        'choice' => [
            'label' => 'Choice',
            'config' => [
                'type' => 'radio',
                'items' => [['label' => 'A', 'value' => 0], ['label' => 'B', 'value' => 1]],
            ],
        ],
        'mode' => [
            'label' => 'Mode',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [['label' => 'Light', 'value' => 'light'], ['label' => 'Dark', 'value' => 'dark']],
            ],
        ],
        'level' => [
            'label' => 'Level',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [['label' => 'Low', 'value' => 0], ['label' => 'High', 'value' => 2]],
            ],
        ],
        'flags' => [
            'label' => 'Flags',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'items' => [['label' => 'One', 'value' => 1], ['label' => 'Two', 'value' => 2]],
            ],
        ],
        'starts_at' => [
            'label' => 'Starts at',
            'config' => ['type' => 'datetime'],
        ],
        'website' => [
            'label' => 'Website',
            'config' => ['type' => 'link'],
        ],
        'notes' => [
            'label' => 'Notes',
            'config' => ['type' => 'text'],
        ],
        'settings' => [
            'label' => 'Settings',
            'config' => ['type' => 'json'],
        ],
        // A list although maxitems is 1: no relationship, so TYPO3 treats it as a list (#262).
        'parent' => [
            'label' => 'Parent',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'tx_testcontract_record',
                'maxitems' => 1,
            ],
        ],
        // A list although it is a selectSingle: the MM makes it manyToMany (#262).
        'tags' => [
            'label' => 'Tags',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_myext_domain_model_color',
                'MM' => 'tx_testcontract_record_color_mm',
            ],
        ],
        // One record although it is a category field (#262).
        'main_category' => [
            'label' => 'Main category',
            'config' => ['type' => 'category', 'relationship' => 'oneToOne'],
        ],
        'images' => [
            'label' => 'Images',
            'config' => ['type' => 'file', 'allowed' => 'common-image-types'],
        ],
        'attachment' => [
            'label' => 'Attachment',
            'config' => ['type' => 'file', 'allowed' => 'pdf', 'maxitems' => 1],
        ],
    ],
];
