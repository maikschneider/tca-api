..  _filters:

=======
Filters
=======

The ``filters`` section defines which columns can be filtered and what strategy
to use. Filters are applied via query parameters.

Filter strategies
=================

.. list-table::
   :header-rows: 1
   :widths: 15 25 60

   * - Strategy
     - Query example
     - Description
   * - ``exact``
     - ``?filters[title]=Foo``
     - Exact match (``WHERE title = 'Foo'``).
   * - ``partial``
     - ``?filters[name]=oo``
     - Substring match (``WHERE name LIKE '%oo%'``).
   * - ``word_start``
     - ``?filters[search]=Fo``
     - Starts-with match (``WHERE search LIKE 'Fo%'``).
   * - ``search``
     - ``?filters[search]=Alice``
     - Multi-field search — searches across multiple configured columns.
   * - ``range``
     - ``?filters[price][gte]=10&filters[price][lte]=100``
     - Range filter with ``gte`` (greater-than-or-equal) and ``lte``
       (less-than-or-equal) operators.
   * - ``mm``
     - ``?filters[categories]=5``
     - Many-to-many filter via an intermediate MM table.

Configuration examples
======================

Basic filters
-------------

..  code-block:: php

    'filters' => [
        'title'  => ['strategy' => 'exact'],
        'name'   => ['strategy' => 'partial'],
        'search' => ['strategy' => 'word_start'],
    ],

Many-to-many filter
-------------------

For MM relations, additional configuration specifies the intermediate table and
key columns:

..  code-block:: php

    'filters' => [
        'categories' => [
            'strategy'       => 'mm',
            'mm_table'       => 'sys_category_record_mm',
            'mm_local_key'   => 'uid_local',
            'mm_foreign_key' => 'uid_foreign',
            'mm_constraints' => [
                'tablenames' => 'tx_myext_domain_model_article',
                'fieldname'  => 'categories',
            ],
        ],
    ],

Search filter
-------------

The search filter allows searching across multiple columns simultaneously:

..  code-block:: php

    'filters' => [
        'search' => [
            'strategy' => 'search',
            'columns'  => ['first_name', 'last_name', 'email'],
        ],
    ],

Usage: ``?filters[search]=Alice`` — searches across all configured columns.

Range filter
------------

..  code-block:: php

    'filters' => [
        'price' => ['strategy' => 'range'],
    ],

Usage: ``?filters[price][gte]=10&filters[price][lte]=100``
