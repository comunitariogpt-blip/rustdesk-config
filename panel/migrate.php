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

// --- 3. Perfil das contas do painel -----------------------------------------
// Vem antes do passo 4 de proposito: a consolidacao dos apelidos desempata por
// admins.role, e existe banco que ganhou device_prefs numa versao anterior a
// esta coluna. O default e 'tecnico' (menor privilegio para qualquer INSERT que
// esqueca a coluna); o UPDATE logo em seguida promove quem ja existia, porque
// ate aqui toda conta do painel era administradora de fato.
if (!col_exists('admins', 'role')) {
    run("ALTER TABLE admins ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'tecnico'",
        'admins: role');
    run("UPDATE admins SET role = 'admin'",
        'admins: contas existentes viram Administrador');
} else {
    echo "  · admins.role já existe\n";
}

// --- 4. Apelido e favorito globais ------------------------------------------
$add = [];
if (!col_exists('devices', 'alias'))    $add[] = 'ADD COLUMN alias VARCHAR(190) NULL';
if (!col_exists('devices', 'favorite')) $add[] = 'ADD COLUMN favorite TINYINT NOT NULL DEFAULT 0';
if ($add) {
    run('ALTER TABLE devices ' . implode(', ', $add), 'devices: alias, favorite (globais)');
} else {
    echo "  · devices.alias/favorite já existem\n";
}

// --- 5. Versao intermediaria: alias/favorite eram por admin, em device_prefs -
// Se o banco passou por ela, as preferencias das varias contas sao consolidadas
// num valor unico antes da tabela sair: o favorito e o MAX (qualquer estrela
// vira estrela para todos) e o apelido e o de um Administrador, com o mais
// recente desempatando. Subconsulta com LIMIT 1 em vez de GROUP_CONCAT, que tem
// limite de tamanho e quebraria com apelido contendo o separador.
if (table_exists('device_prefs')) {
    $n = (int)$pdo->query('SELECT COUNT(DISTINCT device_id) FROM device_prefs
                           WHERE (alias IS NOT NULL AND alias <> "") OR favorite = 1')->fetchColumn();
    if ($n > 0) {
        run('UPDATE devices d
             JOIN (SELECT device_id, MAX(favorite) AS fav FROM device_prefs GROUP BY device_id) p
               ON p.device_id = d.id
             SET d.favorite = p.fav',
            'devices.favorite: consolidado de device_prefs');
        run('UPDATE devices d
             SET d.alias = (
               SELECT p.alias FROM device_prefs p JOIN admins a ON a.id = p.admin_id
               WHERE p.device_id = d.id AND p.alias IS NOT NULL AND p.alias <> ""
               ORDER BY (a.role = "admin") DESC, p.updated_at DESC, p.admin_id ASC
               LIMIT 1)
             WHERE EXISTS (SELECT 1 FROM device_prefs p2
                           WHERE p2.device_id = d.id AND p2.alias IS NOT NULL AND p2.alias <> "")',
            'devices.alias: consolidado de device_prefs (o do Administrador vence)');
    }
    run('DROP TABLE device_prefs', "device_prefs removida ($n dispositivo(s) consolidado(s))");
} else {
    echo "  · device_prefs não existe (apelido/favorito já são globais)\n";
}

echo $did === 0
    ? "\nBanco já estava atualizado — nada a fazer.\n"
    : ($dry ? "\n$did passo(s) seriam aplicados. Rode sem --dry-run.\n" : "\n$did passo(s) aplicados.\n");
