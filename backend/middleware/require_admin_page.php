<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';

$adminId = $_SESSION['admin_id'] ?? null;

if (!is_int($adminId) || $adminId < 1) {
    header('Location: /admin/login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

try {
    $stmt = $pdo->prepare(
        'SELECT full_name FROM admin WHERE admin_id = :admin_id'
    );
    $stmt->execute(['admin_id' => $adminId]);
    $adminName = $stmt->fetchColumn();

    if ($adminName === false) {
        unset($_SESSION['admin_id'], $_SESSION['admin_name']);
        header('Location: /admin/login.php');
        exit;
    }

    $_SESSION['admin_name'] = (string) $adminName;
} catch (PDOException $e) {
    error_log('Admin page authentication error: ' . $e->getMessage());
    http_response_code(503);
    exit('Admin authentication is temporarily unavailable.');
}