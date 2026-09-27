<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Unit;

use ClickClacks\ClickClacksError;
use ClickClacks\Client;
use ClickClacks\Transport\CurlTransport;
use ClickClacks\Transport\HttpRequest;
use ClickClacks\Transport\Psr18Transport;
use ClickClacks\Transport\TransportException;
use PHPUnit\Framework\TestCase;

/**
 * The real transports against a local PHP server that echoes what it received.
 */
final class TransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $host = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $router = __DIR__ . '/../Support/echo-server.php';
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            self::markTestSkipped('Could not start the PHP built-in server');
        }
        self::$server = $process;
        self::$host = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 100; ++$i) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(20_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function testCurlSendsHeadersAndBodyAndReadsTheResponse(): void
    {
        $transport = new CurlTransport();
        $response = $transport->send(new HttpRequest(self::$host . '/api/v1/batch?validate=true', [
            'Authorization' => 'Bearer cks_live_x',
            'Content-Type' => 'application/json',
        ], '{"items":[]}', 5));
        self::assertSame(202, $response->status);
        self::assertSame('7', $response->header('Retry-After'));
        $echo = json_decode($response->body, true);
        self::assertSame('/api/v1/batch?validate=true', $echo['uri']);
        self::assertSame('Bearer cks_live_x', $echo['authorization']);
        self::assertSame('{"items":[]}', $echo['body']);
        // The handle is reused for the next request.
        self::assertSame(202, $transport->send(new HttpRequest(self::$host . '/', [], '{}', 5))->status);
    }

    public function testCurlTimesOutAsATransportException(): void
    {
        $this->expectException(TransportException::class);
        (new CurlTransport())->send(new HttpRequest(self::$host . '/slow', [], '{}', 0.2));
    }

    public function testCurlReportsARefusedConnectionAsATransportException(): void
    {
        $this->expectException(TransportException::class);
        (new CurlTransport())->send(new HttpRequest('http://127.0.0.1:' . self::freePort() . '/', [], '{}', 2));
    }

    public function testTheClientGzipsEndToEnd(): void
    {
        $errors = [];
        $client = new Client([
            'key' => 'cks_live_x',
            'host' => self::$host,
            'autoFlush' => false,
            'onError' => static function (ClickClacksError $e) use (&$errors): void {
                $errors[] = $e;
            },
        ]);
        $client->track(['event' => 'Invoice paid', 'distinctId' => 'user_8412', 'properties' => ['note' => str_repeat('clack ', 500)]]);
        $result = $client->flush();
        self::assertSame([], $errors);
        self::assertSame(1, $result->accepted);
    }

    public function testPsr18SendsThroughGuzzle(): void
    {
        $transport = new Psr18Transport(new \GuzzleHttp\Client(['http_errors' => false, 'timeout' => 5]));
        $response = $transport->send(new HttpRequest(self::$host . '/api/v1/batch', ['Authorization' => 'Bearer cks_live_y'], '{"items":[1]}', 5));
        self::assertSame(202, $response->status);
        $echo = json_decode($response->body, true);
        self::assertSame('Bearer cks_live_y', $echo['authorization']);
        self::assertSame('{"items":[1]}', $echo['body']);
        self::assertSame('7', $response->header('retry-after'));
    }

    public function testPsr18NetworkErrorsBecomeTransportExceptions(): void
    {
        $this->expectException(TransportException::class);
        $transport = new Psr18Transport(new \GuzzleHttp\Client(['timeout' => 1]));
        $transport->send(new HttpRequest('http://127.0.0.1:' . self::freePort() . '/', [], '{}', 1));
    }

    public function testTheClientTakesAPsr18ClientAsAnOption(): void
    {
        $client = new Client([
            'key' => 'cks_live_x',
            'host' => self::$host,
            'autoFlush' => false,
            'httpClient' => new \GuzzleHttp\Client(['http_errors' => false]),
            'onError' => static fn() => null,
        ]);
        $client->identify(['distinctId' => 'user_8412']);
        self::assertSame(1, $client->flush()->accepted);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
