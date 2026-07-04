<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

/**
 * One resolved relation step in a dotted filter path (see {@see RelationPathFilter}).
 *
 * A hop describes how to map a set of UIDs of the related ("target") table back to
 * the UIDs of the record that holds the relation (the "source" table). Two kinds:
 *
 *  - `fk`     — single-value select relation: the source row stores the target UID in
 *               {@see $fkColumn} (e.g. `article.color_id → color.uid`).
 *  - `mm`     — many-to-many relation through an intermediate MM table (e.g.
 *               `article ⇄ sys_category_record_mm ⇄ sys_category`), including
 *               TYPO3 `type=category` and `type=group` with `MM`.
 *  - `inline` — `type=inline` relation: the target (child) row stores a back-pointer to
 *               the source UID in {@see $foreignField}, optionally discriminated by
 *               {@see $foreignTableField} (parent table name) and {@see $foreignMatchFields}.
 *
 * Immutable and serialisable — instances are baked into the cached ApiDefinition
 * at boot (see {@see \MaikSchneider\TcaApi\Loader\ApiDefinitionLoader}).
 */
final readonly class RelationHop
{
    public const KIND_FK     = 'fk';
    public const KIND_MM     = 'mm';
    public const KIND_INLINE = 'inline';

    /**
     * @param 'fk'|'mm'|'inline'    $kind
     * @param string               $sourceTable        Table the relation is declared on.
     * @param string               $targetTable        Related table the hop points to.
     * @param string|null          $fkColumn           FK column on the source table (kind=fk).
     * @param string|null          $mmTable            Intermediate MM table (kind=mm).
     * @param string|null          $mmSourceKey        MM column holding the source-table UID (kind=mm).
     * @param string|null          $mmTargetKey        MM column holding the target-table UID (kind=mm).
     * @param array<string, mixed> $mmMatch            MM_match_fields constraints applied on the MM table (kind=mm).
     * @param string|null          $foreignField       Column on the child table holding the source UID (kind=inline).
     * @param string|null          $foreignTableField  Column on the child table holding the source table name (kind=inline).
     * @param array<string, mixed> $foreignMatchFields Discriminator constraints on the child table (kind=inline).
     */
    public function __construct(
        public string $kind,
        public string $sourceTable,
        public string $targetTable,
        public ?string $fkColumn = null,
        public ?string $mmTable = null,
        public ?string $mmSourceKey = null,
        public ?string $mmTargetKey = null,
        public array $mmMatch = [],
        public ?string $foreignField = null,
        public ?string $foreignTableField = null,
        public array $foreignMatchFields = [],
    ) {
    }

    public static function fk(string $sourceTable, string $targetTable, string $fkColumn): self
    {
        return new self(self::KIND_FK, $sourceTable, $targetTable, fkColumn: $fkColumn);
    }

    /**
     * @param array<string, mixed> $mmMatch
     */
    public static function mm(
        string $sourceTable,
        string $targetTable,
        string $mmTable,
        string $mmSourceKey,
        string $mmTargetKey,
        array $mmMatch = [],
    ): self {
        return new self(
            self::KIND_MM,
            $sourceTable,
            $targetTable,
            mmTable: $mmTable,
            mmSourceKey: $mmSourceKey,
            mmTargetKey: $mmTargetKey,
            mmMatch: $mmMatch,
        );
    }

    /**
     * @param array<string, mixed> $foreignMatchFields
     */
    public static function inline(
        string $sourceTable,
        string $targetTable,
        string $foreignField,
        ?string $foreignTableField = null,
        array $foreignMatchFields = [],
    ): self {
        return new self(
            self::KIND_INLINE,
            $sourceTable,
            $targetTable,
            foreignField: $foreignField,
            foreignTableField: $foreignTableField,
            foreignMatchFields: $foreignMatchFields,
        );
    }
}
