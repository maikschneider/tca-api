..  _motivation:

==========
Motivation
==========

Several existing approaches exist for serving TYPO3 content as structured
data — Extbase repositories, the Record API (v13+), EXT:headless, and
annotation-driven frameworks like `EXT:t3api
<https://github.com/sourcebroker/t3api>`__ and `EXT:nnrestapi
<https://extensions.typo3.org/extension/nnrestapi>`__. TCA_API was built
because none of them solve the **read-heavy API use case** without significant
trade-offs in performance, boilerplate, or flexibility.

..  attention::

    The analysis below is based on code reading and architectural reasoning.
    **Concrete, real-world benchmarks against production setups of EXT:t3api
    and EXT:nnrestapi have not yet been conducted.** The numbers reflect
    the theoretical query-count differences between the strategies, confirmed
    by a synthetic in-process test. Conclusions may not hold for all workloads.

    Feedback and benchmark contributions from developers experienced with these
    extensions are very welcome — see :ref:`call-for-feedback` at the end of
    this chapter.

Why existing approaches fall short
====================================

Why not EXT:nnrestapi?
-----------------------

`EXT:nnrestapi <https://extensions.typo3.org/extension/nnrestapi>`__ is an
endpoint framework: you write a PHP class extending ``AbstractApi``, annotate
its methods, and return data however you choose. This gives maximum flexibility,
but shifts all responsibility to the developer:

-  **No built-in relation loading.** Returning Extbase domain objects hands
   serialization to TYPO3's standard DataMapper, which resolves every relation
   property individually during JSON conversion. A 20-item collection with 2
   relation types produces the same **41 queries** as any Extbase-based approach.

-  **No built-in filtering, pagination, or validation.** Each endpoint is custom
   PHP. Adding a filter means writing a query constraint by hand; pagination
   requires manually counting rows and slicing results. Every resource needs its
   own implementation.

-  **No configuration model.** There is no declarative description of a
   resource's shape, access rules, or allowed operations. Everything is imperative
   code inside action methods, growing linearly with the number of resources.

-  **Plain JSON output.** Responses are plain JSON — no Hydra JSON-LD, no
   ``@context``, no ``@type``, no discoverable collection links.

nnrestapi is a reasonable choice for bespoke, one-off endpoints where the
flexibility is genuinely needed. It is a poor fit for exposing multiple
resources uniformly.

Why not EXT:t3api?
-------------------

`EXT:t3api <https://github.com/sourcebroker/t3api>`__ is the closest prior
art — Hydra JSON-LD output, built-in filtering, pagination, serialization
groups, and an API-Platform-inspired annotation model. It is a mature extension.
The core limitation is the persistence layer:

-  **Built on Extbase DataMapper.** Resources must be Extbase domain models
   (``AbstractDomainObject`` subclasses). Queries use
   ``PersistenceManagerInterface::createQueryForType()``, and all relation
   properties are resolved via Extbase's ``DataMapper``.

-  **N+1 queries for embedded relations.** DataMapper resolves relations through
   ``LazyLoadingProxy`` objects fetched on first access. Serializing 20 articles
   with 2 embedded relation types fires **41 database queries** — one for the
   collection plus one per relation per row.

-  **Extbase model required per resource.** Exposing a table from a third-party
   extension that ships no domain model class requires creating one.

Why not the TYPO3 Record API?
------------------------------

The `Record API
<https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Database/DatabaseRecords/RecordObjects.html>`__
introduced in TYPO3 v13 provides ``RecordFactory`` and typed ``Record`` objects
with lazy relation resolution. It is a solid foundation for Fluid templates,
but has key limitations for API use:

-  **Per-record hydration overhead.**
   ``RecordFactory::createResolvedRecordFromDatabaseRow()`` instantiates a
   ``Record`` object per row, transforms each field through
   ``RecordFieldTransformer``, and wraps relations in ``LazyRecordCollection``
   or ``RecordPropertyClosure`` closures. For a collection of 20 records with
   5 relation columns, this creates 20 Record objects + 100 lazy wrappers —
   before any relation is even accessed.

-  **No batch relation loading.** When serializing a collection to JSON, every
   lazy relation fires a separate query on first access. 20 articles × (1 color
   + 1 category MM) = **41 queries**. The ``GreedyDatabaseBackend`` mitigates
   this by pre-fetching an entire foreign table by PID, but this over-fetches
   and only helps within a single page context.

-  **Designed for rendering, not serialization.** Calling ``$record->toArray()``
   force-instantiates all lazy closures. There is no depth control, no cycle
   detection, and no way to configure which relations to embed vs. return as
   references.

Why not Extbase alone?
-----------------------

Extbase's ``DataMapper`` suffers from the classic **N+1 query problem**. Each
relation property on each domain object triggers a separate
``getPreparedQuery()`` call. The ``@Lazy`` annotation defers queries but doesn't
batch them.

Why not EXT:headless?
----------------------

`EXT:headless <https://github.com/TYPO3-Headless/headless>`__ replaces TYPO3's
HTML output with JSON via TypoScript ``JSON`` content objects. It uses the same
rendering pipeline (CONTENT cObjects, DataProcessors) and executes the same
queries as a normal page render. The benefit is smaller payloads for the
frontend, not fewer database queries.

