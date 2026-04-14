..  _userinfo:

=================
Userinfo Endpoint
=================

A userinfo endpoint exposes the **currently authenticated FE user's own record**
without requiring a UID in the URL. Set ``'type' => 'userinfo'`` in the
``general`` section:

..  code-block:: php

    use MaikSchneider\TcaApi\Registry\ApiRegistry;

    ApiRegistry::register('me', [
        'general' => [
            'type'         => 'userinfo',
            'table'        => 'fe_users',
            'resourceName' => 'me',
            'resourceType' => 'FeUser',
        ],
        'columns' => [
            'username'   => ['groups' => ['show']],
            'email'      => ['groups' => ['show']],
            'name'       => ['groups' => ['show']],
            'first_name' => ['groups' => ['show']],
            'last_name'  => ['groups' => ['show']],
        ],
    ]);

..  code-block:: text

    GET /_api/me   → Returns the record of the logged-in FE user

Behaviour
=========

-  Only ``GET`` is allowed — write operations are not supported on userinfo
   endpoints.
-  Returns **403 Forbidden** if no FE user is authenticated.
-  All column features work as normal: ``embed``, ``virtualProperties``, column
   processors.
-  The ``security`` and ``operations`` keys are ignored — access is always tied
   to FE user authentication.
