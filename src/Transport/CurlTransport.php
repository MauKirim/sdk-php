<?php

declare(strict_types=1);

namespace MauKirim\Transport;

use MauKirim\Exception\MauKirimException;

/**
 * The default transport: ext-curl, no redirects, no retries of its own.
 *
 * The SDK needs a single unbroken `Authorization` + `Idempotency-Key` hop, so
 * redirects are never followed and every failure surfaces as one
 * {@see MauKirimException}.
 */
final class CurlTransport implements TransportInterface
{
    private readonly int $timeout;

    /**
     * @param int $timeout Total request timeout in seconds (connection + transfer).
     */
    public function __construct(int $timeout = 30)
    {
        $this->timeout = $timeout > 0 ? $timeout : 30;
    }

    public function request(string $method, string $url, array $headers, ?string $body): Response
    {
        $handle = curl_init();

        if ($handle === false) {
            throw MauKirimException::transport('Unable to initialise cURL.');
        }

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);

        if ($raw === false) {
            $message = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);

            throw MauKirimException::transport(
                sprintf('cURL error %d: %s', $errno, $message !== '' ? $message : 'request failed'),
            );
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $raw = (string) $raw;

        return new Response(
            $statusCode,
            substr($raw, $headerSize),
            self::parseHeaders(substr($raw, 0, $headerSize)),
        );
    }

    /**
     * Parses the last response header block (1xx/3xx blocks precede the final one).
     *
     * @return array<string, string>
     */
    private static function parseHeaders(string $rawHeaders): array
    {
        $headers = [];

        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                }

                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }

        return $headers;
    }
}
