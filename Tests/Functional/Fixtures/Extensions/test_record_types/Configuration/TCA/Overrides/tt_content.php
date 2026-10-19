<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;
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

ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
    'label' => 'Plugin with FlexForm',
    'value' => 'tca_api_flexform',
]);
$GLOBALS['TCA']['tt_content']['types']['tca_api_flexform'] = [
    'showitem' => 'header, pi_flexform',
];
$flexFormDataStructure = '<T3DataStructure>
    <sheets>
        <sDEF>
            <ROOT>
                <type>array</type>
                <el>
                    <settings.title><label>Title</label><config><type>input</type></config></settings.title>
                    <settings.limit><label>Limit</label><config><type>number</type></config></settings.limit>
                </el>
            </ROOT>
        </sDEF>
        <display>
            <ROOT>
                <type>array</type>
                <el>
                    <settings.showDate><label>Show date</label><config><type>check</type></config></settings.showDate>
                    <settings.links>
                        <type>array</type>
                        <section>1</section>
                        <el>
                            <link>
                                <type>array</type>
                                <el>
                                    <url><label>URL</label><config><type>input</type></config></url>
                                </el>
                            </link>
                        </el>
                    </settings.links>
                </el>
            </ROOT>
        </display>
    </sheets>
</T3DataStructure>';
if ((new Typo3Version())->getMajorVersion() >= 14) {
    $GLOBALS['TCA']['tt_content']['types']['tca_api_flexform']['columnsOverrides']['pi_flexform']['config']['ds'] = $flexFormDataStructure;
} else {
    $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']['*,tca_api_flexform'] = $flexFormDataStructure;
}
