<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\DataAccess;

use MaikSchneider\TcaApi\DataAccess\ResolvedInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResolvedInputTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $scalar    = ['title' => 'Hello', 'category' => 'NEW_abc'];
        $extraMap  = ['tx_ext_child' => ['NEW_abc' => ['title' => 'Child']]];
        $violation = [['propertyPath' => 'title', 'message' => 'Too long', 'code' => 'too_long']];

        $input = new ResolvedInput($scalar, $extraMap, $violation);

        self::assertSame($scalar, $input->scalarBody);
        self::assertSame($extraMap, $input->extraDataMap);
        self::assertSame($violation, $input->violations);
    }

    #[Test]
    public function violationsDefaultsToEmptyArray(): void
    {
        $input = new ResolvedInput(['title' => 'Hi'], []);

        self::assertSame([], $input->violations);
    }

    #[Test]
    public function emptyScalarBodyIsAllowed(): void
    {
        $input = new ResolvedInput([], []);

        self::assertSame([], $input->scalarBody);
        self::assertSame([], $input->extraDataMap);
    }
}
