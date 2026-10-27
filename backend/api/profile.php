<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../middleware/require-auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if (!in_array($method, ['GET', 'PATCH'], true)) {
    header('Allow: GET, PATCH');

    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare('SELECT user_id, username, email, full_name, phone_number, created_at, updated_at
                              from users where user_id = :user_id');

        $stmt->execute(['user_id' => $authenticatedUserId]);

        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            jsonResponse(404, ['success' => false, 'message' => 'Profile not found.']);
        }

        jsonResponse(200, ['success' => true, 'data' => $profile]);
    } catch (PDOException $e) {
        error_log('profile API error: ' . $e->getMessage());

        jsonResponse(500, ['success' => false, 'message' => 'Unable to load profile.']);
    }
}

require_once __DIR__ . '/../config/request.php';
require_once __DIR__ . '/../config/csrf.php';

require_once __DIR__ . '/../config/request.php';
require_once __DIR__ . '/../config/csrf.php';

requireValidCsrfToken();

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    jsonResponse(400, ['success' => false, 'message' => 'Request body must contain valid JSON.']);
}

$allowedFields = ['username', 'email', 'full_name', 'phone_number'];

if ($data === [] || array_diff(array_keys($data), $allowedFields) !== []) {
    jsonResponse(422, ['success' => false, 'message' => 'Provide at least one valid profile field.']);
}

try {
    $stmt = $pdo->prepare('SELECT username, email, full_name, phone_number FROM users WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $authenticatedUserId]);
    $currentProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentProfile) {
        jsonResponse(404, ['success' => false, 'message' => 'Profile not found.']);
    }

    $updated = $currentProfile;

    foreach ($data as $field => $value) {
        if (!is_string($value) || trim($value) === '') {
            jsonResponse(422, ['success' => false, 'message' => 'Profile values must be non-empty text.']);
        }

        $value = trim($value);
        $maxLengths = ['username' => 100, 'email' => 255, 'full_name' => 255, 'phone_number' => 15];

        if (strlen($value) > $maxLengths[$field]) {
            jsonResponse(422, ['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is too long.']);
        }

        if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(422, ['success' => false, 'message' => 'Enter a valid email address.']);
        }

        $updated[$field] = $value;
    }

    if (isset($data['email']) || isset($data['username'])) {
        $duplicateStmt = $pdo->prepare('SELECT user_id FROM users WHERE (username = :username OR email = :email)
                                        AND user_id <> :user_id LIMIT 1');
        $duplicateStmt->execute([
            'username' => $updated['username'],
            'email' => $updated['email'],
            'user_id' => $authenticatedUserId,
        ]);

        if ($duplicateStmt->fetchColumn()) {
            jsonResponse(409, ['success' => false, 'message' => 'That username or email is already in use.']);
        }
    }

    $updateStmt = $pdo->prepare('UPDATE users
                                 SET username = :username, email = :email, full_name = :full_name,
                                     phone_number = :phone_number, updated_at = CURRENT_TIMESTAMP
                                 WHERE user_id = :user_id
                                 RETURNING user_id, username, email, full_name, phone_number, created_at, updated_at');
    $updateStmt->execute([
        'username' => $updated['username'],
        'email' => $updated['email'],
        'full_name' => $updated['full_name'],
        'phone_number' => $updated['phone_number'],
        'user_id' => $authenticatedUserId,
    ]);

    $profile = $updateStmt->fetch(PDO::FETCH_ASSOC);
    $_SESSION['username'] = $profile['username'];

    jsonResponse(200, ['success' => true, 'message' => 'Profile updated successfully.', 'data' => $profile]);
} catch (PDOException $e) {
    error_log('Profile update API error: ' . $e->getMessage());

    if ($e->getCode() === '23505') {
        jsonResponse(409, ['success' => false, 'message' => 'That username or email is already in use.']);
    }

    jsonResponse(500, ['success' => false, 'message' => 'Unable to update profile.']);
}

?>