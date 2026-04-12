<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\OperationHandler\CreateHandler;
use MaikSchneider\TcaApi\OperationHandler\DeleteHandler;
use MaikSchneider\TcaApi\OperationHandler\GetCollectionHandler;
use MaikSchneider\TcaApi\OperationHandler\GetItemHandler;
use MaikSchneider\TcaApi\OperationHandler\GetUserInfoHandler;
use MaikSchneider\TcaApi\OperationHandler\UpdateHandler;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Registry\HandlerRegistry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

HandlerRegistry::register(GetItemHandler::class);
HandlerRegistry::register(GetCollectionHandler::class);
HandlerRegistry::register(CreateHandler::class);
HandlerRegistry::register(UpdateHandler::class);
HandlerRegistry::register(DeleteHandler::class);
HandlerRegistry::register(GetUserInfoHandler::class);

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

ApiRegistry::register(
    'news',
    require ExtensionManagementUtility::extPath('tca_api') . 'Configuration/TcaApi/News.php',
);
