<?php

declare(strict_types=1);

namespace MauKirim;

/**
 * Verification of the `x-maukirim-signature` header MauKirim sends with every
 * webhook delivery.
 *
 * The signature is `sha256=<hex>`: HMAC-SHA256 over the **raw request body**
 * keyed by the webhook secret. Always verify before parsing the JSON, and always
 * verify against the raw bytes you received — re-encoding the payload changes it.
 *
 * ```php
 * $result = \MauKirim\WebhookSignature::verify(
 *     file_get_contents('php://input'),
 *     $_SERVER['HTTP_X_MAUKIRIM_SIGNATURE'] ?? null,
 *     $webhookSecret,
 * );
 * if (!$result) { http_response_code(400); exit; }
 * ```
 *
 * Deliveries are at-least-once and retries reuse the same `x-maukirim-delivery`
 * id, so deduplicate on that header.
 */
final class WebhookSignature
{
    private const PREFIX = 'sha256=';

    /**
     * @param string      $rawBody         The exact bytes of the request body.
     * @param string|null $signatureHeader Value of `x-maukirim-signature`; null when the header is absent.
     * @param string      $secret          The webhook secret (64 hex characters).
     *
     * @return bool True only when the header is well formed and the digest matches.
     */
    public static function verify(string $rawBody, ?string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === null) {
            return false;
        }

        $header = trim($signatureHeader);

        if (strncasecmp($header, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return false;
        }

        $presented = substr($header, strlen(self::PREFIX));

        // A SHA-256 digest is exactly 64 hexadecimal characters; anything else
        // is malformed and never worth comparing.
        if (strlen($presented) !== 64) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($presented));
    }
}
