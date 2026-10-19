<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TCA']['tt_content']['columns']['tx_split_content'] = [
    'label'  => 'Content element',
    'config' => [
        'type'          => 'select',
        'renderType'    => 'selectSingle',
        'foreign_table' => 'tt_content',
        'items'         => [['label' => '', 'value' => 0]],
        'default'       => 0,
    ],
];
