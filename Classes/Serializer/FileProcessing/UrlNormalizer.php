<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\FileProcessing;

/**
 * Normalises FAL public URLs to root-relative form for the JSON API.
 *
 * TYPO3's getPublicUrl()/getImageUri() return site-root-relative paths *without*
 * a leading slash (e.g. "fileadmin/_processed_/foo.jpg"). In a JSON API consumed
 * by JavaScript such a value resolves against the current document path, so an
 * <img src> or fetch() on "/events/123/slug" wrongly targets
 * "/events/123/fileadmin/...". Prepending a slash makes the URL resolve against
 * the host root regardless of the page it is used on.
 *
 * Already-absolute URLs are returned untouched: full URLs with a scheme
 * (http://, https://), scheme-relative URLs (//host/…), and values that already
 * start with a slash.
 */
final class UrlNormalizer
{
    public static function toRootRelative(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        // Already root-relative ("/…") or scheme-relative ("//host/…").
        if (str_starts_with($url, '/')) {
            return $url;
        }

        // Already absolute with a scheme (http://, https://, …).
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) === 1) {
            return $url;
        }

        return '/' . $url;
    }
}
