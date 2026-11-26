<?php
declare(strict_types=1);

const APP_NAME = 'TokoKu POS';
// Pengaturan database dibaca dari config/config.local.php (tidak ikut ke Git).
// Jika file itu tidak ada, nilai bawaan XAMPP di bawah dipakai.
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_NAME') || define('DB_NAME', 'toko');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
const CURRENCY = 'Rp';

date_default_timezone_set('Asia/Jakarta');

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function url(string $path = ''): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    if ($base === '/' || $base === '\\' || $base === '.') {
        $base = '';
    }
    return $base . '/' . ltrim($path, '/');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(mixed $value): string
{
    return CURRENCY . ' ' . number_format((float) $value, 0, ',', '.');
}

function parse_price(mixed $value): float
{
    $raw = trim((string) $value);
    $raw = preg_replace('/[^0-9,.\-]/', '', $raw) ?? '';
    if ($raw === '' || !preg_match('/^\d[\d,.]*$/', $raw)) {
        throw new InvalidArgumentException('Harga harus berupa angka yang valid.');
    }

    if (str_contains($raw, ',')) {
        // Format Indonesia: 1.250.000,50 atau 4000,00.
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    } elseif (substr_count($raw, '.') > 1 || preg_match('/\.\d{3}$/', $raw)) {
        // Format ribuan: 1.250.000 atau 3.000.
        $raw = str_replace('.', '', $raw);
    }

    if (!is_numeric($raw)) {
        throw new InvalidArgumentException('Harga harus berupa angka yang valid.');
    }
    return (float) $raw;
}

function price_input(mixed $value): string
{
    return number_format((float) $value, 0, ',', '.');
}
