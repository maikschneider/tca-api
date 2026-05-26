..  _start:

=======
TCA_API
=======

:Extension key:
   tca_api

:Package name:
   maikschneider/tca-api

:Version:
   |release|

:Language:
   en

:Author:
   Maik Schneider & Contributors

:License:
   This document is published under the
   `Open Publication License <https://www.opencontent.org/openpub/>`__.

:Rendered:
   |today|

----

TCA_API is a TYPO3 extension that exposes database tables as **Hydra JSON-LD**
resources through a configuration-driven REST API. Define which tables, columns,
and operations to expose — the extension handles routing, serialization,
validation, pagination, and access control.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        What is TCA_API, what are its features, and what does it do?

    ..  card:: :ref:`Installation <installation>`

        How to install and set up the extension via Composer and TYPO3 site sets.

    ..  card:: :ref:`Configuration <configuration>`

        Complete reference for resource definitions, columns, filters, sorting,
        security, and site settings.

    ..  card:: :ref:`API Usage <api-usage>`

        How to use the REST endpoints, pagination, filtering, and the OpenAPI
        spec and Swagger UI.

    ..  card:: :ref:`Developer Guide <developer>`

        PSR-14 events, custom operation handlers, request attributes, and
        extensibility.

    ..  card:: :ref:`Known Problems <known-problems>`

        Current limitations, known issues, and planned features.

..  Table of Contents

..  toctree::
    :maxdepth: 2
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    ApiUsage/Index
    Developer/Index
    KnownProblems/Index
