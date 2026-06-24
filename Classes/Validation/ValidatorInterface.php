<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Custom write-validation rule for a column.
 *
 * Reference an implementation by class-string in a column's `validators` config:
 *
 *   'iban' => [
 *       'validators' => [
 *           ['type' => \Acme\Validator\IbanValidator::class, 'options' => ['country' => 'DE']],
 *       ],
 *   ],
 *
 * Implementations are auto-discovered through the DI tag below — no Services.yaml
 * entry is needed as long as the consuming extension uses autoconfigure.
 */
#[AutoconfigureTag('tca_api.validator')]
interface ValidatorInterface
{
    /**
     * Validate the value carried by $context.
     *
     * Return an empty array when the value is valid, or one or more Violations
     * describing each failure.
     *
     * @return list<Violation>
     */
    public function validate(ValidationContext $context): array;
}
