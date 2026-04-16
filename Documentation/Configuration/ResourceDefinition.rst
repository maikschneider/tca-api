..  _resource-definition:

===================
Resource Definition
===================

Resources are defined as PHP files placed in any active extension's
:file:`Configuration/TcaApi/` directory. **No manual registration is needed** —
the extension auto-discovers all :file:`*.php` files from every active package's
:file:`Configuration/TcaApi/` directory at boot time and caches the result.

Each file returns a PHP array with the resource configuration.

General section
===============

The ``general`` key defines the basic resource properties:

..  code-block:: php

    return [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'articles',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show', 'create', 'update', 'delete'],
            'itemsPerPage' => 20,
            'defaultPid'   => 1,
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
       ``delete``.
   * - ``itemsPerPage``
     - Default page size for list operations (overrides the global site setting).
   * - ``maxItemsPerPage``
     - Upper limit for ``itemsPerPage``; when set, the requested page size is
       clamped to this value. No limit when omitted.
   * - ``defaultPid``
     - Page ID for newly created records.

Minimal example (zero-config)
=============================

Omit ``columns`` entirely and all non-system TCA columns are auto-exposed for
read and write:

..  code-block:: php

    use MaikSchneider\TcaApi\Enum\AccessRole;

    return [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'articles',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show', 'create', 'update', 'delete'],
        ],
        'security' => [
            'list'   => AccessRole::PUBLIC,
            'show'   => AccessRole::PUBLIC,
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
