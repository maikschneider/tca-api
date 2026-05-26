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

TYPO3 offers several existing approaches for serving content as structured data,
each with different trade-offs in performance, boilerplate, and flexibility.
TCA API was built to address a gap that none of them fill well: a **read-heavy
API with zero per-resource boilerplate and no N+1 query problem**.

The core insight is that raw SQL bulk preloading — fetching all relation data for
an entire collection in one query per relation type, rather than one per row —
reduces query counts from ``O(N×R)`` to ``O(R)``, regardless of collection size.

For the full analysis, including a comparison with `EXT:t3api
<https://github.com/sourcebroker/t3api>`__, `EXT:nnrestapi
<https://extensions.typo3.org/extension/nnrestapi>`__, the TYPO3 Record API, and
Extbase, see :ref:`motivation`.

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

    **Feedback and contributions are very welcome.** This is the right moment to
    validate the architecture, security model, and design decisions before they
    stabilize. If you have experience with TYPO3 REST APIs or security auditing,
    your input is especially valuable. Please join the conversation in the
    `GitHub Discussions <https://github.com/maikschneider/tca-api/discussions>`__.
