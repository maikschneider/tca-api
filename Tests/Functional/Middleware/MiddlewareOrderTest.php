<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Middleware;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;

/**
 * Access checks read the frontend and backend user that core's authentication
 * middlewares resolve, so the API middleware must run after both of them. It also
 * runs after the base redirect resolver, so middlewares ordered before that one
 * (such as the testing framework's backend-user handler) still precede it.
 */
final class MiddlewareOrderTest extends ApiFunctionalTestCase
{
    public function testApiMiddlewareRunsAfterItsPredecessors(): void
    {
        // The resolver returns the stack last-in-first-out; reverse it into execution order.
        $stack = array_reverse(array_keys(iterator_to_array($this->get(MiddlewareStackResolver::class)->resolve('frontend'))));
        $apiPosition = array_search('maikschneider/tca-api/middleware', $stack, true);

        self::assertIsInt($apiPosition);
        $predecessors = [
            'typo3/cms-frontend/authentication',
            'typo3/cms-frontend/backend-user-authentication',
            'typo3/cms-frontend/base-redirect-resolver',
        ];
        foreach ($predecessors as $predecessor) {
            $predecessorPosition = array_search($predecessor, $stack, true);
            self::assertIsInt($predecessorPosition, $predecessor . ' is not in the frontend stack');
            self::assertLessThan($apiPosition, $predecessorPosition, $predecessor . ' must run before the API middleware');
        }
    }
}
