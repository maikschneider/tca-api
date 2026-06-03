..  _route-enhancer:

================
Route Enhancer
================

``RouteEnhancerProcessor`` generates speaking frontend URLs for any record
without writing a line of routing code. It hands the URL build off to the
TYPO3 site router, so any ``routeEnhancer`` configured on the target page —
typically an Extbase plugin route — is applied transparently.

Use it as the ``processor`` on a column or virtual property and add a
``route`` key describing the target. The processor reads the row, resolves
placeholders, and emits the URL.

When to use it
==============

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Need
     - Use
   * - User-entered typolink in a ``type=link`` column
     - :php:`TypoLinkProcessor` (auto-applied for ``type=link``)
   * - Derive a stable URL **per record** from a config rule
     - :php:`RouteEnhancerProcessor`

Minimal example
===============

The route enhancer in your site config:

..  code-block:: yaml

    # config/sites/<identifier>/config.yaml
    routeEnhancers:
      NewsPlugin:
        type: Extbase
        extension: News
        plugin: Pi1
        routes:
          - routePath: '/{news_title}'
            _controller: 'News::detail'
            _arguments:
              news_title: 'news'
        aspects:
          news_title:
            type: PersistedAliasMapper
            tableName: tx_news_domain_model_news
            routeFieldName: path_segment

And the matching resource definition:

..  code-block:: php

    use MaikSchneider\TcaApi\Serializer\Processing\RouteEnhancerProcessor;

    return [
        'general' => [
            'table'        => 'tx_news_domain_model_news',
            'resourceName' => 'news',
            'resourceType' => 'News',
        ],
        'virtualProperties' => [
            'url' => [
                'processor' => RouteEnhancerProcessor::class,
                'route' => [
                    'pid'        => '{$tca_api.news.detailPid}',
                    'extension'  => 'News',
                    'plugin'     => 'Pi1',
                    'controller' => 'News',
                    'action'     => 'detail',
                    'arguments'  => ['news' => '{uid}'],
                ],
            ],
        ],
    ];

Every ``news`` resource record now carries a ``url`` field with the speaking
URL, e.g. ``https://example.com/news/my-article``.

Placeholder grammar
===================

Three forms are recognised in ``pid``, ``arguments``, and ``parameters``:

.. list-table::
   :header-rows: 1
   :widths: 30 70

   * - Form
     - Resolved against
   * - ``{column_name}``
     - The raw DB row currently being serialized.
   * - ``{$site.setting.key}``
     - The current site's ``SiteSettings``. Useful for per-environment page ids.
   * - Anything else
     - Literal value, unchanged.

Single-placeholder strings (e.g. ``'{uid}'``) preserve their underlying type —
an integer ``uid`` stays an integer when passed to the router. Mixed strings
(``'rec-{uid}'``) are stringified.

If any required placeholder cannot be resolved, the processor returns ``null``
for that record. Configuration errors raise ``InvalidArgumentException`` at
boot time, not at request time.

Configuration keys
==================

.. list-table::
   :header-rows: 1
   :widths: 20 15 65

   * - Key
     - Type
     - Description
   * - ``pid``
     - ``int | string``
     - **Required.** Target page id. Literal positive integer, or a string
       placeholder.
   * - ``extension``
     - ``string``
     - Extbase extension key (UpperCamelCase, e.g. ``"News"``). Combined with
       ``plugin`` to build the ``tx_<ext>_<plugin>`` query namespace. Must
       appear together with ``plugin``.
   * - ``plugin``
     - ``string``
     - Extbase plugin name (e.g. ``"Pi1"``).
   * - ``controller``
     - ``string``
     - Extbase controller. Merged into the plugin namespace as
       ``[controller]``.
   * - ``action``
     - ``string``
     - Extbase action. Merged into the plugin namespace as ``[action]``.
   * - ``arguments``
     - ``array``
     - Extbase plugin arguments. Wrapped under
       ``tx_<extension>_<plugin>``. Requires ``extension`` + ``plugin``.
   * - ``parameters``
     - ``array``
     - Plain top-level query parameters. Independent of Extbase routing —
       can be used together with or without ``extension`` + ``plugin``.
   * - ``absolute``
     - ``bool``
     - Force an absolute URL. Defaults to ``true`` (API consumers usually
       cross domains).
   * - ``fragment``
     - ``string``
     - URL fragment without the leading ``#``.

Examples
========

Plain page link
---------------

..  code-block:: php

    'url' => [
        'processor' => RouteEnhancerProcessor::class,
        'route'     => ['pid' => 42],
    ],

Plain page link with query parameters
-------------------------------------

..  code-block:: php

    'url' => [
        'processor' => RouteEnhancerProcessor::class,
        'route'     => [
            'pid'        => 42,
            'parameters' => ['ref' => '{uid}'],
        ],
    ],

Per-record target page (column-driven PID)
------------------------------------------

Useful when each record carries its own target page:

..  code-block:: php

    'url' => [
        'processor' => RouteEnhancerProcessor::class,
        'route'     => [
            'pid'       => '{detail_pid}',
            'extension' => 'News',
            'plugin'    => 'Pi1',
            'arguments' => ['news' => '{uid}'],
        ],
    ],

Site-settings driven PID
------------------------

Site settings allow per-environment configuration without changing the resource
definition. Declare the setting in
``Configuration/Sets/<YourSet>/settings.definitions.yaml``:

..  code-block:: yaml

    settings:
      tca_api.news.detailPid:
        label: 'News detail page id'
        category: TcaApi.general
        type: int
        default: 42

Then reference it from the resource:

..  code-block:: php

    'route' => [
        'pid'       => '{$tca_api.news.detailPid}',
        'extension' => 'News',
        'plugin'    => 'Pi1',
        'arguments' => ['news' => '{uid}'],
    ],

Relative URL
------------

..  code-block:: php

    'route' => [
        'pid'      => 42,
        'absolute' => false,
    ],

Language handling
=================

The processor reads the resolved ``SiteLanguage`` from the current request and
passes it to the page router as ``_language``. The TYPO3 router then anchors
the URL to the matching language base (``/de/...``, ``/fr/...``, …).

For multi-language sites where the **detail page differs per language**, use
TYPO3 site settings' language-aware overrides — the resolver picks up the
matching value automatically. In most setups a single PID is enough because
the router resolves the localized URL via ``sys_language_overlay``.

How it works
============

For each record, the processor:

1. Resolves the target page id via the placeholder resolver.
2. Looks up the matching ``Site`` via ``SiteFinder``.
3. Resolves remaining placeholders in ``arguments`` and ``parameters`` using
   the target site's ``SiteSettings``.
4. Composes the query parameters — wrapping Extbase arguments under
   ``tx_<ext>_<plugin>`` when Extbase routing is declared.
5. Injects the current ``SiteLanguage`` as ``_language``.
6. Delegates to ``$site->getRouter()->generateUri()`` — every
   ``routeEnhancer`` attached to the target page applies on the way out.

Failure modes
=============

The processor returns ``null`` (no URL emitted) when:

- The ``route`` key is absent on the column definition.
- ``pid`` resolves to a value that is not a positive integer.
- Any required ``{column}`` placeholder is missing from the row.
- Any ``{$site.setting.key}`` placeholder resolves to an empty/unset value.
- ``SiteFinder`` cannot find a site for the resolved page id.
- The router throws while generating the URI (e.g. invalid route arguments).

Configuration errors (unknown keys, wrong types, missing required keys) raise
``InvalidArgumentException`` at boot time, with messages naming the offending
key.
