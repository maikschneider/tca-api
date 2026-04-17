<?php

declare(strict_types=1);

$GLOBALS['TCA']['sys_file_metadata']['columns']['tx_tcaapi_owner'] = [
    'label' => 'TCA API Owner (FE User)',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'foreign_table' => 'fe_users',
        'items' => [['label' => '- none -', 'value' => 0]],
        'default' => 0,
    ],
];
