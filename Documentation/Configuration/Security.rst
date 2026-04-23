..  _security:

========
Security
========

The ``security`` section assigns an access role to each operation. Access is
checked by the :php:`AccessController` before the request reaches the operation
handler.

Default access roles
====================

Operations not listed in ``security`` fall back to sensible defaults:

.. list-table::
   :header-rows: 1
   :widths: 15 15 70

   * - Operation
     - Default
     - Explanation
   * - ``list``
     - ``PUBLIC``
     - Read operations are publicly accessible without authentication.
   * - ``show``
     - ``PUBLIC``
     - Read operations are publicly accessible without authentication.
   * - ``create``
     - ``DISABLED``
     - Write operations are **disabled** until explicitly configured.
   * - ``update``
     - ``DISABLED``
     - Write operations are **disabled** until explicitly configured.
   * - ``delete``
     - ``DISABLED``
     - Write operations are **disabled** until explicitly configured.

..  important::

    Listing an operation in ``general.operations`` is not enough to enable it.
    Write operations (``create``, ``update``, ``delete``) also require an
    explicit ``security`` entry. Without one they return **403 Forbidden**.

Access roles
============

.. list-table::
   :header-rows: 1
   :widths: 20 80

   * - Role
     - Description
   * - ``AccessRole::PUBLIC``
     - No authentication required. Anyone can access the endpoint.
   * - ``AccessRole::DISABLED``
     - Always denied regardless of authentication. Used as the implicit default
       for write operations that have no explicit security configuration.
   * - ``AccessRole::FE_USER``
     - Requires a logged-in frontend user.
   * - ``AccessRole::FE_GROUP``
     - Requires a frontend user belonging to a specific group. Use
       ``[AccessRole::FE_GROUP, [1,2]]`` to restrict to specific group IDs.
   * - ``AccessRole::BE_USER``
     - Requires any authenticated backend user.
   * - ``AccessRole::BE_ADMIN``
     - Requires an admin backend user.
   * - ``AccessRole::OWNER``
     - Authenticated FE user whose UID matches the record's ownership column.
       See :ref:`ownership` below.

Configuration
=============

..  code-block:: php

    use MaikSchneider\TcaApi\Enum\AccessRole;

    'security' => [
        // list and show are PUBLIC by default — only specify to restrict them
        'create' => AccessRole::FE_USER,
        'update' => AccessRole::OWNER,      // Only the record owner may update
        'delete' => AccessRole::OWNER,
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

..  _ownership:

Ownership
=========

The ``ownership`` section enables declarative record-level security for write
operations. It pairs with ``AccessRole::OWNER`` in the ``security`` config.

..  code-block:: php

    use MaikSchneider\TcaApi\Enum\AccessRole;

    'security' => [
        'create' => AccessRole::FE_USER,
        'update' => AccessRole::OWNER,
        'delete' => AccessRole::OWNER,
    ],
    'ownership' => [
        'column' => 'fe_user_id',   // DB column holding the owner's FE user UID
    ],

**What this does:**

-  **On create** — the ``fe_user_id`` column is automatically set to the
   authenticated FE user's UID server-side. The client cannot supply this value;
   it is stripped from the request body regardless of the column's ``groups``
   config.
-  **On update/delete** — the record's ``fe_user_id`` is compared to the current
   FE user's UID. If they don't match the request returns **403**.

Separate tracking vs. auth columns
-----------------------------------

Use ``setOnCreate`` when you want an additional tracking column alongside the
auth column:

..  code-block:: php

    'ownership' => [
        'column'      => 'fe_user_id',      // column checked on update/delete (also written on create)
        'setOnCreate' => 'fe_creator_id',   // additional column written on create only
    ],

On create, **both** ``column`` and ``setOnCreate`` receive the FE user UID.
``column`` must be populated for ``OWNER`` auth to work on subsequent
update/delete; ``setOnCreate`` provides an immutable "created by" audit trail in
a separate DB column.

BE_ADMIN bypass
---------------

Backend admins bypass ownership checks by default. Set ``beAdminBypass: false``
to enforce ownership for admins too:

..  code-block:: php

    'ownership' => [
        'column'        => 'fe_user_id',
        'beAdminBypass' => false,           // default: true
    ],

Ownership config reference
--------------------------

.. list-table::
   :header-rows: 1
   :widths: 15 10 10 65

   * - Key
     - Required
     - Default
     - Description
   * - ``column``
     - When using ``OWNER``
     - —
     - DB column holding owner UID; compared on update/delete.
   * - ``setOnCreate``
     - No
     - Same as ``column``
     - Column auto-set on create (if different from ``column``).
   * - ``beAdminBypass``
     - No
     - ``true``
     - When ``true``, ``BE_ADMIN`` skips the ownership check.

Behaviour notes
---------------

-  ``AccessRole::OWNER`` without ``ownership.column`` configured → **403**
   (fail-secure).
-  Unauthenticated request + ``OWNER`` → **403** (no FE user found).
-  ``setOnCreate`` without a logged-in FE user → column is not set (no injection
   if user is null).
-  Ownership columns are always stripped from client input regardless of
   ``groups`` config.
-  ``OWNER`` is only meaningful on ``update`` and ``delete``; using it on
   ``list``/``show`` will always deny (no single record to compare against).

Table write restrictions
========================

The API enforces a built-in **deny list** for tables that must never be written
through the API regardless of security configuration. Attempting to write to
any of these tables returns **403 Forbidden**:

- ``be_users``, ``be_groups``, ``be_sessions``
- ``fe_sessions``
- ``sys_filemounts``, ``sys_be_shortcuts``, ``sys_action``, ``sys_log``

This protection is unconditional and cannot be overridden by configuration.

Denied access
=============

When access is denied, the API returns:

-  **401 Unauthorized** — when no user is authenticated but one is required.
-  **403 Forbidden** — when the authenticated user does not have sufficient
   privileges.
