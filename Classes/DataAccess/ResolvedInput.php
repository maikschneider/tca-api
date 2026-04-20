<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

/**
 * Result of RelationInputResolver::resolve().
 *
 * scalarBody    - request body with all relation objects replaced by NEW_xxx
 *                 placeholder strings (or integer UIDs for existing records).
 *                 inline (foreign_field) columns may also contain NEW_xxx
 *                 placeholder strings when inline relations are resolved.
 *
 * extraDataMap  - additional table entries to include in the same DataHandler
 *                 processDataMap call:
 *                 ['table_name' => ['NEW_xxx' => [...field_data...]]]
 *                 DataHandler resolves NEW_xxx placeholders across all tables,
 *                 enabling atomic multi-record creation with correct cross-references.
 *
 * violations    - security or validation failures collected while processing
 *                 nested child objects. Non-empty means the caller must reject
 *                 the request (return 422) before calling processDataMap.
 *                 Each entry: ['propertyPath' => string, 'message' => string, 'code' => string]
 */
final readonly class ResolvedInput
{
    /**
     * @param array<string, mixed>                       $scalarBody
     * @param array<string, array<string, array>>        $extraDataMap
     * @param list<array{propertyPath: string, message: string, code: string}> $violations
     */
    public function __construct(
        public array $scalarBody,
        public array $extraDataMap,
        public array $violations = [],
    ) {
    }
}
