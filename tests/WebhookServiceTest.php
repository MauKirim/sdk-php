<?php

declare(strict_types=1);

namespace MauKirim\Tests;

use MauKirim\Client;
use MauKirim\Exception\MauKirimException;
use MauKirim\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class WebhookServiceTest extends TestCase
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

    public function testGetFetchesTheDestinationAndTheAvailableEvents(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson([
            'ok' => true,
            'webhook' => [
                'id' => 'wh_1',
                'deviceId' => 'dev_1',
                'url' => 'https://example.test/hooks/maukirim',
                'events' => ['message', 'message.ack'],
                'active' => true,
            ],
            'availableEvents' => ['message', 'message.ack', 'call.offer'],
            'gatewayWired' => true,
        ]);

        $result = $this->client($transport)->webhooks->get('dev_1');

        $request = $transport->lastRequest();
        self::assertSame('GET', $request->method);
        self::assertSame('/api/v1/devices/dev_1/webhook', $request->urlPath());
        self::assertSame('', $request->urlQuery());
        self::assertSame('wh_1', $result['webhook']['id']);
        self::assertSame(['message', 'message.ack'], $result['webhook']['events']);
        self::assertTrue($result['gatewayWired']);
        self::assertContains('call.offer', $result['availableEvents']);
    }

    public function testGetSurfacesAnUnreachableRentalAsRentalNotFound(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'rental_not_found'], 404);

        try {
            $this->client($transport)->webhooks->get('dev_9');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('rental_not_found', $exception->getErrorCode());
        }
    }

    public function testSetSendsOnlyTheUrlWhenNoEventsOrActiveFlagAreGiven(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson([
            'ok' => true,
            'webhook' => ['id' => 'wh_1', 'deviceId' => 'dev_1', 'url' => 'https://example.test/hooks', 'events' => [], 'active' => true],
            'secret' => str_repeat('a', 64),
        ]);

        $result = $this->client($transport)->webhooks->set('dev_1', 'https://example.test/hooks');

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/devices/dev_1/webhook', $request->urlPath());
        self::assertSame(['url' => 'https://example.test/hooks'], $request->json());
        self::assertNull($request->header('Idempotency-Key'));

        self::assertSame(str_repeat('a', 64), $result['secret']);
        self::assertSame('wh_1', $result['webhook']['id']);
    }

    public function testSetSendsEventsAndAnExplicitlyFalseActiveFlag(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'webhook' => ['id' => 'wh_1']]);

        $this->client($transport)->webhooks->set('dev_1', 'https://example.test/hooks', ['message', 'call.offer'], false);

        self::assertSame([
            'url' => 'https://example.test/hooks',
            'events' => ['message', 'call.offer'],
            'active' => false,
        ], $transport->lastRequest()->json());
    }

    public function testSetSendsAnExplicitlyTrueActiveFlag(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'webhook' => ['id' => 'wh_1']]);

        $this->client($transport)->webhooks->set('dev_1', 'https://example.test/hooks', [], true);

        self::assertSame([
            'url' => 'https://example.test/hooks',
            'active' => true,
        ], $transport->lastRequest()->json());
    }

    public function testAnEditDoesNotReturnASecret(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'webhook' => ['id' => 'wh_1', 'active' => false]]);

        $result = $this->client($transport)->webhooks->set('dev_1', 'https://example.test/hooks', [], false);

        self::assertArrayNotHasKey('secret', $result);
    }

    public function testRotatePutsToTheWebhookRoute(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson([
            'ok' => true,
            'webhook' => ['id' => 'wh_1', 'deviceId' => 'dev_1', 'url' => 'https://example.test/hooks', 'events' => [], 'active' => true],
            'secret' => str_repeat('b', 64),
        ]);

        $result = $this->client($transport)->webhooks->rotate('dev_1');

        $request = $transport->lastRequest();
        self::assertSame('PUT', $request->method);
        self::assertSame('/api/v1/devices/dev_1/webhook', $request->urlPath());
        self::assertNull($request->body);
        self::assertSame(str_repeat('b', 64), $result['secret']);
    }

    public function testRotateWithoutAConfiguredWebhookIsANotFound(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => false, 'code' => 'webhook_not_configured'], 404);

        try {
            $this->client($transport)->webhooks->rotate('dev_1');
            self::fail('Expected MauKirimException.');
        } catch (MauKirimException $exception) {
            self::assertSame('webhook_not_configured', $exception->getErrorCode());
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    public function testRemoveDeletesTheDestination(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'removed' => true]);

        $result = $this->client($transport)->webhooks->remove('dev_1');

        $request = $transport->lastRequest();
        self::assertSame('DELETE', $request->method);
        self::assertSame('/api/v1/devices/dev_1/webhook', $request->urlPath());
        self::assertTrue($result['removed']);
    }

    public function testDeliveriesSendsTheClampedDefaults(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'deliveries' => [], 'hasMore' => false]);

        $result = $this->client($transport)->webhooks->deliveries();

        $request = $transport->lastRequest();
        self::assertSame('GET', $request->method);
        self::assertSame('/api/v1/webhook-deliveries', $request->urlPath());
        self::assertSame('limit=25&offset=0', $request->urlQuery());
        self::assertSame([], $result['deliveries']);
        self::assertFalse($result['hasMore']);
    }

    public function testDeliveriesSendsTheDeviceAndTheGivenWindow(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'deliveries' => [], 'hasMore' => true]);

        $this->client($transport)->webhooks->deliveries('dev_1', 50, 100);

        self::assertSame('deviceId=dev_1&limit=50&offset=100', $transport->lastRequest()->urlQuery());
    }

    /**
     * @return iterable<string, array{string|null, int|null, int|null, string}>
     */
    public static function deliveryQueries(): iterable
    {
        yield 'blank device id is ignored' => ['', null, null, 'limit=25&offset=0'];
        yield 'limit above the maximum is clamped' => [null, 500, null, 'limit=100&offset=0'];
        yield 'limit below the minimum is clamped' => [null, 0, null, 'limit=1&offset=0'];
        yield 'negative limit falls back to the minimum' => [null, -3, null, 'limit=1&offset=0'];
        yield 'exact maximum is kept' => [null, 100, null, 'limit=100&offset=0'];
        yield 'exact minimum is kept' => [null, 1, null, 'limit=1&offset=0'];
        yield 'negative offset becomes zero' => [null, null, -5, 'limit=25&offset=0'];
        yield 'zero offset is kept' => [null, null, 0, 'limit=25&offset=0'];
        yield 'plain offset is kept' => [null, null, 42, 'limit=25&offset=42'];
        yield 'device id with a space is encoded' => ['dev 1', null, null, 'deviceId=dev%201&limit=25&offset=0'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deliveryQueries')]
    public function testDeliveryQueryEncodingAndClamping(?string $deviceId, ?int $limit, ?int $offset, string $expected): void
    {
        $transport = new FakeTransport();
        $transport->pushJson(['ok' => true, 'deliveries' => [], 'hasMore' => false]);

        $this->client($transport)->webhooks->deliveries($deviceId, $limit, $offset);

        self::assertSame($expected, $transport->lastRequest()->urlQuery());
    }

    public function testDeliveriesDecodesTheDeliveryLog(): void
    {
        $transport = new FakeTransport();
        $transport->pushJson([
            'ok' => true,
            'deliveries' => [
                [
                    'id' => 'dlv_1',
                    'deviceId' => 'dev_1',
                    'event' => 'message',
                    'state' => 'delivered',
                    'attempts' => 1,
                    'responseStatus' => 200,
                    'errorCode' => null,
                    'payload' => ['id' => 'msg_1'],
                    'createdAt' => '2026-01-01T00:00:00.000Z',
                    'completedAt' => '2026-01-01T00:00:01.000Z',
                ],
            ],
            'hasMore' => false,
        ]);

        $result = $this->client($transport)->webhooks->deliveries('dev_1');

        self::assertSame('delivered', $result['deliveries'][0]['state']);
        self::assertSame('message', $result['deliveries'][0]['event']);
        self::assertSame(['id' => 'msg_1'], $result['deliveries'][0]['payload']);
    }
}
