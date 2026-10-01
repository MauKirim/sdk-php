<?php

declare(strict_types=1);

namespace MauKirim\Service;

/**
 * Rented WhatsApp numbers (scopes `read`, `device`, `send`).
 *
 * A rental is reachable only while its status is `active` or `past_due`; any
 * other status answers `404 rental_not_found`. Sending is queued: the response
 * carries a `progress` snapshot and the final per-recipient state arrives later
 * by webhook.
 */
final class DeviceService extends AbstractService
{
    /**
     * Lists every number the account rents, newest first, in any status.
     *
     * `GET /devices` — scope `read`, no pagination. The `number` field is a full
     * `+E.164` value only while `status` is `active` or `past_due`; otherwise it
     * is masked and `pushName` is null, `hasAvatar` is false and every
     * `connection` field is null.
     *
     * @return array{ok: true, devices: list<array{
     *     id: string,
     *     label: string,
     *     number: string,
     *     plan: string,
     *     status: string,
     *     term: string,
     *     currency: string,
     *     priceMinor: int,
     *     autoRenew: bool,
     *     currentPeriodEnd: string,
     *     pushName: string|null,
     *     hasAvatar: bool,
     *     connection: array{
     *         state: string|null,
     *         isConnected: bool|null,
     *         isLoggedIn: bool|null,
     *         missingSince: string|null
     *     }|null
     * }>}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function list(): array
    {
        return $this->client->call('GET', '/devices');
    }

    /**
     * Fetches one rented number with its rental terms and webhook settings.
     *
     * `GET /devices/{id}` — scope `read`. The signing secret is never returned;
     * `webhook` is null when no destination is configured.
     *
     * @return array{ok: true, device: array<string, mixed>, rental: array<string, mixed>, webhook: array<string, mixed>|null}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function get(string $id): array
    {
        return $this->client->call('GET', '/devices/' . rawurlencode($id));
    }

    /**
     * Sets the WhatsApp-visible display name of a connected number.
     *
     * `PATCH /devices/{id}/profile` — scope `device`, JSON body. 1-64 characters.
     *
     * @return array{ok: true, pushName: string}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function setProfile(string $id, string $pushName): array
    {
        return $this->client->call('PATCH', '/devices/' . rawurlencode($id) . '/profile', [
            'json' => ['pushName' => $pushName],
        ]);
    }

    /**
     * Replaces the profile photo of a connected number.
     *
     * `PATCH /devices/{id}/profile` — scope `device`, `multipart/form-data` with
     * an `avatar` part (JPEG/PNG up to 5 MiB). There is no remove-photo operation.
     *
     * @param string $fileName    Name of the file being uploaded.
     * @param string $contentType `image/jpeg` or `image/png`.
     * @param string $bytes       Raw image bytes.
     *
     * @return array{ok: true, hasAvatar: true}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function uploadAvatar(string $id, string $fileName, string $contentType, string $bytes): array
    {
        return $this->client->call('PATCH', '/devices/' . rawurlencode($id) . '/profile', [
            'multipart' => [
                [
                    'name' => 'avatar',
                    'contents' => $bytes,
                    'filename' => $fileName,
                    'contentType' => $contentType,
                ],
            ],
        ]);
    }

    /**
     * Shows the number as available or unavailable on WhatsApp.
     *
     * `POST /devices/{id}/presence` — scope `device`. The device must be connected.
     *
     * @param string $presence `available` or `unavailable`.
     *
     * @return array{ok: true, type: string}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function setPresence(string $id, string $presence): array
    {
        return $this->client->call('POST', '/devices/' . rawurlencode($id) . '/presence', [
            'json' => ['type' => $presence],
        ]);
    }

    /**
     * Starts or stops the typing indicator for one recipient.
     *
     * `POST /devices/{id}/chat-presence` — scope `device`. An immediate gateway
     * action: no `Idempotency-Key` and no charge.
     *
     * @param string $phone  Recipient in E.164 form (7-15 digits).
     * @param string $action `start` or `stop`.
     *
     * @return array{ok: true, action: string}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function setChatPresence(string $id, string $phone, string $action): array
    {
        return $this->client->call('POST', '/devices/' . rawurlencode($id) . '/chat-presence', [
            'json' => ['phone' => $phone, 'action' => $action],
        ]);
    }

    /**
     * Queues a text message from the rented number.
     *
     * `POST /devices/{id}/messages` — scope `send`, `Idempotency-Key` required,
     * message at most 4096 characters. Replaying a key returns the original
     * batch with `created: false`; there is no batch status endpoint, so final
     * delivery state arrives by webhook.
     *
     * @param string $phone          Recipient in E.164 form.
     * @param string $message        Text, 1-4096 characters.
     * @param string $idempotencyKey Unique key, 1-128 characters.
     *
     * @return array{
     *     ok: true,
     *     batchId: string,
     *     created: bool,
     *     media?: array{id: string},
     *     progress: array{
     *         batchId: string,
     *         total: int,
     *         counts: array<string, int>,
     *         terminal: bool,
     *         items: list<array<string, mixed>>
     *     }
     * }
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function sendMessage(string $id, string $phone, string $message, string $idempotencyKey): array
    {
        return $this->client->call('POST', '/devices/' . rawurlencode($id) . '/messages', [
            'json' => ['phone' => $phone, 'message' => $message],
            'idempotency_key' => $idempotencyKey,
            'idempotency_required' => true,
        ]);
    }

    /**
     * Queues an image or document from the rented number.
     *
     * `POST /devices/{id}/messages` — scope `send`, `Idempotency-Key` required,
     * `multipart/form-data` with a `file` part (image up to 5 MiB, document up to
     * 16 MiB), `phone`, and an optional `caption` of at most 1024 characters.
     *
     * @param string      $fileName       Name of the file being uploaded.
     * @param string      $contentType    Part content type, e.g. `image/jpeg`.
     * @param string      $phone          Recipient in E.164 form.
     * @param string|null $caption        Caption, at most 1024 characters; null/empty sends none.
     * @param string      $bytes          Raw file contents.
     * @param string      $idempotencyKey Unique key, 1-128 characters.
     *
     * @return array{ok: true, batchId: string, created: bool, media?: array{id: string}, progress: array<string, mixed>}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function sendMedia(
        string $id,
        string $fileName,
        string $contentType,
        string $phone,
        ?string $caption,
        string $bytes,
        string $idempotencyKey,
    ): array {
        $parts = [
            [
                'name' => 'file',
                'contents' => $bytes,
                'filename' => $fileName,
                'contentType' => $contentType,
            ],
            [
                'name' => 'phone',
                'contents' => $phone,
            ],
        ];

        if ($caption !== null && $caption !== '') {
            $parts[] = [
                'name' => 'caption',
                'contents' => $caption,
            ];
        }

        return $this->client->call('POST', '/devices/' . rawurlencode($id) . '/messages', [
            'multipart' => $parts,
            'idempotency_key' => $idempotencyKey,
            'idempotency_required' => true,
        ]);
    }
}
