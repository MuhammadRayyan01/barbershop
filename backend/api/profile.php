<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../middleware/auth_require.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if (!in_array($method, ['GET', 'PATCH'], true)) {
    header('Allow: GET, PATCH');
    jsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, full_name,
                    phone_number, created_at, updated_at
             FROM users
             WHERE user_id = :user_id'
        );

        $stmt->execute([
            'user_id' => $authenticatedUserId
        ]);

        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            jsonResponse(404, [
                'success' => false,
                'message' => 'Profile not found.'
            ]);
        }

        jsonResponse(200, [
            'success' => true,
            'data' => $profile
        ]);

    } catch (PDOException $e) {
        error_log('Profile GET error: ' . $e->getMessage());

        jsonResponse(500, [
            'success' => false,
            'message' => 'Unable to load profile.'
        ]);
    }
}

require_once __DIR__ . '/../config/request.php';
require_once __DIR__ . '/../config/csrf.php';

requireValidCsrfToken();

$data = json_decode(
    (string) file_get_contents('php://input'),
    true
);

if (!is_array($data)) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Request body must contain valid JSON.'
    ]);
}

$allowedFields = [
    'username',
    'email',
    'full_name',
    'phone_number',
    'current_password',
    'new_password'
];

if (
    $data === [] ||
    array_diff(array_keys($data), $allowedFields) !== []
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Provide valid profile fields.'
    ]);
}

try {
    $stmt = $pdo->prepare(
        'SELECT user_id, username, email, full_name,
                phone_number, password_hash
         FROM users
         WHERE user_id = :user_id'
    );

    $stmt->execute([
        'user_id' => $authenticatedUserId
    ]);

    $currentProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentProfile) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Profile not found.'
        ]);
    }

    $updated = $currentProfile;

    $maxLengths = [
        'username' => 100,
        'email' => 255,
        'full_name' => 255,
        'phone_number' => 15
    ];

    foreach (['username', 'email', 'full_name', 'phone_number'] as $field) {

        if (!array_key_exists($field, $data)) {
            continue;
        }

        $value = $data[$field];

        if (!is_string($value) || trim($value) === '') {
            jsonResponse(422, [
                'success' => false,
                'message' => ucfirst(str_replace('_', ' ', $field))
                    . ' must not be empty.'
            ]);
        }

        $value = trim($value);

        if (strlen($value) > $maxLengths[$field]) {
            jsonResponse(422, [
                'success' => false,
                'message' => ucfirst(str_replace('_', ' ', $field))
                    . ' is too long.'
            ]);
        }

        if (
            $field === 'email' &&
            !filter_var($value, FILTER_VALIDATE_EMAIL)
        ) {
            jsonResponse(422, [
                'success' => false,
                'message' => 'Enter a valid email address.'
            ]);
        }

        $updated[$field] = $value;
    }

    if (isset($data['email'])) {

        $emailStmt = $pdo->prepare(
            'SELECT user_id
             FROM users
             WHERE LOWER(email) = LOWER(:email)
             AND user_id <> :user_id
             LIMIT 1'
        );

        $emailStmt->execute([
            'email' => $updated['email'],
            'user_id' => $authenticatedUserId
        ]);

        if ($emailStmt->fetchColumn()) {
            jsonResponse(409, [
                'success' => false,
                'message' => 'This email address is already registered.'
            ]);
        }
    }

    if (isset($data['username'])) {

        $usernameStmt = $pdo->prepare(
            'SELECT user_id
             FROM users
             WHERE username = :username
             AND user_id <> :user_id
             LIMIT 1'
        );

        $usernameStmt->execute([
            'username' => $updated['username'],
            'user_id' => $authenticatedUserId
        ]);

        if ($usernameStmt->fetchColumn()) {
            jsonResponse(409, [
                'success' => false,
                'message' => 'This username is already in use.'
            ]);
        }
    }

    $newPasswordHash = null;

    $newPassword = $data['new_password'] ?? '';
    $currentPassword = $data['current_password'] ?? '';

    if (!is_string($newPassword) || !is_string($currentPassword)) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Invalid password values.'
        ]);
    }

    if ($newPassword !== '' || $currentPassword !== '') {

        if ($currentPassword === '') {
            jsonResponse(422, [
                'success' => false,
                'message' => 'Enter your current password.'
            ]);
        }

        if (!password_verify(
            $currentPassword,
            $currentProfile['password_hash']
        )) {
            jsonResponse(422, [
                'success' => false,
                'message' => 'Your current password is incorrect.'
            ]);
        }

        if ($newPassword === '') {
            jsonResponse(422, [
                'success' => false,
                'message' => 'Enter your new password.'
            ]);
        }

        $passwordLength = mb_strlen($newPassword, 'UTF-8');

        if ($passwordLength <= 12) {
            jsonResponse(422, [
                'success' => false,
                'message' => 'New password must be more than 12 characters.'
            ]);
        }

        if ($passwordLength > 72) {
            jsonResponse(422, [
                'success' => false,
                'message' => 'New password cannot exceed 72 characters.'
            ]);
        }

        $newPasswordHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        if ($newPasswordHash === false) {
            jsonResponse(500, [
                'success' => false,
                'message' => 'Unable to secure the new password.'
            ]);
        }
    }


    $updateSql = '
        UPDATE users
        SET username = :username,
            email = :email,
            full_name = :full_name,
            phone_number = :phone_number,
            updated_at = CURRENT_TIMESTAMP
    ';

    $params = [
        'username' => $updated['username'],
        'email' => $updated['email'],
        'full_name' => $updated['full_name'],
        'phone_number' => $updated['phone_number'],
        'user_id' => $authenticatedUserId
    ];

    if ($newPasswordHash !== null) {
        $updateSql .= ', password_hash = :password_hash';
        $params['password_hash'] = $newPasswordHash;
    }

    $updateSql .= '
        WHERE user_id = :user_id
        RETURNING user_id, username, email, full_name,
                  phone_number, created_at, updated_at
    ';

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute($params);

    $profile = $updateStmt->fetch(PDO::FETCH_ASSOC);

    $_SESSION['username'] = $profile['username'];

    jsonResponse(200, [
        'success' => true,
        'message' => 'Profile updated successfully.',
        'data' => $profile
    ]);

} catch (PDOException $e) {

    error_log('Profile update API error: ' . $e->getMessage());

    if ($e->getCode() === '23505') {
        jsonResponse(409, [
            'success' => false,
            'message' => 'That username or email is already in use.'
        ]);
    }

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to update profile.'
    ]);
}
