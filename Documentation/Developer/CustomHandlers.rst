..  _custom-handlers:

=========================
Custom Operation Handlers
=========================

The dispatcher routes each request through a **handler pipeline** — a prioritised
list of objects that implement ``OperationHandlerInterface``. Built-in handlers
cover ``list``, ``show``, ``create``, ``update``, ``delete``, and ``userinfo``.
Third-party extensions can add new operation types or replace built-in behaviour
by registering their own handlers.

Interface
=========

..  code-block:: php

    namespace MaikSchneider\TcaApi\OperationHandler;

    use MaikSchneider\TcaApi\Configuration\ApiDefinition;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    interface OperationHandlerInterface
    {
        public function supports(
            ServerRequestInterface $request,
            string $operation,
            ApiDefinition $config
        ): bool;

        public function handle(
            ServerRequestInterface $request,
            ApiDefinition $config
        ): ResponseInterface;

        public function getPriority(): int;
    }

-  ``supports()`` — return ``true`` if this handler should process the request.
-  ``handle()`` — execute the operation and return a PSR-7 response.
-  ``getPriority()`` — higher values are checked first. Built-in handlers use
   priority ``10``.

Writing a custom handler
========================

..  code-block:: php

    namespace My\Extension\OperationHandler;

    use MaikSchneider\TcaApi\Configuration\ApiDefinition;
    use MaikSchneider\TcaApi\OperationHandler\OperationHandlerInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    final class PublishHandler implements OperationHandlerInterface
    {
        public function supports(
            ServerRequestInterface $request,
            string $operation,
            ApiDefinition $config
        ): bool {
            return $operation === 'publish'
                && $config->table === 'tx_myext_domain_model_article';
        }

        public function handle(
            ServerRequestInterface $request,
            ApiDefinition $config
        ): ResponseInterface {
            $uid = (int) $request->getAttribute('tca_api.uid');
            // … publish logic …
        }

        public function getPriority(): int
        {
            return 10;
        }
    }

Registering handlers
====================

No :file:`ext_localconf.php` changes are needed. TCA_API's
:file:`Configuration/Services.yaml` contains an ``_instanceof`` rule that
automatically tags every class implementing ``OperationHandlerInterface`` with
``tca_api.operation_handler``. The ``HandlerRegistry`` collects all tagged
services via Symfony's ``AutowireIterator`` and sorts them by priority.

All that is required is that the handler class is discoverable by the DI
container. If your extension uses the standard service auto-discovery pattern
(``resource: '../Classes/*'``), nothing else is needed. The dispatcher iterates
handlers **highest priority first** and dispatches to the first match.

To override a built-in handler, return a priority higher than ``10``:

..  code-block:: php

    public function getPriority(): int
    {
        return 20; // checked before the built-in handler (priority 10)
    }
