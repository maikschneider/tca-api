<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Exception;

/**
 * The workspace a resource writes into cannot be used for the current request:
 * EXT:workspaces is not loaded, the workspace does not exist, or a
 * {$site.setting} placeholder does not resolve to a workspace uid.
 */
final class WorkspaceResolutionException extends \RuntimeException
{
}
