<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Immutable parameter object for a `show` operation handled by {@see ResourceDataProvider}.
 *
 * @see CollectionQuery for the list counterpart and field semantics.
 */
final readonly class ItemQuery
{
    /**
     * @param array<string> $fields Column allowlist; empty = all readable columns.
     */
    public function __construct(
        public array $fields = [],
        public ?SiteLanguage $language = null,
        public string $operation = 'show',
        public ?ServerRequestInterface $request = null,
        public string $baseUrl = '',
    ) {
    }
}
