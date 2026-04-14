..  _configuration:

=============
Configuration
=============

TCA API is configured through two mechanisms:

1. **Site settings** — global per-site options such as the API prefix, pagination
   defaults, CORS headers, and OpenAPI access.
2. **Resource definitions** — per-table PHP arrays that define which tables,
   columns, and operations to expose.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    SiteSettings
    ResourceDefinition
    Columns
    Filters
    Sorting
    Security
    Relations
    Validation
    Userinfo
