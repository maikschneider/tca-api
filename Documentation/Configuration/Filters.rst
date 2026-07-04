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

Filtering by related records
----------------------------

Several tools touch related records; the right one depends on *what* you filter
by and *where* the reference is stored:

.. list-table::
   :header-rows: 1
   :widths: 34 33 33

   * - Goal
     - Filter
     - Example
   * - Single-value FK's UID (stored on the row)
     - comparison filter on the FK column
     - ``'color_id' => ExactFilter::class`` → ``?filters[color_id]=2``
   * - MM membership (the related UID)
     - ``MmFilter``
     - ``'categories' => MmFilter::class`` → ``?filters[categories]=5``
   * - A *column* of a related record (FK, MM or inline; one or more hops)
     - relation-path (dotted key)
     - ``'categories.title' => ExactFilter::class`` → ``?filters[categories.title]=News``
   * - An inline child's UID (no column on the parent)
     - relation-path with ``.uid``
     - ``'related_items.uid' => ExactFilter::class``

* ``relationField.uid`` also works for FK and MM relations, but prefer the FK
  column filter or ``MmFilter`` there — they avoid the extra join to the target
  table (the path form additionally requires the related record to be visible).
* Relation-path (dotted) filters must use the bracket form ``?filters[…]`` —
  PHP rewrites dots in top-level parameter names to underscores.

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

Relation-path filters
---------------------

A **dotted filter key** filters the resource by a column reached through one or
more relations. The last path segment is a scalar column on the deepest related
table; the segments before it are relations to traverse. The declared filter
(``ExactFilter`` below) performs the comparison on that column — the dot in the
key is detected automatically, so there is no extra filter class to register:

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\ExactFilter;

    'filters' => [
        'color_id.name'           => ExactFilter::class,  // one FK hop     → the colour's name
        'categories.title'        => ExactFilter::class,  // one MM hop     → a category's title
        'related_items.name'      => ExactFilter::class,  // one inline hop → an inline child's name
        'categories.parent.title' => ExactFilter::class,  // two hops       → a category's parent's title
    ],

Usage (dotted keys require the bracket form — see the note below):

..  code-block:: text

    ?filters[color_id.name]=Red
    ?filters[categories.title]=News
    ?filters[categories.parent.title]=Root

Any comparison filter can be the leaf. ``ExactFilter`` is the default;
``RangeFilter``, ``WordStartFilter`` and ``PartialFilter`` compare the leaf
column directly, and their options are forwarded through the options form:

..  code-block:: php

    'filters' => [
        'stock.updated_at' => [RangeFilter::class, ['type' => 'date']],
    ],

How it works
~~~~~~~~~~~~

Each relation hop wraps the previous result in an ``IN (subquery)`` that maps
related UIDs back to the holder record, built inside-out and de-duplicating, so
pagination and counts stay correct. Every hop is built through the native query
builder, so each intermediate table's enable-field restrictions (``deleted``,
``hidden`` / disabled, start/end time, ``fe_group``) are applied at every
level — a hidden or deleted intermediate record never leaks a match.

Supported relations and limits
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

* **Supported:** single-value ``select`` foreign-key relations; MM relations
  (including ``type=category`` and ``type=group`` with ``MM``); and ``type=inline``
  relations (``foreign_field``, honouring the ``foreign_table_field`` and
  ``foreign_match_fields`` discriminators).
* **Not supported:** non-MM group relations (comma-separated storage — add ``MM``
  to the field instead), and MM/group relations that allow **more than one**
  table (the target table would be ambiguous).
* **Maximum of 3 relation hops** per path.
* Invalid paths — unknown column, unsupported or ambiguous relation, or too many
  hops — are rejected at boot with a clear ``InvalidApiDefinitionException``, so
  the misconfiguration surfaces immediately rather than on the first request that
  uses the filter.

..  note::

    Relation-path filters must be supplied via the bracket form
    (``?filters[categories.title]=News``). PHP rewrites dots in *top-level*
    query-parameter names to underscores, so ``?categories.title=News`` would
    not match — dots inside ``filters[…]`` are preserved.

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

Searching related records
~~~~~~~~~~~~~~~~~~~~~~~~~~~

An entry in ``columns`` may be a **relation path** (dotted), so a single search can span
the resource's own columns and columns of related records — matched via the same hop
resolution as :ref:`relation-path filters <filters>` and OR-ed together:

..  code-block:: php

    'filters' => [
        'q' => [
            SearchFilter::class,
            ['columns' => ['title', 'categories.title', 'color_id.name']],
        ],
    ],

``?filters[q]=news`` then matches the article's own ``title`` **or** any of its categories'
``title`` **or** its colour's ``name``. Related columns are matched through a
``t.uid IN (subquery)`` (honouring the related table's enable-field restrictions), so
pagination stays correct. The ``match`` mode applies to every column, own and related.
Dotted columns are resolved and validated at boot — a typo or unsupported relation is
rejected with an ``InvalidApiDefinitionException`` rather than failing at request time.

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

.. note::

   ``DataRepository`` queries the main resource table under the alias ``t``
   (``FROM {table} AS t``, ``SELECT t.*``). When your filter joins an
   additional table, qualify any main-table column references with ``t.`` to
   avoid ambiguous-column SQL errors.

Joining an additional table
---------------------------

Use ``$qb->join()`` to add a JOIN inside ``apply()``. Reference the main
table via its alias ``t``:

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\FilterContext;
    use MaikSchneider\TcaApi\Filter\FilterInterface;
    use TYPO3\CMS\Core\Database\Query\QueryBuilder;

    final class TagFilter implements FilterInterface
    {
        public function apply(QueryBuilder $qb, FilterContext $context): void
        {
            $qb->join(
                    't',
                    'tx_myext_domain_model_tag',
                    'tag',
                    $qb->expr()->eq('t.tag_id', $qb->quoteIdentifier('tag.uid')),
                )
                ->andWhere($qb->expr()->eq(
                    'tag.name',
                    $qb->createNamedParameter($context->value),
                ));
        }
    }

The first argument of ``join()`` must be ``'t'`` — the alias under which
``DataRepository`` registered the resource table. Column references on the
joined table (``tag.name`` above) need no alias prefix.

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
