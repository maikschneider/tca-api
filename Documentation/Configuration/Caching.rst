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
     - Cache TTL in seconds.
   * - ``parametersToIgnore``
     - No
     - ``[]``
     - Query parameters that bypass the cache entirely when present in the
       request (top-level or nested under ``filters[…]``). Useful for preview
       or debug parameters.

Cache flow
==========

For every ``GET`` request to a cached resource the dispatcher:

1. Builds a cache key from the operation, resource name, UID, sorted query
   parameters, and the resolved language id (see :ref:`languages`). Different
   locales never share a cache entry.
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

Cache invalidation
==================

Cache entries are invalidated automatically from two sources, so cached
responses stay in sync no matter how a record changes:

*   **TYPO3 backend edits** — a DataHandler hook (``clearCachePostProc``) flushes
    all cached responses tagged with the affected table name whenever a record is
    created, updated, or deleted through the backend.
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
