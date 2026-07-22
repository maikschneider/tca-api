<?php

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

/**
 * Icon registration for the TCA API backend module.
 */
return [
    'module-tca-api' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:tca_api/Resources/Public/Icons/Extension.svg',
    ],
];
