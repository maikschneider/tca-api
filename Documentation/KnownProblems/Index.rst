..  _known-problems:

==============
Known Problems
==============

Current state
=============

TCA API is in **alpha state** (version 0.1.0). While the core functionality is
stable and tested, the API surface may change between minor releases.

Planned features
================

The following features are planned but not yet implemented:

-  **Object-level security for PATCH** — fine-grained access control on
   individual records during partial updates (medium priority).
-  **Custom route patterns** — support for custom URL patterns like
   ``/user/current`` (medium priority).
-  **Maximum items per page** — enforce an upper limit on ``itemsPerPage`` to
   prevent abuse (low priority).
-  **Inline relation field selection** — select specific fields from related
   records when embedding (high priority).
-  **Relation filtering in serialization** — filter related records during
   serialization (low priority).

Reporting issues
================

Please report bugs and feature requests on the
`GitHub issue tracker <https://github.com/maikschneider/tca-api/issues>`__.
