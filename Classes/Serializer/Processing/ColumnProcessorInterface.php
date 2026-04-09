<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\Processing;

interface ColumnProcessorInterface
{
    /**
     * Process a column value into a serializable scalar or structure.
     *
     * @param mixed $value  Raw column value; null for virtual properties.
     * @param array $config The column / virtual property config from TcaApi.
     * @param array $context ['serializedRow' => [...], 'rawRow' => [...]]
     */
    public function process(mixed $value, array $config, array $context): mixed;
}
