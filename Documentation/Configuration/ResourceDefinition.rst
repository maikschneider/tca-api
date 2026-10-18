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
   * - ``recordTypes``
     - Scopes the resource to record types of the table's TCA ``ctrl.type``
       field (``CType``, ``doktype``, …). Accepts an array
       (``['textmedia', 'header']``), a comma-separated list or ``'*'`` for
       every type in ``$TCA[table]['types']``. In default mode each record then
       exposes only its type's columns; the options form
       (``['include' => …, 'schema' => 'table']``) keeps all table columns.
       When omitted, the resource serves records of every type with all
       columns — see :ref:`record-types`.
   * - ``writeMode``
     - Write execution strategy. ``acting_user`` (default) — DataHandler runs
       under the authenticated user identity, preserving the actor for audit
       purposes. ``system_admin`` — uses a synthetic backend-admin context,
       bypassing all TYPO3 ACL boundaries. Only enable for fully trusted,
       internal-only APIs.
   * - ``group``
     - OpenAPI tag grouping this resource's operations in Swagger UI. A string
       (``'Editorial'``) or an array with a ``name`` and optional
       ``description``. Defaults to ``resourceType`` — see
       :ref:`endpoint-grouping`.

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

..  _record-types:

Record types
============

Tables with a TCA ``ctrl.type`` field — ``tt_content.CType``, ``pages.doktype``
— hold records of several types. ``recordTypes`` limits a resource to some of
them:

..  code-block:: php

    'general' => [
        'table'        => 'tt_content',
        'resourceName' => 'content',
        'resourceType' => 'Content',
        'operations'   => ['list', 'show', 'create', 'update'],
        'recordTypes'  => ['textmedia', 'header'],
    ],

The scope applies to every operation, not only to ``list``: a record of
another type answers ``404`` on ``show``, ``update`` and ``delete``, exactly
like a record on another page. A filter on the type column cannot do that,
because filters only narrow collections.

On writes:

*   A resource with **one** record type sets the type field on ``create``
    itself. A client may omit it; a different value is a ``422``.
*   A resource with **several** record types requires the type field on
    ``create`` and rejects values outside the list with ``422``
    (``INVALID_RECORD_TYPE``).
*   ``update`` cannot move a record to a type outside the list.

The OpenAPI schema describes the type field as an ``enum`` of the allowed
types. Unknown types, tables without ``ctrl.type`` and foreign-field types
(``'type' => 'local_field:foreign_field'``) fail at boot.

..  note::

    Relations are not scoped: a column referencing records of the table
    renders them regardless of ``recordTypes``, as it does for
    ``storagePid``.

Columns per record type
-----------------------

In default mode (no column declares ``groups``), each record exposes only the
columns of **its type's form** — the fields in the type's ``showitem``, with
palettes unpacked, in the same order. A ``header`` content element has no
``bodytext`` or ``assets``, a ``textmedia`` element does. System fields that
palettes carry (``hidden``, ``starttime``, ``sys_language_uid``, …) stay hidden
as everywhere else.

The type's ``columnsOverrides`` apply as well. A ``required`` or ``max`` set
for one type is validated only for records of that type, and FlexForm and
field processing use the type's configuration.

*   A collection over several types returns each item with its own columns.
*   On writes, columns outside the target type's form are ignored — the type
    being created or switched to, otherwise the record's current type.
*   The OpenAPI ``Read`` schema of a multi-type resource is a ``oneOf`` over
    one schema per type (``ContentTextmediaRead``, ``ContentHeaderRead``, …)
    with a ``discriminator`` on the type field. A single-type resource's
    ``Read`` and ``Write`` schemas describe that type. The ``Write`` schema of
    a multi-type resource lists the columns of all its types, because ``PATCH``
    bodies are partial and cannot be matched against a single type.

Declared ``columns`` keep their configuration but count only for the types
whose form contains them. Explicit mode is unaffected: declared columns are
the columns.

To scope a resource without changing its columns, use the options form with
``'schema' => 'table'``:

..  code-block:: php

    'recordTypes' => [
        'include' => ['textmedia', 'header'],   // default: '*'
        'schema'  => 'table',                   // default: 'type'
    ],

..  _endpoint-grouping:

Endpoint grouping (OpenAPI tags)
================================

By default each resource gets its own Swagger UI section, named after its
``resourceType``. Set ``group`` to place several resources under a shared,
named section:

..  code-block:: php

    'general' => [
        'table'        => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
        'group'        => 'Editorial',
    ],

Use the array form to add a description shown above the section:

..  code-block:: php

    'group' => [
        'name'        => 'Editorial',
        'description' => 'Editorial content endpoints',
    ],

Point several resources at the same ``name`` to merge them into one section.
Sections appear in resource-registration order; the first configured
``description`` for a group wins. Omitting ``group`` falls back to
``resourceType``, so grouping is fully opt-in and backward compatible.

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
