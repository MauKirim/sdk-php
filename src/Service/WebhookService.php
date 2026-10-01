<?php

declare(strict_types=1);

namespace MauKirim\Service;

/**
 * Per-device webhook destinations, signing secrets and the delivery log
 * (scope `webhook`).
 *
 * A destination receives every event it subscribes to; the signing secret is
 * returned **once**, when it is created or rotated, and never again. Verify
 * deliveries with {@see \MauKirim\WebhookSignature::verify()}.
 */
final class WebhookService extends AbstractService
{
    /** Deliveries returned when no limit is given. */
    public const DEFAULT_LIMIT = 25;

    /** Largest page the API accepts. */
    public const MAX_LIMIT = 100;

    /**
     * Reads the destination configured for one rented number.
     *
     * `GET /devices/{id}/webhook` — scope `webhook`. Never returns the signing
     * secret; `webhook` is null when nothing is configured, while
     * `availableEvents` and `gatewayWired` still describe the deployment.
     *
     * @return array{
     *     ok: true,
     *     webhook: array{id: string, deviceId: string, url: string, events: list<string>, active: bool}|null,
     *     availableEvents: list<string>,
     *     gatewayWired: bool
     * }
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function get(string $deviceId): array
    {
        return $this->client->call('GET', '/devices/' . rawurlencode($deviceId) . '/webhook');
    }

    /**
     * Creates or edits the destination for one rented number.
     *
     * `POST /devices/{id}/webhook` — scope `webhook`. The `url` must be public
     * HTTPS (no credentials, query, fragment, IP literal or localhost). On
     * creation the response carries the signing secret; an edit keeps the
     * existing secret and omits it.
     *
     * @param string       $url    Destination URL.
     * @param list<string> $events Events to subscribe to; an empty list means every event.
     * @param bool|null    $active Explicitly activate (`true`) or deactivate (`false`) the
     *                             destination. Null leaves it untouched, which is what the API
     *                             does by default (creates active).
     *
     * @return array{ok: true, webhook: array<string, mixed>, secret?: string}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function set(string $deviceId, string $url, array $events = [], ?bool $active = null): array
    {
        $body = ['url' => $url];

        if ($events !== []) {
            $body['events'] = array_values($events);
        }

        if ($active !== null) {
            $body['active'] = $active;
        }

        return $this->client->call('POST', '/devices/' . rawurlencode($deviceId) . '/webhook', ['json' => $body]);
    }

    /**
     * Issues a new signing secret for one rented number.
     *
     * `PUT /devices/{id}/webhook` — scope `webhook`. The old secret stops
     * verifying immediately. Answers `404 webhook_not_configured` when the
     * device has no destination.
     *
     * @return array{ok: true, webhook: array<string, mixed>, secret: string}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function rotate(string $deviceId): array
    {
        return $this->client->call('PUT', '/devices/' . rawurlencode($deviceId) . '/webhook');
    }

    /**
     * Deletes the destination and its delivery log.
     *
     * `DELETE /devices/{id}/webhook` — scope `webhook`.
     *
     * @return array{ok: true, removed: true}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function remove(string $deviceId): array
    {
        return $this->client->call('DELETE', '/devices/' . rawurlencode($deviceId) . '/webhook');
    }

    /**
     * Reads what MauKirim forwarded, and what the destination answered.
     *
     * `GET /webhook-deliveries` — scope `webhook`. A blank device id is
     * ignored, `limit` is clamped to 1-100 (default 25) and `offset` to at
     * least 0 (default 0).
     *
     * @return array{
     *     ok: true,
     *     deliveries: list<array{
     *         id: string,
     *         deviceId: string,
     *         event: string,
     *         state: 'queued'|'failed'|'delivered'|'exhausted',
     *         attempts: int,
     *         responseStatus: int|null,
     *         errorCode: string|null,
     *         payload: array<string, mixed>,
     *         createdAt: string,
     *         completedAt: string|null
     *     }>,
     *     hasMore: bool
     * }
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function deliveries(?string $deviceId = null, ?int $limit = null, ?int $offset = null): array
    {
        $query = [];

        if ($deviceId !== null && trim($deviceId) !== '') {
            $query['deviceId'] = $deviceId;
        }

        $query['limit'] = self::clampLimit($limit);
        $query['offset'] = max(0, $offset ?? 0);

        return $this->client->call('GET', '/webhook-deliveries', ['query' => $query]);
    }

    private static function clampLimit(?int $limit): int
    {
        if ($limit === null) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
