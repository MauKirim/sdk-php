<?php

declare(strict_types=1);

namespace MauKirim\Tests;

use MauKirim\Client;
use MauKirim\Exception\MauKirimException;
use MauKirim\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class DeviceServiceTest extends TestCase
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

    private static function boundaryOf(string $contentType): string
    {
        return substr($contentType, strlen('multipart/form-data; boundary='));
    }

    public function testListReturnsEveryDevice(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson([
            'ok' => true,
            'devices' => [
                [
                    'id' => 'dev_1',
                    'label' => 'Sales',
                    'number' => '+6281234567890',
                    'plan' => 'starter',
                    'status' => 'active',
                    'term' => 'monthly',
                    'currency' => 'IDR',
                    'priceMinor' => 15000000,
                    'autoRenew' => true,
                    'currentPeriodEnd' => '2026-02-01T00:00:00.000Z',
                    'pushName' => 'Toko Budi',
                    'hasAvatar' => true,
                    'connection' => [
                        'state' => 'open',
                        'isConnected' => true,
                        'isLoggedIn' => true,
                        'missingSince' => null,
                    ],
                ],
            ],
        ]);

        $result = $this->client($transport)->devices->list();

        self::assertSame('GET', $transport->lastRequest()->method);
        self::assertSame('/api/v1/devices', $transport->lastRequest()->urlPath());
        self::assertSame('dev_1', $result['devices'][0]['id']);
        self::assertTrue($result['devices'][0]['connection']['isConnected']);
    }

    public function testGetFetchesASingleDeviceWithItsWebhook(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson([
            'ok' => true,
            'device' => ['id' => 'dev_1', 'status' => 'active'],
            'rental' => ['id' => 'ren_1'],
            'webhook' => [
                'id' => 'wh_1',
                'deviceId' => 'dev_1',
                'url' => 'https://example.test/hooks/maukirim',
                'events' => ['message'],
                'active' => true,
            ],
        ]);

        $result = $this->client($transport)->devices->get('dev_1');

        self::assertSame('GET', $transport->lastRequest()->method);
        self::assertSame('/api/v1/devices/dev_1', $transport->lastRequest()->urlPath());
        self::assertNull($transport->lastRequest()->header('Idempotency-Key'));
        self::assertSame('dev_1', $result['device']['id']);
        self::assertSame('wh_1', $result['webhook']['id']);
        self::assertArrayNotHasKey('secret', $result['webhook']);
    }

    public function testDeviceIdsAreUrlEncoded(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'device' => []]);

        $this->client($transport)->devices->get('dev 1/2');

        self::assertSame('/api/v1/devices/dev%201%2F2', $transport->lastRequest()->urlPath());
    }

    public function testRentalNotFoundIsSurfacedVerbatim(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'rental_not_found'], 404);

        try {
            $this->client($transport)->devices->get('dev_9');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('rental_not_found', $exception->getErrorCode());
            self::assertSame(404, $exception->getStatusCode());
            self::assertFalse($exception->isRetryable());
        }
    }

    public function testSetProfilePatchesThePushName(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'pushName' => 'Toko Budi']);

        $result = $this->client($transport)->devices->setProfile('dev_1', 'Toko Budi');

        $request = $transport->lastRequest();
        self::assertSame('PATCH', $request->method);
        self::assertSame('/api/v1/devices/dev_1/profile', $request->urlPath());
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertNull($request->header('Idempotency-Key'));
        self::assertSame(['pushName' => 'Toko Budi'], $request->json());
        self::assertSame('Toko Budi', $result['pushName']);
    }

    public function testUploadAvatarPatchesAMultipartAvatarPart(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'hasAvatar' => true]);

        $result = $this->client($transport)->devices->uploadAvatar('dev_1', 'logo.png', 'image/png', 'PNGBYTES');

        $request = $transport->lastRequest();
        self::assertSame('PATCH', $request->method);
        self::assertSame('/api/v1/devices/dev_1/profile', $request->urlPath());
        self::assertStringStartsWith('multipart/form-data; boundary=', (string) $request->header('Content-Type'));

        $boundary = self::boundaryOf((string) $request->header('Content-Type'));
        self::assertSame(
            "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"logo.png\"\r\n"
            . "Content-Type: image/png\r\n"
            . "\r\n"
            . "PNGBYTES\r\n"
            . "--{$boundary}--\r\n",
            $request->body,
        );

        self::assertTrue($result['hasAvatar']);
    }

    public function testSetPresencePostsThePresenceType(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'type' => 'unavailable']);

        $result = $this->client($transport)->devices->setPresence('dev_1', 'unavailable');

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/devices/dev_1/presence', $request->urlPath());
        self::assertSame(['type' => 'unavailable'], $request->json());
        self::assertSame('unavailable', $result['type']);
    }

    public function testSetChatPresencePostsPhoneAndAction(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'action' => 'start']);

        $result = $this->client($transport)->devices->setChatPresence('dev_1', '+6281234567890', 'start');

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/devices/dev_1/chat-presence', $request->urlPath());
        self::assertNull($request->header('Idempotency-Key'));
        self::assertSame(['phone' => '+6281234567890', 'action' => 'start'], $request->json());
        self::assertSame('start', $result['action']);
    }

    public function testSendMessagePostsAJsonBodyWithTheIdempotencyKey(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson([
            'ok' => true,
            'batchId' => 'bat_1',
            'created' => true,
            'progress' => [
                'batchId' => 'bat_1',
                'total' => 1,
                'counts' => ['queued' => 1, 'sending' => 0, 'accepted' => 0, 'failed' => 0, 'uncertain' => 0, 'cancelled' => 0],
                'terminal' => false,
                'items' => [],
            ],
        ]);

        $result = $this->client($transport)->devices->sendMessage('dev_1', '+6281234567890', 'Halo!', 'idem-msg-1');

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/devices/dev_1/messages', $request->urlPath());
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('idem-msg-1', $request->header('Idempotency-Key'));
        self::assertSame(['phone' => '+6281234567890', 'message' => 'Halo!'], $request->json());

        self::assertSame('bat_1', $result['batchId']);
        self::assertTrue($result['created']);
        self::assertFalse($result['progress']['terminal']);
        self::assertSame(1, $result['progress']['counts']['queued']);
    }

    public function testSendMessageReplayReportsCreatedFalse(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson([
            'ok' => true,
            'batchId' => 'bat_1',
            'created' => false,
            'progress' => ['batchId' => 'bat_1', 'total' => 1, 'counts' => [], 'terminal' => true, 'items' => []],
        ]);

        $result = $this->client($transport)->devices->sendMessage('dev_1', '+6281234567890', 'Halo!', 'idem-msg-1');

        self::assertFalse($result['created']);
    }

    public function testSendMessageWithoutAnIdempotencyKeyFailsBeforeAnyRequestIsMade(): void
    {
        $transport = new FakeTransport();

        try {
            $this->client($transport)->devices->sendMessage('dev_1', '+6281234567890', 'Halo!', '');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('invalid_input', $exception->getErrorCode());
            self::assertStringContainsString('Idempotency-Key', $exception->getMessage());
        }

        self::assertSame(0, $transport->count());
    }

    public function testSendMediaPostsFilePhoneAndKeyWithoutACaption(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'batchId' => 'bat_2', 'created' => true, 'media' => ['id' => 'med_2']]);

        $result = $this->client($transport)->devices->sendMedia(
            'dev_1',
            'photo.jpg',
            'image/jpeg',
            '+6281234567890',
            null,
            'JPEGBYTES',
            'idem-media-1',
        );

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/devices/dev_1/messages', $request->urlPath());
        self::assertStringStartsWith('multipart/form-data; boundary=', (string) $request->header('Content-Type'));
        self::assertSame('idem-media-1', $request->header('Idempotency-Key'));

        $boundary = self::boundaryOf((string) $request->header('Content-Type'));
        self::assertSame(
            "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"photo.jpg\"\r\n"
            . "Content-Type: image/jpeg\r\n"
            . "\r\n"
            . "JPEGBYTES\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"phone\"\r\n"
            . "\r\n"
            . "+6281234567890\r\n"
            . "--{$boundary}--\r\n",
            $request->body,
        );

        self::assertSame('med_2', $result['media']['id']);
    }

    public function testSendMediaIncludesTheCaptionWhenGiven(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'batchId' => 'bat_3', 'created' => true]);

        $this->client($transport)->devices->sendMedia(
            'dev_1',
            'receipt.pdf',
            'application/pdf',
            '+6281234567890',
            'Terima kasih',
            '%PDF',
            'idem-media-2',
        );

        $body = (string) $transport->lastRequest()->body;
        self::assertStringContainsString("name=\"caption\"\r\n\r\nTerima kasih\r\n", $body);
        self::assertStringContainsString("filename=\"receipt.pdf\"", $body);
    }

    public function testSendMediaWithoutAnIdempotencyKeyFailsBeforeAnyRequestIsMade(): void
    {
        $transport = new FakeTransport();

        try {
            $this->client($transport)->devices->sendMedia('dev_1', 'a.jpg', 'image/jpeg', '+6281234567890', null, 'x', '');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('invalid_input', $exception->getErrorCode());
        }

        self::assertSame(0, $transport->count());
    }

    public function testDeviceNotConnectedIsNotRetried(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'device_not_connected'], 409);
        $transport->pushJson(['ok' => true, 'batchId' => 'bat_4', 'created' => true]);

        try {
            $this->client($transport)->devices->sendMessage('dev_1', '+6281234567890', 'Halo!', 'idem-msg-2');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('device_not_connected', $exception->getErrorCode());
            self::assertFalse($exception->isRetryable());
        }

        self::assertSame(1, $transport->count());
    }
}
