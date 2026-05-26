..  _relations:

=========
Relations
=========

Relations are resolved automatically from the TCA schema. By default, related
records are serialized as **plain IRI strings**:

..  code-block:: json

    {
        "color_id": "/_api/colors/1",
        "categories": [
            "/_api/categories/5",
            "/_api/categories/8"
        ]
    }

This format is compatible with API Platform Admin and other Hydra clients that
resolve IRIs on demand. To inline the full related record instead, see
`Embedding related records`_ below.

Embedding related records
=========================

Add ``'embed' => true`` to a column to inline the full related record instead of
a stub:

..  code-block:: php

    'columns' => [
        'color_id'   => ['groups' => ['list', 'show'], 'embed' => true],
        'categories' => ['groups' => ['list', 'show'], 'embed' => true],
    ],

In default mode, ``embed`` alone is enough — it does **not** trigger explicit
mode:

..  code-block:: php

    'columns' => [
        'color_id' => ['embed' => true],
    ],

Response with embedded data:

..  code-block:: json

    {
        "color": {
            "@id": "/_api/colors/1",
            "@type": "Color",
            "uid": 1,
            "name": "Red",
            "hex": "#ff0000"
        },
        "categories": [
            {
                "@id": "/_api/sys-categories/5",
                "@type": "SysCategory",
                "uid": 5,
                "title": "News"
            }
        ]
    }

Controlling recursion depth
===========================

Control recursion depth with ``'embed' => ['depth' => 2]``. The default depth
is 1.

..  code-block:: php

    'columns' => [
        'color_id' => ['embed' => ['depth' => 2]],
    ],

..  important::

    The related resource must be registered in the ``ApiRegistry`` for embedding
    to work.

Multi-table group fields
========================

TCA ``type=group`` fields with multiple allowed tables store relations in the
prefixed ``tablename_uid`` format (e.g. ``pages_1,sys_file_3``). These fields
support embedding just like single-table relations:

..  code-block:: php

    // TCA definition
    'related_items' => [
        'label' => 'Related Items',
        'config' => [
            'type' => 'group',
            'allowed' => 'tx_myext_domain_model_article,tx_myext_domain_model_color',
        ],
    ],

    // API resource configuration
    'columns' => [
        'related_items' => ['groups' => ['list', 'show'], 'embed' => true],
    ],

The embedded response contains full objects from different tables, each with its
own ``@type`` and fields:

..  code-block:: json

    {
        "related_items": [
            {
                "@id": "/_api/articles/201",
                "@type": "Article",
                "uid": 201,
                "title": "Related Article"
            },
            {
                "@id": "/_api/colors/1",
                "@type": "Color",
                "uid": 1,
                "name": "Red"
            }
        ]
    }

Each referenced table is resolved to its registered API resource. When no
resource is registered for a table, a default-mode config is synthesized
automatically (all TCA columns exposed).

Bulk preloading is fully supported: collection requests issue one ``SELECT``
per referenced table regardless of how many parent rows exist, avoiding N+1
queries.

Writing nested objects
======================

When a create or update request contains a related record as an **object**
(rather than a UID), TCA_API creates the child record in-line. The child
table must have a registered resource in the API registry.

..  code-block:: json

    {
        "title": "My Article",
        "color_id": { "name": "Red" }
    }

By default the child resource is looked up by the **foreign table** name from
TCA. When multiple resources are registered for the same DB table, use the
``resourceName`` column option to pin the lookup to a specific resource:

..  code-block:: php

    'columns' => [
        'color_id' => [
            'groups'       => ['list', 'show', 'create', 'update'],
            'resourceName' => 'colors',   // look up "colors" resource, not by table
        ],
    ],

This also controls which resource's ``security.create`` role is enforced on the
nested write and which resource's column config is used for validation.

..  note::

    If ``resourceName`` is set but no matching resource is registered, TCA_API
    throws an ``\InvalidArgumentException`` at load time (validated in
    :php:`ApiDefinitionLoader`).

Supported relation types
========================

.. list-table::
   :header-rows: 1
   :widths: 25 40 15

   * - TCA type
     - Default output
     - Embedding
   * - ``select`` / ``group``
     - UID list (``1,2,3``) — single table → IRI strings
     - Yes
   * - ``select`` / ``group``
     - Prefixed list (``table_uid``) — multi-table → IRI strings
     - Yes
   * - ``inline`` / ``select``
     - ``foreign_field`` back-reference → IRI strings
     - Yes
   * - Any + ``MM``
     - Intermediate MM table → IRI strings
     - Yes
   * - ``type=group`` + ``MM``
     - Column holds count, relations in MM → IRI strings
     - Yes
