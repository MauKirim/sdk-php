<?php

declare(strict_types=1);

namespace MauKirim\Tests\Support;

/**
 * A request captured by {@see FakeTransport}.
 */
final class RecordedRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
    ) {
    }

    /**
     * Case-insensitive header lookup.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode((string) $this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function urlPath(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return is_string($path) ? $path : $this->url;
    }

    public function urlQuery(): string
    {
        $query = parse_url($this->url, PHP_URL_QUERY);

        return is_string($query) ? $query : '';
    }
}
