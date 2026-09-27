<?php

declare(strict_types=1);

namespace ClickClacks\Transport;

/** One POST to the API, exactly as it goes over the wire. */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers,
        /** The body as sent: JSON, or gzip bytes when `Content-Encoding: gzip` is set. */
        public readonly string $body,
        /** Seconds before the request is abandoned. */
        public readonly float $timeout,
    ) {}
}
