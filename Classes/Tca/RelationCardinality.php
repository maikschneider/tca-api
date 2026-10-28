<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tca;

use MaikSchneider\TcaApi\Serializer\RelationSerializer;
use TYPO3\CMS\Core\Schema\RelationshipType;

/**
 * Whether a relation column holds one record or a list. The serializer and the
 * OpenAPI schema builder both decide through this class, so the spec describes a
 * relation the way it is rendered.
 *
 * Follows TYPO3's RelationshipType: one record for "oneToOne" and "manyToOne",
 * a list otherwise, whatever maxitems says. A group over several tables stays a
 * list of prefixed references.
 */
final class RelationCardinality
{
    /** @param array<string, mixed> $fieldConfig the column's TCA "config" */
    public static function isHasOne(array $fieldConfig): bool
    {
        if (!RelationshipType::fromTcaConfiguration(self::withSelectSingleRelationship($fieldConfig))->hasOne()) {
            return false;
        }

        return ($fieldConfig['type'] ?? null) !== 'group' || RelationSerializer::hasOneForeignTable($fieldConfig) !== null;
    }

    /**
     * TcaPreparation::configureSelectSingle() sets the relationship of a selectSingle
     * with foreign_table, so TCA that hasn't been prepared (unit tests, raw configs)
     * gets the same answer as prepared TCA.
     *
     * @param array<string, mixed> $fieldConfig
     * @return array<string, mixed>
     */
    private static function withSelectSingleRelationship(array $fieldConfig): array
    {
        if (($fieldConfig['type'] ?? null) === 'select'
            && ($fieldConfig['renderType'] ?? null) === 'selectSingle'
            && isset($fieldConfig['foreign_table'])
            && !isset($fieldConfig['relationship'])
        ) {
            $fieldConfig['relationship'] = isset($fieldConfig['MM']) ? 'manyToMany' : 'manyToOne';
        }

        return $fieldConfig;
    }
}
