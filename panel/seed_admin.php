<?php
declare(strict_types=1);
// CLI: create or reset a panel admin.
//   php seed_admin.php <username> [password]
// If password omitted, a strong one is generated and printed.
require_once __DIR__ . '/src/db.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$user = $argv[1] ?? 'admin';
$pass = $argv[2] ?? bin2hex(random_bytes(6)); // 12 hex chars if not provided
$hash = password_hash($pass, PASSWORD_BCRYPT);

$pdo = db();
$st = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
$st->execute([$user]);
if ($st->fetchColumn()) {
    $pdo->prepare('UPDATE admins SET password_hash = ? WHERE username = ?')->execute([$hash, $user]);
    echo "Admin '$user' atualizado.\n";
} else {
    $pdo->prepare('INSERT INTO admins (username, password_hash, name, created_at) VALUES (?,?,?,UTC_TIMESTAMP())')
        ->execute([$user, $hash, $user]);
    echo "Admin '$user' criado.\n";
}
echo "Usuário: $user\nSenha:   $pass\n";
