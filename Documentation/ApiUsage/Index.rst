..  _api-usage:

=========
API Usage
=========

Once a resource is configured, it is automatically available under the API
prefix (``/_api/`` by default).

CRUD endpoints
==============

All resources follow a standard REST pattern:

.. list-table::
   :header-rows: 1
   :widths: 10 40 50

   * - Method
     - Endpoint
     - Description
   * - ``GET``
     - ``/_api/articles``
     - List collection.
   * - ``GET``
     - ``/_api/articles/1``
     - Show a single item.
   * - ``POST``
     - ``/_api/articles``
     - Create a new item. Send a JSON body or ``multipart/form-data`` for file
       uploads.
   * - ``PUT``
     - ``/_api/articles/1``
     - Full update. All writable fields are expected. Use ``multipart/form-data``
       when uploading files.
   * - ``PATCH``
     - ``/_api/articles/1``
     - Partial update. Only submitted fields are updated. Existing file
       references are preserved when the upload field is omitted.
   * - ``DELETE``
     - ``/_api/articles/1``
     - Delete an item.

Response format
===============

All responses use **Hydra JSON-LD** (:mimetype:`application/ld+json`).

Single item response
--------------------

..  code-block:: json

    {
        "@context": "http://www.w3.org/ns/hydra/context.jsonld",
        "@type": "Article",
        "@id": "/_api/articles/1",
        "uid": 1,
        "title": "Hello World"
    }

Collection response
-------------------

..  code-block:: json

    {
        "@context": "http://www.w3.org/ns/hydra/context.jsonld",
        "@type": "hydra:Collection",
        "hydra:totalItems": 42,
        "hydra:member": [
            {
                "@type": "Article",
                "@id": "/_api/articles/1",
                "uid": 1,
                "title": "Hello World"
            }
        ],
        "hydra:view": {
            "@type": "hydra:PartialCollectionView",
            "hydra:first": "/_api/articles?page=1",
            "hydra:last": "/_api/articles?page=3",
            "hydra:next": "/_api/articles?page=2"
        }
    }

Pagination
==========

Collections support offset-based pagination with Hydra ``PartialCollectionView``
links.

..  code-block:: text

    GET /_api/articles?page=2
    GET /_api/articles?page=2&itemsPerPage=10

The response includes navigation links in the ``hydra:view`` object
(``hydra:first``, ``hydra:last``, ``hydra:next``, ``hydra:previous``).

The default page size is controlled by:

1. The resource's ``itemsPerPage`` setting (per-resource override).
2. The site setting ``tca_api.defaultItemsPerPage`` (global default, 20).

Filtering
=========

Filtering is applied via the ``filters`` query parameter:

..  code-block:: text

    GET /_api/articles?filters[title]=Hello
    GET /_api/articles?filters[name]=oo
    GET /_api/articles?filters[categories]=5
    GET /_api/articles?filters[search]=Alice
    GET /_api/articles?filters[price][gte]=10&filters[price][lte]=100

See :ref:`filters` for the full list of filter strategies and configuration.

Sorting
=======

Sorting is controlled via the ``order`` query parameter:

..  code-block:: text

    GET /_api/articles?order[title]=asc
    GET /_api/articles?order[uid]=desc

See :ref:`sorting` for configuration details.

Sparse fieldsets
================

Use the ``fields`` parameter to request only specific columns:

..  code-block:: text

    GET /_api/articles?fields[]=title&fields[]=uid

Only the requested fields (plus ``@id``, ``@type``, and ``uid``) are included in
the response.

OpenAPI spec & Swagger UI
=========================

The extension generates a live **OpenAPI 3.0 JSON spec** from the registered
resources and exposes two additional endpoints:

.. list-table::
   :header-rows: 1
   :widths: 40 60

   * - Endpoint
     - Description
   * - ``{apiPrefix}openapi.json``
     - Machine-readable OpenAPI spec (e.g. ``/_api/openapi.json``).
   * - ``{apiPrefix}swagger-ui``
     - Interactive Swagger UI (e.g. ``/_api/swagger-ui``).

Access to both endpoints is controlled by the ``tca_api.openApiExposed`` and
``tca_api.swaggerUiEnabled`` site settings respectively. Both default to
``PUBLIC``.

File uploads
============

Columns configured with an ``upload`` key accept files via
``multipart/form-data`` on ``POST``, ``PUT``, and ``PATCH`` requests. See
:ref:`file-uploads` for a complete guide including request examples, the
``upload`` config reference, validation error codes, and filename mask
placeholders.
