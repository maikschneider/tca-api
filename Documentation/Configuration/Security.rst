..  _security:

========
Security
========

The ``security`` section assigns an access role to each operation. Access is
checked by the :php:`AccessController` before the request reaches the operation
handler.

Access roles
============

.. list-table::
   :header-rows: 1
   :widths: 20 80

   * - Role
     - Description
   * - ``AccessRole::PUBLIC``
     - No authentication required. Anyone can access the endpoint.
   * - ``AccessRole::FE_USER``
     - Requires a logged-in frontend user.
   * - ``AccessRole::FE_GROUP``
     - Requires a frontend user belonging to a specific group.
   * - ``AccessRole::BE_USER``
     - Requires any authenticated backend user.
   * - ``AccessRole::BE_ADMIN``
     - Requires an admin backend user.

Configuration
=============

..  code-block:: php

    use MaikSchneider\TcaApi\Enum\AccessRole;

    'security' => [
        'list'   => AccessRole::PUBLIC,
        'show'   => AccessRole::PUBLIC,
        'create' => AccessRole::FE_USER,
        'update' => AccessRole::BE_USER,
        'delete' => AccessRole::BE_ADMIN,
    ],

Callable voters
===============

For custom access logic, use a callable instead of an ``AccessRole`` enum value.
The callable receives the PSR-7 server request and an optional record array (for
object-level security):

..  code-block:: php

    'security' => [
        'create' => [MyAccessChecker::class, 'checkCreatePermission'],
    ],

The callable must return ``true`` to grant access or ``false`` to deny it.

..  code-block:: php

    class MyAccessChecker
    {
        public static function checkCreatePermission(
            \Psr\Http\Message\ServerRequestInterface $request,
            ?array $record = null
        ): bool {
            // Custom access logic
            return true;
        }
    }

Denied access
=============

When access is denied, the API returns:

-  **401 Unauthorized** — when no user is authenticated but one is required.
-  **403 Forbidden** — when the authenticated user does not have sufficient
   privileges.
