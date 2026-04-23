<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Cache\CacheInvalidationHook;
use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

GeneralUtility::makeInstance(ApiDefinitionLoader::class)->load();

// ── API response cache ──────────────────────────────────────────────────────
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tca_api'] ??= [];

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc']['tca_api']
    = CacheInvalidationHook::class . '->clearCachePostProc';
