..  _authentication:

==============
Authentication
==============

:ref:`security` decides which caller may perform an operation. This chapter
explains how a client becomes that caller: as a frontend user, a backend user,
or a script holding a key.

tca_api does not log anyone in itself. It reads the identity that TYPO3 or an
installed extension established for the request.

..  contents::
    :local:
    :depth: 1

..  _authentication-identity:

How tca_api sees identity
=========================

Before any access check, tca_api resolves the caller of the request, the
**principal**, through a chain of authenticators:

.. list-table::
   :header-rows: 1
   :widths: 30 70

   * - Authenticator
     - Identity
   * - API keys
     - A key sent as ``X-API-Key`` or ``Authorization: Bearer``. Only registered
       when the `api_key <https://github.com/maikschneider/api-key>`__
       extension is installed, see :ref:`authentication-api-key`.
   * - Session
     - The frontend user and the backend user that TYPO3 restored from their
       session cookies (``fe_typo_user``, ``be_typo_user``). Always active.
   * - Custom
     - Any ``AuthenticatorInterface`` of another extension, see
       :ref:`authenticators`.

Credentials sent with the request are checked before the session. A request
without any credentials is anonymous and can only reach ``PUBLIC`` operations.

The two error codes mean different things:

.. list-table::
   :header-rows: 1
   :widths: 25 75

   * - Status
     - Meaning
   * - ``401 Unauthorized``
     - Credentials were sent, but they are invalid: a wrong, expired or
       revoked key, or a key used from a forbidden IP address or site. The
       ``WWW-Authenticate`` header names the scheme. The request is rejected
       even if a valid session exists.
   * - ``403 Forbidden``
     - The caller is anonymous, or authenticated but not allowed: the role
       does not match, the record belongs to another user, or the key's
       scopes do not cover the operation.

..  note::

    An anonymous request to a protected operation answers ``403``, not
    ``401``. ``401`` always means "your credentials are wrong", so a client can
    tell a broken key from a missing permission.

..  _authentication-browser:

Browser and single-page apps
============================

In the browser the regular TYPO3 login is the authentication. A user who
logged in through `felogin <https://docs.typo3.org/c/typo3/cms-felogin/main/en-us/>`__
holds a frontend session cookie, and every API request that carries the cookie
acts as that user. A backend editor who is logged in to the TYPO3 backend in
the same browser also carries the backend session cookie and satisfies
``BE_USER`` and ``BE_ADMIN``.

On the same origin nothing has to be configured: ``fetch()`` sends the cookies
of the own origin by default.

Cross-origin clients
--------------------

A single-page app on another origin needs three things:

1.  Allow credentials and name the app's origin in the site settings:

    ..  code-block:: yaml
        :caption: config/sites/<site>/settings.yaml

        tca_api:
          corsEnabled: true
          corsOrigin: 'https://app.example.com'
          corsAllowCredentials: true

    ..  warning::

        With ``corsOrigin: '*'`` and ``corsAllowCredentials`` enabled, tca_api
        echoes the request's ``Origin`` back. Any website can then read the
        API on behalf of a logged-in visitor. Always name the origin.

2.  Send the cookies with every request:

    ..  code-block:: javascript

        const response = await fetch('https://www.example.com/_api/articles', {
            credentials: 'include',
        });

3.  Make sure the browser sends the cookie at all. The frontend session cookie
    defaults to ``SameSite=Lax``, which browsers only send to the *same site*
    (``app.example.com`` and ``www.example.com`` are the same site). For an
    app on a different domain, the cookie must be ``SameSite=None``, which
    requires HTTPS:

    ..  code-block:: php
        :caption: config/system/additional.php

        $GLOBALS['TYPO3_CONF_VARS']['FE']['cookieSameSite'] = 'none';

    This affects every frontend session of the installation, so prefer
    hosting the app on a subdomain of the site.

Logging in from a script
------------------------

Logging in a frontend user programmatically, e.g. with curl, is impractical.
Since TYPO3 v13 every frontend login has to carry a signed request token
(``__RequestToken``) that is bound to a nonce cookie. A script would first
have to load a page with the felogin form, extract the token, keep the cookie
jar and then post the credentials. For scripts and server-to-server calls use
API keys instead.

