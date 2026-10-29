<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

use MaikSchneider\TcaApi\Exception\WorkspaceResolutionException;

/**
 * Resolves the workspace a resource creates records in for the current request:
 * an integer as configured, a {$site.setting} placeholder from the settings of
 * the request's site. 0 means live.
 */
final class WorkspaceResolver
{
    public static function workspaceId(ApiDefinition $config): int
    {
        if ($config->workspaceSetting !== null) {
            return SiteSettingValue::nonNegativeInt(
                $config->workspaceSetting,
                $config,
                'workspace',
                'a workspace uid',
                WorkspaceResolutionException::class,
                1761700000,
            );
        }

        return $config->workspace ?? 0;
    }
}
