<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\ConfigurationModuleProvider;

use MaikSchneider\TcaApi\Registry\ApiRegistry;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\AbstractProvider;

final class TcaApiConfigurationProvider extends AbstractProvider
{
    public function __construct(private readonly ApiRegistry $apiRegistry)
    {
    }

    public function getConfiguration(): array
    {
        $configuration = [];
        foreach ($this->apiRegistry->getAll() as $resourceName => $definition) {
            $configuration[$resourceName] = $this->toArray($definition);
        }
        ArrayUtility::naturalKeySortRecursive($configuration);
        return $configuration;
    }

    private function toArray(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_object($value)) {
            $result = [];
            foreach ((new \ReflectionClass($value))->getProperties() as $property) {
                $result[$property->getName()] = $this->toArray($property->getValue($value));
            }
            return $result;
        }

        if (is_array($value)) {
            return array_map($this->toArray(...), $value);
        }

        return $value;
    }
}
