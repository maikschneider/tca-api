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

All events are stoppable — call ``stopPropagation()`` to abort further
processing.

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
   * - ``stopPropagation(): void``
     - Prevents subsequent listeners from receiving the event.

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
   * - ``stopPropagation(): void``
     - Prevents subsequent listeners from receiving the event.

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
