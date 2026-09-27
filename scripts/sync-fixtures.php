<?php

declare(strict_types=1);

// Copies the API's contract fixtures from the ClickClacks app repo into tests/fixtures/server/.
// Usage: composer fixtures:sync [-- /path/to/clickclacks]   (default: ../clickclacks-prod)
// The source is docs/api/v1/fixtures/*.json in that repo. tests/Unit/ContractTest.php replays them.

$repo = $argv[1] ?? (getenv('CLICKCLACKS_REPO') ?: dirname(__DIR__) . '/../clickclacks-prod');
$source = rtrim($repo, '/') . '/docs/api/v1/fixtures';
$target = dirname(__DIR__) . '/tests/fixtures/server';

if (!is_dir($source)) {
    fwrite(STDERR, "No fixtures at {$source}. Pass the ClickClacks repo path, or set CLICKCLACKS_REPO.\n");
    exit(1);
}
$files = glob($source . '/*.json') ?: [];
if ($files === []) {
    fwrite(STDERR, "{$source} has no .json fixtures\n");
    exit(1);
}
if (is_dir($target)) {
    foreach (glob($target . '/*') ?: [] as $old) {
        unlink($old);
    }
} else {
    mkdir($target, 0o755, true);
}
foreach ($files as $file) {
    // The fixtures carry placeholder keys shaped like real ones, which secret scanners
    // (GitHub push protection) refuse. The tests never read them, so swap in an obvious
    // example.
    $json = (string) file_get_contents($file);
    $json = (string) preg_replace('/\b(?:cks|sk)_live_[A-Za-z0-9]+/', 'cks_live_example', $json);
    file_put_contents($target . '/' . basename($file), $json);
}
echo 'Copied ' . count($files) . " fixtures from {$source} to {$target}\n";
