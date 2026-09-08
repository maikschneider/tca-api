<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

final class WordStartFilter extends AbstractLikeFilter
{
    protected function pattern(string $escapedValue): string
    {
        return $escapedValue . '%';
    }
}
