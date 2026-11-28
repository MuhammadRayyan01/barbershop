<?php 
declare(strict_types=1);

$root = dirname(__DIR__);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

// Fallback when the platform rewrites REQUEST_URI to this file.
if (str_starts_with($path, '/api/index.php') && isset($_GET['__route'])) {
    $path = '/' . ltrim((string) $_GET['__route'], '/');
}
unset($_GET['__route']);

if (str_contains($path, "\0") || str_contains($path, '..')) {
    http_response_code(400);
    exit('Bad request');
} 

if (str_ends_with($path, '/')) {
    $path .= 'index.php';
}

// Internal files that must never be opened directly from the browser.
$blocked = [
    '#^/api/#',
    '#^/backend/config/#',
    '#^/backend/middleware/#',
    '#^/(admin|user)/includes/#',
    '#^/includes/#',
    '#^/sql/#',
    '#^/scripts/#',
    '#^/node_modules/#',
    '#/\.#',
];
foreach ($blocked as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(404);
        exit('Not found');
    }
}

$file = realpath($root . $path);

if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file) || !str_ends_with($file, '.php')) {
    http_response_code(404);
    exit('Not found');
}

$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME'] = $path;
$_SERVER['PHP_SELF'] = $path;
$_SERVER['DOCUMENT_ROOT'] = $root;

chdir(dirname($file));
require $file;
