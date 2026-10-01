<?php

declare(strict_types=1);

namespace MauKirim\Transport;

use MauKirim\Exception\MauKirimException;

/**
 * The seam between the SDK and the network.
 *
 * Every request the SDK performs goes through this interface, which makes the
 * client fully testable without touching the network. The default
 * implementation is {@see CurlTransport}.
 */
interface TransportInterface
{
    /**
     * Performs one HTTP request.
     *
     * @param string                $method  HTTP method, upper case (`GET`, `POST`, ...).
     * @param string                $url     Absolute URL including any query string.
     * @param array<string, string> $headers Request headers; multipart requests already carry
     *                                       their `Content-Type` boundary header.
     * @param string|null           $body    Fully encoded request body, or null for none.
     *
     * @return Response The raw response; non-2xx statuses are returned, not thrown.
     *
     * @throws MauKirimException When the request never completed (DNS, TLS, timeout, ...).
     *                           The exception reports a status code of 0 and is retryable.
     */
    public function request(string $method, string $url, array $headers, ?string $body): Response;
}
