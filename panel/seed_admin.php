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
// Este script e o break-glass: a conta que ele toca sai sempre com perfil
// 'admin', senao um banco novo nasceria sem ninguem capaz de administrar (a
// coluna role tem default 'tecnico').
if ($st->fetchColumn()) {
    $pdo->prepare("UPDATE admins SET password_hash = ?, role = 'admin' WHERE username = ?")
        ->execute([$hash, $user]);
    echo "Admin '$user' atualizado.\n";
} else {
    $pdo->prepare("INSERT INTO admins (username, password_hash, name, role, created_at)
                   VALUES (?,?,?,'admin',UTC_TIMESTAMP())")
        ->execute([$user, $hash, $user]);
    echo "Admin '$user' criado.\n";
}
echo "Usuário: $user\nSenha:   $pass\n";
