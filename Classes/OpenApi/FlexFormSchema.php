<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Tca\RecordTypeFields;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * JSON schema of a decoded type=flex value, built from the FlexForm data structure
 * the backend form uses for a record type.
 *
 * The serializer decodes FlexForm XML with xml2array, so a value has the shape
 * data.<sheet>.lDEF.<field>.vDEF, and a section holds its containers under
 * <section>.el.<index>.<container>.el.<field>.vDEF.
 */
final class FlexFormSchema
{
    /**
     * Null when the value depends on a record type that is not given, or when no
     * data structure resolves for the column.
     *
     * @return array<string, mixed>|null
     */
    public static function forColumn(string $table, string $column, ?string $recordType): ?array
    {
        $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'] ?? '';
        $typeField = \is_string($typeField) && !str_contains($typeField, ':') ? $typeField : '';
        if ($typeField !== '' && $recordType === null) {
            return null;
        }

        $fieldTca = RecordTypeFields::columnDefinition($table, $column, $recordType);
        if (($fieldTca['config']['type'] ?? '') !== 'flex' || ($fieldTca['config']['ds'] ?? null) === [] || ($fieldTca['config']['ds'] ?? '') === '') {
            return null;
        }

        // TYPO3 v13 selects the data structure by ds_pointerField and requires each pointer field in the row.
        $row = [];
        $pointerFields = $fieldTca['config']['ds_pointerField'] ?? '';
        foreach (GeneralUtility::trimExplode(',', \is_string($pointerFields) ? $pointerFields : '', true) as $pointerField) {
            $row[$pointerField] = '';
        }
        if ($typeField !== '') {
            $row[$typeField] = $recordType;
        }

        try {
            $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
            $schema = GeneralUtility::makeInstance(TcaSchemaFactory::class)->get($table);
            $dataStructure = $flexFormTools->parseDataStructureByIdentifier(
                $flexFormTools->getDataStructureIdentifier($fieldTca, $table, $column, $row, $schema),
                $schema,
            );
        } catch (\Exception) {
            return null;
        }

        $sheets = [];
        foreach (\is_array($dataStructure['sheets'] ?? null) ? $dataStructure['sheets'] : [] as $sheetName => $sheet) {
            $elements = \is_array($sheet) && \is_array($sheet['ROOT']['el'] ?? null) ? $sheet['ROOT']['el'] : [];
            $sheets[(string)$sheetName] = self::object(['lDEF' => self::object(self::fields($elements))]);
        }

        return self::object(['data' => self::object($sheets)]);
    }

    /**
     * @param array<array-key, mixed> $elements
     * @return array<string, array<string, mixed>>
     */
    private static function fields(array $elements): array
    {
        $fields = [];
        foreach ($elements as $name => $element) {
            if (!\is_array($element)) {
                continue;
            }
            $fields[(string)$name] = (bool)($element['section'] ?? false)
                ? self::section(\is_array($element['el'] ?? null) ? $element['el'] : [])
                : self::object(['vDEF' => self::value(\is_array($element['config'] ?? null) ? $element['config'] : [])]);
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $containers
     * @return array<string, mixed>
     */
    private static function section(array $containers): array
    {
        $containerSchemas = [];
        foreach ($containers as $name => $container) {
            $elements = \is_array($container) && \is_array($container['el'] ?? null) ? $container['el'] : [];
            $containerSchemas[(string)$name] = self::object(['el' => self::object(self::fields($elements))]);
        }

        return self::object(['el' => ['type' => 'object', 'additionalProperties' => self::object($containerSchemas)]]);
    }

    /**
     * DataHandler stores check and number values as typed XML values, but values
     * written before that, or by other tools, are strings.
     *
     * @param array<array-key, mixed> $config
     * @return array<string, mixed>
     */
    private static function value(array $config): array
    {
        return match ($config['type'] ?? '') {
            'check'  => ['type' => ['integer', 'string']],
            'number' => ['type' => [($config['format'] ?? '') === 'decimal' ? 'number' : 'integer', 'string']],
            default  => ['type' => 'string'],
        };
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
    }
}
