<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Immutable parameter object for a `list` operation handled by {@see ResourceDataProvider}.
 *
 * Carries everything the provider needs to fetch and serialize a page of records
 * without a parsed HTTP request. The HTTP handlers build this from request
 * attributes; the Fluid data layer builds it from explicit arguments.
 *
 * `$itemsPerPage` is nullable: when null the provider resolves the default from
 * the resource config (or its own fallback). `$request` is optional and only
 * forwarded to custom filters that inspect it — no built-in filter requires it.
 */
final readonly class CollectionQuery
{
    /**
     * @param array<string, mixed>  $filters Map of filter column => value (already promoted, no top-level params).
     * @param array<string, string> $order   Map of column => 'asc'|'desc'; validated against the config allowlist.
     * @param array<string>         $fields  Column allowlist; empty = all readable columns.
     */
    public function __construct(
        public int $page = 1,
        public ?int $itemsPerPage = null,
        public array $filters = [],
        public array $order = [],
        public array $fields = [],
        public ?SiteLanguage $language = null,
        public string $operation = 'list',
        public ?ServerRequestInterface $request = null,
        public string $baseUrl = '',
    ) {
    }
}
