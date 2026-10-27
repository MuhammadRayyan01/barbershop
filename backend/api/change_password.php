<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/request.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../middleware/require-auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

requireValidCsrfToken();

$decodedData = json_decode((string) file_get_contents('php://input'), true);
$data = is_array($decodedData) ? $decodedData : [];

$currentPassword = $data['current_password'] ?? null;
$newPassword = $data['new_password'] ?? null;

if (
    !is_string($currentPassword) ||
    !is_string($newPassword) ||
    $currentPassword === '' ||
    $newPassword === ''
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Current password and new password are required.'
    ]);
}

if (
    strlen($currentPassword) > 72 ||
    strlen($newPassword) < 12 ||
    strlen($newPassword) > 72
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'The new password must be between 12 and 72 bytes.'
    ]);
}

try {
    $stmt = $pdo->prepare(
        'SELECT password_hash
         FROM users
         WHERE user_id = :user_id'
    );

    $stmt->execute([
        'user_id' => $authenticatedUserId
    ]);

    $passwordHash = $stmt->fetchColumn();

    if (
        !is_string($passwordHash) ||
        !password_verify($currentPassword, $passwordHash)
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'The current password is incorrect.'
        ]);
    }

    if (password_verify($newPassword, $passwordHash)) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'The new password must be different from the current password.'
        ]);
    }

    $newPasswordHash = password_hash(
        $newPassword,
        PASSWORD_DEFAULT
    );

    $updateStmt = $pdo->prepare(
        'UPDATE users
         SET password_hash = :password_hash,
             updated_at = CURRENT_TIMESTAMP
         where user_id = :user_id'
    );

    $updateStmt->execute([
        'password_hash' => $newPasswordHash,
        'user_id' => $authenticatedUserId
    ]);


    session_regenerate_id(true);

    jsonResponse(200, [
        'success' => true,
        'message' => 'Password changed successfully.'
    ]);

} catch (PDOException $e) {
    error_log('Change password API error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to change password.'
    ]);
}