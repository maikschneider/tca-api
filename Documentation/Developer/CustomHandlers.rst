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

    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    interface OperationHandlerInterface
    {
        public function supports(
            ServerRequestInterface $request,
            string $operation,
            array $config
        ): bool;

        public function handle(
            ServerRequestInterface $request,
            array $config
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

    use MaikSchneider\TcaApi\OperationHandler\OperationHandlerInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

    #[Autoconfigure(public: true)]
    class PublishHandler implements OperationHandlerInterface
    {
        public function supports(
            ServerRequestInterface $request,
            string $operation,
            array $config
        ): bool {
            return $operation === 'publish'
                && ($config['general']['table'] ?? '') === 'tx_myext_domain_model_article';
        }

        public function handle(
            ServerRequestInterface $request,
            array $config
        ): ResponseInterface {
            $uid = (int) $request->getAttribute('tca_api.uid');
            // … publish logic …
        }

        public function getPriority(): int
        {
            return 10;
        }
    }

..  important::

    The ``#[Autoconfigure(public: true)]`` attribute on the class is required for
    the TYPO3 DI container to expose the service.

Registering handlers
====================

Register handlers in your extension's :file:`ext_localconf.php`. The dispatcher
iterates handlers **highest priority first** and dispatches to the first match.

..  code-block:: php

    use MaikSchneider\TcaApi\Registry\HandlerRegistry;
    use My\Extension\OperationHandler\PublishHandler;

    // New operation type — priority 10 (default)
    HandlerRegistry::register(PublishHandler::class);

    // Override a built-in handler — checked before built-in (priority 20 > 10)
    HandlerRegistry::register(MyCustomShowHandler::class, priority: 20);

The ``HandlerRegistry`` uses TYPO3's DI container via
``GeneralUtility::makeInstance()``, so constructor dependencies are injected
automatically.
