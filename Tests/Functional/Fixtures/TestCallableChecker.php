<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Fixtures;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Test-only callable access checker.
 * Used in CallableAccessTest to verify callable security config.
 */
final class TestCallableChecker
{
    public function allowAll(ServerRequestInterface $request): bool
    {
        return true;
    }

    public function denyAll(ServerRequestInterface $request): bool
    {
        return false;
    }

    /**
     * Returns true only when the X-Test-Allow: 1 header is present.
     */
    public function checkHeader(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Test-Allow') === '1';
    }

    /**
     * Returns true only when a frontend user is logged in.
     * Demonstrates that callables have full request context.
     */
    public function requireFeUser(ServerRequestInterface $request): bool
    {
        $feUser = $request->getAttribute('frontend.user');

        return $feUser !== null && !empty($feUser->user['uid']);
    }
}