How TCA_API solves this
========================

TCA_API takes a fundamentally different approach: **raw SQL via QueryBuilder
with bulk preloading** and a **zero-boilerplate configuration model**.

1. **No ORM, no object hydration.** Records are raw associative arrays from
   ``ConnectionPool::getQueryBuilderForTable()``. Zero overhead from property
   mapping, proxy objects, or lazy wrappers.

2. **EmbedPreloader eliminates N+1 queries.** Before serialization, the
   preloader scans all rows in a collection, collects every referenced foreign
   key, and executes **one query per relation type** — regardless of collection
   size:

   -  hasOne FKs: ``SELECT * FROM colors WHERE uid IN (1, 2, 3)``
   -  hasMany MM:
      ``SELECT f.*, mm.uid_local FROM categories f JOIN mm ON ... WHERE mm.uid_foreign IN (...)``
   -  hasMany foreignField:
      ``SELECT * FROM children WHERE parent_id IN (...)``

3. **Fixed query count.** The number of queries is ``1 + R`` (one collection
   query + one per relation type), not ``1 + N×R``. Adding more rows to a page
   does not increase the query count.

4. **Zero boilerplate per resource.** A three-key PHP array is a complete
   resource definition — no domain model class, no repository, no controller,
   no routing config. Filtering, pagination, access control, and validation are
   declared in the same file.

Query count analysis
=====================

The query-count difference between strategies is deterministic and can be
derived from simple formulas:

-  **Naive N+1 / Extbase / t3api:** ``Q = 1 + N × R``
-  **TCA_API (EmbedPreloader):** ``Q = 1 + R``

Where ``N`` is the collection size and ``R`` is the number of embedded
relation types per record.

.. list-table::
   :header-rows: 1
   :widths: 20 15 15 15 15

   *  -  Collection size
      -  Relations
      -  N+1 queries
      -  TCA_API queries
      -  Savings
   *  -  20 items
      -  2
      -  41
      -  3
      -  92.7%
   *  -  50 items
      -  2
      -  101
      -  3
      -  97.0%
   *  -  100 items
      -  3
      -  301
      -  4
      -  98.7%
   *  -  100 items
      -  5
      -  501
      -  6
      -  98.8%

These numbers are theoretical projections from the formula, not measurements
from live extensions. Wall-clock impact depends on database round-trip latency,
query complexity, and caching — all of which vary significantly between projects.

Comparison matrix
==================

.. list-table::
   :header-rows: 1
   :widths: 17 17 17 17 16 16

   *  -  Concern
      -  TCA_API
      -  EXT:t3api
      -  EXT:nnrestapi
      -  Record API
      -  EXT:headless
   *  -  Query strategy
      -  Bulk preload
      -  Extbase N+1
      -  Extbase N+1 or manual
      -  Lazy + greedy-by-PID
      -  Same as page render
   *  -  Queries (20 × 2 rels)
      -  3
      -  ~41
      -  ~41 (or raw, no rels)
      -  ~41
      -  ~40-80
   *  -  Object overhead
      -  None (raw arrays)
      -  Domain objects + proxies
      -  Domain objects or arrays
      -  Record + closures
      -  TypoScript cObjects
   *  -  Configuration model
      -  PHP array (zero code)
      -  Annotations on model
      -  Per-method PHP
      -  N/A
      -  TypoScript
   *  -  Filtering + pagination
      -  Built-in
      -  Built-in
      -  Manual per endpoint
      -  N/A
      -  N/A
   *  -  JSON output format
      -  Hydra JSON-LD
      -  Hydra JSON-LD
      -  Plain JSON
      -  Manual
      -  Native JSON
   *  -  Extbase model required
      -  No
      -  Yes
      -  Optional
      -  No
      -  No
   *  -  Write operations
      -  DataHandler
      -  Repository
      -  Manual
      -  N/A
      -  N/A
   *  -  TYPO3 version
      -  ^13.4 || ^14.0
      -  ^12.4 || ^13.3
      -  ^13.0
      -  ^13.0
      -  ^12.0+

..  _call-for-feedback:

Call for feedback
==================

The comparison above is based on source-code reading and architectural
reasoning. It has **not** been independently verified by developers
experienced with EXT:t3api or EXT:nnrestapi, nor has it been validated
against real-world production workloads.

If you work with these extensions and find that any claim is inaccurate,
misleading, or missing important nuance — please open a
`GitHub Discussion <https://github.com/maikschneider/tca-api/discussions>`__
or a pull request. Corrections are welcome, and the goal is an honest
comparison rather than marketing copy.

Specifically, contributions are wanted for:

-  **Real-world benchmarks** using actual projects with EXT:t3api or
   EXT:nnrestapi, ideally with a shared test dataset so results are
   reproducible.
-  **Edge cases** where the N+1 analysis does not apply — for example,
   EXT:t3api configurations that avoid relation embedding, or nnrestapi
   endpoints using raw ``QueryBuilder`` queries.
-  **Corrections** to any architectural claim that is inaccurate as of
   the current version of either extension.
