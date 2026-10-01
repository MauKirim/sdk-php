<?php

declare(strict_types=1);

namespace MauKirim\Tests\Support;

use MauKirim\Transport\Response;
use MauKirim\Transport\TransportInterface;

/**
 * In-memory transport: records every request and replays a scripted queue of
 * responses (or throws scripted exceptions). No network access, ever.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<RecordedRequest> */
    public array $requests = [];

    /** @var list<Response|\Throwable> */
    private array $queue = [];

    public function push(Response $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function pushException(\Throwable $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function pushJson(array $data, int $status = 200): self
    {
        return $this->push(new Response($status, (string) json_encode($data)));
    }

    public function request(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->requests[] = new RecordedRequest($method, $url, $headers, $body);

        if ($this->queue === []) {
            throw new \LogicException('FakeTransport queue is empty; push a response first.');
        }

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function count(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): RecordedRequest
    {
        if ($this->requests === []) {
            throw new \LogicException('FakeTransport recorded no request.');
        }

        return $this->requests[count($this->requests) - 1];
    }
}
