<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Do not cache the logout response.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Already logged out (for example the session expired in another tab): just go to login.
if (!isset($_SESSION['user_id'])) {
    header('Location: /user/login.php', true, 303);
    exit;
}

// The logout form in includes/header.php sends the token as the "csrf_token" field.
$submittedToken = $_POST['csrf_token'] ?? '';
requireValidCsrfToken(is_string($submittedToken) ? $submittedToken : '');

// Clear all session data.
$_SESSION = [];

// Remove the session cookie.
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

// Destroy the session.
session_destroy();

// Redirect to login (correct when the site root is the project folder: php -S localhost:8080).
header('Location: /user/login.php', true, 303);
exit;
