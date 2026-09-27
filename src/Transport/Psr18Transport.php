<?php

declare(strict_types=1);

namespace ClickClacks\Transport;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends through a PSR-18 client, such as Guzzle or Symfony HttpClient. Timeouts are the
 * client's own; configure them there.
 */
final class Psr18Transport implements Transport
{
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    public function __construct(
        private readonly ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $requestFactory ??= $client instanceof RequestFactoryInterface ? $client : null;
        $streamFactory ??= $client instanceof StreamFactoryInterface ? $client : null;
        $requestFactory ??= self::discover(RequestFactoryInterface::class);
        $streamFactory ??= self::discover(StreamFactoryInterface::class);
        if (!$requestFactory instanceof RequestFactoryInterface || !$streamFactory instanceof StreamFactoryInterface) {
            throw new \InvalidArgumentException(
                'ClickClacks: a PSR-18 client needs PSR-17 request and stream factories. Pass `requestFactory` and `streamFactory`.',
            );
        }
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $psrRequest = $this->requestFactory->createRequest('POST', $request->url)
            ->withBody($this->streamFactory->createStream($request->body));
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        try {
            $response = $this->client->sendRequest($psrRequest);
        } catch (\Psr\Http\Client\ClientExceptionInterface $error) {
            throw new TransportException($error->getMessage(), 0, $error);
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return new HttpResponse($response->getStatusCode(), $headers, (string) $response->getBody());
    }

    /**
     * Finds a PSR-17 factory from the common packages, when one is installed.
     *
     * @template T of object
     *
     * @param class-string<T> $interface
     *
     * @return T|null
     */
    private static function discover(string $interface): ?object
    {
        foreach (['GuzzleHttp\Psr7\HttpFactory', 'Nyholm\Psr7\Factory\Psr17Factory', 'Laminas\Diactoros\RequestFactory'] as $class) {
            if (class_exists($class)) {
                $factory = new $class();
                if ($factory instanceof $interface) {
                    return $factory;
                }
            }
        }

        return null;
    }
}
