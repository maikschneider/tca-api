..  _introduction:

============
Introduction
============

What does it do?
================

TCA_API is a TYPO3 extension that automatically generates a REST API from your
TYPO3 TCA (Table Configuration Array) definitions. It exposes database tables as
`Hydra JSON-LD <https://www.hydra-cg.com/>`__ resources through a
configuration-driven approach — no custom controllers or Extbase models needed.

By placing a simple PHP configuration file in your extension's
:file:`Configuration/TcaApi/` directory, you get a fully functional REST API with
CRUD operations, filtering, sorting, pagination, validation, and access control.

Motivation
==========

TYPO3 offers several existing approaches for serving content as structured data.
TCA_API was built to fill a gap where other API extensions fall short: exposing
multiple resources uniformly, with minimal boilerplate and strong query
efficiency. See :ref:`motivation` for the full comparison.

..  toctree::
    :hidden:

    ../Motivation/Index

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
   many-to-many filter strategies via query parameters; relation-path filters
   (``categories.title``) reach across one or more hops; configurable defaults
   and private, non-overrideable filters; extensible via ``FilterInterface``
-  **Sorting** — Configurable allowed sort columns with defaults
-  **Pagination** — Offset-based pagination with Hydra ``PartialCollectionView``
   links
-  **Validation** — Required, length, range, item-count, and regex validators
   with structured 422 error responses; auto-derived from TCA and extensible
   via ``ValidatorInterface``
-  **File uploads** — ``multipart/form-data`` uploads on write endpoints with
   per-column FAL storage, size limits, and filename masks
-  **Access control** — Per-operation roles: ``PUBLIC``, ``FE_USER``, ``FE_GROUP``,
   ``BE_USER``, ``BE_ADMIN``, ``OWNER`` (record-level ownership), or custom
   callable voters
-  **Write privilege model** — Actor-aware write context with configurable
   execution strategy, table-level access control, and structured audit logging
-  **Virtual properties** — Computed fields via callables or column processors,
   with support for referencing existing columns (including file/image columns
   at different sizes)
-  **Relation handling** — Shallow stubs or fully embedded related records
   (configurable depth); create related records inline on write
-  **Userinfo endpoint** — Expose the authenticated FE user's own record at a
   configurable URL
-  **Response caching** — Tag-based caching for ``list`` and ``show`` with
   automatic invalidation through the DataHandler, configurable TTL and
   per-request bypass
-  **Multi-language** — URL base segments (``/de/api/…``) and an optional
   ``X-Locale`` header resolve the TYPO3 ``SiteLanguage``, with translation
   overlays and language-scoped cache keys
-  **OpenAPI + Swagger UI** — Auto-generated OpenAPI 3.1.0 spec and interactive
   Swagger UI served directly from the API prefix, plus a backend module on
   TYPO3 v14
-  **PSR-14 events** — Hook into the request lifecycle with Before/AfterOperation
   and Before/AfterWrite events
-  **TYPO3 DataHandler** — Write operations use TYPO3's DataHandler for safe,
   consistent data manipulation
-  **Extensible handler pipeline** — Register custom operation handlers or override
   built-in ones from any extension
-  **TCA-style overrides** — Override or extend any resource config shipped by a
   third-party package via ``Configuration/TcaApi/Overrides/``

Demo project
============

`maikschneider/typo3-petstore <https://github.com/maikschneider/typo3-petstore>`__
is a runnable TYPO3 project that exercises a range of TCA_API resource
configurations in one place — a working reference for how the configuration
options in this manual fit together. It is in early stages and evolves
alongside the extension.
