<?php
require_once __DIR__ . '/includes/auth.php';

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;

function ensure_attempts_table(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS app_login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attempt (username, ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

if (current_user()) { header('Location: ' . url(home_page())); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = substr(trim($_POST['username'] ?? ''), 0, 50);
    $password = (string) ($_POST['password'] ?? '');
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $pdo = db();
    ensure_attempts_table();
    $pdo->exec('DELETE FROM app_login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM app_login_attempts WHERE username = ? AND ip = ? AND created_at > (NOW() - INTERVAL ' . LOGIN_LOCK_MINUTES . ' MINUTE)');
    $stmt->execute([$username, $ip]);
    $failed = (int) $stmt->fetchColumn();

    if ($failed >= LOGIN_MAX_ATTEMPTS) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . LOGIN_LOCK_MINUTES . ' menit.';
    } else {
        $stmt = $pdo->prepare('SELECT id, nama, username, password_hash, role FROM app_users WHERE username = ? AND aktif = 1 LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $pdo->prepare('DELETE FROM app_login_attempts WHERE username = ? AND ip = ?')->execute([$username, $ip]);
            unset($user['password_hash']);
            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            log_activity('login', 'Masuk ke dalam aplikasi');
            header('Location: ' . url(home_page())); exit;
        }
        $pdo->prepare('INSERT INTO app_login_attempts (username, ip) VALUES (?, ?)')->execute([$username, $ip]);
        $left = LOGIN_MAX_ATTEMPTS - $failed - 1;
        $error = 'Username atau password salah.' . ($left > 0 ? ' Sisa percobaan: ' . $left . '.' : ' Akun dikunci ' . LOGIN_LOCK_MINUTES . ' menit.');
    }
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Masuk · <?= e(APP_NAME) ?></title><link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>"></head><body><main class="center-page"><section class="auth-card"><div class="brand-mark">TK</div><h1>Selamat datang</h1><p class="muted">Masuk untuk mengelola penjualan toko.</p><?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><label>Username</label><input class="input" name="username" autocomplete="username" required autofocus><label style="margin-top:14px">Password</label><input class="input" type="password" name="password" autocomplete="current-password" required><button class="btn btn-primary" type="submit">Masuk ke aplikasi</button></form><p class="auth-footer">TokoKu POS · Sistem kasir sederhana</p></section></main></body></html>
