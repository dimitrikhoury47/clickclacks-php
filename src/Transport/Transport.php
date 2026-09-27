<?php

declare(strict_types=1);

namespace ClickClacks\Transport;

/**
 * Sends one request. Implement it to route requests through your own HTTP stack, or to
 * fake the API in tests.
 */
interface Transport
{
    /**
     * @throws TransportException when no response arrived (network error or timeout)
     */
    public function send(HttpRequest $request): HttpResponse;
}
