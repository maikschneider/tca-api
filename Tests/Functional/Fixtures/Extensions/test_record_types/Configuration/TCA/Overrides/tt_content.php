<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
    'label' => 'Teaser',
    'value' => 'tca_api_teaser',
]);

$GLOBALS['TCA']['tt_content']['types']['tca_api_teaser'] = [
    'showitem' => '--div--;core.form.tabs:general, --palette--;;general, header, bodytext,'
        . ' --div--;core.form.tabs:access, --palette--;;hidden',
    'columnsOverrides' => [
        'bodytext' => [
            'config' => [
                'required' => true,
                'max'      => 20,
            ],
        ],
    ],
];

foreach (['tca_api_foo_bar' => 'Foo bar', 'tca_api_foo-bar' => 'Foo-bar'] as $cType => $label) {
    ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
        'label' => $label,
        'value' => $cType,
    ]);
    $GLOBALS['TCA']['tt_content']['types'][$cType] = [
        'showitem' => 'header',
    ];
}
