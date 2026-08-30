<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\Processing;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;

/**
 * Lets a processor fetch what it needs for a whole collection in one go.
 *
 * process() is called once per record, so a processor that does its own lookup
 * issues one query per row. Embedded columns avoid that through EmbedPreloader
 * and FileReferencePreloader; this is the equivalent for processors.
 *
 * prepare() runs once before the collection is iterated, with every row that is
 * about to be serialized. Cache the result on the processor and serve process()
 * from it. A processor is prepared at most once per collection, and never for a
 * single-record request — process() must still work without a prior prepare().
 */
interface PreloadingProcessorInterface
{
    public const SERVICE_TAG = 'tca_api.preloading_processor';

    /**
     * @param array<int, array<string, mixed>> $rows Raw DB rows about to be serialized
     */
    public function prepare(array $rows, ApiDefinition $config): void;
}
