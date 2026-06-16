<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// ---------------------------------------------------------------------------
// Admin web session
// ---------------------------------------------------------------------------
function start_admin_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('support_panel');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => $https,
    ]);
    session_start();
}

function current_admin(): ?array {
    start_admin_session();
    if (empty($_SESSION['admin_id'])) return null;
    $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
    $st->execute([$_SESSION['admin_id']]);
    return $st->fetch() ?: null;
}

function require_admin(): array {
    $a = current_admin();
    if (!$a) { header('Location: /login'); exit; }
    return $a;
}

function csrf_token(): string {
    start_admin_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function check_csrf(): void {
    start_admin_session();
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('CSRF inválido — recarregue a página.');
    }
}

// ---------------------------------------------------------------------------
// Operator token (RustDesk app auth)
// ---------------------------------------------------------------------------
function operator_from_token(?string $token): ?array {
    if (!$token) return null;
    $st = db()->prepare(
        'SELECT o.* FROM operator_tokens t
         JOIN operators o ON o.id = t.operator_id
         WHERE t.token = ? AND t.revoked = 0 AND o.status = 1');
    $st->execute([$token]);
    $o = $st->fetch();
    if (!$o) return null;
    db()->prepare('UPDATE operator_tokens SET last_seen = ? WHERE token = ?')
        ->execute([now_utc(), $token]);
    return $o;
}
