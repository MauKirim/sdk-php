<?php

declare(strict_types=1);

namespace MauKirim\Tests;

use MauKirim\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_0123456789abcdef0123456789abcdef';

    private const BODY = '{"event":"message.delivered","delivery_id":"dlv_1","payload":{"id":"msg_1"}}';

    private static function signatureFor(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function testAcceptsACorrectlySignedRequestBody(): void
    {
        self::assertTrue(WebhookSignature::verify(self::BODY, self::signatureFor(self::BODY), self::SECRET));
    }

    public function testRejectsASignatureMadeWithAnotherSecret(): void
    {
        $signature = self::signatureFor(self::BODY, 'whsec_other');

        self::assertFalse(WebhookSignature::verify(self::BODY, $signature, self::SECRET));
    }

    public function testRejectsATamperedBody(): void
    {
        $signature = self::signatureFor(self::BODY);

        self::assertFalse(WebhookSignature::verify(self::BODY . ' ', $signature, self::SECRET));
        self::assertFalse(WebhookSignature::verify('{"event":"message.delivered"}', $signature, self::SECRET));
    }

    public function testRejectsAMissingHeader(): void
    {
        self::assertFalse(WebhookSignature::verify(self::BODY, null, self::SECRET));
        self::assertFalse(WebhookSignature::verify(self::BODY, '', self::SECRET));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedSignatures(): iterable
    {
        $hex = hash_hmac('sha256', self::BODY, self::SECRET);

        yield 'no prefix' => [$hex];
        yield 'wrong algorithm' => ['sha1=' . $hex];
        yield 'empty digest' => ['sha256='];
        yield 'prefix only with whitespace' => ['sha256=   '];
        yield 'non hex digest' => ['sha256=' . str_repeat('z', 64)];
        yield 'truncated digest' => ['sha256=' . substr($hex, 0, 32)];
        yield 'digest with extra characters' => ['sha256=' . $hex . 'ff'];
        yield 'raw bytes instead of hex' => ['sha256=' . hash_hmac('sha256', self::BODY, self::SECRET, true)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSignatures')]
    public function testRejectsMalformedSignatures(string $signature): void
    {
        self::assertFalse(WebhookSignature::verify(self::BODY, $signature, self::SECRET));
    }

    public function testAcceptsAnUpperCaseDigest(): void
    {
        $signature = 'sha256=' . strtoupper(hash_hmac('sha256', self::BODY, self::SECRET));

        self::assertTrue(WebhookSignature::verify(self::BODY, $signature, self::SECRET));
    }

    public function testAcceptsAnUpperCaseScheme(): void
    {
        $signature = 'SHA256=' . hash_hmac('sha256', self::BODY, self::SECRET);

        self::assertTrue(WebhookSignature::verify(self::BODY, $signature, self::SECRET));
    }

    public function testTrimsSurroundingWhitespaceInTheHeader(): void
    {
        self::assertTrue(WebhookSignature::verify(self::BODY, '  ' . self::signatureFor(self::BODY) . '  ', self::SECRET));
    }

    public function testVerifiesBinaryBodiesByteForByte(): void
    {
        $binary = random_bytes(256);
        $signature = self::signatureFor($binary);

        self::assertTrue(WebhookSignature::verify($binary, $signature, self::SECRET));
        self::assertFalse(WebhookSignature::verify($binary . "\x00", $signature, self::SECRET));
    }

    public function testAnEmptyBodyIsStillVerified(): void
    {
        self::assertTrue(WebhookSignature::verify('', self::signatureFor(''), self::SECRET));
        self::assertFalse(WebhookSignature::verify('', self::signatureFor('{}'), self::SECRET));
    }
}
