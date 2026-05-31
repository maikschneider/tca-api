..  _request-attributes:

==================
Request Attributes
==================

Before the handler loop, the dispatcher sets the following attributes on the
PSR-7 request. These are available in operation handlers and event listeners.

.. list-table::
   :header-rows: 1
   :widths: 30 15 55

   * - Attribute
     - Type
     - Description
   * - ``tca_api.uid``
     - ``int|null``
     - UID parsed from the URL segment (``null`` for collection operations).
   * - ``tca_api.operation``
     - ``string``
     - Resolved operation name (``list``, ``show``, ``create``, ``update``,
       ``delete``).
   * - ``tca_api.fields``
     - ``array``
     - Sparse-fieldset parameter from ``?fields[]=…``.
   * - ``tca_api.page``
     - ``int``
     - Pagination page number (≥ 1).
   * - ``tca_api.items_per_page``
     - ``int``
     - Items per page for the current request (clamped to ``maxItemsPerPage``
       when configured).
   * - ``tca_api.filters``
     - ``array``
     - Raw filter parameters from ``?filters[…]=…``.
   * - ``tca_api.order``
     - ``array``
     - Raw order parameters from ``?order[…]=asc|desc``.
   * - ``tca_api.partial``
     - ``bool``
     - ``true`` for PATCH requests (partial update), ``false`` otherwise.
   * - ``tca_api.language``
     - ``SiteLanguage|null``
     - The resolved ``TYPO3\CMS\Core\Site\Entity\SiteLanguage`` for the request
       (URL base + ``X-Locale`` override). See :ref:`languages`.
   * - ``tca_api.request_prefix``
     - ``string|null``
     - The matched URL prefix including the language base segment, e.g.
       ``/de/api`` for a request to ``/de/api/articles`` on a German site.
       Used to build self-referential IRIs that preserve the language base.

Usage example
=============

..  code-block:: php

    public function handle(
        ServerRequestInterface $request,
        array $config
    ): ResponseInterface {
        $uid = (int) $request->getAttribute('tca_api.uid');
        $operation = $request->getAttribute('tca_api.operation');
        $filters = $request->getAttribute('tca_api.filters', []);
        $page = $request->getAttribute('tca_api.page', 1);

        // …
    }
