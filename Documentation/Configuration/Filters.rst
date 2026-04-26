..  _filters:

=======
Filters
=======

The ``filters`` section defines which columns can be filtered and what strategy
to use. Filters are applied via query parameters.

Each filterable column maps to a **filter class**. Use the **shorthand** (class
name only) or the **options form** (two-element array with class + config):

Built-in filter classes
=======================

.. list-table::
   :header-rows: 1
   :widths: 20 40 40

   * - Class
     - Description
     - Options
   * - ``ExactFilter``
     - ``WHERE column = value``
     - —
   * - ``PartialFilter``
     - ``WHERE column LIKE %value%``
     - —
   * - ``WordStartFilter``
     - ``WHERE column LIKE value%``
     - —
   * - ``RangeFilter``
     - Comparison operators on a column (numeric, string or date)
     - Value must be ``['gte'=>…, 'lte'=>…, 'gt'=>…, 'lt'=>…]``. Optional
       ``type`` (``int`` | ``float`` | ``string`` | ``date`` | ``datetime``)
       forces the cast; otherwise the value type is autodetected.
   * - ``SearchFilter``
     - ``OR`` across multiple columns (LIKE)
     - ``columns`` (required), ``match`` (``partial`` | ``word_start``, default
       ``partial``)
   * - ``MmFilter``
     - Subquery via MM intermediate table
     - ``mm_table``, ``mm_local_key``, ``mm_foreign_key``, ``mm_constraints``
       (derived from TCA when omitted)

Configuration examples
======================

Basic filters
-------------

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\ExactFilter;
    use MaikSchneider\TcaApi\Filter\PartialFilter;
    use MaikSchneider\TcaApi\Filter\WordStartFilter;

    'filters' => [
        'title'  => ExactFilter::class,            // ?filters[title]=Foo
        'name'   => PartialFilter::class,          // ?filters[name]=oo  → LIKE %oo%
        'slug'   => WordStartFilter::class,        // ?filters[slug]=Fo  → LIKE Fo%
    ],

Many-to-many filter
-------------------

For ``MmFilter``, if the options array is omitted the extension derives the MM
config from TCA automatically (requires a valid ``MM`` key on the field):

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\MmFilter;

    'filters' => [
        // Shorthand: derive MM config from TCA automatically
        'categories' => MmFilter::class,

        // Options form: supply MM table config explicitly
        'tags' => [
            MmFilter::class,
            [
                'mm_table'       => 'tx_myext_article_tag_mm',
                'mm_local_key'   => 'uid_local',
                'mm_foreign_key' => 'uid_foreign',
            ],
        ],
    ],

Search filter
-------------

The search filter allows searching across multiple columns simultaneously:

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\SearchFilter;

    'filters' => [
        'q' => [
            SearchFilter::class,
            [
                'columns' => ['title', 'teaser', 'body'],
                'match'   => 'partial',            // 'partial' (default) or 'word_start'
            ],
        ],
    ],

Usage: ``?filters[q]=typo3`` — searches across all configured columns with
``WHERE (title LIKE '%typo3%' OR teaser LIKE '%typo3%' OR body LIKE '%typo3%')``.

Range filter
------------

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\RangeFilter;

    'filters' => [
        'year'  => RangeFilter::class,
    ],

Usage: ``?filters[year][gte]=2020&filters[year][lte]=2024``

By default the value type is detected from the request: integers stay integers,
decimal/numeric strings are bound as strings (DBAL handles the cast), and
non-numeric strings (such as dates) are bound as strings. Use the ``type``
option to override autodetection — for example to compare against a date or
decimal column, or to keep digit-only strings (zero-padded codes, etc.)
intact:

..  code-block:: php

    'filters' => [
        'created_at' => [RangeFilter::class, ['type' => 'date']],   // ?filters[created_at][gte]=2024-01-01
        'price'      => [RangeFilter::class, ['type' => 'float']],  // ?filters[price][lte]=99.99
        'sku'        => [RangeFilter::class, ['type' => 'string']], // preserves leading zeros
    ],

Supported ``type`` values: ``int``, ``float``, ``string``, ``date``,
``datetime`` (``date`` and ``datetime`` are aliases of ``string``).

Custom filters
==============

Implement ``FilterInterface`` to create your own filter strategy:

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\FilterInterface;
    use TYPO3\CMS\Core\Database\Query\QueryBuilder;

    class MyCustomFilter implements FilterInterface
    {
        public function apply(
            QueryBuilder $queryBuilder,
            string $column,
            mixed $value,
            array $options = []
        ): void {
            // Build custom WHERE clauses
        }
    }

Register it the same way as built-in filters:

..  code-block:: php

    'filters' => [
        'myColumn' => MyCustomFilter::class,
        // or with options:
        'other'    => [MyCustomFilter::class, ['key' => 'value']],
    ],
