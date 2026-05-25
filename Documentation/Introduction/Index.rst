..  _introduction:

============
Introduction
============

What does it do?
================

TCA API is a TYPO3 extension that automatically generates a REST API from your
TYPO3 TCA (Table Configuration Array) definitions. It exposes database tables as
`Hydra JSON-LD <https://www.hydra-cg.com/>`__ resources through a
configuration-driven approach — no custom controllers or Extbase models needed.

By placing a simple PHP configuration file in your extension's
:file:`Configuration/TcaApi/` directory, you get a fully functional REST API with
CRUD operations, filtering, sorting, pagination, validation, and access control.

Motivation
==========

Several existing approaches exist for serving TYPO3 content as structured data —
Extbase repositories, the Record API (v13+), EXT:headless, annotation-driven
frameworks like `EXT:t3api <https://github.com/sourcebroker/t3api>`__ and
`EXT:nnrestapi <https://extensions.typo3.org/extension/nnrestapi>`__, and custom
controllers. TCA API was built because none of them solve the **read-heavy API
use case** without significant trade-offs in performance, boilerplate, or
flexibility.

Why not EXT:nnrestapi?
-----------------------

`EXT:nnrestapi <https://extensions.typo3.org/extension/nnrestapi>`__ is an
endpoint framework: you write a PHP class extending ``AbstractApi``, annotate its
methods, and return data however you like. This gives maximum flexibility, but
shifts all responsibility to the developer:

-  **No built-in relation loading.** If you return Extbase domain objects, the
   extension serializes them via TYPO3's standard DataMapper — which resolves
   every relation property individually as it encounters it during JSON
   conversion. A 20-item collection with 2 relation types produces the same
   **41 queries** as any other Extbase-based approach.

-  **No built-in filtering, pagination, or validation.** Each endpoint is custom
   code. Adding a filter means writing a query constraint; adding pagination means
   manually counting rows and slicing results. Every resource needs its own
   implementation.

-  **No configuration model.** There is no declarative description of a
   resource's shape, access rules, or allowed operations. Everything is imperative
   PHP inside action methods.

-  **Plain JSON output.** Responses are plain JSON objects — no Hydra JSON-LD,
   no ``@context``, no ``@type``, no discoverable collection links.

nnrestapi is a good choice for bespoke, one-off endpoints where the flexibility
is genuinely needed. It is a poor fit for exposing multiple resources uniformly:
the per-endpoint boilerplate grows linearly with the number of resources.

Why not EXT:t3api?
-------------------

`EXT:t3api <https://github.com/sourcebroker/t3api>`__ is the closest prior art —
it produces Hydra JSON-LD, supports filtering, pagination, and serialization
groups, and uses an API-Platform-inspired annotation model. It is a mature
extension. The core limitation is its persistence layer:

-  **Built on Extbase DataMapper.** t3api exposes Extbase models
   (``AbstractDomainObject`` subclasses) and uses
   ``PersistenceManagerInterface::createQueryForType()`` to execute queries. All
   relation properties are resolved via Extbase's ``DataMapper``.

-  **N+1 queries for embedded relations.** Because DataMapper resolves relations
   by instantiating ``LazyLoadingProxy`` objects and fetching each on first access,
   serializing a collection of 20 articles with 2 embedded relation types fires
   **41 database queries** — one for the collection and one per relation per row.

-  **Extbase model required.** Each resource must have an Extbase domain model
   class with mapping annotations. Exposing a table from a third-party extension
   that ships no domain model requires creating one.

Why not the TYPO3 Record API?
-----------------------------

The `Record API
<https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Database/DatabaseRecords/RecordObjects.html>`__
introduced in TYPO3 v13 provides ``RecordFactory`` and typed ``Record`` objects
with lazy relation resolution. It is a solid foundation for Fluid templates, but
has key limitations for API use:

-  **Per-record hydration overhead.**
   ``RecordFactory::createResolvedRecordFromDatabaseRow()`` instantiates a
   ``Record`` object per row, transforms each field through
   ``RecordFieldTransformer``, and wraps relations in ``LazyRecordCollection``
   or ``RecordPropertyClosure`` closures. For a collection of 20 records with 5
   relation columns, this creates 20 Record objects + 100 lazy wrappers — before
   any relation is even accessed.

-  **No batch relation loading.** When serializing a collection to JSON, every
   lazy relation fires a separate query on first access. 20 articles × (1 color
   + 1 category MM) = **41 queries**. The ``GreedyDatabaseBackend`` mitigates
   this by pre-fetching an entire foreign table by PID, but this over-fetches
   (loads all colors on a page, not just the referenced ones) and only helps
   within a single page context.

-  **Designed for rendering, not serialization.** Calling ``$record->toArray()``
   force-instantiates all lazy closures. There is no depth control, no cycle
   detection, and no way to configure which relations to embed vs. return as
   references.

