<?php

declare(strict_types=1);

namespace MauKirim\Tests;

use MauKirim\Client;
use MauKirim\Exception\MauKirimException;
use MauKirim\Service\DeviceService;
use MauKirim\Service\NotificationService;
use MauKirim\Service\OtpService;
use MauKirim\Service\WebhookService;
use MauKirim\Tests\Support\FakeTransport;
use MauKirim\Transport\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const API_KEY = 'mk_live_test_key';

    /** @var list<float> */
    private array $slept = [];

    /**
     * @param array<string, mixed> $options
     */
    private function client(FakeTransport $transport, array $options = []): Client
    {
        $this->slept = [];

        return new Client(self::API_KEY, $options + [
            'transport' => $transport,
            'sleeper' => function (float $seconds): void {
                $this->slept[] = $seconds;
            },
        ]);
    }

    public function testUsesDefaultBaseUrlAndAuthorizationHeader(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'devices' => []]);

        $body = $this->client($transport)->devices->list();

        self::assertSame(['ok' => true, 'devices' => []], $body);
        self::assertSame(1, $transport->count());

        $request = $transport->lastRequest();
        self::assertSame('GET', $request->method);
        self::assertSame('https://app.maukirim.com/api/v1/devices', $request->url);
        self::assertSame('Bearer ' . self::API_KEY, $request->header('Authorization'));
        self::assertSame('application/json', $request->header('Accept'));
    }

    public function testCustomBaseUrlTrailingSlashIsStripped(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'devices' => []]);

        $this->client($transport, ['base_url' => 'https://example.test/api/v1/'])->devices->list();

        self::assertSame('https://example.test/api/v1/devices', $transport->lastRequest()->url);
    }

    public function testEmptyBaseUrlFallsBackToTheProductionUrl(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'devices' => []]);

        $this->client($transport, ['base_url' => ''])->devices->list();

        self::assertSame('https://app.maukirim.com/api/v1/devices', $transport->lastRequest()->url);
    }

    public function testDefaultUserAgentIsSent(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'devices' => []]);

        $this->client($transport)->devices->list();

        self::assertStringStartsWith('maukirim-php/', (string) $transport->lastRequest()->header('User-Agent'));
    }

    public function testUserAgentCanBeOverridden(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'devices' => []]);

        $this->client($transport, ['user_agent' => 'my-app/2.0'])->devices->list();

        self::assertSame('my-app/2.0', $transport->lastRequest()->header('User-Agent'));
    }

    public function testErrorEnvelopeIsDecodedIntoAnException(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'api_key_invalid'], 401);

        try {
            $this->client($transport)->devices->list();
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('api_key_invalid', $exception->getErrorCode());
            self::assertSame(401, $exception->getStatusCode());
            self::assertFalse($exception->isRetryable());
            self::assertStringContainsString('api_key_invalid', $exception->getMessage());
        }

        self::assertSame(1, $transport->count());
    }

    public function testNonJsonErrorBodyStillYieldsStatusCode(): void
    {
        $transport = new FakeTransport();
        $transport->push(new Response(502, '<html>bad gateway</html>'));

        try {
            $this->client($transport, ['max_retries' => 0])->devices->list();
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertNull($exception->getErrorCode());
            self::assertSame(502, $exception->getStatusCode());
            self::assertTrue($exception->isRetryable());
        }

        self::assertSame(1, $transport->count());
    }

    public function testSafeMethodsAreRetriedWithoutAnIdempotencyKey(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'worker_offline'], 503);
        $transport->pushJson(['ok' => false, 'code' => 'worker_offline'], 503);
        $transport->pushJson(['ok' => true, 'devices' => [['id' => 'dev_1']]]);

        $body = $this->client($transport)->devices->list();

        self::assertSame([['id' => 'dev_1']], $body['devices']);
        self::assertSame(3, $transport->count());
        self::assertEquals([0.1, 0.3], $this->slept);

        foreach ($transport->requests as $request) {
            self::assertNull($request->header('Idempotency-Key'));
            self::assertSame('GET', $request->method);
        }
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statusRetryability(): iterable
    {
        yield '400 invalid_input' => [400, false];
        yield '401 unauthorized' => [401, false];
        yield '402 insufficient_credits' => [402, false];
        yield '403 forbidden' => [403, false];
        yield '404 not_found' => [404, false];
        yield '409 conflict' => [409, false];
        yield '410 media_unavailable' => [410, false];
        yield '413 batch_too_large' => [413, false];
        yield '429 rate_limited' => [429, false];
        yield '500 server_error' => [500, true];
        yield '502 bad gateway' => [502, true];
        yield '503 worker_offline' => [503, true];
    }

    #[DataProvider('statusRetryability')]
    public function testRetryabilityMatrix(int $status, bool $expected): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'server_error'], $status);
        $transport->pushJson(['ok' => true, 'devices' => []]);

        try {
            $this->client($transport, ['max_retries' => 0])->devices->list();
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame($expected, $exception->isRetryable(), 'status ' . $status);
            self::assertSame($status, $exception->getStatusCode());
        }
    }

    public function testRetries503WithBackoffAndReusesTheIdempotencyKey(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'worker_offline'], 503);
        $transport->pushJson(['ok' => false, 'code' => 'worker_offline'], 503);
        $transport->pushJson(['ok' => true, 'challengeId' => 'chl_1']);

        $result = $this->client($transport)->otp->send('+6281234567890', 'login', 'idem-key-1');

        self::assertSame('chl_1', $result['challengeId']);
        self::assertSame(3, $transport->count());
        self::assertEquals([0.1, 0.3], $this->slept);

        foreach ($transport->requests as $request) {
            self::assertSame('idem-key-1', $request->header('Idempotency-Key'));
        }
    }

    public function testMaxRetriesOptionLimitsAttempts(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'worker_offline'], 503);
        $transport->pushJson(['ok' => false, 'code' => 'worker_offline'], 503);

        try {
            $this->client($transport, ['max_retries' => 1])->otp->send('+6281234567890', 'login', 'idem-key-2');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame(503, $exception->getStatusCode());
        }

        self::assertSame(2, $transport->count());
    }

    public function testZeroMaxRetriesMeansASingleAttempt(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'worker_offline'], 503);

        try {
            $this->client($transport, ['max_retries' => 0])->otp->send('+6281234567890', 'login', 'idem-key-3');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException) {
            // expected
        }

        self::assertSame(1, $transport->count());
        self::assertSame([], $this->slept);
    }

    public function testTransportFailuresAreRetriedAndThenRethrown(): void
    {
        $transport = new FakeTransport();
        $transport->pushException(MauKirimException::transport('Could not connect to host.'));
        $transport->pushException(MauKirimException::transport('Operation timed out.'));
        $transport->pushJson(['ok' => true, 'devices' => []]);

        $body = $this->client($transport)->devices->list();

        self::assertSame([], $body['devices']);
        self::assertSame(3, $transport->count());
        self::assertEquals([0.1, 0.3], $this->slept);
    }

    public function testExhaustedTransportFailuresSurfaceAsRetryableException(): void
    {
        $transport = new FakeTransport();
        $transport->pushException(MauKirimException::transport('Operation timed out.'));
        $transport->pushException(MauKirimException::transport('Operation timed out.'));
        $transport->pushException(MauKirimException::transport('Operation timed out.'));

        try {
            $this->client($transport)->devices->list();
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame(0, $exception->getStatusCode());
            self::assertTrue($exception->isRetryable());
        }

        self::assertSame(3, $transport->count());
    }

    public function testNonRetryableErrorsAreNotRetried(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'invalid_input'], 400);
        $transport->pushJson(['ok' => true, 'challengeId' => 'chl_1']);

        try {
            $this->client($transport)->otp->send('+6281234567890', 'login', 'idem-key-4');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('invalid_input', $exception->getErrorCode());
        }

        self::assertSame(1, $transport->count());
        self::assertSame([], $this->slept);
    }

    public function testKeylessMutationsAreNotRetried(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'worker_offline'], 503);
        $transport->pushJson(['ok' => true, 'type' => 'available']);

        try {
            $this->client($transport)->devices->setPresence('dev_1', 'available');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertTrue($exception->isRetryable());
            self::assertSame(503, $exception->getStatusCode());
        }

        self::assertSame(1, $transport->count());
        self::assertSame([], $this->slept);
    }

    public function testInvalidJsonOnSuccessIsAnError(): void
    {
        $transport = new FakeTransport();
        $transport->push(new Response(200, 'this is not json'));

        try {
            $this->client($transport)->devices->list();
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('invalid_response', $exception->getErrorCode());
            self::assertSame(200, $exception->getStatusCode());
            self::assertFalse($exception->isRetryable());
        }

        self::assertSame(1, $transport->count());
    }

    public function testEmptyApiKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client('');
    }

    public function testCustomBackoffScheduleIsHonoured(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'worker_offline'], 503);
        $transport->pushJson(['ok' => false, 'code' => 'worker_offline'], 503);
        $transport->pushJson(['ok' => true, 'devices' => []]);

        $this->client($transport, ['backoff' => [5, 50]])->devices->list();

        self::assertEquals([0.005, 0.05], $this->slept);
        self::assertSame(3, $transport->count());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'base_url' => [['base_url' => 123]];
        yield 'transport' => [['transport' => 'curl']];
        yield 'max_retries' => [['max_retries' => '2']];
        yield 'user_agent' => [['user_agent' => 5]];
        yield 'timeout' => [['timeout' => '30']];
        yield 'backoff' => [['backoff' => 'fast']];
        yield 'sleeper' => [['sleeper' => ['not', 'callable']]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client(self::API_KEY, $options);
    }

    public function testServicesAreExposedAsReadonlyProperties(): void
    {
        $client = $this->client(new FakeTransport());

        self::assertInstanceOf(OtpService::class, $client->otp);
        self::assertInstanceOf(NotificationService::class, $client->notifications);
        self::assertInstanceOf(DeviceService::class, $client->devices);
        self::assertInstanceOf(WebhookService::class, $client->webhooks);
        self::assertSame($client->otp, $client->otp);
    }
}
