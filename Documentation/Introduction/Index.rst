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

    TCA_API is currently in **beta state** (version 0.4.0). The API surface may
    change between minor releases. It is not yet recommended for production use
    without thorough testing.

    **Feedback and contributions are very welcome.** This is the right moment to
    validate the architecture, security model, and design decisions before they
    stabilize. If you have experience with TYPO3 REST APIs or security auditing,
    your input is especially valuable. Please join the conversation in the
    `GitHub Discussions <https://github.com/maikschneider/tca-api/discussions>`__.
