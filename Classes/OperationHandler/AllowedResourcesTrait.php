<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use Psr\Http\Message\ServerRequestInterface;

trait AllowedResourcesTrait
{
    /**
     * The site's tca_api.allowedResources (empty = all), so relations to resources
     * the site doesn't serve render as uids instead of IRIs it would answer with 404.
     *
     * @return list<string>
     */
    private function allowedResourcesFromRequest(ServerRequestInterface $request): array
    {
        $allowed = $request->getAttribute('tca_api.allowed_resources');

        return \is_array($allowed) ? array_values(array_filter($allowed, \is_string(...))) : [];
    }
}
