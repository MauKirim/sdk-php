<?php

declare(strict_types=1);

namespace MauKirim\Tests;

use MauKirim\Client;
use MauKirim\Exception\MauKirimException;
use MauKirim\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class OtpServiceTest extends TestCase
{
    private const API_KEY = 'mk_live_test_key';

    private function client(FakeTransport $transport): Client
    {
        return new Client(self::API_KEY, [
            'transport' => $transport,
            'sleeper' => static function (float $seconds): void {
            },
        ]);
    }

    public function testSendPostsTheExactJsonBodyWithTheIdempotencyKey(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson([
            'ok' => true,
            'challengeId' => 'chl_1',
            'batchId' => 'bat_1',
            'expiresAt' => '2026-01-01T00:05:00.000Z',
        ]);

        $result = $this->client($transport)->otp->send('+6281234567890', 'login', 'idem-otp-1');

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/otp/send', $request->urlPath());
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('idem-otp-1', $request->header('Idempotency-Key'));
        self::assertSame(['phone' => '+6281234567890', 'purpose' => 'login'], $request->json());

        self::assertSame([
            'ok' => true,
            'challengeId' => 'chl_1',
            'batchId' => 'bat_1',
            'expiresAt' => '2026-01-01T00:05:00.000Z',
        ], $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function purposes(): iterable
    {
        yield 'login' => ['login'];
        yield 'signup' => ['signup'];
        yield 'payment' => ['payment'];
        yield 'passwordReset' => ['passwordReset'];
        yield 'phoneChange' => ['phoneChange'];
    }

    /**
     * @param string $purpose
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('purposes')]
    public function testSendPassesEveryDocumentedPurposeThrough(string $purpose): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'challengeId' => 'chl_1']);

        $this->client($transport)->otp->send('+6281234567890', $purpose, 'idem-otp-2');

        self::assertSame($purpose, $transport->lastRequest()->json()['purpose']);
    }

    public function testSendWithoutAnIdempotencyKeyFailsBeforeAnyRequestIsMade(): void
    {
        $transport = new FakeTransport();

        try {
            $this->client($transport)->otp->send('+6281234567890', 'login', '');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('invalid_input', $exception->getErrorCode());
            self::assertSame(0, $exception->getStatusCode());
            self::assertFalse($exception->isRetryable());
            self::assertStringContainsString('Idempotency-Key', $exception->getMessage());
        }

        self::assertSame(0, $transport->count());
    }

    public function testVerifyPostsChallengeAndCodeWithoutAnIdempotencyKey(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'verified' => true]);

        $result = $this->client($transport)->otp->verify('chl_1', '123456');

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/otp/verify', $request->urlPath());
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertNull($request->header('Idempotency-Key'));
        self::assertSame(['challengeId' => 'chl_1', 'code' => '123456'], $request->json());

        self::assertSame(['ok' => true, 'verified' => true], $result);
    }

    public function testVerifyReturnsVerifiedFalseForAWrongCodeWithoutThrowing(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'verified' => false]);

        $result = $this->client($transport)->otp->verify('chl_1', '000000');

        self::assertFalse($result['verified']);
    }

    public function testVerifySurfacesAnUnknownChallengeAsAnApiError(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'not_found'], 404);

        try {
            $this->client($transport)->otp->verify('chl_missing', '123456');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('not_found', $exception->getErrorCode());
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    public function testRateLimitedOtpSendIsNotRetried(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'rate_limited'], 429);
        $transport->pushJson(['ok' => true, 'challengeId' => 'chl_2']);

        try {
            $this->client($transport)->otp->send('+6281234567890', 'login', 'idem-otp-3');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('rate_limited', $exception->getErrorCode());
            self::assertFalse($exception->isRetryable());
        }

        self::assertSame(1, $transport->count());
    }
}
