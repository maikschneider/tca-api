<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

/**
 * Result of RelationInputResolver::resolve().
 *
 * scalarBody    - request body with all relation objects replaced by NEW_xxx
 *                 placeholder strings (or integer UIDs for existing records).
 *                 inline (foreign_field) columns are included for CREATE (with
 *                 placeholder strings) and omitted for UPDATE.
 *
 * extraDataMap  - additional table entries to include in the same DataHandler
 *                 processDataMap call:
 *                 ['table_name' => ['NEW_xxx' => [...field_data...]]]
 *                 DataHandler resolves NEW_xxx placeholders across all tables,
 *                 enabling atomic multi-record creation with correct cross-references.
 */
final readonly class ResolvedInput
{
    /**
     * @param array<string, mixed>                       $scalarBody
     * @param array<string, array<string, array>>        $extraDataMap
     */
    public function __construct(
        public array $scalarBody,
        public array $extraDataMap,
    ) {}
}
