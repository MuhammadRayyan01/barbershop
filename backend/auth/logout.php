<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';

<<<<<<< HEAD
header('Content-Type: application/json; charset=utf-8');
=======
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$expectsJson = str_contains($accept, 'application/json') || str_contains($contentType, 'application/json');
>>>>>>> 9649eca488bfa2bb8cd6062ec9fc94e112c31e7b

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
<<<<<<< HEAD
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
=======
    if ($expectsJson) {
        header('Content-Type: application/json; charset=utf-8');
        respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }
    http_response_code(405);
    exit('Method not allowed.');
}

if(!isset($_SESSION['user_id'])){
    if ($expectsJson) {
        header('Content-Type: application/json; charset=utf-8');
        respond(401, ['success' => false,'message' => 'You are not logged in.']);
    }
    header('Location: /user/login.php');
    exit;
>>>>>>> 9649eca488bfa2bb8cd6062ec9fc94e112c31e7b
}

// Clear session data
$_SESSION = [];

// Delete session cookie
if (ini_get('session.use_cookies')) {
    $cookieParams = session_get_cookie_params();

<<<<<<< HEAD
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => $cookieParams['secure'],
        'httponly' => $cookieParams['httponly'],
        'samesite' => $cookieParams['samesite'] ?? 'Lax'
    ]);
=======
    setcookie(session_name(),'',
                [     
                'expires' => time() - 42000,
                'path' => $cookieParams['path'],
                'domain' => $cookieParams['domain'],
                'httponly' => $cookieParams['httponly'],
                'samesite' => $cookieParams['samesite'] ?? 'Lax'
                ]);
>>>>>>> 9649eca488bfa2bb8cd6062ec9fc94e112c31e7b
}

// Destroy session
session_destroy();
<<<<<<< HEAD

respond(200, [
    'success' => true,
    'message' => 'Logout successful.'
]);
=======
if ($expectsJson) {
    header('Content-Type: application/json; charset=utf-8');
    respond(200, ['success' => true, 'message' => 'Logout successful']);
}

header('Location: /user/login.php');
exit;
?>
>>>>>>> 9649eca488bfa2bb8cd6062ec9fc94e112c31e7b
