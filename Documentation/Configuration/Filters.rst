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
     - Value must be ``['gte'=>…, 'lte'=>…, 'gt'=>…, 'lt'=>…]``. The bound
       parameter type is inferred from the column's TCA configuration
       (``number``, ``datetime``, …); the optional ``type``
       (``int`` | ``float`` | ``string`` | ``date`` | ``datetime``) overrides
       it.
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

The bound DBAL parameter type is resolved in this order:

1. The explicit ``type`` filter option (escape hatch — see below).
2. The TCA configuration of the column:

   * ``type: number`` (integer format) → ``int``
   * ``type: number, format: decimal`` → ``float``
   * ``type: datetime`` without ``dbType`` (UNIX timestamp column) → ``int``
   * ``type: datetime`` with ``dbType`` (native ``DATE``/``DATETIME``/``TIME``)
     → ``string``
   * ``type: input, eval: …,int,…`` → ``int``

3. Autodetection from the request value (integers stay integers, decimal /
   numeric strings are bound as strings, non-numeric strings such as ISO
   dates are bound as strings).

Use the ``type`` option to override TCA-inferred and autodetected types — for
example to keep digit-only strings (zero-padded SKU codes) intact, or to
force a specific cast on a column whose TCA type does not map cleanly:

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

Implement ``FilterInterface`` to create your own filter strategy. The extension
discovers all implementations automatically via Symfony DI — no
``Services.yaml`` registration is needed.

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\FilterContext;
    use MaikSchneider\TcaApi\Filter\FilterInterface;
    use TYPO3\CMS\Core\Database\Query\QueryBuilder;

    final class PublishedAfterFilter implements FilterInterface
    {
        public function apply(QueryBuilder $qb, FilterContext $context): void
        {
            $qb->andWhere($qb->expr()->gte(
                $context->column,
                $qb->createNamedParameter((int)$context->value),
            ));
        }
    }

``FilterContext`` is a typed readonly value object:

.. list-table::
   :header-rows: 1
   :widths: 20 20 60

   * - Property
     - Type
     - Description
   * - ``value``
     - ``mixed``
     - Filter value from the request query string
   * - ``table``
     - ``string``
     - Resource table name
   * - ``column``
     - ``string``
     - Column name this filter is applied to
   * - ``options``
     - ``array``
     - Filter-specific options from the resource config
   * - ``request``
     - ``ServerRequestInterface|null``
     - PSR-7 request — available in HTTP context; ``null`` in unit tests
   * - ``resourceConfig``
     - ``ApiDefinition|null``
     - Full resource config — available in HTTP context; ``null`` in unit tests

Use ``$context->option('key', $default)`` to read from ``options`` with a
fallback default.

Register it the same way as built-in filters:

..  code-block:: php

    'filters' => [
        'myColumn' => MyCustomFilter::class,
        // or with options — accessed via $context->option('key')
        'other'    => [MyCustomFilter::class, ['key' => 'value']],
    ],

Default values and private filters
===================================

Two meta-keys are available on any filter definition and control server-side
defaults and enforcement:

.. list-table::
   :header-rows: 1
   :widths: 15 15 70

   * - Option
     - Type
     - Description
   * - ``default``
     - ``mixed``
     - Value applied when the filter is absent from the request URL params.
   * - ``private``
     - ``bool``
     - When ``true``, ``default`` always applies — user-supplied values are
       ignored. The filter is also excluded from the OpenAPI spec.

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\ExactFilter;

    'filters' => [
        // Overrideable default — applied when ?filters[color_id] is absent
        'color_id' => [ExactFilter::class, ['default' => '1']],

        // Private filter — default always applies, cannot be overridden via
        // URL, and does not appear in the OpenAPI spec
        'deleted' => [ExactFilter::class, ['default' => '0', 'private' => true]],
    ],

A private filter without a ``default`` has no effect.

Boot-time pre-resolution (FilterPreResolvableInterface)
=======================================================

For filters that need expensive configuration — such as TCA schema lookups —
implement ``FilterPreResolvableInterface`` in addition to ``FilterInterface``:

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\FilterContext;
    use MaikSchneider\TcaApi\Filter\FilterDefinition;
    use MaikSchneider\TcaApi\Filter\FilterInterface;
    use MaikSchneider\TcaApi\Filter\FilterPreResolvableInterface;
    use TYPO3\CMS\Core\Database\Query\QueryBuilder;

    final class MyExpensiveFilter implements FilterInterface, FilterPreResolvableInterface
    {
        public function preResolve(FilterDefinition $definition): FilterDefinition
        {
            // Called once at definition build time (cache miss).
            // Derive expensive config and bake it in via withOptions().
            if ($definition->table === '') {
                return $definition; // guard for unit-test contexts
            }
            return $definition->withOptions(['resolved_value' => $this->deriveFromTca($definition)]);
        }

        public function apply(QueryBuilder $qb, FilterContext $context): void
        {
            // $context->option('resolved_value') is already set from preResolve()
            $qb->andWhere($qb->expr()->eq(
                $context->column,
                $qb->createNamedParameter($context->option('resolved_value')),
            ));
        }
    }

``ApiDefinitionLoader`` calls ``preResolve()`` once per filter column during the
definition build (on cache miss). The returned ``FilterDefinition``, with derived
options merged in, is stored alongside the ``ApiDefinition`` cache entry.
Subsequent boots load the pre-resolved definition directly — the TCA lookup does
not repeat.

``apply()`` must remain safe when ``preResolve()`` was never called (unit-test
contexts where no loader is involved). Check ``$definition->table === ''`` or
``$context->option('resolved_value') === null`` as guards.
