<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/load_env.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

requireValidCsrfToken();
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(400, ['success' => false, 'message' => 'Invalid request body.']);
}
$action = $input['action'] ?? '';

try {
    if ($action === 'request') {
        $email = strtolower(trim(is_string($input['email'] ?? null) ? $input['email'] : ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            jsonResponse(422, ['success' => false, 'message' => 'Masukkan alamat email yang valid.']);
        }

        $stmt = $pdo->prepare('SELECT user_id, email FROM users WHERE lower(email) = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')->execute(['user_id' => $user['user_id']]);
            $save = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, CURRENT_TIMESTAMP + INTERVAL '1 hour')");
            $save->execute(['user_id' => $user['user_id'], 'token_hash' => $tokenHash]);
            $pdo->commit();

            $baseUrl = rtrim((string) (getenv('APP_BASE_URL') ?: ''), '/');
            if ($baseUrl === '') {
                $host = $_SERVER['HTTP_HOST'] ?? '';
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $baseUrl = $host !== '' ? $scheme . '://' . $host : '';
            }
            $resetUrl = $baseUrl . '/user/forgot-password.php?token=' . rawurlencode($token);
            $subject = 'Atur ulang kata sandi Pemon Barbershop';
            $message = "Kami menerima permintaan untuk mengatur ulang kata sandi Anda. Buka tautan berikut dalam satu jam:\n\n{$resetUrl}\n\nJika Anda tidak meminta ini, abaikan email ini.";
            $from = (string) (getenv('MAIL_FROM') ?: '');
            $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
            if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $headers .= 'From: Pemon Barbershop <' . $from . ">\r\n";
            }
            if ($baseUrl !== '' && !mail((string) $user['email'], $subject, $message, $headers)) {
                error_log('Password reset email could not be sent for user ' . (int) $user['user_id']);
            }
        }
        jsonResponse(200, ['success' => true, 'message' => 'Jika email terdaftar, tautan pengaturan ulang akan dikirim. Periksa kotak masuk Anda.']);
    }

    if ($action === 'reset') {
        $token = is_string($input['token'] ?? null) ? $input['token'] : '';
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            jsonResponse(422, ['success' => false, 'message' => 'Tautan pengaturan ulang tidak valid atau sudah kedaluwarsa.']);
        }
        if (strlen($password) < 12 || strlen($password) > 72) {
            jsonResponse(422, ['success' => false, 'message' => 'Kata sandi harus terdiri dari 12 sampai 72 karakter.']);
        }
        $pdo->beginTransaction();
        $find = $pdo->prepare('SELECT reset_id, user_id FROM password_reset_tokens WHERE token_hash = :token_hash AND expires_at > CURRENT_TIMESTAMP FOR UPDATE');
        $find->execute(['token_hash' => hash('sha256', $token)]);
        $record = $find->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            $pdo->rollBack();
            jsonResponse(422, ['success' => false, 'message' => 'Tautan pengaturan ulang tidak valid atau sudah kedaluwarsa.']);
        }
        $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id');
        $update->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'user_id' => $record['user_id']]);
        $remove = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
        $remove->execute(['user_id' => $record['user_id']]);
        $pdo->commit();
        jsonResponse(200, ['success' => true, 'message' => 'Kata sandi berhasil diperbarui. Silakan masuk dengan kata sandi baru.', 'redirect' => 'login.php']);
    }

    jsonResponse(400, ['success' => false, 'message' => 'Invalid action.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Password reset error: ' . $error->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Permintaan tidak dapat diproses saat ini.']);
}
