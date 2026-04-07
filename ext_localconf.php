<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Registry\ApiRegistry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ApiRegistry::register(
    'articles',
    require ExtensionManagementUtility::extPath('tca_api') . 'Configuration/TcaApi/Articles.php',
);

ApiRegistry::register(
    'categories',
    require ExtensionManagementUtility::extPath('tca_api') . 'Configuration/TcaApi/Categories.php',
);

ApiRegistry::register(
    'tags',
    require ExtensionManagementUtility::extPath('tca_api') . 'Configuration/TcaApi/Tags.php',
);
