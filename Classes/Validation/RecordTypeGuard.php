<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;

final class RecordTypeGuard
{
    /**
     * Checks the record type in the body against general.recordTypes.
     *
     * Returns the violations and the type a created record gets: the submitted
     * value, or the only allowed type when the client sent none. Updates keep
     * the usual column writability, so their type value is only validated here.
     *
     * @return array{0: list<array{propertyPath: string, message: string, code: string}>, 1: ?string}
     */
    public static function resolve(array $body, ApiDefinition $config, bool $creating): array
    {
        if (!$config->isRecordTypeScoped()) {
            return [[], null];
        }

        $field = $config->recordTypeField;

        if (!\array_key_exists($field, $body)) {
            if (!$creating || $config->fixedRecordType() !== null) {
                return [[], $config->fixedRecordType()];
            }

            return [[[
                'propertyPath' => $field,
                'message'      => sprintf("Field '%s' is required. Allowed: %s", $field, implode(', ', $config->recordTypes)),
                'code'         => 'REQUIRED',
            ]], null];
        }

        $value = $body[$field];
        if (!\is_scalar($value) || !\in_array((string)$value, $config->recordTypes, true)) {
            return [[[
                'propertyPath' => $field,
                'message'      => sprintf("Field '%s' must be one of: %s", $field, implode(', ', $config->recordTypes)),
                'code'         => 'INVALID_RECORD_TYPE',
            ]], null];
        }

        return [[], (string)$value];
    }
}
