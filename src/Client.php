<?php

declare(strict_types=1);

namespace MauKirim;

use MauKirim\Exception\MauKirimException;
use MauKirim\Service\DeviceService;
use MauKirim\Service\NotificationService;
use MauKirim\Service\OtpService;
use MauKirim\Service\WebhookService;
use MauKirim\Transport\CurlTransport;
use MauKirim\Transport\MultipartBody;
use MauKirim\Transport\TransportInterface;

/**
 * The MauKirim API client.
 *
 * MauKirim (https://maukirim.com) is a managed WhatsApp gateway: it sends OTP
 * codes and templated notifications from a shared number, and rents managed
 * WhatsApp numbers you operate over REST.
 *
 * ```php
 * $client = new \MauKirim\Client(getenv('MAUKIRIM_API_KEY'));
 * $client->otp->send('+6281234567890', 'login', 'order-91f2-login-1');
 * ```
 *
 * Constructing a client never performs a request; the API key is only checked
 * for being non-empty.
 */
final class Client
{
    /** Base URL used when the `base_url` option is omitted or empty. */
    public const DEFAULT_BASE_URL = 'https://app.maukirim.com/api/v1';

    /** `User-Agent` used when the `user_agent` option is omitted. */
    public const DEFAULT_USER_AGENT = 'maukirim-php/1.0.0';

    /** Number of retries applied to retryable failures when `max_retries` is omitted. */
    public const DEFAULT_MAX_RETRIES = 2;

    /** Backoff, in milliseconds, applied before each retry. */
    public const DEFAULT_BACKOFF_MILLISECONDS = [100, 300];

    /** OTP challenges: `POST /otp/send`, `POST /otp/verify`. */
    public readonly OtpService $otp;

    /** Templated notifications from a shared number: `POST /messages/send`, `POST /messages/media`. */
    public readonly NotificationService $notifications;

    /** Rented WhatsApp numbers: listing, profile, presence and sending. */
    public readonly DeviceService $devices;

    /** Per-device webhook destinations, secrets and delivery log. */
    public readonly WebhookService $webhooks;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly TransportInterface $transport;

    private readonly int $maxRetries;

    private readonly string $userAgent;

    /** @var list<int> */
    private readonly array $backoffMilliseconds;

    /** @var \Closure(float): void */
    private readonly \Closure $sleeper;