Why not Extbase alone?
-----------------------

Extbase's ``DataMapper`` suffers from the classic **N+1 query problem**. Each
relation property on each domain object triggers a separate
``getPreparedQuery()`` call. The ``@Lazy`` annotation defers queries but doesn't
batch them — iterating over a lazy collection in a loop produces more queries
than eager loading.

Why not EXT:headless?
---------------------

`EXT:headless <https://github.com/TYPO3-Headless/headless>`__ replaces TYPO3's
HTML output with JSON via TypoScript ``JSON`` content objects. It uses the same
rendering pipeline (CONTENT cObjects, DataProcessors) and executes the same
queries as a normal page render. The benefit is smaller payloads for the
frontend, not fewer database queries.

How TCA API solves this
-----------------------

TCA API takes a fundamentally different approach: **raw SQL via QueryBuilder with
bulk preloading** and a **zero-boilerplate configuration model**.

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
   no routing config. Filtering, pagination, access control, validation, and
   OpenAPI documentation are declared in the same file.

Performance
===========

The extension includes a runnable benchmark
(:file:`Tests/Functional/Benchmark/`) that measures query counts and wall-clock
time against a real MySQL database (DDEV). Results for 20 articles, each with a
hasOne (color) and a hasMany MM (categories) relation:

.. list-table::
   :header-rows: 1
   :widths: 30 10 15 45

   *  -  Approach
      -  Queries
      -  Time (50 iter., MySQL)
      -  Scaling
   *  -  **TCA API** (EmbedPreloader)
      -  **3**
      -  **~40 ms**
      -  ``O(1+R)`` — constant per relation type
   *  -  Naive N+1 / Extbase / t3api
      -  41
      -  ~407 ms
      -  ``O(1+N×R)`` — linear per row × relation
   *  -  Record API (object hydration)
      -  41+
      -  >407 ms
      -  ``O(1+N×R)`` + instantiation overhead

The **10× wall-clock difference** reflects real MySQL round-trip latency. Each
additional query carries network overhead to the database; bulk preloading
collapses 41 round trips into 3. The gap widens further in production
environments where the database is on a separate host.

**Query count scaling** (from benchmark formulas):

.. list-table::
   :header-rows: 1
   :widths: 20 15 15 15 15

   *  -  Collection size
      -  Relations
      -  N+1 queries
      -  TCA API queries
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

Run the benchmark yourself:

.. code-block:: bash

   vendor/bin/phpunit Tests/Functional/Benchmark/QueryCountBenchmarkTest.php --testdox

Comparison matrix
-----------------

.. list-table::
   :header-rows: 1
   :widths: 17 17 17 17 16 16

   *  -  Concern
      -  TCA API
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

Features
========

-  **Full CRUD** — List, show, create, update (PUT & PATCH), and delete operations
-  **Hydra JSON-LD** — Responses follow the `Hydra <https://www.hydra-cg.com/>`__
   specification (:mimetype:`application/ld+json`)
-  **Configuration-driven** — Expose tables by registering a PHP configuration
   array; no custom controllers needed
-  **Serialization groups** — Use ``groups`` to control which columns appear per
   operation (``list``, ``show``, ``create``, ``update``)
-  **Filtering** — Exact, partial, word-start, range, full-text search, and
   many-to-many filter strategies via query parameters; extensible via
   ``FilterInterface``
-  **Sorting** — Configurable allowed sort columns with defaults
-  **Pagination** — Offset-based pagination with Hydra ``PartialCollectionView``
   links
-  **Validation** — Required, maxLength, minLength, and regex validators with
   structured 422 error responses
-  **Access control** — Per-operation roles: ``PUBLIC``, ``FE_USER``, ``FE_GROUP``,
   ``BE_USER``, ``BE_ADMIN``, ``OWNER`` (record-level ownership), or custom
   callable voters
-  **Virtual properties** — Computed fields via callables or column processors,
   with support for referencing existing columns (including file/image columns
   at different sizes)
-  **Relation handling** — Shallow stubs or fully embedded related records
   (configurable depth)
-  **Userinfo endpoint** — Expose the authenticated FE user's own record at a
   configurable URL
-  **OpenAPI + Swagger UI** — Auto-generated OpenAPI 3.0 spec and interactive
   Swagger UI served directly from the API prefix
-  **PSR-14 events** — Hook into the request lifecycle with Before/AfterOperation
   and Before/AfterWrite events
-  **TYPO3 DataHandler** — Write operations use TYPO3's DataHandler for safe,
   consistent data manipulation
-  **Extensible handler pipeline** — Register custom operation handlers or override
   built-in ones from any extension

Current state
=============

..  attention::

    TCA API is currently in **alpha state** (version 0.1.0). The API surface may
    change between minor releases. It is not yet recommended for production use
    without thorough testing.
