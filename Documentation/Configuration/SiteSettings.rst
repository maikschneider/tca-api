..  _site-settings:

=============
Site Settings
=============

The following settings are configurable per site in the TYPO3 backend under
:guilabel:`Site Management → Sites → Settings` in the **TCA_API** category.

General
=======

..  confval:: tca_api.enabled
    :type: bool
    :default: ``true``

    Enable or disable the API for this site. When disabled, the middleware
    passes all requests through without processing.

..  confval:: tca_api.apiPrefix
    :type: string
    :default: ``/_api/``

    URL path prefix for all API endpoints. Must start and end with a slash.
    The API is inactive until this is set.

..  confval:: tca_api.defaultItemsPerPage
    :type: int
    :default: ``20``

    Default number of items returned per page in collection responses.
    Can be overridden per resource in the resource definition.

..  confval:: tca_api.allowedResources
    :type: string
    :default: *(empty — all)*

    Comma-separated list of resource names to expose on this site. Leave
    empty to allow all registered resources.

..  confval:: tca_api.debugMode
    :type: bool
    :default: ``false``

    Return verbose error details in API responses. **Disable on production
    sites.**

API specification
=================

..  confval:: tca_api.openApiExposed
    :type: string
    :default: ``PUBLIC``

    Who may access the OpenAPI JSON spec at ``{apiPrefix}openapi.json``.
    Allowed values: ``PUBLIC``, ``FE_USER``, ``BE_USER``, ``BE_ADMIN``,
    ``NONE``.

..  confval:: tca_api.apiSpecTitle
    :type: string
    :default: ``TCA_API``

    Title shown in the OpenAPI spec info block and Swagger UI header.

..  confval:: tca_api.apiSpecDescription
    :type: string
    :default: *(empty)*

    Short description shown in the OpenAPI spec info block and Swagger UI.

..  confval:: tca_api.apiSpecVersion
    :type: string
    :default: ``1.0.0``

    Version string for the OpenAPI spec info block.

..  confval:: tca_api.swaggerUiEnabled
    :type: string
    :default: ``PUBLIC``

    Who may access the interactive Swagger UI at ``{apiPrefix}swagger-ui``.
    Allowed values: ``PUBLIC``, ``FE_USER``, ``BE_USER``, ``BE_ADMIN``,
    ``NONE``.

CORS
====

..  confval:: tca_api.corsEnabled
    :type: bool
    :default: ``false``

    Add CORS headers to API responses.

..  confval:: tca_api.corsOrigin
    :type: string
    :default: ``*``

    Value for the ``Access-Control-Allow-Origin`` header. Use ``*`` to allow
    all origins.

..  confval:: tca_api.corsAllowCredentials
    :type: bool
    :default: ``false``

    When enabled, adds ``Access-Control-Allow-Credentials: true`` to CORS
    responses. Required when the frontend sends cookies or ``Authorization``
    headers with cross-origin requests. Note: browsers reject credentialed
    requests when ``corsOrigin`` is ``*`` — set it to the specific origin
    instead.
