<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Registry\ApiRegistry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ApiRegistry::register(
    'articles',
    require ExtensionManagementUtility::extPath('tca_api') . 'Configuration/TcaApi/Articles.php',
);

ApiRegistry::register(
    'colors',
    require ExtensionManagementUtility::extPath('tca_api') . 'Configuration/TcaApi/Colors.php',
);

ApiRegistry::register(
    'sys-categories',
    require ExtensionManagementUtility::extPath('tca_api') . 'Configuration/TcaApi/SysCategories.php',
);
