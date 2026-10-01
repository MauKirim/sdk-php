<?php

declare(strict_types=1);

namespace MauKirim\Tests\Transport;

use MauKirim\Transport\MultipartBody;
use PHPUnit\Framework\TestCase;

final class MultipartBodyTest extends TestCase
{
    public function testEncodesAFilePartAndAFieldPart(): void
    {
        $multipart = MultipartBody::encode([
            ['name' => 'file', 'contents' => 'PNGDATA', 'filename' => 'logo.png', 'contentType' => 'image/png'],
            ['name' => 'phone', 'contents' => '+6281234567890'],
        ], 'BOUNDARY');

        $expected = "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"logo.png\"\r\n"
            . "Content-Type: image/png\r\n"
            . "\r\n"
            . "PNGDATA\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"phone\"\r\n"
            . "\r\n"
            . "+6281234567890\r\n"
            . "--BOUNDARY--\r\n";

        self::assertSame($expected, $multipart->body);
        self::assertSame('multipart/form-data; boundary=BOUNDARY', $multipart->contentType);
        self::assertSame('BOUNDARY', $multipart->boundary);
    }

    public function testGeneratesARandomHexBoundaryByDefault(): void
    {
        $first = MultipartBody::encode([['name' => 'a', 'contents' => 'b']]);
        $second = MultipartBody::encode([['name' => 'a', 'contents' => 'b']]);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first->boundary);
        self::assertNotSame($first->boundary, $second->boundary);
        self::assertStringContainsString('--' . $first->boundary . '--', $first->body);
    }

    public function testEscapesQuotesInFileNamesAndFieldNames(): void
    {
        $multipart = MultipartBody::encode([
            ['name' => 'we"ird', 'contents' => 'x', 'filename' => 'a"b.txt'],
        ], 'B');

        self::assertStringContainsString('name="we\\"ird"', $multipart->body);
        self::assertStringContainsString('filename="a\\"b.txt"', $multipart->body);
    }

    public function testRejectsHeaderInjectionThroughNames(): void
    {
        $multipart = MultipartBody::encode([
            ['name' => "evil\r\nX-Injected: 1", 'contents' => 'x', 'filename' => "f\r\ng.txt"],
        ], 'B');

        self::assertStringNotContainsString("\r\nX-Injected", $multipart->body);
        self::assertStringContainsString('name="evilX-Injected: 1"', $multipart->body);
        self::assertStringContainsString('filename="fg.txt"', $multipart->body);
    }

    public function testPassesBinaryContentsThroughByteForByte(): void
    {
        $bytes = random_bytes(64);
        $multipart = MultipartBody::encode([['name' => 'file', 'contents' => $bytes]], 'B');

        self::assertStringContainsString($bytes, $multipart->body);
        self::assertSame(strlen($bytes), substr_count($multipart->body, $bytes) * strlen($bytes));
    }
}
