..  _events:

=============
PSR-14 Events
=============

TCA API dispatches PSR-14 events throughout the request lifecycle, allowing you
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
     - Abort operation, modify request.
   * - ``AfterOperationEvent``
     - After handler, before response
     - Add computed fields, transform response data.
   * - ``BeforeWriteEvent``
     - Before DataHandler create/update/delete
     - Validate or modify data before persistence.
   * - ``AfterWriteEvent``
     - After DataHandler operations
     - Trigger side effects (cache clear, logging).

All events are stoppable — listeners can prevent further processing by calling
the stop propagation method on the event.

Registering a listener
======================

Register event listeners in your extension's :file:`Configuration/Services.yaml`:

..  code-block:: yaml

    services:
      My\Extension\EventListener\EnrichArticleListener:
        tags:
          - name: event.listener
            identifier: 'my-extension/enrich-article'
            event: MaikSchneider\TcaApi\Event\AfterOperationEvent

Example listener
================

..  code-block:: php

    namespace My\Extension\EventListener;

    use MaikSchneider\TcaApi\Event\AfterOperationEvent;

    class EnrichArticleListener
    {
        public function __invoke(AfterOperationEvent $event): void
        {
            $data = $event->getData();
            $config = $event->getConfig();

            if (($config['general']['table'] ?? '') !== 'tx_myext_domain_model_article') {
                return;
            }

            // Add a computed field to the response
            $data['computedField'] = 'some value';
            $event->setData($data);
        }
    }
