<?php
require_once __DIR__ . '/includes/auth.php';
header('Location: ' . url(current_user() ? home_page() : 'login.php'));
exit;
