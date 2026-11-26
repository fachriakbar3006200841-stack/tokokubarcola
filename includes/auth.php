<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Mengambil pengguna yang sedang login.
 */
function current_user(): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded) {
        return $user;
    }

    $loaded = true;
    $uid = (int) ($_SESSION['uid'] ?? 0);

    if ($uid <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT
            u.id,
            u.store_id,
            u.role_id,
            u.name,
            u.username,
            u.last_login_at,
            r.code AS role,
            r.name AS role_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
           AND u.is_active = 1
           AND u.deleted_at IS NULL
         LIMIT 1'
    );

    $stmt->execute([$uid]);
    $user = $stmt->fetch() ?: null;

    if (!$user) {
        unset($_SESSION['uid']);
        session_regenerate_id(true);
    }

    return $user;
}

/**
 * Memeriksa status login.
 */
function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * Memaksa pengguna login.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . url('login.php'));
        exit;
    }
}

/**
 * Memeriksa role pengguna.
 */
function has_role(string|array $roles): bool
{
    $user = current_user();

    if (!$user) {
        return false;
    }

    $roles = (array) $roles;

    return in_array($user['role'], $roles, true);
}

/**
 * Memaksa pengguna memiliki role tertentu.
 */
function require_role(string|array $roles): void
{
    require_login();

    if (!has_role($roles)) {
        http_response_code(403);
        exit('403 - Anda tidak memiliki akses ke halaman ini.');
    }
}

/**
 * Memeriksa permission berdasarkan role.
 */
function has_permission(string $permission): bool
{
    $user = current_user();

    if (!$user) {
        return false;
    }

    static $cache = [];

    $roleId = (int) $user['role_id'];
    $key = $roleId . ':' . $permission;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM role_permissions rp
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ?
           AND p.code = ?'
    );

    $stmt->execute([$roleId, $permission]);

    return $cache[$key] = ((int) $stmt->fetchColumn() > 0);
}

/**
 * Memaksa pengguna memiliki permission tertentu.
 */
function require_permission(string $permission): void
{
    require_login();

    if (!has_permission($permission)) {
        http_response_code(403);
        exit('403 - Permission tidak mencukupi.');
    }
}

/**
 * Kompatibilitas pemeriksaan role.
 */
function is_admin(): bool
{
    return has_role(['super_admin', 'admin']);
}

function is_staff(): bool
{
    return has_role(['super_admin', 'admin', 'kasir']);
}

function require_staff(): void
{
    require_role(['super_admin', 'admin', 'kasir']);
}

/**
 * Tujuan halaman setelah login.
 */
function home_page(): string
{
    return 'dashboard.php';
}

/**
 * Kompatibilitas CSRF.
 */
function check_csrf(): void
{
    verify_csrf();
}

/**
 * Kompatibilitas pencatatan aktivitas.
 */
function log_activity(
    string $action,
    string $description = ''
): void {
    audit($action, 'authentication', null, $description);
}
