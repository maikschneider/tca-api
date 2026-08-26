<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

/**
 * Result of FileReferenceInputResolver::resolve().
 *
 * body       - request body with every type=file column removed, so the relation
 *              resolver and the column filter never see a value they would read
 *              as a sys_file_reference uid.
 *
 * references - column → [refKey => sys_file_reference data]. Same shape the
 *              upload path produces, so both go through attachFileReferences().
 *              uid_foreign is intentionally absent — it is unknown until the
 *              parent record exists.
 *
 * violations - unreadable input, unknown files, maxitems overruns. Non-empty
 *              means the caller must reject the request with 422.
 */
final readonly class ResolvedFileReferenceInput
{
    /**
     * @param array<string, mixed>                                             $body
     * @param array<string, array<string, array>>                              $references
     * @param list<array{propertyPath: string, message: string, code: string}> $violations
     */
    public function __construct(
        public array $body,
        public array $references,
        public array $violations = [],
    ) {
    }
}
