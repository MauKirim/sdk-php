<?php

declare(strict_types=1);

namespace MauKirim\Tests;

use MauKirim\Exception\MauKirimException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MauKirimExceptionTest extends TestCase
{
    public function testItIsARuntimeException(): void
    {
        $exception = new MauKirimException('boom');

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame(0, $exception->getStatusCode());
        self::assertNull($exception->getErrorCode());
        self::assertFalse($exception->isRetryable());
    }

    public function testFromResponseCarriesTheEnvelopeCode(): void
    {
        $exception = MauKirimException::fromResponse(403, '{"ok":false,"code":"api_key_scope_denied"}');

        self::assertSame('api_key_scope_denied', $exception->getErrorCode());
        self::assertSame(403, $exception->getStatusCode());
        self::assertStringContainsString('api_key_scope_denied', $exception->getMessage());
        self::assertFalse($exception->isRetryable());
    }

    public function testFromResponseWithoutACodeFallsBackToTheBody(): void
    {
        $exception = MauKirimException::fromResponse(418, 'teapot');

        self::assertNull($exception->getErrorCode());
        self::assertSame(418, $exception->getStatusCode());
        self::assertStringContainsString('teapot', $exception->getMessage());
        self::assertFalse($exception->isRetryable());
    }

    public function testFromResponseIgnoresANonStringCode(): void
    {
        $exception = MauKirimException::fromResponse(500, '{"ok":false,"code":123}');

        self::assertNull($exception->getErrorCode());
        self::assertTrue($exception->isRetryable());
    }

    public function testFromResponseWithAnEmptyBodyExplainsItself(): void
    {
        $exception = MauKirimException::fromResponse(500, '');

        self::assertStringContainsString('no response body', $exception->getMessage());
    }

    public function testFromResponseTruncatesHugeBodies(): void
    {
        $exception = MauKirimException::fromResponse(400, str_repeat('x', 5000));

        self::assertLessThan(300, strlen($exception->getMessage()));
        self::assertStringEndsWith('...', $exception->getMessage());
    }

    #[DataProvider('retryableStatuses')]
    public function testStatusRetryability(int $status, bool $expected): void
    {
        self::assertSame($expected, MauKirimException::statusIsRetryable($status));
        self::assertSame($expected, MauKirimException::fromResponse($status, '')->isRetryable());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function retryableStatuses(): iterable
    {
        yield '200' => [200, false];
        yield '400' => [400, false];
        yield '401' => [401, false];
        yield '404' => [404, false];
        yield '408' => [408, false];
        yield '429' => [429, false];
        yield '500' => [500, true];
        yield '501' => [501, false];
        yield '502' => [502, true];
        yield '503' => [503, true];
        yield '504' => [504, false];
    }

    public function testTransportFailuresAreRetryableAndCarryNoStatus(): void
    {
        $exception = MauKirimException::transport('cURL error 28: Operation timed out');

        self::assertSame(0, $exception->getStatusCode());
        self::assertNull($exception->getErrorCode());
        self::assertTrue($exception->isRetryable());
        self::assertStringContainsString('timed out', $exception->getMessage());
    }

    public function testClientErrorsAreNotRetryable(): void
    {
        $exception = MauKirimException::clientError('An Idempotency-Key is required for POST /otp/send.');

        self::assertSame('invalid_input', $exception->getErrorCode());
        self::assertSame(0, $exception->getStatusCode());
        self::assertFalse($exception->isRetryable());
    }

    public function testInvalidResponsesReportTheStatusTheyCameWith(): void
    {
        $exception = MauKirimException::invalidResponse('Expected a JSON object', 200);

        self::assertSame('invalid_response', $exception->getErrorCode());
        self::assertSame(200, $exception->getStatusCode());
        self::assertFalse($exception->isRetryable());
    }

    public function testThePreviousFailureIsPreserved(): void
    {
        $previous = new \RuntimeException('low level');
        $exception = MauKirimException::transport('wrapped', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
