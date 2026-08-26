<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Article',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l18n_parent',
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
            'showitem' => 'title, color_id, parent_id, categories, profile_photo, downloads, first_name, last_name, article_url, fe_user_id, related_colors, related_items, related_items_inline, hidden',
        ],
    ],
    'columns' => [
        'sys_language_uid' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
            'config' => [
                'type' => 'language',
            ],
        ],
        'l18n_parent' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [['label' => '', 'value' => 0]],
                'foreign_table' => 'tx_myext_domain_model_article',
                'foreign_table_where' => 'AND {#tx_myext_domain_model_article}.{#pid}=###CURRENT_PID### AND {#tx_myext_domain_model_article}.{#sys_language_uid} IN (-1,0)',
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
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'color_id' => [
            'label' => 'Color',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_myext_domain_model_color',
                'items' => [
                    ['label' => '- none -', 'value' => 0],
                ],
                'default' => 0,
            ],
        ],
        'parent_id' => [
            'label' => 'Parent Article',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_myext_domain_model_article',
                'items' => [
                    ['label' => '- none -', 'value' => 0],
                ],
                'default' => 0,
            ],
        ],
        'categories' => [
            'label' => 'Categories',
            'config' => [
                'type' => 'category',
            ],
        ],
        'profile_photo' => [
            'label'  => 'Profile Photo',
            'config' => [
                'type'     => 'file',
                'maxitems' => 1,
                'allowed'  => 'jpg,jpeg,png,gif,webp',
            ],
        ],
        'downloads' => [
            'label'  => 'Downloads',
            'config' => [
                'type'    => 'file',
                'allowed' => 'pdf,csv,xlsx,docx',
            ],
        ],
        'first_name' => [
            'label' => 'First Name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
            ],
        ],
        'last_name' => [
            'label' => 'Last Name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
            ],
        ],
        'article_url' => [
            'label' => 'Article URL',
            'config' => [
                'type' => 'link',
            ],
        ],
        'fe_user_id' => [
            'label' => 'Owner (FE User)',
            'config' => [
                'type' => 'number',
            ],
        ],
        'page_id' => [
            // Points at a table no fixture resource covers, so nested creation on it
            // cannot resolve a child config.
            'label' => 'Page (select, unregistered foreign table)',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'pages',
            ],
        ],
        'related_pages' => [
            'label' => 'Related Pages (group, unregistered foreign table)',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'size' => 5,
            ],
        ],
        'related_colors' => [
            'label' => 'Related Colors (group, single table)',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_myext_domain_model_color',
                'size' => 5,
            ],
        ],
        'related_items' => [
            'label' => 'Related Items (group, multi-table)',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_myext_domain_model_article,tx_myext_domain_model_color',
                'size' => 5,
            ],
        ],
        'related_colors_mm_grp' => [
            'label' => 'Related Colors (group, MM)',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_myext_domain_model_color',
                'MM' => 'tx_myext_article_colors_mm',
                'size' => 5,
            ],
        ],
        'related_items_inline' => [
            'label' => 'Related Items (inline)',
            'config' => [
                'type' => 'inline',
                'foreign_field' => 'foreign_article_id',
                'foreign_table' => 'tx_myext_domain_model_color',
            ],
        ],
        'related_items_ftf' => [
            'label' => 'Related Items (inline, foreign_table_field)',
            'config' => [
                'type'                => 'inline',
                'foreign_table'       => 'tx_myext_domain_model_color',
                'foreign_field'       => 'foreign_article_id',
                'foreign_table_field' => 'parent_tablename',
            ],
        ],
        'related_items_fmf_a' => [
            'label' => 'Related Items (inline, foreign_match_fields type_a)',
            'config' => [
                'type'                 => 'inline',
                'foreign_table'        => 'tx_myext_domain_model_color',
                'foreign_field'        => 'foreign_article_id',
                'foreign_match_fields' => ['field_to_match' => 'type_a'],
            ],
        ],
        'related_items_fmf_b' => [
            'label' => 'Related Items (inline, foreign_match_fields type_b)',
            'config' => [
                'type'                 => 'inline',
                'foreign_table'        => 'tx_myext_domain_model_color',
                'foreign_field'        => 'foreign_article_id',
                'foreign_match_fields' => ['field_to_match' => 'type_b'],
            ],
        ],
        'meta' => [
            'label' => 'Meta (JSON)',
            'config' => [
                'type' => 'json',
            ],
        ],
        'pi_flexform' => [
            'label' => 'FlexForm',
            'config' => [
                'type' => 'flex',
                'ds' => '<T3DataStructure><sheets><sDEF><ROOT><type>array</type><el><settings.myField><config><type>input</type></config></settings.myField></el></ROOT></sDEF></sheets></T3DataStructure>',
            ],
        ],
        'published_at' => [
            'label' => 'Published At (Unix timestamp)',
            'config' => [
                'type' => 'datetime',
            ],
        ],
        'event_date' => [
            'label' => 'Event Date (native datetime)',
            'config' => [
                'type' => 'datetime',
                'dbType' => 'datetime',
            ],
        ],
    ],
];
