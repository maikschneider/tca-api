<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Fixtures;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Test-only object-level voter.
 * Grants access only when the authenticated FE user owns the record.
 */
final class TestOwnerChecker
{
    public function isOwner(ServerRequestInterface $request, array $record): bool
    {
        $feUser = $request->getAttribute('frontend.user');
        if ($feUser === null || empty($feUser->user['uid'])) {
            return false;
        }

        return (int)$record['fe_user_id'] === (int)$feUser->user['uid'];
    }
}
