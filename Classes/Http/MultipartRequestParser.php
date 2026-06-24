<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Http;

use Psr\Http\Message\ServerRequestInterface;
use Riverline\MultiPartParser\StreamedPart;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\UploadedFile;

/**
 * Parses multipart/form-data bodies for PUT/PATCH requests.
 *
 * PHP's SAPI only populates $_POST / $_FILES — and therefore the PSR-7
 * getParsedBody() / getUploadedFiles() methods — for POST requests. For
 * PUT/PATCH with multipart/form-data the raw body is left unparsed, so
 * update handlers would silently see no form fields and no uploaded files.
 *
 * This parser reads the raw body stream, parses the MIME document, and
 * re-injects the result via withParsedBody()/withUploadedFiles() so that
 * every downstream consumer can use the standard PSR-7 accessors unchanged.
 *
 * @see https://github.com/maikschneider/tca-api/issues/143
 */
final class MultipartRequestParser
{
    /**
     * Return a request enriched with parsed body and uploaded files, or the
     * original request when parsing is unnecessary or fails.
     */
    public function enrich(ServerRequestInterface $request): ServerRequestInterface
    {
        if (!$this->needsParsing($request)) {
            return $request;
        }

        try {
            $document = $this->parseDocument($request);
            if (!$document->isMultiPart()) {
                return $request;
            }

            $fieldPairs    = [];
            $uploadedFiles = [];

            foreach ($document->getParts() as $part) {
                $name = $part->getName();
                if ($name === '') {
                    continue;
                }

                if ($part->isFile()) {
                    $this->collectFile($uploadedFiles, $name, $part);
                } else {
                    // Preserve PHP's bracket semantics (foo[], foo[bar]) by
                    // round-tripping through parse_str below.
                    $fieldPairs[] = rawurlencode($name) . '=' . rawurlencode($part->getBody());
                }
            }
        } catch (\Throwable) {
            // Body is not a parseable multipart document — degrade gracefully
            // and leave the request (and any already-parsed body) untouched.
            return $request;
        }

        $parsedBody = [];
        if ($fieldPairs !== []) {
            parse_str(implode('&', $fieldPairs), $parsedBody);
        }

        return $request
            ->withParsedBody($parsedBody)
            ->withUploadedFiles($uploadedFiles);
    }

    private function needsParsing(ServerRequestInterface $request): bool
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (!str_contains($contentType, 'multipart/')) {
            return false;
        }

        // PHP's SAPI only parses multipart bodies for POST; for PUT/PATCH it
        // never populates getParsedBody()/getUploadedFiles(), so we own parsing
        // whenever such a request carries a raw multipart body.
        if (!\in_array(strtoupper($request->getMethod()), ['PUT', 'PATCH'], true)) {
            return false;
        }

        // Uploaded files already present means an earlier layer populated them
        // (or a test injected them) — respect that and don't re-parse.
        if ($request->getUploadedFiles() !== []) {
            return false;
        }

        $body = $request->getBody();
        $body->rewind();

        return (string)$body !== '';
    }

    private function parseDocument(ServerRequestInterface $request): StreamedPart
    {
        $body = $request->getBody();
        $body->rewind();
        $raw = (string)$body;
        $body->rewind();

        // StreamedPart parses a full MIME document, so the Content-Type header
        // (carrying the boundary) must precede the raw body.
        $mime = 'Content-Type: ' . $request->getHeaderLine('Content-Type') . "\r\n\r\n" . $raw;

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open temporary stream for multipart parsing.', 1750000000);
        }

        fwrite($stream, $mime);
        rewind($stream);

        return new StreamedPart($stream);
    }

    /**
     * @param array<string, UploadedFile|list<UploadedFile>> $uploadedFiles
     */
    private function collectFile(array &$uploadedFiles, string $name, StreamedPart $part): void
    {
        $content = $part->getBody();
        $stream  = new Stream('php://temp', 'rw');
        $stream->write($content);
        $stream->rewind();

        $file = new UploadedFile(
            $stream,
            \strlen($content),
            \UPLOAD_ERR_OK,
            $part->getFileName(),
            $part->getMimeType(),
        );

        // A "downloads[]" style name denotes a list of files on one column.
        if (str_ends_with($name, '[]')) {
            $column   = substr($name, 0, -2);
            $existing = $uploadedFiles[$column] ?? [];
            $list     = \is_array($existing) ? $existing : [$existing];
            $list[]   = $file;
            $uploadedFiles[$column] = $list;
            return;
        }

        $uploadedFiles[$name] = $file;
    }
}
