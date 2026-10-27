..  _authenticators:

==============
Authenticators
==============

Before any access check, the dispatcher resolves the caller of the request, the
**principal**, through a chain of authenticators. Access roles, ownership and
the write audit trail all read this principal, so an extension can add a new
way to authenticate (API keys, tokens) without touching access control.

Built in is the ``SessionAuthenticator``: the frontend and backend user that
TYPO3's authentication middlewares restored from their session cookies. A
request without any credentials is anonymous.

..  _authenticators-api-key:

API keys
========

When the `api_key <https://github.com/maikschneider/api-key>`__ extension is
installed (``composer require maikschneider/api-key``), tca_api registers an
``ApiKeyAuthenticator`` (priority ``10``). It accepts a key sent as
``X-API-Key: t3key_…`` or ``Authorization: Bearer t3key_…`` and maps it to a
principal with ``source = 'api_key'``:

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Key
     - Principal
   * - Service key (no user)
     - service principal; satisfies ``API_KEY`` only
   * - Bound to a frontend user
     - that frontend user with its groups; satisfies ``FE_USER``,
       ``FE_GROUP``, ``OWNER`` and ``API_KEY``
   * - Bound to a backend user
     - that backend user; satisfies ``BE_USER``, ``BE_ADMIN`` (for admins) and
       ``API_KEY``
   * - Invalid, expired, revoked, IP- or site-restricted
     - ``401 Unauthorized``, never anonymous

The bound user is read from the database, not logged in: keyed requests create
no ``fe_sessions`` row, send no ``Set-Cookie`` and leave ``lastlogin``
untouched. The key's scopes become the principal's scopes, so a key bound to a
user can never do more than its scopes allow. A key without scopes is
unrestricted. Bearer tokens without the ``t3key_`` prefix are left to other
authenticators.

The bridge also adds ``X-API-Key`` to ``Access-Control-Allow-Headers`` and
documents the ``ApiKey`` and ``ApiKeyBearer`` security schemes in the OpenAPI
document. Without the extension none of this is registered.

Interface
=========

..  code-block:: php

    namespace MaikSchneider\TcaApi\Security\Authentication;

    use Psr\Http\Message\ServerRequestInterface;

    interface AuthenticatorInterface
    {
        public function authenticate(ServerRequestInterface $request): AuthenticationResult;

        public function getPriority(): int;
    }

An authenticator that reads a custom request header implements
``RequestHeaderAuthenticatorInterface`` instead; its ``getRequestHeaders()``
are added to ``Access-Control-Allow-Headers``, so browser clients can send them
through a CORS preflight.

Every implementation in any extension is registered automatically. The chain
asks the authenticators in descending priority and stops at the first one that
is applicable. ``SessionAuthenticator`` has priority ``-10``, so credentials sent
with the request are checked before a session.

``authenticate()`` returns one of three results:

.. list-table::
   :header-rows: 1
   :widths: 40 60

   * - Result
     - Effect
   * - ``AuthenticationResult::notApplicable()``
     - The request carries no credentials of this kind; the chain continues.
   * - ``AuthenticationResult::authenticated($principal)``
     - The principal is used for this request.
   * - ``AuthenticationResult::failed($challenge, $reason)``
     - Credentials were sent but are invalid. The request is answered with
       ``401 Unauthorized`` and ``WWW-Authenticate: $challenge``, even if a
       session exists.

The principal
=============

``Principal`` can hold a frontend user, a backend user, or both (a browser may
hold both sessions). A *service* principal has neither: it is an authenticated
caller without a TYPO3 user record.

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Satisfies
     - Principal
   * - ``FE_USER``, ``OWNER``
     - ``frontendUserUid > 0``
   * - ``FE_GROUP``
     - ``frontendUserUid > 0`` and a matching ``frontendGroupIds`` entry
   * - ``BE_USER``
     - ``backendUserUid > 0``
   * - ``BE_ADMIN``
     - ``backendUserUid > 0`` and ``isBackendAdmin``
   * - ``API_KEY``
     - any authenticated principal whose ``source`` is not ``session``

Writes record the frontend user, otherwise the backend user, otherwise the
service as actor. ``credential`` is a safe-to-log identifier of the credential
the request carried (e.g. ``api_key:12:t3key_AbCdEfGh``); the write audit log
records it next to the actor. ``scopes`` lists the granted scopes, ``null`` means
unrestricted. See :ref:`scopes`.

Example
=======

..  code-block:: php

    use MaikSchneider\TcaApi\Security\Authentication\AuthenticationResult;
    use MaikSchneider\TcaApi\Security\Authentication\AuthenticatorInterface;
    use MaikSchneider\TcaApi\Security\Authentication\Principal;
    use Psr\Http\Message\ServerRequestInterface;

    final class PartnerTokenAuthenticator implements AuthenticatorInterface
    {
        public function authenticate(ServerRequestInterface $request): AuthenticationResult
        {
            $token = $request->getHeaderLine('X-Partner-Token');
            if ($token === '') {
                return AuthenticationResult::notApplicable();
            }

            $partner = $this->partners->findByToken($token);
            if ($partner === null) {
                return AuthenticationResult::failed('X-Partner-Token realm="api"');
            }

            return AuthenticationResult::authenticated(new Principal(
                frontendUserUid: $partner->frontendUserUid,
                source: 'partner_token',
            ));
        }

        public function getPriority(): int
        {
            return 0;
        }
    }

Reading the principal
=====================

The resolved principal is the ``tca_api.principal`` request attribute.
``Principal::fromRequest($request)`` returns it, and falls back to the session
for requests that did not pass the dispatcher:

..  code-block:: php

    $principal = Principal::fromRequest($request);
    if ($principal->isBackendAdmin()) {
        // …
    }

Documenting the scheme in OpenAPI
=================================

Add the scheme your clients use in a listener for
``ModifyOpenApiSecuritySchemesEvent``. The ``SecurityScheme`` flags tell the
document which operations it applies to: each operation lists, as alternatives,
every scheme that can satisfy its access role, and a credential scheme also
lists the scopes the operation needs. Swagger UI then offers the scheme in its
"Authorize" dialog.

..  code-block:: php

    use MaikSchneider\TcaApi\Event\ModifyOpenApiSecuritySchemesEvent;
    use MaikSchneider\TcaApi\OpenApi\SecurityScheme;
    use TYPO3\CMS\Core\Attribute\AsEventListener;

    #[AsEventListener('my-extension/partner-token-scheme')]
    final class AddPartnerTokenScheme
    {
        public function __invoke(ModifyOpenApiSecuritySchemesEvent $event): void
        {
            $event->addScheme(new SecurityScheme(
                'PartnerToken',
                ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Partner-Token'],
                authenticatesFrontendUsers: true,
                isCredential: true,
            ));
        }
    }

The resulting requirements:

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Access role
     - ``security``
   * - ``PUBLIC``
     - ``[]`` (anonymous)
   * - ``FE_USER``, ``FE_GROUP``
     - schemes with ``authenticatesFrontendUsers``
   * - ``OWNER``
     - schemes with ``authenticatesFrontendUsers``; schemes with
       ``authenticatesBackendUsers`` only while ``ownership.beAdminBypass`` is
       enabled
   * - ``BE_USER``, ``BE_ADMIN``
     - schemes with ``authenticatesBackendUsers``
   * - ``API_KEY``
     - schemes with ``isCredential``
   * - callable voter
     - every scheme, plus ``{}`` (anonymous)
   * - ``DISABLED``
     - no requirement

Protected operations also document a ``401`` response. The Hydra API
documentation has no equivalent of security schemes and is unchanged.
