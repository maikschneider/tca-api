<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Middleware;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;

/**
 * Access checks read the frontend and backend user that core's authentication
 * middlewares resolve, so the API middleware must run after both of them.
 */
final class MiddlewareOrderTest extends ApiFunctionalTestCase
{
    public function testApiMiddlewareRunsAfterTheAuthenticationMiddlewares(): void
    {
        // The resolver returns the stack last-in-first-out; reverse it into execution order.
        $stack = array_reverse(array_keys(iterator_to_array($this->get(MiddlewareStackResolver::class)->resolve('frontend'))));
        $apiPosition = array_search('maikschneider/tca-api/middleware', $stack, true);

        self::assertIsInt($apiPosition);
        foreach (['typo3/cms-frontend/authentication', 'typo3/cms-frontend/backend-user-authentication'] as $authentication) {
            $authenticationPosition = array_search($authentication, $stack, true);
            self::assertIsInt($authenticationPosition, $authentication . ' is not in the frontend stack');
            self::assertLessThan($apiPosition, $authenticationPosition, $authentication . ' must run before the API middleware');
        }
    }
}
