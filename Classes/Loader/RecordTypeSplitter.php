<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Loader;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Exception\InvalidApiDefinitionException;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Expands resource configs with `general.recordTypes.split` into one resource
 * per record type.
 *
 * Runs on the raw configs after the Overrides/ pass, so override files edit the
 * base config and every generated resource inherits it. Each generated config is
 * the base config scoped to one type, with its name taken from `namePattern` and
 * the type's entry under the top-level `types` key merged over it. The
 * resourceType and Swagger UI group follow from the final name unless set.
 */
final class RecordTypeSplitter
{
    public const DEFAULT_NAME_PATTERN = '{resourceName}-{type}';

    private const SPLIT_OPTIONS = ['split', 'namePattern', 'keepBase'];

    /**
     * @param array<string, array<string, mixed>> $rawConfigs raw resource configs keyed by resource name
     * @return array<string, array<string, mixed>>
     * @throws InvalidApiDefinitionException on invalid split options or resource name collisions
     */
    public static function expand(array $rawConfigs): array
    {
        $expanded = [];
        $generatedBy = [];
        foreach ($rawConfigs as $resourceName => $config) {
            $recordTypes = $config['general']['recordTypes'] ?? null;
            $options = \is_array($recordTypes) && array_filter(array_keys($recordTypes), \is_string(...)) !== [] ? $recordTypes : [];
            $label = (string)($config['general']['resourceName'] ?? $resourceName);

            if (isset($options['split']) && !\is_bool($options['split'])) {
                throw new InvalidApiDefinitionException(sprintf('TcaApi config for "%s": general.recordTypes.split must be a boolean.', $label));
            }
            if (($options['split'] ?? false) !== true) {
                if (isset($config['types'])) {
                    throw new InvalidApiDefinitionException(sprintf(
                        'TcaApi config for "%s": "types" requires general.recordTypes.split = true.',
                        $label,
                    ));
                }
                $expanded[$resourceName] = $config;
                continue;
            }

            $generated = self::generate($config, $label, $options);

            // Ahead of its generated resources, so ApiRegistry::getByTable() resolves
            // relations to the base, which serves records of every included type.
            if (($options['keepBase'] ?? true) === true) {
                unset($config['types']);
                $expanded[$resourceName] = $config;
            }

            foreach ($generated as $generatedName => $generatedConfig) {
                if (isset($generatedBy[$generatedName]) || isset($rawConfigs[$generatedName])) {
                    throw new InvalidApiDefinitionException(sprintf(
                        'TcaApi config for "%s": the generated resource name "%s" is already used by %s.',
                        $label,
                        $generatedName,
                        isset($generatedBy[$generatedName]) ? sprintf('a resource generated from "%s"', $generatedBy[$generatedName]) : 'another resource',
                    ));
                }
                $generatedBy[$generatedName] = $label;
                $expanded[$generatedName] = $generatedConfig;
            }
        }

        return $expanded;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    private static function generate(array $config, string $label, array $options): array
    {
        if (isset($options['keepBase']) && !\is_bool($options['keepBase'])) {
            throw new InvalidApiDefinitionException(sprintf('TcaApi config for "%s": general.recordTypes.keepBase must be a boolean.', $label));
        }
        $namePattern = $options['namePattern'] ?? self::DEFAULT_NAME_PATTERN;
        if (!\is_string($namePattern) || !str_contains($namePattern, '{type}')) {
            throw new InvalidApiDefinitionException(sprintf(
                'TcaApi config for "%s": general.recordTypes.namePattern must be a string containing "{type}".',
                $label,
            ));
        }

        $table = (string)($config['general']['table'] ?? '');
        $recordTypes = ApiDefinition::recordTypeList($options, $table) ?? [];

        $typeOverrides = $config['types'] ?? [];
        if (!\is_array($typeOverrides)) {
            throw new InvalidApiDefinitionException(sprintf('TcaApi config for "%s": "types" must be an array keyed by record type.', $label));
        }
        $unknown = array_diff(array_map('strval', array_keys($typeOverrides)), $recordTypes);
        if ($unknown !== []) {
            throw new InvalidApiDefinitionException(sprintf(
                'TcaApi config for "%s": "types" lists record type(s) "%s" that general.recordTypes does not include.',
                $label,
                implode('", "', $unknown),
            ));
        }

        unset($config['types']);
        $typeOptions = array_diff_key($options, array_flip([...self::SPLIT_OPTIONS, 'include']));
        $typeLabels = self::typeLabels($table);

        $generated = [];
        foreach ($recordTypes as $recordType) {
            $override = $typeOverrides[$recordType] ?? [];
            if (!\is_array($override)) {
                throw new InvalidApiDefinitionException(sprintf('TcaApi config for "%s": "types.%s" must be an array.', $label, $recordType));
            }

            $typeConfig = $config;
            $typeConfig['general']['resourceName'] = strtr($namePattern, ['{resourceName}' => $label, '{type}' => $recordType]);
            unset($typeConfig['general']['resourceType']);
            $typeConfig['general']['recordTypes'] = ['include' => [$recordType]] + $typeOptions;
            $typeConfig = self::merge($typeConfig, $override);

            $resourceName = (string)$typeConfig['general']['resourceName'];
            if (preg_match('/^[A-Za-z0-9_-]+$/', $resourceName) !== 1) {
                throw new InvalidApiDefinitionException(sprintf(
                    'TcaApi config for "%s": the generated resource name "%s" may only contain letters, digits, "-" and "_". Set types.%s.general.resourceName or a different namePattern.',
                    $label,
                    $resourceName,
                    $recordType,
                ));
            }
            if (isset($generated[$resourceName])) {
                throw new InvalidApiDefinitionException(sprintf(
                    'TcaApi config for "%s": the generated resource name "%s" is already used by a resource generated from "%s".',
                    $label,
                    $resourceName,
                    $label,
                ));
            }

            $typeConfig['general']['resourceType'] ??= self::pascalCase($resourceName);
            if (!isset($typeConfig['general']['group']) && isset($typeLabels[$recordType])) {
                $typeConfig['general']['group'] = ['name' => $typeConfig['general']['resourceType'], 'description' => $typeLabels[$recordType]];
            }
            $generated[$resourceName] = $typeConfig;
        }

        return $generated;
    }

    /**
     * Merges a types.<type> override over a config. Nested maps merge key by key;
     * lists and scalars are replaced as a whole, so an override can narrow a list
     * such as operations or the groups of an access role.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $baseValue = $base[$key] ?? null;
            $base[$key] = \is_array($value) && \is_array($baseValue) && !array_is_list($value) && !array_is_list($baseValue)
                ? self::merge($baseValue, $value)
                : $value;
        }

        return $base;
    }

    /** 'content-textmedia' → 'ContentTextmedia', 'pages-254' → 'Pages254' */
    private static function pascalCase(string $name): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map('ucfirst', $parts));
    }

    /**
     * Labels of the record types from the items of the table's type field, translated
     * when they are label references.
     *
     * @return array<string, string>
     */
    private static function typeLabels(string $table): array
    {
        $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'] ?? null;
        $items = \is_string($typeField) ? ($GLOBALS['TCA'][$table]['columns'][$typeField]['config']['items'] ?? []) : [];
        if (!\is_array($items)) {
            return [];
        }

        $labels = [];
        foreach ($items as $item) {
            if (!\is_array($item) || !isset($item['value']) || !\is_string($item['label'] ?? null) || $item['label'] === '') {
                continue;
            }
            $labels[(string)$item['value']] = self::translate($item['label']);
        }

        return $labels;
    }

    private static function translate(string $label): string
    {
        if (!str_starts_with($label, 'LLL:') && preg_match('/^[a-z0-9_.]+:[A-Za-z0-9_.-]+$/', $label) !== 1) {
            return $label;
        }

        $translated = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default')->sL($label);

        return $translated !== '' ? $translated : $label;
    }
}
