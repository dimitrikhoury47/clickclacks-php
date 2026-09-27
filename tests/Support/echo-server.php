<?php

declare(strict_types=1);

// A router for `php -S` used by TransportTest: echoes the request back as JSON.
$uri = $_SERVER['REQUEST_URI'] ?? '/';
if (str_starts_with($uri, '/slow')) {
    sleep(1);
}
$body = (string) file_get_contents('php://input');
if (($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip') {
    $body = (string) gzdecode($body);
}
$decoded = json_decode($body, true);
http_response_code(202);
header('Content-Type: application/json');
header('Retry-After: 7');
echo json_encode([
    'uri' => $uri,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'body' => $body,
    'accepted' => is_array($decoded['items'] ?? null) ? count($decoded['items']) : 0,
    'errors' => [],
]);
