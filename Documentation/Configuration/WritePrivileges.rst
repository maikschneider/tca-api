..  _write-privileges:

=====================
Write privilege model
=====================

Write operations (``create``, ``update``, ``delete``) pass through a write
privilege model that sits between the access check (:ref:`security`) and the
TYPO3 DataHandler. It decides **under which identity** a write executes,
enforces a table-level policy that no resource configuration can weaken, and
records every mutation with the acting user.

Write modes
===========

Each resource has a write mode, configured as ``general.writeMode``:

..  list-table::
    :header-rows: 1
    :widths: 25 75

    * - Mode
      - Behaviour
    * - ``acting_user``
      - **Default.** The DataHandler runs under the identity of the real
        authenticated user (frontend or backend), so TYPO3's own access control
        applies and the actor is preserved for auditing.
    * - ``system_admin``
      - Opt-in. The DataHandler runs under a synthetic backend administrator
        (``uid=0``, ``admin=1``) with no user identity.

..  code-block:: php

    'general' => [
        'table'        => 'tx_myext_domain_model_internal',
        'resourceName' => 'internal-records',
        'resourceType' => 'InternalRecord',
        'operations'   => ['list', 'create'],
        'writeMode'    => 'system_admin',   // default: 'acting_user'
    ],

..  warning::

    ``system_admin`` bypasses TYPO3 access-control restrictions on the write.
    The API-level :ref:`security` roles still gate who may call the endpoint,
    but everything TYPO3 would otherwise refuse the user is permitted. Use it
    only where the calling application is fully trusted.

Actor resolution
================

The acting user is resolved from the request in a fixed order:

1.  **Frontend user** — a ``frontend.user`` request attribute with a non-empty
    ``uid`` yields ``actorType = 'fe_user'``.
2.  **Backend user** — an authenticated :php:`$GLOBALS['BE_USER']` yields
    ``actorType = 'be_user'``.
3.  **No user** — falls back to ``actorType = 'system'``, and system mode is
    forced regardless of the configured ``writeMode``.

In ``acting_user`` mode the DataHandler's internal username encodes the
resolved actor, so TYPO3's own ``sys_history`` and log entries stay traceable
to a real account:

..  code-block:: text

    _tca_api[fe_user:42:johndoe]
    _tca_api[be_user:1:admin]

Table access control
====================

A table-level policy is enforced on every write, in **both** write modes, before
the DataHandler is reached. A blocked table returns **403 Forbidden**.

Built-in deny list
------------------

These tables are always blocked, and no configuration can unblock them:

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - Table
      - Reason
    * - ``be_users``
      - Backend user accounts and credentials
    * - ``be_groups``
      - Backend permission groups
    * - ``be_sessions``
      - Active backend sessions
    * - ``fe_sessions``
      - Active frontend sessions
    * - ``sys_filemounts``
      - File system mount points
    * - ``sys_be_shortcuts``
      - Backend user shortcuts
    * - ``sys_action``
      - System actions
    * - ``sys_log``
      - System log

Narrowing or extending the policy
---------------------------------

The built-in list can be **extended**, never reduced. Configure
:php:`MaikSchneider\TcaApi\Security\TableAccessControl` in your extension's
:file:`Configuration/Services.yaml`:

..  code-block:: yaml

    services:
      MaikSchneider\TcaApi\Security\TableAccessControl:
        arguments:
          # When non-empty, ONLY these tables are writable
          $allowList:
            - tx_myext_domain_model_article
            - tx_myext_domain_model_comment
          # Denied in addition to the built-in list
          $denyList:
            - pages
            - tt_content

An empty ``$allowList`` (the default) means "every table that is not denied".
Deny always wins: a table named in both lists is denied.

..  note::

    This policy is about *tables*, not about *who* may write. It is a backstop
    against a misconfigured resource definition exposing something dangerous —
    it does not replace the per-operation roles in :ref:`security`.

Audit logging
=============

Every write is logged through TYPO3's PSR-3 logging framework, so it can be
routed to any configured handler.

A permitted write is logged at ``info`` level as ``TCA API write operation``:

..  code-block:: text

    operation:      create
    table:          tx_myext_domain_model_article
    uid:            NEW_primary
    actor_type:     fe_user
    actor_uid:      42
    actor_username: johndoe
    write_mode:     acting_user

A write refused by the table policy is logged at ``warning`` level as
``TCA API write denied``, with the same actor context plus a ``reason``:

..  code-block:: text

    operation:      write
    table:          be_users
    actor_type:     fe_user
    actor_uid:      42
    actor_username: johndoe
    write_mode:     acting_user
    reason:         Table blocked by access control policy

The ``uid`` of a create is the DataHandler placeholder (``NEW_…``) rather than
the final record uid, because the entry is written before persistence.
