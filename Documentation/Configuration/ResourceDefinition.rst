..  _resource-definition:

===================
Resource Definition
===================

Resources are defined as PHP files placed in any active extension's
:file:`Configuration/TcaApi/` directory. **No manual registration is needed** —
the extension auto-discovers all :file:`*.php` files from every active package's
:file:`Configuration/TcaApi/` directory at boot time and caches the result.

Each file returns a PHP array with the resource configuration.

To modify a resource defined by a third-party package, place an override file
in :file:`Configuration/TcaApi/Overrides/` — see :ref:`configuration-overrides`.

General section
===============

The ``general`` key defines the basic resource properties:

..  code-block:: php

    return [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'articles',
            'resourceType' => 'Article',
            // operations defaults to ['list', 'show'] — omit for read-only resources
            'itemsPerPage' => 20,
            'storagePid'   => 1,
        ],
    ];

.. list-table::
   :header-rows: 1
   :widths: 20 80

   * - Key
     - Description
   * - ``table``
     - TYPO3 database table name.
   * - ``resourceName``
     - URL slug used in ``/_api/{resourceName}``.
   * - ``resourceType``
     - JSON-LD ``@type`` value.
   * - ``type``
     - Set to ``'userinfo'`` to create a :ref:`userinfo endpoint <userinfo>`.
   * - ``operations``
     - Array of enabled operations: ``list``, ``show``, ``create``, ``update``,
       ``delete``. Defaults to ``['list', 'show']`` when omitted.
   * - ``itemsPerPage``
     - Default page size for list operations (overrides the global site setting).
   * - ``maxItemsPerPage``
     - Upper limit for ``itemsPerPage``; when set, the requested page size is
       clamped to this value. No limit when omitted.
   * - ``storagePid``
     - Page ID for newly created records. Also constrains reads to this page
       unless ``readStoragePids`` is set. When omitted, reads are not
       pid-constrained and writes target page 0.
   * - ``readStoragePids``
     - Widens the read-side pid constraint independently of ``storagePid``.
       Accepts an array (``[42, 17, 5]``) or comma-separated list
       (``'42,17,5'``) — reads then use ``pid IN (...)`` — or the sentinel
       ``'*'`` to read from all pages regardless of the write target. When
       omitted, reads fall back to ``storagePid``. This is the complete read
       set: list the write page explicitly if it should also be readable.
   * - ``writeMode``
     - Write execution strategy. ``acting_user`` (default) — DataHandler runs
       under the authenticated user identity, preserving the actor for audit
       purposes. ``system_admin`` — uses a synthetic backend-admin context,
       bypassing all TYPO3 ACL boundaries. Only enable for fully trusted,
       internal-only APIs.

Write mode
==========

By default, write operations (create, update, delete) run under the
**acting-user** context — TYPO3's DataHandler preserves the caller's identity
for audit purposes. Set ``writeMode`` to ``system_admin`` to bypass TYPO3 ACL
checks entirely. Only use this for trusted, back-channel APIs.

..  code-block:: php

    'general' => [
        'table'        => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
        'operations'   => ['create', 'update', 'delete'],
        'writeMode'    => 'system_admin',   // default: 'acting_user'
    ],

..  warning::

    ``system_admin`` mode bypasses all TYPO3 access-control restrictions on
    writes. Never use it for public-facing endpoints.

Storage pages (read vs. write)
==============================

``storagePid`` is the **single write target** — newly created records land
there. By default it also constrains reads to that one page. To read from
several pages while still writing into one, add ``readStoragePids``:

..  code-block:: php

    'general' => [
        'table'           => 'tx_myext_domain_model_article',
        'resourceName'    => 'articles',
        'resourceType'    => 'Article',
        'operations'      => ['list', 'show', 'create'],
        'storagePid'      => 42,            // writes go here
        'readStoragePids' => [42, 17, 5],   // reads span these pages
    ],

Use the sentinel ``'*'`` to read from every page regardless of the write
target:

..  code-block:: php

    'storagePid'      => 42,    // writes go here
    'readStoragePids' => '*',   // reads ignore pid entirely

``readStoragePids`` is the complete read set — it does **not** implicitly
include ``storagePid``. List the write page explicitly if it should be
readable too.

Minimal example (zero-config)
==============================

Three keys are all that is needed for a public read-only resource. ``operations``
defaults to ``['list', 'show']`` and both are ``PUBLIC`` by default:

..  code-block:: php

    return [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'articles',
            'resourceType' => 'Article',
        ],
    ];

To enable write operations, add them explicitly and configure security:

..  code-block:: php

    use MaikSchneider\TcaApi\Enum\AccessRole;

    return [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'articles',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show', 'create', 'update', 'delete'],
            'storagePid'   => 1,
        ],
        'security' => [
            'create' => AccessRole::FE_USER,
            'update' => AccessRole::FE_USER,
            'delete' => AccessRole::BE_ADMIN,
        ],
    ];

Full example
============

..  code-block:: php

    use MaikSchneider\TcaApi\Enum\AccessRole;
    use MaikSchneider\TcaApi\Filter\ExactFilter;
    use MaikSchneider\TcaApi\Filter\MmFilter;
    use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
    use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;

    return [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'articles',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show', 'create', 'update', 'delete'],
        ],
        'columns' => [
            'title' => [
                'type'       => 'string',
                'groups'     => ['list', 'show', 'create', 'update'],
                'required'   => true,
                'validators' => [
                    ['type' => 'maxLength', 'max' => 20],
                    ['type' => 'minLength', 'min' => 3],
                    ['type' => 'regex', 'pattern' => '/^[\w\s]+$/u'],
                ],
            ],
            'color_id'   => ['groups' => ['list', 'show', 'create', 'update']],
            'categories' => ['groups' => ['list', 'show', 'create', 'update']],
            'profile_photo' => ['groups' => ['list', 'show']],
            'downloads' => [
                'groups'    => ['list', 'show'],
                'processor' => FileProcessor::class,
            ],
            'article_url' => [
                'groups'    => ['list', 'show'],
                'processor' => TypoLinkProcessor::class,
            ],
        ],
        'filters' => [
            'title'      => ExactFilter::class,
            'color_id'   => ExactFilter::class,
            'categories' => MmFilter::class,
        ],
        'order' => [
            'allowed' => ['title', 'uid'],
            'default' => ['uid' => 'asc'],
        ],
        'security' => [
            'list'   => AccessRole::PUBLIC,
            'show'   => AccessRole::PUBLIC,
            'create' => AccessRole::FE_USER,
            'update' => AccessRole::FE_USER,
            'delete' => AccessRole::BE_ADMIN,
        ],
    ];
