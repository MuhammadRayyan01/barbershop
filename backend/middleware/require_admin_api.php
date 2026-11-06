<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/db.php';

$adminId = $_SESSION['admin_id'] ?? null;
if (!is_int($adminId) || $adminId < 1) {
    jsonResponse(401, ['success' => false, 'message' => 'Admin sign-in required.']);
}

try {
    $stmt = $pdo->prepare('SELECT 1 FROM admin WHERE admin_id = :admin_id');
    $stmt->execute(['admin_id' => $adminId]);
    if (!$stmt->fetchColumn()) {
        unset($_SESSION['admin_id'], $_SESSION['admin_name']);
        jsonResponse(401, ['success' => false, 'message' => 'Admin sign-in required.']);
    }
} catch (PDOException $e) {
    error_log('Admin API authentication error: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Unable to verify admin access.']);
}
