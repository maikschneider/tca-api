..  _fluid-data-layer:

================================
Fluid / server-side data layer
================================

The same ``Configuration/TcaApi/`` resource definitions that power the REST API
can be used to pull fully-resolved records straight into **Fluid templates** —
with column auto-discovery, relation resolving, image processing, virtual
properties, filtering, ordering, pagination, and language overlay — **without
writing an Extbase model, repository, or QueryBuilder query**.

This is the same engine as the HTTP API; only the transport differs. Instead of
serving a JSON-LD HTTP response, it returns clean PHP arrays you assign to a
view.

``TcaApiRepository``
====================

Inject ``MaikSchneider\TcaApi\Frontend\TcaApiRepository`` and call one of three
methods. It is a public, autowired service.

..  code-block:: php

    use MaikSchneider\TcaApi\Frontend\TcaApiRepository;
    use Psr\Http\Message\ResponseInterface;
    use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

    final class ArticleController extends ActionController
    {
        public function __construct(
            private readonly TcaApiRepository $tcaApi,
        ) {}

        public function listAction(): ResponseInterface
        {
            $this->view->assign('articles', $this->tcaApi->collection(
                'articles',
                order: ['created' => 'desc'],
                itemsPerPage: 5,
            ));

            return $this->htmlResponse();
        }
    }

..  code-block:: html

    <f:for each="{articles}" as="article">
        <h2>{article.title}</h2>
        <img src="{article.image.publicUrl}" alt="" />
        <span>{article.color.name}</span>
    </f:for>

Methods
-------

``collection()`` — a page of records as clean arrays:

..  code-block:: php

    public function collection(
        string $resource,
        array $filters = [],         // ['color_id' => 3]
        array $order = [],           // ['created' => 'desc']
        int $page = 1,
        ?int $itemsPerPage = null,   // null → resource default
        array $fields = [],          // column allowlist; [] = all readable
        ?SiteLanguage $language = null,
    ): array

``collectionResult()`` — the same items plus pagination metadata, for templates
that render a pager:

..  code-block:: php

    $result = $this->tcaApi->collectionResult('articles', itemsPerPage: 10);

    // [
    //   'items'      => [ ...clean records... ],
    //   'pagination' => ['page' => 1, 'itemsPerPage' => 10, 'total' => 42, 'totalPages' => 5],
    // ]

``find()`` — a single record by uid, or ``null`` when not found:

..  code-block:: php

    $article = $this->tcaApi->find('articles', 1);

An unknown ``$resource`` name throws
``MaikSchneider\TcaApi\Exception\UnknownResourceException``.

Data shape
==========

Records are returned as **clean PHP arrays**: the JSON-LD envelope keys
(``@id``, ``@type``, ``@context``, ``hydra:*``) are stripped, so templates address
plain properties.

..  code-block:: php

    [
        'uid'   => 1,
        'title' => 'Hello',
        'color' => ['uid' => 3, 'name' => 'Red'],     // embedded relation
        'image' => ['publicUrl' => '/fileadmin/x.webp', 'width' => 800],
    ]

A shallow (non-embedded) ``hasOne`` relation is represented by its resource IRI
string (e.g. ``/_api/colors/3``); configure ``embed`` on the column to get the
nested record instead. See :ref:`Columns <columns>` for relation and image
options.

Language
========

By default the active frontend ``SiteLanguage`` is resolved from the current
request, so records are fetched and overlaid in the page's language. Pass an
explicit ``$language`` argument to override it (for example when rendering a
language switcher).

Access control
==============

The REST API access roles (``PUBLIC`` / ``FE_USER`` / ``FE_GROUP`` / ``OWNER`` /
…) are **not** enforced on the data-layer path. Those roles govern *external API
exposure*; server-side rendering is the integrator's responsibility — you decide
what a template shows.

Record-level safety still always applies, because it lives in the data access
layer rather than the security layer: ``deleted``, ``hidden``, start/endtime,
``storagePid``, and language overlay are enforced for every query.

Under the hood
==============

``TcaApiRepository`` resolves the resource name to its ``ApiDefinition`` via the
``ApiRegistry`` and delegates to
``MaikSchneider\TcaApi\DataAccess\ResourceDataProvider`` — the same request-free
read pipeline the HTTP operation handlers use. The provider validates filters and
order, resolves and clamps pagination, fetches via ``DataRepository``, eliminates
N+1 queries via ``EmbedPreloader``, and serializes via ``ResourceSerializer``.
The result is then passed through ``FluidArrayNormalizer`` to strip the JSON-LD
envelope.

If you need the canonical JSON-LD output (identical to the REST payload) rather
than clean arrays, call ``ResourceDataProvider`` directly with a
``CollectionQuery`` / ``ItemQuery`` and skip the normalizer.
