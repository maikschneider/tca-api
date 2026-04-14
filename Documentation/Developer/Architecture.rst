..  _architecture:

============
Architecture
============

This chapter describes the high-level architecture of TCA API and how a request
flows through the system.

Request flow
============

.. code-block:: text

    HTTP Request
        ↓
    TcaApiMiddleware (PSR-15 entry point)
        ↓
    RequestDispatcher (path parsing, access control, handler dispatch)
        ↓
    OperationHandlers (GetCollectionHandler, GetItemHandler, CreateHandler, …)
        ↓
    DataRepository (QueryBuilder reads) ↔ DataWriteService (DataHandler writes)
        ↓
    ResourceSerializer (DB row → Hydra JSON-LD)
        ↓
    HydraResponseBuilder (JSON-LD response assembly)

Key components
==============

TcaApiMiddleware
   PSR-15 middleware that intercepts requests matching the configured API prefix.
   Checks if the API is enabled for the current site, adds CORS headers if
   configured, and delegates to ``RequestDispatcher``.

RequestDispatcher
   Parses the URL path to determine the resource and operation. Enforces the
   operation whitelist, checks access control via ``AccessController``, dispatches
   ``BeforeOperationEvent``, and routes to the matching operation handler via
   ``HandlerRegistry``.

Operation Handlers
   Implement ``OperationHandlerInterface``. Built-in handlers:

   - ``GetCollectionHandler`` — list operation
   - ``GetItemHandler`` — show operation
   - ``CreateHandler`` — POST operation
   - ``UpdateHandler`` — PUT/PATCH operations
   - ``DeleteHandler`` — DELETE operation
   - ``GetUserInfoHandler`` — userinfo operation

DataRepository
   Read-only data access via TYPO3 ``QueryBuilder``. Supports filter strategies,
   sorting, pagination, and PID constraints.

DataWriteService
   Wraps TYPO3's ``DataHandler`` for create, update, and delete operations.
   Ensures data integrity and respects TCA rules.

ResourceSerializer
   Converts raw database rows to Hydra JSON-LD arrays. Handles column visibility,
   sparse fieldsets, relation embedding, file processing, and virtual properties.

HydraResponseBuilder
   Assembles the final JSON-LD response structure including ``@context``,
   ``@type``, ``hydra:member`` (for collections), ``hydra:view`` (pagination),
   and ``violations`` (for validation errors).

ApiRegistry
   Static registry that stores all resource configurations. Populated
   automatically by ``ApiDefinitionLoader`` at boot time.

HandlerRegistry
   Static registry for operation handlers with priority-based dispatch.

AccessController
   Evaluates ``AccessRole`` enum values and callable voters against the current
   request context.
