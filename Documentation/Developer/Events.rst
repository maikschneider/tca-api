..  _events:

=============
PSR-14 Events
=============

TCA_API dispatches PSR-14 events throughout the request lifecycle, allowing you
to hook into and modify the API behaviour.

Available events
================

.. list-table::
   :header-rows: 1
   :widths: 25 30 45

   * - Event
     - Fired
     - Use case
   * - ``BeforeOperationEvent``
     - After access check, before handler
     - Abort operation, inspect request.
   * - ``AfterOperationEvent``
     - After handler, before response
     - Add computed fields, transform response data.
   * - ``BeforeWriteEvent``
     - Before DataHandler create/update/delete
     - Validate or modify data before persistence.
   * - ``AfterWriteEvent``
     - After DataHandler operations
     - Trigger side effects (cache clear, logging).
   * - ``ModifyOpenApiSecuritySchemesEvent``
     - While the OpenAPI document is built
     - Declare how clients authenticate, see :ref:`authenticators`.
   * - ``ModifyOpenApiDocumentEvent``
     - Once the OpenAPI document is finished
     - Describe output tca-api can't infer, see
       :ref:`events-modify-openapi-document`.

The operation and write events are stoppable: call ``stopPropagation()`` to
abort further processing.

Event API reference
===================

``BeforeOperationEvent``
------------------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Method
     - Description
   * - ``getOperation(): string``
     - The resolved operation name (``list``, ``show``, ``create``, etc.).
   * - ``getRequest(): ServerRequestInterface``
     - The PSR-7 server request (read-only — modification not supported here).
   * - ``getConfig(): ApiDefinition``
     - The typed resource configuration.
   * - ``stopPropagation(): void``
     - Aborts the operation. The dispatcher returns a 403 response.

``AfterOperationEvent``
-----------------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Method
     - Description
   * - ``getOperation(): string``
     - The operation that just completed.
   * - ``getData(): array``
     - The serialized response data (collection array or single-item array).
   * - ``setData(array $data): void``
     - Replace the response data. The dispatcher sends the modified array.
   * - ``getRequest(): ServerRequestInterface``
     - The PSR-7 server request, with the ``frontend.user`` and ``tca_api.*`` attributes.
   * - ``getConfig(): ApiDefinition``
     - The typed configuration of the resource the operation runs on.
   * - ``stopPropagation(): void``
     - Prevents subsequent listeners from receiving the event.

On a cache miss of ``list`` or ``show``, a listener can shorten the lifetime of
the cached response, see :ref:`caching-limit-lifetime`.

``AfterOperationEvent`` listeners are not run for response-cache hits. If a
listener changes the response based on a query parameter, list its top-level
name in the resource's ``cache.varyByParameters`` (see :ref:`caching`).

``BeforeWriteEvent``
--------------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Method
     - Description
   * - ``getTable(): string``
     - The database table being written to.
   * - ``getOperation(): string``
     - ``create``, ``update``, or ``delete``.
   * - ``getData(): array``
     - The data array about to be passed to DataHandler.
   * - ``setData(array $data): void``
     - Replace the data before it is written.
   * - ``getRequest(): ServerRequestInterface``
     - The PSR-7 server request, with the ``frontend.user`` and ``tca_api.*`` attributes.
   * - ``getConfig(): ApiDefinition``
     - The typed configuration of the resource the operation runs on.
   * - ``getUid(): ?int``
     - The UID of the record being updated or deleted, ``null`` on create.
   * - ``stopPropagation(): void``
     - Aborts the write. The handler returns a 422 response.

``AfterWriteEvent``
-------------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Method
     - Description
   * - ``getTable(): string``
     - The database table that was written.
   * - ``getOperation(): string``
     - ``create``, ``update``, or ``delete``.
   * - ``getUid(): int``
     - The UID of the affected record (new record UID after create).
   * - ``getRequest(): ServerRequestInterface``
     - The PSR-7 server request, with the ``frontend.user`` and ``tca_api.*`` attributes.
   * - ``getConfig(): ApiDefinition``
     - The typed configuration of the resource the operation runs on.
   * - ``stopPropagation(): void``
     - Prevents subsequent listeners from receiving the event.

``ModifyOpenApiSecuritySchemesEvent``
-------------------------------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Method
     - Description
   * - ``getSchemes(): array<string, SecurityScheme>``
     - The schemes collected so far, keyed by name. Built in are
       ``FrontendSession`` and ``BackendSession`` (session cookies).
   * - ``addScheme(SecurityScheme $scheme): void``
     - Adds or replaces a scheme.
   * - ``removeScheme(string $name): void``
     - Removes a scheme, e.g. a session scheme on a token-only site.
   * - ``getResources(): array<string, ApiDefinition>``
     - The resources the document describes.
   * - ``getContext(): RequestContext``
     - Site settings and URLs of the document being built.

