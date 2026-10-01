<?php

declare(strict_types=1);

namespace MauKirim\Transport;

/**
 * A raw HTTP response as returned by a {@see TransportInterface}.
 */
final class Response
{
    /**
     * @param int                         $statusCode HTTP status code.
     * @param string                      $body       Raw response body.
     * @param array<string, string>       $headers    Response headers, keyed by the name as sent.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    /**
     * Whether the status is 2xx, i.e. a body worth decoding.
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
