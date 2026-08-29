<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Fixtures;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Custom link check for testing — allows PDFs only.
 */
final class TestFileLinkChecker
{
    /**
     * @param array<string, mixed> $file sys_file row
     */
    public function isPdf(array $file, ?ServerRequestInterface $request): bool
    {
        return str_ends_with((string)$file['identifier'], '.pdf');
    }
}