..  _authentication-api-key:

Scripts and machine-to-machine
==============================

The `api_key <https://github.com/maikschneider/api-key>`__ extension
(`documentation <https://docs.typo3.org/p/maikschneider/api-key/main/en-us/>`__)
adds stateless API keys. tca_api suggests it but does not require it; once it
is installed, tca_api picks it up automatically.

..  code-block:: bash

    composer require maikschneider/api-key

Create a key on the command line. The key is printed once and cannot be
recovered:

..  code-block:: bash

    # Service key: no user, satisfies AccessRole::API_KEY
    vendor/bin/typo3 apikey:create --title="CI deploy" --scope=articles:write --expires="+90 days"

    # Acts as frontend user 12, with its groups
    vendor/bin/typo3 apikey:create --title="Partner import" --fe-user=12

    # Acts as backend user 3
    vendor/bin/typo3 apikey:create --title="Editorial sync" --be-user=3

Send it in either header:

..  code-block:: bash

    curl -H "X-API-Key: ${TYPO3_API_KEY}" https://example.com/_api/articles
    curl -H "Authorization: Bearer ${TYPO3_API_KEY}" https://example.com/_api/articles

What a key may do depends on what it is bound to:

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Key
     - Satisfies
   * - Service key
     - ``API_KEY``
   * - Bound to a frontend user
     - ``FE_USER``, ``FE_GROUP``, ``OWNER`` and ``API_KEY``
   * - Bound to a backend user
     - ``BE_USER``, ``BE_ADMIN`` (for admins) and ``API_KEY``

The key's scopes always restrict it, also for a key bound to a user, see
:ref:`scopes`. A key without scopes is unrestricted, so give every key the
narrowest scopes it needs.

Keyed requests are stateless: they create no ``fe_sessions`` row, send no
``Set-Cookie`` and leave ``lastlogin`` untouched.

..  tip::

    Behind Apache, the ``Authorization`` header only reaches PHP with
    ``CGIPassAuth On``. The ``X-API-Key`` header always works.

How the bridge maps keys to principals, adds ``X-API-Key`` to the CORS
headers and documents the security schemes is described in
:ref:`authenticators-api-key`.

..  _authentication-extensions:

Other extensions
================

Extensions that log a user in through TYPO3's authentication services work
as well, because tca_api reads the resulting session:

.. list-table::
   :header-rows: 1
   :widths: 30 70

   * - Extension
     - Notes
   * - `kuhschnappel/authtoken <https://extensions.typo3.org/extension/authtoken>`__
     - Tokens for frontend users, sent in an ``X-User-Token`` header. Supports
       TYPO3 up to v13.4. It relies on ``FE_alwaysFetchUser``, so every
       cookie-less request creates an ``fe_sessions`` row and sends
       ``Set-Cookie``.
   * - `r3h6/oauth2-server <https://packagist.org/packages/r3h6/oauth2-server>`__
     - An OAuth2 server for frontend users. Supports TYPO3 up to v13.4.

..  attention::

    `in2frontendauthentication <https://extensions.typo3.org/extension/in2frontendauthentication>`__
    does **not** satisfy ``FE_USER`` or ``FE_GROUP``. It adds frontend groups
    for matching IP addresses but never logs in a user record, so the request
    stays without a frontend user.

Session-based extensions that only log in backend users, such as OAuth2 or
OpenID Connect login providers for the backend, apply to browsers, not to
scripts: the frontend only restores a backend user when the backend session
cookie is present.

..  _authentication-swagger-ui:

Swagger UI
==========

Swagger UI at ``{apiPrefix}swagger-ui`` offers the authentication schemes of
the OpenAPI document in its **Authorize** dialog:

-  **Sessions** (``FrontendSession``, ``BackendSession``): log in on the
   website or in the backend in the same browser and reload Swagger UI. The
   browser sends the session cookie by itself.
-  **API keys** (``ApiKey``, ``ApiKeyBearer``): click **Authorize** and paste
   a key. The key is kept across reloads until you click **Logout**.
