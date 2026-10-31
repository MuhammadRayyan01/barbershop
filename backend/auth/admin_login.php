<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/response.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$data = json_decode((string) file_get_contents('php://input'), true);
$login = is_array($data) ? trim((string) ($data['login'] ?? '')) : '';
$password = is_array($data) ? ($data['password'] ?? '') : '';

if ($login === '' || !is_string($password) || $password === '') {
    jsonResponse(422, ['success' => false, 'message' => 'Username/email dan password wajib diisi.']);
}

try {
    $stmt = $pdo->prepare(
        'SELECT admin_id, username, email, full_name, password_hash
         FROM admin
         WHERE username = :username OR email = :email
         LIMIT 1'
    );
    $stmt->execute(['username' => $login, 'email' => $login]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        jsonResponse(401, ['success' => false, 'message' => 'Username/email atau password salah.']);
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['admin_id'];
    $_SESSION['admin_name'] = $admin['full_name'];
    unset($admin['password_hash']);

    jsonResponse(200, ['success' => true, 'message' => 'Login berhasil.', 'data' => $admin]);
} catch (PDOException $e) {
    error_log('Admin login error: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Tidak dapat memproses login admin.']);
}
