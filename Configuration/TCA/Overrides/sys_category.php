<?php

declare(strict_types=1);

$GLOBALS['TCA']['sys_category']['columns']['fe_group_id'] = [
    'label' => 'FE Group',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'foreign_table' => 'fe_groups',
        'items' => [['label' => '- none -', 'value' => 0]],
        'default' => 0,
    ],
];
