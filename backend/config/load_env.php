<?php
declare(strict_types=1);

$envFile = dirname(__DIR__, 2) . '/.env';

if (!file_exists($envFile)) {
    return;
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    if (!str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);

    $key = trim($key);
    $value = trim($value);

    $value = trim($value, "\"'");

    if ($key !== '' && getenv($key) === false) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}
