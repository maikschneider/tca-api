<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Fixtures;

/**
 * Test-only column callback target.
 * Used in ColumnCallbackTest to verify that a 'callback' on a normal column
 * is invoked at the end of serialization with (serializedRow, rawRow).
 */
final class TestColumnCallback
{
    /**
     * Transforms the already-serialized title value to upper case.
     * Proves the callback receives the serialized row, not just the raw value.
     */
    public function upperTitle(array $serializedRow, array $rawRow): string
    {
        return strtoupper((string)($serializedRow['title'] ?? ''));
    }

    /**
     * Combines two already-serialized columns, proving the callback runs after
     * all columns have been resolved into $serializedRow.
     */
    public function fullName(array $serializedRow, array $rawRow): string
    {
        return trim(($serializedRow['first_name'] ?? '') . ' ' . ($serializedRow['last_name'] ?? ''));
    }

    /**
     * Echoes a raw DB value, proving the callback receives the raw row too.
     */
    public function rawFirstName(array $serializedRow, array $rawRow): string
    {
        return 'raw:' . ($rawRow['first_name'] ?? '');
    }

    /**
     * Reads the name of an embedded relation out of the serialized row. Proves
     * the callback runs only after relations have been fully resolved.
     */
    public function colorName(array $serializedRow, array $rawRow): string
    {
        $color = $serializedRow['color_id'] ?? null;

        return \is_array($color) ? (string)($color['name'] ?? '') : 'unresolved';
    }

    /**
     * Virtual-property callback that echoes the (already callback-transformed)
     * title column. Proves column callbacks run before virtual properties.
     */
    public function echoTitle(array $serializedRow, array $rawRow): string
    {
        return 'vp:' . ($serializedRow['title'] ?? '');
    }

    /**
     * Virtual-property callback that decorates the value its own processor
     * already produced under the 'computed' key. Proves the VP callback runs
     * after (and on top of) the VP processor output.
     */
    public function decorateComputed(array $serializedRow, array $rawRow): string
    {
        return strtoupper((string)($serializedRow['computed'] ?? '')) . '!';
    }

    /**
     * Virtual-property callback that reads another virtual property's base
     * value. Proves all VP base values are resolved before any VP callback.
     */
    public function readComputedVp(array $serializedRow, array $rawRow): string
    {
        return 'seen:' . ($serializedRow['computed'] ?? 'missing');
    }
}
