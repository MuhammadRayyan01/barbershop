<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/response.php';
$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) || $userId < 1) {
    jsonResponse(401, [
        'success' => false,
        'message' => 'Authentication required.'
    ]);
}

try {
    $stmt = $pdo->prepare(
        'SELECT user_id
         FROM users
         WHERE user_id = :user_id'
    );

    $stmt->execute([
        'user_id' => $userId
    ]);

    if (!$stmt->fetchColumn()) {
        $_SESSION = [];
        session_destroy();

        jsonResponse(401, [
            'success' => false,
            'message' => 'Authentication required.'
        ]);
    }

    $authenticatedUserId = $userId;

} catch (PDOException $e) {
    error_log('Authentication middleware error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to verify authentication.'
    ]);
}