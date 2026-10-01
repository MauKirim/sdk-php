<?php

declare(strict_types=1);

namespace MauKirim\Service;

/**
 * WhatsApp OTP challenges (scope `otp`).
 */
final class OtpService extends AbstractService
{
    /**
     * Sends a six-digit OTP to a phone number.
     *
     * `POST /otp/send` — scope `otp`, `Idempotency-Key` required. The code is
     * never returned: it lives 5 minutes and is stored hashed. Reusing the same
     * key with the same request replays the original challenge without a second
     * charge; reusing it with a *different* request is a `409
     * batch_idempotency_conflict`.
     *
     * @param string $phone           Recipient in E.164 form, e.g. `+6281234567890`.
     * @param string $purpose         One of `login`, `signup`, `payment`, `passwordReset`, `phoneChange`.
     * @param string $idempotencyKey  Unique key, 1-128 characters.
     *
     * @return array{ok: true, challengeId: string, batchId: string, expiresAt: string}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function send(string $phone, string $purpose, string $idempotencyKey): array
    {
        return $this->client->call('POST', '/otp/send', [
            'json' => ['phone' => $phone, 'purpose' => $purpose],
            'idempotency_key' => $idempotencyKey,
            'idempotency_required' => true,
        ]);
    }

    /**
     * Verifies a code against a challenge.
     *
     * `POST /otp/verify` — scope `otp`, no `Idempotency-Key`. A wrong, expired,
     * already-used or out-of-attempts code is a **200 response with
     * `verified: false`**, not an error; a wrong guess still consumes one of the
     * five attempts. Only malformed input (400) and unknown challenges (404)
     * raise.
     *
     * @param string $challengeId Value of `challengeId` from {@see OtpService::send()}.
     * @param string $code        The six-digit code the user entered.
     *
     * @return array{ok: true, verified: bool}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function verify(string $challengeId, string $code): array
    {
        return $this->client->call('POST', '/otp/verify', [
            'json' => ['challengeId' => $challengeId, 'code' => $code],
        ]);
    }
}
