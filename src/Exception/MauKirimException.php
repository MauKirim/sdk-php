<?php

declare(strict_types=1);

namespace MauKirim\Exception;

/**
 * The single exception type thrown by the MauKirim SDK.
 *
 * It is raised for every non-2xx API response (carrying the HTTP status and the
 * `code` from the API's `{"ok": false, "code": "..."}` error envelope) and for
 * transport level failures such as a refused connection or a timeout.
 *
 * ```php
 * try {
 *     $client->otp->send($phone, 'login', $key);
 * } catch (\MauKirim\Exception\MauKirimException $e) {
 *     if ($e->getErrorCode() === 'rate_limited') { ... }
 *     if ($e->isRetryable()) { ... }
 * }
 * ```
 */
class MauKirimException extends \RuntimeException
{
    /** HTTP status returned by the API; 0 when the request never reached it. */
    private int $statusCode;

    /** Machine readable `code` from the error envelope, or null when absent. */
    private ?string $errorCode;

    /** Whether repeating the same request could succeed. */
    private bool $retryable;

    /**
     * @param string          $message    Human readable description.
     * @param int             $statusCode HTTP status, 0 for transport failures.
     * @param string|null     $errorCode  API error code (`api_key_invalid`, ...).
     * @param bool            $retryable  Whether the request may be retried as-is.
     * @param \Throwable|null $previous   Underlying failure, if any.
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        ?string $errorCode = null,
        bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);

        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->retryable = $retryable;
    }

    /**
     * A connection level failure: DNS, TLS, refused connection, timeout.
     *
     * Transport failures are always retryable.
     */
    public static function transport(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, 0, null, true, $previous);
    }

    /**
     * Builds the exception for an error response from the API.
     *
     * The API always answers failures with `{"ok": false, "code": "<code>"}`;
     * anything else (a proxy error page, for example) still yields an exception
     * with a null error code.
     */
    public static function fromResponse(int $statusCode, string $body): self
    {
        $decoded = json_decode($body, true);
        $code = null;

        if (is_array($decoded) && isset($decoded['code']) && is_string($decoded['code']) && $decoded['code'] !== '') {
            $code = $decoded['code'];
        }

        if ($code !== null) {
            $message = sprintf('HTTP %d: %s', $statusCode, $code);
        } else {
            $message = sprintf('HTTP %d: %s', $statusCode, self::summarise($body));
        }

        return new self($message, $statusCode, $code, self::statusIsRetryable($statusCode));
    }

    /**
     * A successful response whose body was not a JSON object.
     */
    public static function invalidResponse(string $message, int $statusCode = 0): self
    {
        return new self($message, $statusCode, 'invalid_response', false);
    }

    /**
     * A request the SDK refuses to send, such as a missing required
     * `Idempotency-Key`. No HTTP request is performed.
     */
    public static function clientError(string $message, string $code = 'invalid_input'): self
    {
        return new self($message, 0, $code, false);
    }

    /**
     * Retryable statuses per API-SPEC.md: transient worker/gateway outages.
     */
    public static function statusIsRetryable(int $statusCode): bool
    {
        return $statusCode === 500 || $statusCode === 502 || $statusCode === 503;
    }

    /**
     * HTTP status returned by the API, or 0 for transport failures.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * The API error code, e.g. `api_key_invalid`, `rental_not_found`,
     * `worker_offline`. Null when the response carried no envelope.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * True for 500/502/503 responses and for transport failures/timeouts;
     * false for every 4xx, which will not get better by repeating the request.
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    private static function summarise(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return 'the server returned no response body';
        }

        return strlen($trimmed) > 200 ? substr($trimmed, 0, 200) . '...' : $trimmed;
    }
}
