<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

// Check whether the user is logged in
if (!isset($_SESSION['user_id'])) {
    respond(401, [
        'success' => false,
        'message' => 'You are not logged in.'
    ]);
}

// Clear session data
$_SESSION = [];

// Delete session cookie
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

// Destroy session
session_destroy();

respond(200, [
    'success' => true,
    'message' => 'Logout successful.'
]);