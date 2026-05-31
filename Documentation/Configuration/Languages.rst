..  _languages:

=========
Languages
=========

TCA_API is language-aware. For TYPO3 sites that declare multiple languages, the
extension resolves the requested locale per request, applies the matching
``sys_language_uid`` constraint to queries, overlays translations on the
default-language rows according to the site's ``fallbackType``, and isolates
cache entries per locale.

Resolution order
================

For every API request the dispatcher resolves a single ``SiteLanguage`` using
this precedence:

1.  **The URL** — if the path starts with a language base segment declared on
    the site (e.g. ``/de/api/...`` matches the German language whose
    ``base: /de/``), that language is selected. This piggybacks on TYPO3's own
    ``typo3/cms-frontend/site`` middleware, which has already populated
    ``$request->getAttribute('language')`` before TCA_API runs.

2.  **The ``X-Locale`` header** — if set, overrides the URL-derived language.
    The value is the TYPO3 ``languageId`` integer (not a locale string).

    ..  code-block:: http

        GET /api/articles
        X-Locale: 1

    is equivalent to:

    ..  code-block:: http

        GET /de/api/articles

3.  **The site's default language** — when neither the URL nor the header
    resolves a language.

Invalid ``X-Locale`` values (non-integer, unknown id, or pointing at a disabled
language) return ``400 Bad Request`` with a ``hydra:Error`` body listing the
available enabled language ids:

..  code-block:: json

    {
      "@context": "http://www.w3.org/ns/hydra/context.jsonld",
      "@type": "hydra:Error",
      "hydra:title": "Invalid language",
      "hydra:description": "Invalid language \"99\". Available enabled language ids: 0, 1"
    }

Query and overlay behaviour
===========================

For tables whose TCA declares a ``ctrl.languageField`` (almost every
translatable TYPO3 table — ``pages``, ``tt_content``, ``sys_category``,
``sys_file_metadata``, and any extension table marked translatable), the
repository:

1.  Restricts the base query to ``sys_language_uid IN (0, -1)`` — default-language
    rows plus "all-languages" sentinel rows.
2.  Bulk-loads matching translation rows (``sys_language_uid = <requested>``,
    ``l10n_parent IN <base uids>``).
3.  Applies an overlay row-by-row.

The overlay honours the site's ``fallbackType``:

.. list-table::
   :header-rows: 1
   :widths: 20 80

   * - ``fallbackType``
     - Behaviour for a base row that has no translation
   * - ``strict``
     - Row is **omitted** from the response.
   * - ``fallback``
     - Default-language row is returned **unchanged** (English fallback).
   * - ``free``
     - Same as ``fallback`` for the overlay path.

Rows flagged ``sys_language_uid = -1`` ("visible in all languages") are
**always returned untouched**, regardless of ``fallbackType`` — they are by
definition language-agnostic.

IRI stability
-------------

The Hydra ``@id`` for a record always uses the **default-language UID**, even
when the response payload contains the translated values. A German consumer of
``/de/api/articles/1`` sees:

..  code-block:: json

    {
      "@id": "/api/articles/1",
      "@type": "Article",
      "title": "Hallo Welt"
    }

The translation row's own UID (e.g. ``51``) is never exposed.

Opt-out per resource
====================

A resource can disable language filtering entirely with the
``general.language.mode`` key:

..  code-block:: php

    return [
        'general' => [
            'table'        => 'sys_category',
            'resourceName' => 'categories-all',
            'resourceType' => 'CategoryAll',
            'language'     => ['mode' => 'ignore'],
        ],
        // …
    ];

.. list-table::
   :header-rows: 1
   :widths: 15 10 75

   * - ``mode``
     - Default
     - Behaviour
   * - ``auto``
     - ✓
     - Apply language constraint + overlay when the table's TCA declares a
       ``languageField`` (the standard case).
   * - ``ignore``
     -
     - Skip the language constraint and the overlay. Every row in every language
       is returned as a distinct member. Useful for admin listings or for
       tables that are language-agnostic by design.

Tables whose TCA does not declare ``ctrl.languageField`` (rare —
non-translatable extension tables) are already exempt; the ``ignore`` mode is
only needed when you want to opt out of filtering on a normally-translatable
table.

Response headers
================

Every API response carries:

.. list-table::
   :header-rows: 1
   :widths: 25 75

   * - Header
     - Value
   * - ``Content-Language``
     - The resolved language's ``hreflang`` (falls back to the locale's
       primary language code, e.g. ``de``).
   * - ``Vary``
     - Includes ``X-Locale`` so shared caches (Varnish, Cloudflare, etc.)
       isolate entries per locale. When CORS is enabled the value is
       ``Origin, X-Locale``.

CORS preflight (``OPTIONS``) responses also advertise ``X-Locale`` in
``Access-Control-Allow-Headers`` so browsers can send the override.

Cache key isolation
===================

When :ref:`response caching <caching>` is enabled, the resolved language id is
part of the cache key composition. A request to ``/api/articles`` and one to
``/de/api/articles`` (or the same path with ``X-Locale: 1``) produce **distinct
cache entries** and cannot return each other's payload.

Site fixture example
====================

A typical multi-language site set-up:

..  code-block:: yaml

    # config/sites/main/config.yaml
    base: 'https://example.com/'
    languages:
      -
        title: English
        languageId: 0
        locale: en_US
        base: /
        hreflang: en
        fallbackType: strict
      -
        title: German
        languageId: 1
        locale: de_DE
        base: /de/
        hreflang: de
        fallbackType: fallback
        fallbacks: '0'
    settings:
      tca_api:
        enabled: true
        apiPrefix: '/api/'

With this configuration:

* ``GET /api/articles`` → English rows only.
* ``GET /de/api/articles`` → German overlays where translations exist,
  English fallback otherwise (because ``fallbackType: fallback``).
* ``GET /api/articles`` with header ``X-Locale: 1`` → same as the previous.
* ``GET /api/articles`` with header ``X-Locale: 99`` → ``400 Bad Request``.

What TCA_API does *not* do
==========================

* **Accept-Language header**. Browser language preferences are deliberately
  *not* consulted. Locale selection is either explicit (URL base segment or
  ``X-Locale`` header) or falls back to the site default — never silently
  derived from the user agent.
* **Region/country subtags**. ``X-Locale`` is the TYPO3 ``languageId``
  integer, not an IETF tag — there is no ``en-US`` vs ``en-GB`` matching.
* **Per-locale IRIs**. ``/api/articles/1`` is the canonical IRI regardless of
  language; locale is a request-time dimension, not part of the resource
  identity.
* **Mass-edit translations through the API**. The standard CRUD operations
  always target default-language rows. Creating or updating translation rows
  is currently the TYPO3 backend's job.
