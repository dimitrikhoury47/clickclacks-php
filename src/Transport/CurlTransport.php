<?php

declare(strict_types=1);

namespace ClickClacks\Transport;

/** The default transport: ext-curl, reusing one handle so connections stay alive. */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    public function __construct(
        /** Seconds to wait for a connection. */
        private readonly float $connectTimeout = 5.0,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $url = $request->url;
        if ($url === '') {
            throw new TransportException('No URL to send to');
        }
        if ($this->handle === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new TransportException('curl_init failed');
            }
            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }
        $handle = $this->handle;

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        // cURL adds "Expect: 100-continue" to large bodies, which costs a round trip.
        $headers[] = 'Expect:';

        $responseHeaders = [];
        $timeoutMs = max(1, (int) ceil($request->timeout * 1000));
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, max(1, (int) ceil($this->connectTimeout * 1000))),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return \strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        if (!\is_string($body)) {
            $message = curl_error($handle);
            $errno = curl_errno($handle);
            // A failed handle can hold a broken connection; start fresh next time.
            $this->handle = null;
            throw new TransportException($message !== '' ? $message : 'cURL error ' . $errno);
        }
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        /** @var array<string, string> $responseHeaders */
        return new HttpResponse(\is_int($status) ? $status : 0, $responseHeaders, $body);
    }
}
