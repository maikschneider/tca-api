<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

trait LanguageAwareTrait
{
    private function languageFromRequest(ServerRequestInterface $request): ?SiteLanguage
    {
        $language = $request->getAttribute('tca_api.language');
        return $language instanceof SiteLanguage ? $language : null;
    }
}
