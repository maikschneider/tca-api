<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Frontend;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\CollectionQuery;
use MaikSchneider\TcaApi\DataAccess\ItemQuery;
use MaikSchneider\TcaApi\DataAccess\ResourceDataProvider;
use MaikSchneider\TcaApi\Exception\UnknownResourceException;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Frontend data layer: pull fully-resolved TCA API records into server-side
 * rendering (Fluid templates, custom controllers) using the same resource
 * configuration that drives the REST API — no Extbase model, repository, or
 * QueryBuilder query required.
 *
 * Returns clean PHP arrays (the JSON-LD envelope is stripped by
 * {@see FluidArrayNormalizer}), so templates address plain properties such as
 * `{article.title}` and `{article.color.name}`.
 *
 * Note: the REST API access roles (PUBLIC / FE_USER / OWNER / …) are NOT enforced
 * here — they govern external API exposure, whereas server-side rendering is the
 * integrator's responsibility. Record-level safety from TCA still always applies
 * (deleted, hidden, start/endtime, storagePid, language overlay), since those are
 * enforced inside {@see \MaikSchneider\TcaApi\DataAccess\DataRepository}.
 *
 * Example:
 * ```php
 * final class ArticleController extends ActionController
 * {
 *     public function __construct(private readonly TcaApiRepository $tcaApi) {}
 *
 *     public function listAction(): ResponseInterface
 *     {
 *         $this->view->assign('articles', $this->tcaApi->collection(
 *             'articles', order: ['created' => 'desc'], itemsPerPage: 5,
 *         ));
 *         return $this->htmlResponse();
 *     }
 * }
 * ```
 */
#[Autoconfigure(public: true)]
final class TcaApiRepository
{
    public function __construct(
        private readonly ApiRegistry $apiRegistry,
        private readonly ResourceDataProvider $dataProvider,
        private readonly FluidArrayNormalizer $normalizer,
    ) {
    }

    /**
     * Fetch a page of records as clean arrays.
     *
     * @param array<string, mixed>  $filters Map of filter column => value.
     * @param array<string, string> $order   Map of column => 'asc'|'desc'.
     * @param list<string>          $fields  Column allowlist; empty = all readable.
     * @return list<array<string, mixed>>
     * @throws UnknownResourceException when the resource name is not registered
     */
    public function collection(
        string $resource,
        array $filters = [],
        array $order = [],
        int $page = 1,
        ?int $itemsPerPage = null,
        array $fields = [],
        ?SiteLanguage $language = null,
    ): array {
        return $this->collectionResult($resource, $filters, $order, $page, $itemsPerPage, $fields, $language)['items'];
    }

    /**
     * Fetch a page of records plus pagination metadata, for templates that render
     * a pager.
     *
     * @param array<string, mixed>  $filters
     * @param array<string, string> $order
     * @param list<string>          $fields
     * @return array{items: list<array<string, mixed>>, pagination: array{page: int, itemsPerPage: int, total: int, totalPages: int}}
     * @throws UnknownResourceException when the resource name is not registered
     */
    public function collectionResult(
        string $resource,
        array $filters = [],
        array $order = [],
        int $page = 1,
        ?int $itemsPerPage = null,
        array $fields = [],
        ?SiteLanguage $language = null,
    ): array {
        $result = $this->dataProvider->getCollection($this->requireConfig($resource), new CollectionQuery(
            page:         $page,
            itemsPerPage: $itemsPerPage,
            filters:      $filters,
            order:        $order,
            fields:       $fields,
            language:     $language ?? $this->currentLanguage(),
            operation:    'list',
        ));

        return [
            'items'      => $this->normalizer->normalizeCollection($result->members),
            'pagination' => [
                'page'         => $result->page,
                'itemsPerPage' => $result->itemsPerPage,
                'total'        => $result->total,
                'totalPages'   => $result->totalPages(),
            ],
        ];
    }

    /**
     * Fetch a single record by uid as a clean array, or null when not found.
     *
     * @param list<string> $fields Column allowlist; empty = all readable.
     * @return array<string, mixed>|null
     * @throws UnknownResourceException when the resource name is not registered
     */
    public function find(string $resource, int $uid, array $fields = [], ?SiteLanguage $language = null): ?array
    {
        $item = $this->dataProvider->getItem($this->requireConfig($resource), $uid, new ItemQuery(
            fields:    $fields,
            language:  $language ?? $this->currentLanguage(),
            operation: 'show',
        ));

        return $item === null ? null : $this->normalizer->normalize($item);
    }

    private function requireConfig(string $resource): ApiDefinition
    {
        return $this->apiRegistry->get($resource)
            ?? throw UnknownResourceException::forResource($resource);
    }

    /**
     * Resolve the active SiteLanguage from the current frontend request so records
     * are fetched and overlaid in the page's language by default. Returns null when
     * there is no request context (e.g. CLI), which the provider treats as
     * "no language overlay".
     */
    private function currentLanguage(): ?SiteLanguage
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $language = $request->getAttribute('language');

        return $language instanceof SiteLanguage ? $language : null;
    }
}
