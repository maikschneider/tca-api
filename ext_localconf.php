<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use MaikSchneider\TcaApi\OperationHandler\CreateHandler;
use MaikSchneider\TcaApi\OperationHandler\DeleteHandler;
use MaikSchneider\TcaApi\OperationHandler\FileUploadHandler;
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
HandlerRegistry::register(FileUploadHandler::class);

GeneralUtility::makeInstance(ApiDefinitionLoader::class)->load();