    /**
     * @param string $apiKey  API key, e.g. `mk_live_...`. Must not be empty.
     * @param array{
     *     base_url?: string|null,
     *     transport?: TransportInterface|null,
     *     max_retries?: int|null,
     *     user_agent?: string|null,
     *     timeout?: int|null,
     *     backoff?: list<int>|null,
     *     sleeper?: callable(float): void|null
     * } $options
     *
     * `base_url` defaults to {@see Client::DEFAULT_BASE_URL}; a trailing slash is
     * ignored. `timeout` (seconds, default 30) configures the default cURL
     * transport only. `backoff` (milliseconds) and `sleeper` exist so tests can
     * observe retry pacing without waiting.
     *
     * @throws \InvalidArgumentException When the API key is empty or an option has the wrong type.
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('A MauKirim API key is required.');
        }

        $this->apiKey = $apiKey;

        $baseUrl = $options['base_url'] ?? null;

        if ($baseUrl !== null && !is_string($baseUrl)) {
            throw new \InvalidArgumentException('The "base_url" option must be a string.');
        }

        $baseUrl = trim((string) $baseUrl);
        $this->baseUrl = rtrim($baseUrl === '' ? self::DEFAULT_BASE_URL : $baseUrl, '/');

        $timeout = $options['timeout'] ?? null;

        if ($timeout !== null && !is_int($timeout)) {
            throw new \InvalidArgumentException('The "timeout" option must be an integer number of seconds.');
        }

        $transport = $options['transport'] ?? null;

        if ($transport !== null && !$transport instanceof TransportInterface) {
            throw new \InvalidArgumentException(
                'The "transport" option must implement ' . TransportInterface::class . '.',
            );
        }

        $this->transport = $transport ?? new CurlTransport($timeout ?? 30);

        $maxRetries = $options['max_retries'] ?? null;

        if ($maxRetries !== null && !is_int($maxRetries)) {
            throw new \InvalidArgumentException('The "max_retries" option must be an integer.');
        }

        $this->maxRetries = max(0, $maxRetries ?? self::DEFAULT_MAX_RETRIES);

        $userAgent = $options['user_agent'] ?? null;

        if ($userAgent !== null && !is_string($userAgent)) {
            throw new \InvalidArgumentException('The "user_agent" option must be a string.');
        }

        $this->userAgent = ($userAgent === null || $userAgent === '')
            ? self::DEFAULT_USER_AGENT
            : $userAgent;

        $backoff = $options['backoff'] ?? self::DEFAULT_BACKOFF_MILLISECONDS;

        if (!is_array($backoff)) {
            throw new \InvalidArgumentException('The "backoff" option must be a list of milliseconds.');
        }

        $this->backoffMilliseconds = array_values(array_map(static fn ($ms): int => max(0, (int) $ms), $backoff));

        $sleeper = $options['sleeper'] ?? null;

        if ($sleeper !== null && !is_callable($sleeper)) {
            throw new \InvalidArgumentException('The "sleeper" option must be callable.');
        }

        $this->sleeper = $sleeper === null
            ? static function (float $seconds): void {
                usleep((int) round($seconds * 1_000_000));
            }
            : \Closure::fromCallable($sleeper);

        $this->otp = new OtpService($this);
        $this->notifications = new NotificationService($this);
        $this->devices = new DeviceService($this);
        $this->webhooks = new WebhookService($this);
    }

    /**
     * Performs an API call and decodes the JSON response body.
     *
     * This is the single place where headers, retries, error decoding and JSON
     * decoding live; the resource services are thin wrappers around it.
     *
     * @param string $method  HTTP method.
     * @param string $path    Path below the base URL, starting with `/`.
     * @param array{
     *     query?: array<string, string|int>|null,
     *     json?: array<string, mixed>|null,
     *     multipart?: list<array{name: string, contents: string, filename?: string|null, contentType?: string|null}>|null,
     *     idempotency_key?: string|null,
     *     idempotency_required?: bool
     * } $options
     *
     * @return array<string, mixed> The decoded response body.
     *
     * @throws MauKirimException On transport failure, non-2xx response, undecodable
     *                           body, or a missing required `Idempotency-Key`.
     *
     * @internal Used by the resource services; not part of the public contract.
     */
    public function call(string $method, string $path, array $options = []): array
    {
        $method = strtoupper($method);

        $idempotencyKey = $options['idempotency_key'] ?? null;

        if ($idempotencyKey !== null && !is_string($idempotencyKey)) {
            throw new \InvalidArgumentException('The "idempotency_key" option must be a string.');
        }

        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        if (($options['idempotency_required'] ?? false) && $idempotencyKey === null) {
            throw MauKirimException::clientError(sprintf(
                'An Idempotency-Key is required for %s %s; pass a unique key (1-128 characters).',
                $method,
                $path,
            ));
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $body = null;

        if (isset($options['multipart']) && $options['multipart'] !== null) {
            $multipart = MultipartBody::encode($options['multipart']);
            $body = $multipart->body;
            $headers['Content-Type'] = $multipart->contentType;
        } elseif (isset($options['json']) && $options['json'] !== null) {
            $encoded = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                throw MauKirimException::clientError(
                    'The request body could not be encoded as JSON: ' . json_last_error_msg(),
                );
            }

            $body = $encoded;
            $headers['Content-Type'] = 'application/json';
        }

        $url = $this->baseUrl . $path;

        /** @var array<string, string|int> $query */
        $query = $options['query'] ?? [];

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->send($method, $url, $headers, $body, $idempotencyKey);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     *
     * @throws MauKirimException
     */
    private function send(string $method, string $url, array $headers, ?string $body, ?string $idempotencyKey): array
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->transport->request($method, $url, $headers, $body);
            } catch (MauKirimException $exception) {
                if (!$exception->isRetryable() || !$this->canRetry($method, $idempotencyKey, $attempt)) {
                    throw $exception;
                }

                $this->backOff($attempt);
                $attempt++;

                continue;
            }

            if ($response->isSuccessful()) {
                $decoded = json_decode($response->body, true);

                if (!is_array($decoded)) {
                    throw MauKirimException::invalidResponse(sprintf(
                        'Expected a JSON object from %s %s, got: %s',
                        $method,
                        $url,
                        $response->body === '' ? '(empty body)' : substr(trim($response->body), 0, 200),
                    ), $response->statusCode);
                }

                return $decoded;
            }

            $exception = MauKirimException::fromResponse($response->statusCode, $response->body);

            if (!$exception->isRetryable() || !$this->canRetry($method, $idempotencyKey, $attempt)) {
                throw $exception;
            }

            $this->backOff($attempt);
            $attempt++;
        }
    }

    /**
     * Retries are only safe when repeating the request cannot duplicate a side
     * effect: safe methods (`GET`/`HEAD`), or any request carrying an
     * `Idempotency-Key`, which the API replays as the original result.
     */
    private function canRetry(string $method, ?string $idempotencyKey, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        return $idempotencyKey !== null || $method === 'GET' || $method === 'HEAD';
    }

    private function backOff(int $attempt): void
    {
        $milliseconds = $this->backoffMilliseconds[$attempt] ?? ($this->backoffMilliseconds === []
            ? 0
            : $this->backoffMilliseconds[count($this->backoffMilliseconds) - 1]);

        ($this->sleeper)($milliseconds / 1000);
    }
}
