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
service as actor. ``scopes`` lists the granted scopes, ``null`` means
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
