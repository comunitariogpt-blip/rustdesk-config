<?php
declare(strict_types=1);
// CLI: traz um banco JA EXISTENTE para o schema atual.
//   php migrate.php [--dry-run]
//
// Idempotente: cada passo checa o information_schema antes e so age se ainda
// for preciso. Pode rodar quantas vezes quiser. Existe porque schema.sql usa
// CREATE TABLE IF NOT EXISTS — em banco ja criado ele pula a tabela inteira em
// silencio e as colunas novas nunca chegam. O MySQL 8 nao tem
// "ADD COLUMN IF NOT EXISTS", por isso a checagem e feita aqui.
require_once __DIR__ . '/src/db.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$dry = in_array('--dry-run', $argv, true);
$pdo = db();
$did = 0;

function col_exists(string $t, string $c): bool {
    $st = db()->prepare('SELECT 1 FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
}
function idx_exists(string $t, string $i): bool {
    $st = db()->prepare('SELECT 1 FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$t, $i]);
    return (bool)$st->fetchColumn();
}
function table_exists(string $t): bool {
    $st = db()->prepare('SELECT 1 FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
}
function run(string $sql, string $label): void {
    global $dry, $did;
    $did++;
    if ($dry) { echo "  [dry-run] $label\n            $sql\n"; return; }
    db()->exec($sql);
    echo "  ✔ $label\n";
}

if (!table_exists('devices')) {
    exit("Banco vazio: rode primeiro  mysql -u ... < panel/schema.sql\n");
}
echo ($dry ? "Simulando" : "Migrando") . ' ' . DB_NAME . " …\n";

// --- 1. Senha de conexao reportada no heartbeat -----------------------------
if (!col_exists('devices', 'conn_password')) {
    run('ALTER TABLE devices ADD COLUMN conn_password VARCHAR(190) NULL,
                             ADD COLUMN conn_password_at DATETIME NULL',
        'devices: conn_password, conn_password_at');
} else {
    echo "  · devices.conn_password já existe\n";
}

// --- 2. Inativacao de dispositivo (global) ----------------------------------
if (!col_exists('devices', 'active')) {
    run('ALTER TABLE devices ADD COLUMN active TINYINT NOT NULL DEFAULT 1',
        'devices: active (dispositivos existentes ficam ativos)');
} else {
    echo "  · devices.active já existe\n";
}
if (!idx_exists('devices', 'idx_device_active')) {
    run('ALTER TABLE devices ADD INDEX idx_device_active (active)', 'devices: idx_device_active');
} else {
    echo "  · devices.idx_device_active já existe\n";
}

// --- 3. Apelido e favorito por admin ----------------------------------------
if (!table_exists('device_prefs')) {
    run('CREATE TABLE device_prefs (
           admin_id INT NOT NULL,
           device_id INT NOT NULL,
           alias VARCHAR(190) NULL,
           favorite TINYINT NOT NULL DEFAULT 0,
           updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           PRIMARY KEY (admin_id, device_id),
           CONSTRAINT fk_prefs_admin  FOREIGN KEY (admin_id)  REFERENCES admins(id)  ON DELETE CASCADE,
           CONSTRAINT fk_prefs_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
           INDEX idx_prefs_device (device_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'device_prefs criada');
} else {
    echo "  · device_prefs já existe\n";
}

// --- 4. Versao intermediaria: alias/favorite eram colunas globais de devices --
// Se o banco passou por ela, o que estava gravado e copiado para CADA admin
// (era global, entao todos continuam vendo o mesmo) antes de remover as colunas.
if (col_exists('devices', 'alias') || col_exists('devices', 'favorite')) {
    $n = (int)$pdo->query('SELECT COUNT(*) FROM devices
                           WHERE (alias IS NOT NULL AND alias <> "") OR favorite = 1')->fetchColumn();
    if ($n > 0) {
        run('INSERT INTO device_prefs (admin_id, device_id, alias, favorite)
             SELECT a.id, d.id, NULLIF(d.alias, ""), d.favorite
             FROM devices d CROSS JOIN admins a
             WHERE (d.alias IS NOT NULL AND d.alias <> "") OR d.favorite = 1
             ON DUPLICATE KEY UPDATE alias = VALUES(alias), favorite = VALUES(favorite)',
            "device_prefs: $n dispositivo(s) copiado(s) para cada admin");
    }
    $drop = [];
    if (col_exists('devices', 'alias'))    $drop[] = 'DROP COLUMN alias';
    if (col_exists('devices', 'favorite')) $drop[] = 'DROP COLUMN favorite';
    run('ALTER TABLE devices ' . implode(', ', $drop), 'devices: ' . implode(', ', $drop));
} else {
    echo "  · devices não tem alias/favorite globais (ok)\n";
}

// --- 5. Perfil das contas do painel -----------------------------------------
// O default e 'tecnico' (menor privilegio para qualquer INSERT que esqueca a
// coluna); o UPDATE logo em seguida promove quem ja existia, porque ate aqui
// toda conta do painel era administradora de fato.
if (!col_exists('admins', 'role')) {
    run("ALTER TABLE admins ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'tecnico'",
        'admins: role');
    run("UPDATE admins SET role = 'admin'",
        'admins: contas existentes viram Administrador');
} else {
    echo "  · admins.role já existe\n";
}

echo $did === 0
    ? "\nBanco já estava atualizado — nada a fazer.\n"
    : ($dry ? "\n$did passo(s) seriam aplicados. Rode sem --dry-run.\n" : "\n$did passo(s) aplicados.\n");
