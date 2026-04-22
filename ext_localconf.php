<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Cache\CacheInvalidationHook;
use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use MaikSchneider\TcaApi\OperationHandler\CreateHandler;
use MaikSchneider\TcaApi\OperationHandler\DeleteHandler;
use MaikSchneider\TcaApi\OperationHandler\GetCollectionHandler;
use MaikSchneider\TcaApi\OperationHandler\GetItemHandler;
use MaikSchneider\TcaApi\OperationHandler\GetUserInfoHandler;
use MaikSchneider\TcaApi\OperationHandler\UpdateHandler;
use MaikSchneider\TcaApi\Registry\HandlerRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

HandlerRegistry::register(GetItemHandler::class);
HandlerRegistry::register(GetCollectionHandler::class);
HandlerRegistry::register(CreateHandler::class);
HandlerRegistry::register(UpdateHandler::class);
HandlerRegistry::register(DeleteHandler::class);
HandlerRegistry::register(GetUserInfoHandler::class);

GeneralUtility::makeInstance(ApiDefinitionLoader::class)->load();

// ── API response cache ──────────────────────────────────────────────────────
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tca_api'] ??= [];

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc']['tca_api']
    = CacheInvalidationHook::class . '->clearCachePostProc';
