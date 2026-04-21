<?php

declare(strict_types=1);

use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

GeneralUtility::makeInstance(ApiDefinitionLoader::class)->load();
