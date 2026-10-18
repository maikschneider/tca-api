<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Exception;

/**
 * A {$site.setting} placeholder in storagePid or readStoragePids does not resolve
 * to a pid for the current request.
 */
final class StoragePidResolutionException extends \RuntimeException
{
}
