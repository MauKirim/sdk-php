<?php

declare(strict_types=1);

namespace MauKirim\Tests;

use MauKirim\Client;
use MauKirim\Exception\MauKirimException;
use MauKirim\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class NotificationServiceTest extends TestCase
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
        $transport->pushJson(['ok' => true, 'batchId' => 'bat_1']);

        $result = $this->client($transport)->notifications->send(
            '+6281234567890',
            'tpl_welcome',
            ['name' => 'Budi'],
            null,
            'idem-notif-1',
        );

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/messages/send', $request->urlPath());
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('idem-notif-1', $request->header('Idempotency-Key'));
        self::assertSame([
            'phone' => '+6281234567890',
            'templateId' => 'tpl_welcome',
            'variables' => ['name' => 'Budi'],
        ], $request->json());

        self::assertSame(['ok' => true, 'batchId' => 'bat_1'], $result);
    }

    public function testSendIncludesTheAttachmentUploadIdOnlyWhenGiven(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'batchId' => 'bat_2']);
        $transport->pushJson(['ok' => true, 'batchId' => 'bat_3']);

        $client = $this->client($transport);

        $client->notifications->send('+6281234567890', 'tpl_receipt', ['amount' => '1000'], 'med_1', 'idem-notif-2');
        self::assertSame('med_1', $transport->requests[0]->json()['attachmentUploadId']);

        $client->notifications->send('+6281234567890', 'tpl_receipt', ['amount' => '1000'], null, 'idem-notif-3');
        self::assertArrayNotHasKey('attachmentUploadId', $transport->requests[1]->json());
    }

    public function testSendKeepsListVariablesAsArraysOfObjectsInOrder(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'batchId' => 'bat_4']);

        $variables = [
            'name' => 'Budi',
            'items' => [
                ['item' => 'Kopi', 'qty' => '2'],
                ['item' => 'Teh', 'qty' => '1'],
            ],
        ];

        $this->client($transport)->notifications->send('+6281234567890', 'tpl_order', $variables, null, 'idem-4');

        self::assertSame('{"phone":"+6281234567890","templateId":"tpl_order","variables":{"name":"Budi","items":[{"item":"Kopi","qty":"2"},{"item":"Teh","qty":"1"}]}}', $transport->lastRequest()->body);
        self::assertSame($variables, $transport->lastRequest()->json()['variables']);
    }

    public function testSendEncodesEmptyVariablesAsAnEmptyJsonObject(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'batchId' => 'bat_5']);

        $this->client($transport)->notifications->send('+6281234567890', 'tpl_static', [], null, 'idem-5');

        self::assertStringContainsString('"variables":{}', (string) $transport->lastRequest()->body);
    }

    public function testSendWithoutAnIdempotencyKeyFailsBeforeAnyRequestIsMade(): void
    {
        $transport = new FakeTransport();

        try {
            $this->client($transport)->notifications->send('+6281234567890', 'tpl_welcome', [], null, '');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('invalid_input', $exception->getErrorCode());
            self::assertStringContainsString('Idempotency-Key', $exception->getMessage());
        }

        self::assertSame(0, $transport->count());
    }

    public function testSendSurfacesIdempotencyConflictsWithoutRetrying(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'batch_idempotency_conflict'], 409);
        $transport->pushJson(['ok' => true, 'batchId' => 'bat_6']);

        try {
            $this->client($transport)->notifications->send('+6281234567890', 'tpl_welcome', [], null, 'idem-6');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('batch_idempotency_conflict', $exception->getErrorCode());
            self::assertSame(409, $exception->getStatusCode());
            self::assertFalse($exception->isRetryable());
        }

        self::assertSame(1, $transport->count());
    }

    public function testUploadMediaPostsTheFileAsMultipart(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson([
            'ok' => true,
            'media' => [
                'id' => 'med_1',
                'contentType' => 'application/pdf',
                'byteSize' => 8,
                'fileName' => 'receipt.pdf',
                'kind' => 'file',
                'expiresAt' => '2026-01-08T00:00:00.000Z',
            ],
        ]);

        $result = $this->client($transport)->notifications->uploadMedia('receipt.pdf', 'application/pdf', '%PDF-1.4');

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/messages/media', $request->urlPath());
        self::assertNull($request->header('Idempotency-Key'));
        self::assertMatchesRegularExpression(
            '#^multipart/form-data; boundary=[0-9a-f]{32}$#',
            (string) $request->header('Content-Type'),
        );

        $boundary = substr((string) $request->header('Content-Type'), strlen('multipart/form-data; boundary='));
        self::assertStringContainsString('Content-Disposition: form-data; name="file"; filename="receipt.pdf"', (string) $request->body);
        self::assertStringContainsString('Content-Type: application/pdf', (string) $request->body);
        self::assertStringContainsString("%PDF-1.4\r\n", (string) $request->body);
        self::assertStringEndsWith('--' . $boundary . "--\r\n", (string) $request->body);

        self::assertSame('med_1', $result['media']['id']);
        self::assertSame('file', $result['media']['kind']);
    }

    public function testUploadMediaSurfacesAnExpiredMediaIdError(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'media_unavailable'], 410);

        try {
            $this->client($transport)->notifications->uploadMedia('a.png', 'image/png', 'png-bytes');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame(410, $exception->getStatusCode());
            self::assertSame('media_unavailable', $exception->getErrorCode());
        }
    }
}
