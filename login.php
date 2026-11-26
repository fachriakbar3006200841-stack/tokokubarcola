<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;


$error = '';

function ensure_login_attempts_table(): void
{
    db()->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempt (username, ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

if (is_logged_in()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

if (is_post()) {
    verify_csrf();

    try {
        $username = trim(substr((string) ($_POST['username'] ?? ''), 0, 50));
        $password = (string) ($_POST['password'] ?? '');
        $ip = client_ip();

        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('Username dan password wajib diisi.');
        }

        $pdo = db();
        ensure_login_attempts_table();

        $pdo->exec("
            DELETE FROM login_attempts
            WHERE created_at < (NOW() - INTERVAL 1 DAY)
        ");

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM login_attempts
            WHERE username = ?
              AND ip = ?
              AND created_at >= (NOW() - INTERVAL " . LOGIN_LOCK_MINUTES . " MINUTE)
        ");
        $stmt->execute([$username, $ip]);
        $failed = (int) $stmt->fetchColumn();

        if ($failed >= LOGIN_MAX_ATTEMPTS) {
            throw new RuntimeException(
                'Terlalu banyak percobaan login. Silakan coba lagi dalam 15 menit.'
            );
        }

        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.store_id,
                u.role_id,
                u.name,
                u.username,
                u.password_hash,
                r.code AS role
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.username = ?
              AND u.is_active = 1
              AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $pdo->prepare("
                INSERT INTO login_attempts (username, ip)
                VALUES (?, ?)
            ")->execute([$username, $ip]);

            $remaining = max(0, LOGIN_MAX_ATTEMPTS - $failed - 1);

            throw new RuntimeException(
                'Username atau password salah. Sisa percobaan: ' . $remaining . '.'
            );
        }

        $pdo->prepare("
            DELETE FROM login_attempts
            WHERE username = ? AND ip = ?
        ")->execute([$username, $ip]);

        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];

        $pdo->prepare("
            UPDATE users
            SET last_login_at = NOW()
            WHERE id = ?
        ")->execute([(int) $user['id']]);

        audit(
            'login',
            'authentication',
            (int) $user['id'],
            'Pengguna ' . $user['username'] . ' berhasil login.'
        );

        header('Location: ' . url('dashboard.php'));
        exit;

    } catch (Throwable $ex) {
        $error = user_error_message($ex);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#c62828">
    <title>Login | AURELUX POS</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --red: #c62828;
            --red-dark: #9f1d1d;
            --yellow: #ffc928;
            --cream: #fff8e7;
            --ink: #252525;
            --muted: #77716a;
            --white: #ffffff;
            --border: #eee7dc;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            background: var(--cream);
            color: var(--ink);
            font-family: 'DM Sans', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
        }

        .page {
            width: 100%;
            max-width: 1120px;
            min-height: 650px;
            display: grid;
            grid-template-columns: 1.05fr .95fr;
            background: var(--white);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 24px 70px rgba(72, 43, 25, .12);
        }

        .visual {
            position: relative;
            overflow: hidden;
            background: var(--red);
            color: var(--white);
            padding: 48px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .visual::before,
        .visual::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .visual::before {
            width: 420px;
            height: 420px;
            right: -170px;
            top: -160px;
            border: 65px solid rgba(255, 201, 40, .13);
        }

        .visual::after {
            width: 330px;
            height: 330px;
            left: -170px;
            bottom: -170px;
            background: rgba(255, 201, 40, .12);
        }

        .visual-content,
        .visual-footer {
            position: relative;
            z-index: 1;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: var(--yellow);
            color: var(--red-dark);
            display: grid;
            place-items: center;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 25px;
            font-weight: 800;
        }

        .brand-name {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 19px;
            font-weight: 800;
            letter-spacing: 2px;
        }

        .brand-caption {
            font-size: 11px;
            color: rgba(255,255,255,.75);
            margin-top: 3px;
        }

        .hero {
            margin-top: 85px;
            max-width: 420px;
        }

        .hero-label {
            display: inline-block;
            padding: 8px 13px;
            background: rgba(255,255,255,.13);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 30px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .hero h1 {
            margin-top: 24px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: clamp(32px, 4vw, 47px);
            line-height: 1.15;
            letter-spacing: -1.8px;
        }

        .hero h1 span {
            color: var(--yellow);
        }

        .hero p {
            margin-top: 18px;
            max-width: 360px;
            color: rgba(255,255,255,.78);
            font-size: 14px;
            line-height: 1.8;
        }

        .shop-illustration {
            margin-top: 45px;
            max-width: 360px;
            padding: 20px;
            border-radius: 18px;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.16);
        }

        .shop-top {
            height: 12px;
            background: var(--yellow);
            border-radius: 7px 7px 0 0;
        }

        .shop-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px;
            background: rgba(255,255,255,.95);
            color: var(--ink);
            border-radius: 0 0 8px 8px;
        }

        .shop-window {
            width: 65%;
            height: 75px;
            background: #fff0c4;
            border: 5px solid #f0d99e;
            border-radius: 5px;
        }

        .shop-door {
            width: 25%;
            height: 75px;
            background: #e8d4a7;
            border: 5px solid #d6bc83;
            border-radius: 5px 5px 0 0;
        }

        .visual-footer {
            color: rgba(255,255,255,.7);
            font-size: 11px;
        }

        .form-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 55px;
        }

        .form-content {
            width: 100%;
            max-width: 370px;
        }

        .welcome {
            margin-bottom: 36px;
        }

        .welcome-label {
            color: var(--red);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .welcome h2 {
            margin-top: 12px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 30px;
            letter-spacing: -1px;
        }

        .welcome p {
            margin-top: 9px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .alert {
            margin-bottom: 22px;
            padding: 14px 16px;
            border-radius: 10px;
            background: #fff0ed;
            border: 1px solid #ffd0c8;
            color: #a92323;
            font-size: 13px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 21px;
        }

        label {
            display: block;
            margin-bottom: 9px;
            font-size: 13px;
            font-weight: 700;
            color: #37332e;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap input {
            width: 100%;
            height: 51px;
            padding: 0 15px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fffdf9;
            outline: none;
            color: var(--ink);
            font: inherit;
            font-size: 13px;
            transition: .2s;
        }

        .input-wrap input:focus {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(198,40,40,.08);
            background: var(--white);
        }

        .input-wrap input::placeholder {
            color: #aaa39a;
        }

        .password-input {
            padding-right: 50px !important;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 13px;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #888;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
        }

        .submit-btn {
            width: 100%;
            height: 52px;
            margin-top: 10px;
            border: none;
            border-radius: 10px;
            background: var(--red);
            color: var(--white);
            font-family: inherit;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .5px;
            cursor: pointer;
            box-shadow: 0 7px 18px rgba(198,40,40,.2);
            transition: .2s;
        }

        .submit-btn:hover {
            background: var(--red-dark);
            transform: translateY(-1px);
        }

        .secure-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 25px;
            color: #928a80;
            font-size: 11px;
        }

        .secure-icon {
            color: #23845c;
            font-size: 14px;
        }

        .mobile-brand {
            display: none;
        }

        @media (max-width: 850px) {
            body {
                padding: 20px;
            }

            .page {
                max-width: 520px;
                min-height: auto;
                grid-template-columns: 1fr;
            }

            .visual {
                display: none;
            }

            .form-panel {
                padding: 55px 35px;
            }

            .mobile-brand {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 45px;
            }

            .mobile-brand .brand-icon {
                background: var(--red);
                color: var(--yellow);
            }

            .mobile-brand .brand-name {
                color: var(--red);
            }

            .mobile-brand .brand-caption {
                color: var(--muted);
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 12px;
            }

            .page {
                border-radius: 17px;
            }

            .form-panel {
                padding: 38px 23px;
            }

            .welcome h2 {
                font-size: 26px;
            }

            .mobile-brand {
                margin-bottom: 35px;
            }
        }
    </style>
</head>

<body>
<main class="page">

    <section class="visual">
        <div class="visual-content">
            <div class="brand">
                <div class="brand-icon">A</div>
                <div>
                    <div class="brand-name">AURELUX</div>
                    <div class="brand-caption">POINT OF SALE SYSTEM</div>
                </div>
            </div>

            <div class="hero">
                <span class="hero-label">Retail Management System</span>

                <h1>
                    Kelola Toko Lebih
                    <span>Mudah & Modern.</span>
                </h1>

                <p>
                    Satu sistem untuk mengelola produk, transaksi,
                    stok barang, pelanggan, dan laporan penjualan
                    toko secara lebih praktis.
                </p>

                <div class="shop-illustration">
                    <div class="shop-top"></div>
                    <div class="shop-body">
                        <div class="shop-window"></div>
                        <div class="shop-door"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="visual-footer">
            AURELUX POS &copy; <?= date('Y') ?> · Sistem Manajemen Toko
        </div>
    </section>

    <section class="form-panel">
        <div class="form-content">

            <div class="mobile-brand">
                <div class="brand-icon">A</div>
                <div>
                    <div class="brand-name">AURELUX</div>
                    <div class="brand-caption">POINT OF SALE SYSTEM</div>
                </div>
            </div>

            <div class="welcome">
                <div class="welcome-label">Selamat Datang Kembali</div>
                <h2>Masuk ke Akun</h2>
                <p>
                    Silakan masukkan username dan password
                    untuk mengakses sistem manajemen toko.
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert" role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <input
                            id="username"
                            name="username"
                            type="text"
                            maxlength="50"
                            autocomplete="username"
                            placeholder="Masukkan username"
                            value="<?= e((string) ($_POST['username'] ?? '')) ?>"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input
                            class="password-input"
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="Masukkan password"
                            required
                        >
                        <button
                            class="toggle-password"
                            type="button"
                            id="togglePassword"
                            aria-label="Tampilkan password"
                        >LIHAT</button>
                    </div>
                </div>

                <button class="submit-btn" type="submit">
                    MASUK KE SISTEM &rarr;
                </button>
            </form>

            <div class="secure-note">
                <span class="secure-icon">&#10003;</span>
                Akses aman dan terlindungi
            </div>

        </div>
    </section>

</main>

<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    togglePassword.addEventListener('click', function () {
        const isPassword = passwordInput.type === 'password';

        passwordInput.type = isPassword ? 'text' : 'password';
        togglePassword.textContent = isPassword ? 'SEMBUNYI' : 'LIHAT';
        togglePassword.setAttribute(
            'aria-label',
            isPassword ? 'Sembunyikan password' : 'Tampilkan password'
        );
    });
</script>

</body>
</html>
