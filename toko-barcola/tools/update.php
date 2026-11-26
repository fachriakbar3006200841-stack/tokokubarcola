<?php
// Skrip pembaruan (hanya bisa dijalankan lewat command line).
// Prinsip: HANYA MENAMBAH. Akun yang sudah ada tidak diubah sama sekali
// (password, nama, role, status tetap seperti semula).
//   - menambah role 'user' pada tabel app_users
//   - membuat akun admin / kasir / user HANYA jika belum ada
//     (password akun baru = username + 1234)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya bisa dijalankan lewat command line.');
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = db();

    $pdo->exec("ALTER TABLE app_users MODIFY role ENUM('admin','kasir','user') NOT NULL DEFAULT 'kasir'");
    echo "[OK] Role 'user' tersedia di tabel app_users" . PHP_EOL;

    $pdo->exec('CREATE TABLE IF NOT EXISTS app_login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attempt (username, ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    echo "[OK] Tabel pembatas login siap" . PHP_EOL;

    $accounts = [
        ['Administrator', 'admin', 'admin'],
        ['Kasir Utama',   'kasir', 'kasir'],
        ['User',          'user',  'user'],
    ];
    $check = $pdo->prepare('SELECT COUNT(*) FROM app_users WHERE username = ?');
    $insert = $pdo->prepare('INSERT INTO app_users (nama, username, password_hash, role, aktif) VALUES (?, ?, ?, ?, 1)');
    foreach ($accounts as [$nama, $username, $role]) {
        $check->execute([$username]);
        if ((int) $check->fetchColumn() > 0) {
            echo "[LEWAT] Akun $username sudah ada, tidak diubah" . PHP_EOL;
            continue;
        }
        $insert->execute([$nama, $username, password_hash($username . '1234', PASSWORD_DEFAULT), $role]);
        echo "[BARU] Akun $username ($role) dibuat. Password: {$username}1234" . PHP_EOL;
    }

    echo PHP_EOL . 'Daftar pengguna sekarang:' . PHP_EOL;
    foreach ($pdo->query('SELECT id, username, role, aktif FROM app_users ORDER BY id') as $r) {
        echo '  ' . $r['id'] . ' | ' . $r['username'] . ' | ' . $r['role'] . ' | aktif=' . $r['aktif'] . PHP_EOL;
    }
    echo PHP_EOL . 'SELESAI.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[GAGAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
