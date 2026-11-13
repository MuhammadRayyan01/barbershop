<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';

$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$expectsJson = str_contains($accept, 'application/json')
    || str_contains($contentType, 'application/json');

function respond(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');

    if ($expectsJson) {
        respond(405, [
            'success' => false,
            'message' => 'Method not allowed.'
        ]);
    }

    http_response_code(405);
    exit('Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    if ($expectsJson) {
        respond(401, [
            'success' => false,
            'message' => 'You are not logged in.'
        ]);
    }

    header('Location: /user/login.php');
    exit;
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookieParams = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => $cookieParams['secure'],
        'httponly' => $cookieParams['httponly'],
        'samesite' => $cookieParams['samesite'] ?? 'Lax'
    ]);
}

session_destroy();

if ($expectsJson) {
    respond(200, [
        'success' => true,
        'message' => 'Logout successful.'
    ]);
}

header('Location: /user/login.php');
exit;
