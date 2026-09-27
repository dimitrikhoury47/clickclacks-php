<?php

declare(strict_types=1);

namespace ClickClacks\Transport;

/** The parts of an API response the client reads. */
final class HttpResponse
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers header names in any case
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = $value;
        }
        $this->headers = $lower;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
