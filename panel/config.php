<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Load secrets. Manual parse (NOT parse_ini_file) so values may contain '#',
// quotes, etc. without being misinterpreted as INI comments.
// ---------------------------------------------------------------------------
$__secrets = [];
$__envFile = __DIR__ . '/secrets.env';
if (is_readable($__envFile)) {
    foreach (file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__line) {
        if ($__line === '' || $__line[0] === '#') continue;
        $__pos = strpos($__line, '=');
        if ($__pos === false) continue;
        $__secrets[trim(substr($__line, 0, $__pos))] = substr($__line, $__pos + 1);
    }
}

define('DB_HOST', $__secrets['DB_HOST'] ?? '127.0.0.1');
define('DB_PORT', $__secrets['DB_PORT'] ?? '3306');
define('DB_NAME', $__secrets['DB_NAME'] ?? 'rustdesk_panel');
define('DB_USER', $__secrets['DB_USER'] ?? '');
define('DB_PASS', $__secrets['DB_PASS'] ?? '');
define('APP_KEY', $__secrets['APP_KEY'] ?? 'insecure-dev-key');

// ---------------------------------------------------------------------------
// App config
// ---------------------------------------------------------------------------
define('PANEL_BRAND', $__secrets['PANEL_BRAND'] ?? 'Meu Suporte');
define('PANEL_TITLE', $__secrets['PANEL_TITLE'] ?? 'Meu Suporte · Suporte Remoto');
define('API_SERVER_URL', $__secrets['API_SERVER_URL'] ?? 'https://rd.exemplo.com');  // baked into the clients
define('ID_SERVER', $__secrets['ID_SERVER'] ?? '');                                  // hbbs IP/host (exibido no painel)
define('DISPLAY_TZ', $__secrets['DISPLAY_TZ'] ?? 'UTC');                              // UI display timezone

date_default_timezone_set(DISPLAY_TZ);
