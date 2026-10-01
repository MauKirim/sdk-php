# MauKirim PHP SDK

[![Packagist Version](https://img.shields.io/packagist/v/maukirim/sdk.svg?label=packagist)](https://packagist.org/packages/maukirim/sdk)
[![Packagist Downloads](https://img.shields.io/packagist/dt/maukirim/sdk.svg)](https://packagist.org/packages/maukirim/sdk)
[![PHP Version](https://img.shields.io/packagist/php-v/maukirim/sdk.svg)](https://packagist.org/packages/maukirim/sdk)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

The official PHP client for [MauKirim](https://maukirim.com) — a managed WhatsApp gateway for
Indonesian businesses. With it you send WhatsApp OTP codes (`POST /otp/send`, `POST /otp/verify`),
send approved templated notifications from a shared number, and operate numbers you rent from
MauKirim: read them, set their profile and presence, send text and media from them, and manage the
webhooks that report what happened. Everything talks to the REST API at
[https://app.maukirim.com/api/v1](https://app.maukirim.com/api/v1) over HTTPS with a bearer API key,
and the SDK is a single dependency-free package (`ext-curl` + `ext-json`) that never makes a request
you did not ask for.

Requires PHP 8.1 or newer.

## Installation

```bash
composer require maukirim/sdk
```

Create an API key in the [MauKirim dashboard](https://app.maukirim.com) and expose it to your app as
an environment variable — never commit it.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use MauKirim\Client;

$client = new Client(getenv('MAUKIRIM_API_KEY'));
```

## Configuration

```php
$client = new Client('mk_live_...', [
    'base_url' => 'https://app.maukirim.com/api/v1', // default
    'timeout' => 30,                                 // seconds, cURL transport only
    'max_retries' => 2,                              // retries for retryable failures
    'user_agent' => 'my-app/2.0',
]);
```

| Option | Default | Meaning |
| --- | --- | --- |
| `base_url` | `https://app.maukirim.com/api/v1` | API root; a trailing slash is ignored. Point it at a local mock in tests. |
| `transport` | `MauKirim\Transport\CurlTransport` | Anything implementing `MauKirim\Transport\TransportInterface`. |
| `max_retries` | `2` | Extra attempts after a retryable failure. `0` disables retries. |
| `user_agent` | `maukirim-php/1.0.0` | Sent as `User-Agent`. |
| `timeout` | `30` | Total request timeout in seconds. |
| `backoff` | `[100, 300]` | Milliseconds to wait before each retry; the last value repeats. |
| `sleeper` | `usleep` | `callable(float $seconds)` used to wait between attempts; inject one to keep tests instant. |

The API key must not be empty; every other option is optional. Constructing a client performs no
request.

## Usage

### OTP

```php
use MauKirim\Exception\MauKirimException;

// 1. Send a six-digit code. The challenge expires after 5 minutes.
try {
    $challenge = $client->otp->send('+6281234567890', 'login', 'login-' . $orderId);
    // $challenge = ['ok' => true, 'challengeId' => 'chl_...', 'batchId' => '...', 'expiresAt' => '...']
    // Store $challenge['challengeId'] with the session - the code itself is never returned.
} catch (MauKirimException $e) {
    if ($e->getErrorCode() === 'rate_limited') {
        // 5 sends per hour per (account, phone): ask the user to try again later.
    }
    throw $e;
}

// 2. Verify what the user typed.
$result = $client->otp->verify($challenge['challengeId'], $codeFromUser);

if ($result['verified']) {
    // Correct code: continue the login.
} else {
    // Wrong, expired, already used, undelivered, or out of attempts (budget: 5).
}
```

A wrong code is **not** an error: `POST /otp/verify` answers `200` with `verified: false`. Only
malformed input (`400`) and unknown challenges (`404`) throw.

The `purpose` is one of `login`, `signup`, `payment`, `passwordReset`, `phoneChange`.

### Notifications

Send a notification from a shared MauKirim number using a template approved in the dashboard. The
`Idempotency-Key` must be unique per intended send: retrying the same key with the same request
replays the original result instead of sending a second message.

```php
$client->notifications->send(
    '+6281234567890',
    'tpl_order_shipped',
    [
        'name' => 'Budi',
        'tracking' => 'JNE123456',
        // A list placeholder takes 0-10 objects whose keys match the template's section.
        'items' => [
            ['item' => 'Kopi Gayo', 'qty' => '2'],
            ['item' => 'Teh Melati', 'qty' => '1'],
        ],
    ],
    null,                        // attachmentUploadId
    'order-' . $orderId . '-shipped',
);
```

Only use `attachmentUploadId` with per-send templates; upload the file first and pass the returned
`media.id` (uploads expire after 7 days):

```php
$media = $client->notifications->uploadMedia('invoice.pdf', 'application/pdf', $pdfBytes);

$client->notifications->send(
    '+6281234567890',
    'tpl_invoice',
    ['amount' => '150.000'],
    $media['media']['id'],
    'order-' . $orderId . '-invoice',
);
```

JPEG/PNG up to 5 MB; PDF/Office/CSV/TXT/ZIP up to 16 MB.

### Rented numbers

Rented numbers are the ones you operate: `list()` returns every rental with its status, and the
full `+E.164` number is only present while the rental is `active` or `past_due`.

```php
$devices = $client->devices->list();          // newest first, any status
$deviceId = $devices['devices'][0]['id'];

$detail = $client->devices->get($deviceId);   // device + rental terms + webhook settings
$client->devices->setProfile($deviceId, 'Toko Budi');            // visible display name
$client->devices->uploadAvatar($deviceId, 'logo.png', 'image/png', $pngBytes);
$client->devices->setPresence($deviceId, 'available');           // or 'unavailable'
$client->devices->setChatPresence($deviceId, '+6281234567890', 'start'); // typing indicator

// A text message (at most 4096 characters).
$batch = $client->devices->sendMessage(
    $deviceId,
    '+6281234567890',
    'Halo dari toko kami!',
    'order-' . $orderId . '-msg',
);

// An image or document with an optional caption (at most 1024 characters).
$client->devices->sendMedia(
    $deviceId,
    'invoice.pdf',
    'application/pdf',
    '+6281234567890',
    'Invoice Anda',
    $pdfBytes,
    'order-' . $orderId . '-invoice',
);
```

Sending is queued: `$batch['progress']` is a snapshot with per-item states
(`queued` → `sending` → `accepted`/`failed`, plus `uncertain`) and `terminal`. There is no batch
status endpoint — the final state per recipient arrives by webhook. Replaying an `Idempotency-Key`
returns the original batch with `created: false`.

### Webhooks

Every rented number can forward its events to one HTTPS destination. The signing secret is returned
**once**, when the destination is created or when you rotate it, so store it immediately:

```php
// Create (or edit) the destination. events: [] subscribes to every event.
$created = $client->webhooks->set(
    $deviceId,
    'https://example.com/hooks/maukirim',
    ['message', 'message.ack', 'call.offer'],
);
file_put_contents('/etc/secrets/maukirim-webhook', $created['secret']); // only on creation!

// Read it back later - the secret is never returned again.
$current = $client->webhooks->get($deviceId);
$events  = $current['availableEvents']; // everything the deployment can emit
$wired   = $current['gatewayWired'];    // false while the number is disconnected

// Issue a new secret (the old one stops verifying immediately).
$rotated = $client->webhooks->rotate($deviceId);

// Inspect what MauKirim delivered and what your endpoint answered.
$page = $client->webhooks->deliveries($deviceId, 50, 0); // limit 1-100 (default 25), offset >= 0
foreach ($page['deliveries'] as $delivery) {
    if ($delivery['state'] === 'exhausted') {
        error_log('webhook gave up: ' . $delivery['errorCode']);
    }
}
if ($page['hasMore']) {
    // fetch the next page with an offset of count($deliveries)
}

$client->webhooks->remove($deviceId); // deletes the destination and its delivery log
```

### Verifying webhook signatures

MauKirim signs the **raw request body** with the webhook secret: HMAC-SHA256, hex, prefixed
`sha256=`. Always verify before trusting the payload, and always compare against the raw bytes you
received — re-encoding the JSON changes them.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use MauKirim\WebhookSignature;

$secret = file_get_contents('/etc/secrets/maukirim-webhook');
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_MAUKIRIM_SIGNATURE'] ?? null;

if (!WebhookSignature::verify($rawBody, $signature, $secret)) {
    http_response_code(400);
    exit;
}

$event = json_decode($rawBody, true);

// Deliveries are at-least-once, and retries reuse the same delivery id: deduplicate on it.
if (alreadyProcessed($event['delivery_id'])) {
    http_response_code(200);
    exit;
}

handleEvent($event['event'], $event['device_id'], $event['payload']);
http_response_code(200); // any 2xx acknowledges; 3xx counts as a failure
```

`verify()` returns `false` for a missing, malformed or mismatched signature, and compares with
`hash_equals()` in constant time.

## Errors

Every failure — a non-2xx response or a transport failure — throws
`MauKirim\Exception\MauKirimException`, which extends `RuntimeException`:

```php
use MauKirim\Exception\MauKirimException;

try {
    $client->devices->sendMessage($deviceId, $phone, $message, $idempotencyKey);
} catch (MauKirimException $e) {
    error_log($e->getMessage());        // "HTTP 409: device_not_connected"
    $code = $e->getErrorCode();         // "device_not_connected", or null when the body had no envelope
    $status = $e->getStatusCode();      // 409, or 0 when the request never reached the API
    $retry = $e->isRetryable();         // true for 500/502/503 and transport failures
}
```

| HTTP | code | What to do |
| --- | --- | --- |
| 400 | `invalid_input` | Fix the request (missing field, wrong content type, body over 8 KB, missing or > 128-character `Idempotency-Key`, variable mismatch). |
| 401 | `api_key_invalid`, `api_key_revoked`, `api_key_expired` | Replace the API key. |
| 402 | `insufficient_credits` | Top up the account balance. |
| 403 | `api_key_scope_denied`, `forbidden` | Give the key the route's scope, or fix the account. |
| 404 | `rental_not_found`, `device_not_found`, `gateway_not_found`, `webhook_not_configured`, `not_found` | The record does not exist for this account. |
| 409 | `batch_idempotency_conflict` | Same key, different request: use a new key. |
| 409 | `device_not_connected`, `gateway_disabled`, `rental_device_not_ready` | The number cannot send right now. |
| 410 | `media_unavailable` | The upload id is unknown, expired (7 days) or belongs to another account. |
| 413 | `batch_too_large` | Shorten the text (> 4096), caption (> 1024) or idempotency key (> 128). |
| 429 | `rate_limited` | Too many OTP sends for the number; retry later, not immediately. |
| 500 | `server_error` | Retryable; the SDK retries once. |
| 502, 503 | `worker_offline` | Transient; the SDK retries. |

Missing or empty values the SDK requires (the `Idempotency-Key` on the three sending routes) and
invalid options raise before any request is made, as
`MauKirimException` (`code: invalid_input`, status `0`) and `InvalidArgumentException` respectively.

## Retries

The SDK retries `500`, `502`, `503` and transport failures (DNS, TLS, refused connection, timeout)
up to `max_retries` times, waiting `backoff` (100 ms, then 300 ms) between attempts and honouring no
hidden state: the same headers — including the **same `Idempotency-Key`** — are replayed, so the API
deduplicates the retry instead of sending twice.

Retries only happen when repeating the request is safe: safe methods (`GET`, `HEAD`) always retry,
any other method retries only when it carries an `Idempotency-Key`. A keyless `POST`/`PATCH`/`DELETE`
(for example `webhooks->rotate()`) is never repeated automatically, because a second call would
rotate a second secret or send a second message. Retry pacing — and everything else — is injectable:

```php
$client = new Client('mk_live_...', [
    'transport' => new MyRecordingTransport(),
    'backoff' => [0, 0],
    'sleeper' => static fn (float $seconds) => null,
]);
```

Implement `MauKirim\Transport\TransportInterface` to plug in your own client (Guzzle, PSR-18,
Symfony HttpClient, a fake in tests). The SDK encodes JSON and `multipart/form-data` bodies itself,
so a transport only has to send a method, a URL, headers and a fully encoded body, then return a
`MauKirim\Transport\Response`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite never touches the network: every test drives the client through the fake transport in
`tests/Support`, which records requests and replays scripted responses.

## Publishing

The package is published on [Packagist](https://packagist.org/) as `maukirim/sdk`:

1. Submit the repository once at [https://packagist.org/packages/submit](https://packagist.org/packages/submit)
   with the URL `https://github.com/MauKirim/sdk-php`. Packagist reads `composer.json` (name
   `maukirim/sdk`, PSR-4 `MauKirim\` → `src/`, `php >=8.1`) and lists every tag it finds.
2. Enable the Packagist webhook — either the GitHub integration Packagist offers when submitting, or
   a repository webhook pointing at your Packagist API token — so every new tag is published
   automatically. Otherwise press "Update" on the Packagist page after tagging.
3. Release with a semver tag:

   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```

   Composer resolves version constraints from the tags (`^1.0` for 1.x), so no version field belongs
   in `composer.json`. Breaking changes go to a new major tag (`v2.0.0`) and are installed with
   `maukirim/sdk:^2.0`; the package name never changes. Pre-releases use tags like `v1.1.0-rc1`.

Consumers then install with `composer require maukirim/sdk:^1.0`.

## License

MIT — see [LICENSE](LICENSE).
