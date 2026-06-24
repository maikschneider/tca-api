<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Validation\Fixtures;

use MaikSchneider\TcaApi\Validation\ValidationContext;
use MaikSchneider\TcaApi\Validation\ValidatorInterface;
use MaikSchneider\TcaApi\Validation\Violation;

/**
 * Test double: records the received ValidationContext and returns a configurable
 * list of violations. Lets unit tests assert both context threading and the
 * violation-mapping done by FieldValidator.
 */
final class RecordingValidator implements ValidatorInterface
{
    public ?ValidationContext $received = null;

    /** @var list<Violation> */
    public array $toReturn = [];

    public function validate(ValidationContext $context): array
    {
        $this->received = $context;

        return $this->toReturn;
    }
}
