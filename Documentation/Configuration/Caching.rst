..  _caching:

=======
Caching
=======

TCA_API supports tag-based HTTP response caching for ``list`` and ``show``
operations. When enabled, responses are stored in a TYPO3 cache and served
from there on subsequent identical requests — bypassing the database query and
serialization entirely.

Configuration
=============

Add a ``cache`` section to any resource definition:

..  code-block:: php

    return [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'articles',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show'],
        ],
        'cache' => [
            'enabled'            => true,
            'lifetime'           => 3600,          // seconds; default: 86400
            'parametersToIgnore' => ['preview'],   // bypass cache when ?preview=… present
        ],
    ];

.. list-table::
   :header-rows: 1
   :widths: 25 10 15 50

   * - Key
     - Required
     - Default
     - Description
   * - ``enabled``
     - No
     - ``false``
     - Enable caching for this resource.
   * - ``lifetime``
     - No
     - ``86400``
     - Cache TTL in seconds. Shortened to the next ``starttime`` or
       ``endtime`` of the served records (see :ref:`caching-scheduled-records`)
       and by listeners (see :ref:`caching-limit-lifetime`).
   * - ``parametersToIgnore``
     - No
     - ``[]``
     - Query parameters that bypass the cache entirely when present in the
       request (top-level or nested under ``filters[…]``). Useful for preview
       or debug parameters.

Cache flow
==========

For every ``GET`` request to a cached resource the dispatcher:

1. Builds a cache key from the site, resolved read pages, operation, resource
   name, UID, sorted query parameters, and the resolved language id (see
   :ref:`languages`). Different sites, storage scopes and locales never share
   a cache entry. Invalid storage-page settings fail before a cache hit.
2. Checks the ``tca_api`` TYPO3 cache.
3. **HIT** — returns the stored response body with ``X-TCA-API-Cache: HIT``
   header.
4. **MISS** — runs the handler, serializes records (tagging each one as
   ``{table}_{uid}``), stores the response, and returns it with
   ``X-TCA-API-Cache: MISS`` and ``X-Cache-Tags`` headers.

Response headers
----------------

.. list-table::
   :header-rows: 1
   :widths: 30 70

   * - Header
     - Value
   * - ``X-TCA-API-Cache``
     - ``HIT`` or ``MISS``
   * - ``X-Cache-Tags``
     - Space-separated list of tags for the records in this response,
       e.g. ``tx_myext_domain_model_article_1 tx_myext_domain_model_article_2``

..  _caching-scheduled-records:

Scheduled records
=================

Like the TYPO3 page cache, an entry expires when a record of the resource's
table starts or ends. If the table has a ``starttime`` or ``endtime`` field
in ``ctrl.enablecolumns``, the entry lifetime on a cache miss is capped at the
next future value:

*   ``list``: of all records the request selects, ignoring their time window:
    the same storage pages, record types, language and filters, on every
    page of the result. Hidden and deleted records don't count.
*   ``show``: of the record itself.
*   On a non-default language, the translations in that language count too.

This costs one query per cache miss. Tables without these fields and cache
hits run no extra query. Related records (embedded relations, file references)
are not taken into account; limit the lifetime for them yourself, see below.

..  _caching-limit-lifetime:

Limiting the lifetime
=====================

Every entry is stored with ``lifetime``, capped at the next transition of the
resource's own records (see :ref:`caching-scheduled-records`). When a response
also changes at a time tca-api doesn't know, e.g. when the categories an
``articles`` resource embeds have a ``starttime``, a listener or operation
handler can shorten the lifetime of the entry it contributes to via
``CacheTagCollector::limitLifetime()``:

..  code-block:: php

    use MaikSchneider\TcaApi\Cache\CacheTagCollector;
    use MaikSchneider\TcaApi\Event\AfterOperationEvent;
    use TYPO3\CMS\Core\Attribute\AsEventListener;
    use TYPO3\CMS\Core\Database\Connection;
    use TYPO3\CMS\Core\Database\ConnectionPool;
    use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;

    #[AsEventListener('myext/limit-api-cache-lifetime')]
    final class LimitApiCacheLifetime
    {
        public function __construct(
            private readonly CacheTagCollector $cacheTagCollector,
            private readonly ConnectionPool $connectionPool,
        ) {}

        public function __invoke(AfterOperationEvent $event): void
        {
            if ($event->getConfig()->resourceName !== 'articles'
                || !$this->cacheTagCollector->isActive()
            ) {
                return;
            }

            $nextStarttime = $this->findNextStarttime('sys_category');
            if ($nextStarttime !== null) {
                $this->cacheTagCollector->limitLifetime($nextStarttime - $GLOBALS['EXEC_TIME']);
            }
        }

        private function findNextStarttime(string $table): ?int
        {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeByType(StartTimeRestriction::class);
            $next = $queryBuilder
                ->selectLiteral($queryBuilder->expr()->min('starttime'))
                ->from($table)
                ->where($queryBuilder->expr()->gt(
                    'starttime',
                    $queryBuilder->createNamedParameter($GLOBALS['SIM_ACCESS_TIME'], Connection::PARAM_INT),
                ))
                ->executeQuery()
                ->fetchOne();

            return $next === null || $next === false ? null : (int)$next;
        }
    }

*   The entry is stored with the smaller of ``lifetime`` and the smallest
    limit of the request; a limit can't extend ``lifetime``.
*   A limit below ``1`` skips storing the response.
*   Calls outside a cache miss of ``list`` or ``show`` (cache disabled, cache
    hit, write operations) are ignored.
*   Without a call the lifetime is unchanged.

Cache invalidation
==================

Cache entries are invalidated automatically from two sources, so cached
responses stay in sync no matter how a record changes:

*   **TYPO3 backend edits** — a DataHandler hook (``clearCachePostProc``) flushes
    all cached responses tagged with the affected table name whenever a record is
    created, updated, or deleted through the backend. Clear-cache commands with
    explicit tags (``TCEMAIN.clearCacheCmd = cacheTag:…``) flush those tags too.
*   **API write operations** (``POST``, ``PUT``, ``PATCH``, ``DELETE``) — each
    write dispatches an ``AfterWriteEvent`` and ``WriteCacheInvalidationListener``
    flushes the affected tags: ``create`` flushes the table tag (``{table}``);
    ``update`` and ``delete`` flush both the table tag (``{table}``) and the
    record tag (``{table}_{uid}``). Writing a record via the API and reading it
    back immediately returns the fresh state.

Cache backend
=============

The extension registers the ``tca_api`` cache with an empty configuration,
which defaults to TYPO3's ``Typo3DatabaseBackend`` (tables ``cf_tca_api`` /
``cf_tca_api_tags``). For production use, configure a faster backend in
:file:`config/system/settings.php` (or :file:`LocalConfiguration.php`):

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tca_api'] = [
        'backend'  => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'options'  => ['defaultLifetime' => 86400],
    ];

A Redis or Memcached backend can further reduce response times for
high-traffic deployments.
