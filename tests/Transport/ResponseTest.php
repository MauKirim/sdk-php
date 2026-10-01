<?php

declare(strict_types=1);

namespace MauKirim\Tests\Transport;

use MauKirim\Transport\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    #[DataProvider('statuses')]
    public function testIsSuccessfulCoversTheWholeTwoHundredRange(int $status, bool $expected): void
    {
        self::assertSame($expected, (new Response($status))->isSuccessful());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statuses(): iterable
    {
        yield '199' => [199, false];
        yield '200' => [200, true];
        yield '204' => [204, true];
        yield '299' => [299, true];
        yield '300' => [300, false];
        yield '404' => [404, false];
        yield '503' => [503, false];
    }

    public function testItExposesStatusBodyAndHeaders(): void
    {
        $response = new Response(200, '{"ok":true}', ['Content-Type' => 'application/json', 'X-Trace' => 'abc']);

        self::assertSame(200, $response->statusCode);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame('abc', $response->headers['X-Trace']);
    }
}