``ModifyOpenApiDocumentEvent``
------------------------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Method
     - Description
   * - ``getDocument(): array``
     - The finished OpenAPI document as an array.
   * - ``setDocument(array $document): void``
     - Replace the document. ``{apiPrefix}openapi.json``, ``tca-api:openapi``
       and the backend module serve the result.
   * - ``getResources(): array<string, ApiDefinition>``
     - The resources the document describes.
   * - ``getContext(): RequestContext``
     - Site settings and URLs of the document being built.

Registering a listener
======================

Register event listeners in your extension's :file:`Configuration/Services.yaml`:

..  code-block:: yaml

    services:
      My\Extension\EventListener\RestrictArticleDeleteListener:
        tags:
          - name: event.listener
            identifier: 'my-extension/restrict-article-delete'
            event: MaikSchneider\TcaApi\Event\BeforeOperationEvent

Example listener
================

The following listener blocks ``delete`` requests on a specific table unless a
custom request header is present. ``getConfig()`` returns an
:php:`ApiDefinition` object — access its properties directly.

..  code-block:: php

    namespace My\Extension\EventListener;

    use MaikSchneider\TcaApi\Event\BeforeOperationEvent;

    final class RestrictArticleDeleteListener
    {
        public function __invoke(BeforeOperationEvent $event): void
        {
            if ($event->getConfig()->table !== 'tx_myext_domain_model_article') {
                return;
            }

            if ($event->getOperation() !== 'delete') {
                return;
            }

            if (!$event->getRequest()->hasHeader('X-Admin-Token')) {
                $event->stopPropagation(); // dispatcher returns 403
            }
        }
    }

The write events carry the request as well, so a listener reads the frontend
user from the event instead of :php:`$GLOBALS['TYPO3_REQUEST']`. Since several
resources can share one table, compare ``getConfig()->resourceName`` rather
than ``getTable()`` to target a single resource:

..  code-block:: php

    namespace My\Extension\EventListener;

    use MaikSchneider\TcaApi\Event\AfterWriteEvent;
    use Psr\Log\LoggerInterface;

    final class LogArticleWritesListener
    {
        public function __construct(private readonly LoggerInterface $logger)
        {
        }

        public function __invoke(AfterWriteEvent $event): void
        {
            if ($event->getConfig()->resourceName !== 'articles') {
                return;
            }

            $feUser = $event->getRequest()->getAttribute('frontend.user');

            $this->logger->info('Article {operation} by frontend user {user}', [
                'operation' => $event->getOperation(),
                'uid'       => $event->getUid(),
                'user'      => $feUser?->user['uid'] ?? null,
            ]);
        }
    }

..  _events-modify-openapi-document:

Describing custom output in the OpenAPI document
================================================

The OpenAPI document is generated from the TCA and the resource configs. Keys an
``AfterOperationEvent`` listener adds, the output of custom processors and the
query parameters of custom operation handlers are missing from it or typed as
``string``, so generated clients don't type them.

``ModifyOpenApiDocumentEvent`` is dispatched once with the finished document.
``{apiPrefix}openapi.json``, ``vendor/bin/typo3 tca-api:openapi`` and the
backend module serve what the listeners return; the backend module sets
``servers`` to the site base afterwards. The Hydra API documentation
(``docs.jsonld``) is not affected by the event.

The command and the backend module build the document without a frontend
request, so a listener must not make it depend on the current user or request.

The document is already reduced to the resources the site serves
(``tca_api.allowedResources``), so a listener only extends what it finds:

*   Add schemas and edit operations that exist in the document, and check
    before editing. On a site that doesn't serve a resource, its schemas and
    paths are missing, and writing to them creates an incomplete entry.
*   Don't add paths for resources the site doesn't serve.
*   Return the full document: removing top-level keys such as ``paths``
    breaks Swagger UI and generated clients.
*   ``getResources()`` lists the resources the document describes, and
    ``getContext()`` carries the site settings, e.g. for a feature flag.

..  caution::

    The document is public by default (``tca_api.openApiExposed`` is
    ``PUBLIC``). Don't describe internal or hidden fields, and don't edit
    ``security`` or ``securitySchemes`` here; use
    ``ModifyOpenApiSecuritySchemesEvent`` for authentication schemes. The
    document only describes the API: it never changes what the API serves or
    enforces.

The listener edits plain arrays, so validate the result, e.g. with Spectral or
against real responses. The following listener describes a ``readingTime`` key
another listener adds to every article:

..  code-block:: php

    namespace My\Extension\EventListener;

    use MaikSchneider\TcaApi\Event\ModifyOpenApiDocumentEvent;
    use TYPO3\CMS\Core\Attribute\AsEventListener;

    #[AsEventListener('my-extension/article-reading-time')]
    final class DescribeArticleReadingTime
    {
        public function __invoke(ModifyOpenApiDocumentEvent $event): void
        {
            $document = $event->getDocument();
            if (!isset($document['components']['schemas']['ArticleRead']['properties'])) {
                return; // the site doesn't serve articles
            }

            $document['components']['schemas']['ArticleRead']['properties']['readingTime'] = [
                'type'        => 'integer',
                'description' => 'Estimated reading time in minutes.',
            ];
            $event->setDocument($document);
        }
    }
