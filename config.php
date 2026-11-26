<?php
declare(strict_types=1);

const APP_NAME = 'AURELUX POS';
const APP_VERSION = '1.0.0';

// Pengaturan lokal (kredensial DB) ada di config/config.local.php — TIDAK ikut repository.
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_NAME') || define('DB_NAME', 'aurelux_pos');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
// Folder backup berada DI LUAR folder web (htdocs). Ubah di config.local.php bila perlu.
defined('BACKUP_DIR') || define('BACKUP_DIR', dirname(__DIR__, 3) . '/aurelux-backups');
defined('APP_DEBUG') || define('APP_DEBUG', false);

date_default_timezone_set('Asia/Jakarta');
ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);
