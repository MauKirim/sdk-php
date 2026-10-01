<?php

declare(strict_types=1);

namespace MauKirim\Transport;

/**
 * A `multipart/form-data` body, encoded the same way cURL and Guzzle encode one:
 * CRLF line endings, `name`/`filename` quoted and escaped, and a closing
 * `--boundary--` delimiter.
 *
 * The API uses multipart for the two upload routes and for media messages
 * (`file` / `avatar` parts plus the plain fields that travel with them).
 */
final class MultipartBody
{
    /**
     * @param string $body        The encoded body.
     * @param string $boundary    Boundary token used in the body.
     * @param string $contentType Ready to use `Content-Type` header value.
     */
    private function __construct(
        public readonly string $body,
        public readonly string $boundary,
        public readonly string $contentType,
    ) {
    }

    /**
     * @param list<array{
     *     name: string,
     *     contents: string,
     *     filename?: string|null,
     *     contentType?: string|null
     * }> $parts
     * @param string|null $boundary Boundary to use; a random one is generated when omitted.
     */
    public static function encode(array $parts, ?string $boundary = null): self
    {
        $boundary ??= bin2hex(random_bytes(16));

        $body = '';

        foreach ($parts as $part) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . self::escape($part['name']) . '"';

            if (isset($part['filename']) && $part['filename'] !== null && $part['filename'] !== '') {
                $body .= '; filename="' . self::escape($part['filename']) . '"';
            }

            $body .= "\r\n";

            if (isset($part['contentType']) && $part['contentType'] !== null && $part['contentType'] !== '') {
                $body .= 'Content-Type: ' . $part['contentType'] . "\r\n";
            }

            $body .= "\r\n" . $part['contents'] . "\r\n";
        }

        $body .= '--' . $boundary . "--\r\n";

        return new self($body, $boundary, 'multipart/form-data; boundary=' . $boundary);
    }

    /**
     * Strips CR/LF (which would let a crafted file name inject extra headers)
     * and escapes quotes and backslashes as RFC 7578 expects.
     */
    private static function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], str_replace(["\r", "\n"], '', $value));
    }
}
