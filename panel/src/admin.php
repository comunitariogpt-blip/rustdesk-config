<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';

const ONLINE_WINDOW = 90; // seconds since last heartbeat to count as online

// ===========================================================================
// Router
// ===========================================================================
function admin_dispatch(string $uri): void {
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($uri === '/login')  { $m === 'POST' ? do_login() : page_login(); return; }
    if ($uri === '/logout') { do_logout(); return; }

    $admin = require_admin();
    switch ($uri) {
        case '/':            page_dashboard($admin);   break;
        case '/operators':   $m === 'POST' ? operator_action($admin) : page_operators($admin); break;
        case '/devices':     page_devices($admin);     break;
        case '/connections': page_connections($admin); break;
        case '/audit':       page_audit($admin);       break;
        case '/settings':    $m === 'POST' ? settings_save($admin) : page_settings($admin); break;
        default: http_response_code(404); layout_simple('404', '<h2>Página não encontrada</h2>');
    }
}

// ===========================================================================
// Auth pages
// ===========================================================================
function page_login(string $err = ''): void {
    $csrf = csrf_token();
    $brand = e(PANEL_BRAND);
    $errHtml = $err ? '<div class="alert">' . e($err) . '</div>' : '';
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html><html lang="pt-br"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entrar · {$brand}</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="login-body"><div class="login-card">
<div class="login-logo">{$brand}</div>
<div class="login-sub">Painel de Suporte Remoto</div>
{$errHtml}
<form method="post" action="/login">
<input type="hidden" name="csrf" value="{$csrf}">
<label>Usuário</label><input name="username" autofocus required>
<label>Senha</label><input type="password" name="password" required>
<button type="submit">Entrar</button>
</form></div></body></html>
HTML;
}

function do_login(): void {
    check_csrf();
    $u = trim((string)($_POST['username'] ?? ''));
    $p = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT * FROM admins WHERE username = ?');
    $st->execute([$u]);
    $a = $st->fetch();
    if (!$a || !password_verify($p, $a['password_hash'])) {
        page_login('Usuário ou senha inválidos.');
        return;
    }
    start_admin_session();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$a['id'];
    db()->prepare('UPDATE admins SET last_login = ? WHERE id = ?')->execute([now_utc(), (int)$a['id']]);
    header('Location: /');
}

function do_logout(): void {
    start_admin_session();
    $_SESSION = [];
    session_destroy();
    header('Location: /login');
}

// ===========================================================================
// Dashboard
// ===========================================================================
function page_dashboard(array $admin): void {
    $pdo = db();
    $ops   = (int)$pdo->query('SELECT COUNT(*) FROM operators WHERE status=1')->fetchColumn();
    $devTotal = (int)$pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
    $devOnline = (int)$pdo->query(
        'SELECT COUNT(*) FROM devices WHERE last_seen >= UTC_TIMESTAMP() - INTERVAL ' . ONLINE_WINDOW . ' SECOND'
    )->fetchColumn();
    $connToday = (int)$pdo->query(
        "SELECT COUNT(*) FROM connections WHERE started_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY"
    )->fetchColumn();
    $conn7d = (int)$pdo->query(
        "SELECT COUNT(*) FROM connections WHERE started_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY"
    )->fetchColumn();

    // Connections per day (last 14d), grouped by São Paulo date (-03:00)
    $rows = $pdo->query(
        "SELECT DATE(CONVERT_TZ(started_at,'+00:00','-03:00')) d, COUNT(*) c
         FROM connections
         WHERE started_at >= UTC_TIMESTAMP() - INTERVAL 14 DAY AND started_at IS NOT NULL
         GROUP BY d ORDER BY d"
    )->fetchAll();
    $byDay = [];
    foreach ($rows as $r) $byDay[$r['d']] = (int)$r['c'];
    $days = []; $dayCounts = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = (new DateTime("-$i day", new DateTimeZone(DISPLAY_TZ)))->format('Y-m-d');
        $days[] = (new DateTime($d))->format('d/m');
        $dayCounts[] = $byDay[$d] ?? 0;
    }

    // Top sources (technician device) last 30d
    $top = $pdo->query(
        "SELECT COALESCE(NULLIF(peer_name,''), peer_id, '—') label, COUNT(*) c
         FROM connections
         WHERE started_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
         GROUP BY label ORDER BY c DESC LIMIT 8"
    )->fetchAll();
    $topLabels = array_map(fn($r) => $r['label'], $top);
    $topCounts = array_map(fn($r) => (int)$r['c'], $top);

    // Recent connections
    $recent = $pdo->query(
        "SELECT * FROM connections ORDER BY id DESC LIMIT 10"
    )->fetchAll();

    $jsDays   = json_encode($days);
    $jsCounts = json_encode($dayCounts);
    $jsTopL   = json_encode($topLabels ?: ['—']);
    $jsTopC   = json_encode($topCounts ?: [0]);
    $devOff   = max(0, $devTotal - $devOnline);

    ob_start(); ?>
    <div class="kpis">
      <div class="kpi"><div class="kpi-n"><?= $ops ?></div><div class="kpi-l">Operadores ativos</div></div>
      <div class="kpi"><div class="kpi-n"><?= $devOnline ?> / <?= $devTotal ?></div><div class="kpi-l">Dispositivos online</div></div>
      <div class="kpi"><div class="kpi-n"><?= $connToday ?></div><div class="kpi-l">Conexões (24h)</div></div>
      <div class="kpi"><div class="kpi-n"><?= $conn7d ?></div><div class="kpi-l">Conexões (7 dias)</div></div>
    </div>
    <div class="grid2">
      <div class="card"><h3>Conexões por dia (14 dias)</h3><canvas id="chDays" height="120"></canvas></div>
      <div class="card"><h3>Dispositivos</h3><canvas id="chDev" height="120"></canvas></div>
    </div>
    <div class="card"><h3>Conexões por técnico (origem · 30 dias)</h3><canvas id="chTop" height="90"></canvas></div>
    <div class="card"><h3>Conexões recentes</h3><?= render_conn_table($recent) ?></div>
    <script src="/assets/chart.umd.min.js"></script>
    <script>
    const C='#00AEEF', G='rgba(0,174,239,.15)';
    new Chart(chDays,{type:'line',data:{labels:<?= $jsDays ?>,datasets:[{label:'Conexões',data:<?= $jsCounts ?>,borderColor:C,backgroundColor:G,fill:true,tension:.3}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
    new Chart(chDev,{type:'doughnut',data:{labels:['Online','Offline'],datasets:[{data:[<?= $devOnline ?>,<?= $devOff ?>],backgroundColor:[C,'#cbd5e1']}]},options:{plugins:{legend:{position:'bottom'}}}});
    new Chart(chTop,{type:'bar',data:{labels:<?= $jsTopL ?>,datasets:[{label:'Conexões',data:<?= $jsTopC ?>,backgroundColor:C}]},options:{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0}}}}});
    </script>
    <?php
    layout(ob_get_clean(), $admin, 'dashboard', 'Visão geral');
}

// ===========================================================================
// Operators CRUD
// ===========================================================================
function page_operators(array $admin, string $flash = ''): void {
    $ops = db()->query('SELECT * FROM operators ORDER BY username')->fetchAll();
    $csrf = csrf_token();
    ob_start();
    if ($flash) echo '<div class="alert ok">' . e($flash) . '</div>';
    ?>
    <div class="card">
      <h3>Novo operador</h3>
      <form method="post" action="/operators" class="formrow">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="create">
        <input name="username" placeholder="usuário" required>
        <input name="name" placeholder="nome">
        <input name="email" placeholder="email" type="email">
        <input name="password" placeholder="senha" required>
        <label class="chk"><input type="checkbox" name="is_admin" value="1"> admin</label>
        <button type="submit">Criar</button>
      </form>
    </div>
    <div class="card"><h3>Operadores</h3>
    <table class="tbl"><thead><tr>
      <th>Usuário</th><th>Nome</th><th>Email</th><th>Admin</th><th>Status</th><th>Último login</th><th>Ações</th>
    </tr></thead><tbody>
    <?php foreach ($ops as $o): ?>
      <tr>
        <td><?= e($o['username']) ?></td>
        <td><?= e($o['name']) ?></td>
        <td><?= e($o['email']) ?></td>
        <td><?= ((int)$o['is_admin']) ? '✔' : '' ?></td>
        <td><?= ((int)$o['status']) === 1
              ? '<span class="badge on">ativo</span>'
              : '<span class="badge off">desativado</span>' ?></td>
        <td><?= e(fmt_dt($o['last_login'])) ?></td>
        <td class="actions">
          <form method="post" action="/operators" onsubmit="return this.np.value!==''">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
            <input name="np" placeholder="nova senha" size="10">
            <button>Resetar</button>
          </form>
          <form method="post" action="/operators">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
            <button><?= ((int)$o['status'])===1 ? 'Desativar' : 'Ativar' ?></button>
          </form>
          <form method="post" action="/operators" onsubmit="return confirm('Excluir operador <?= e($o['username']) ?>?')">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
            <button class="danger">Excluir</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php
    layout(ob_get_clean(), $admin, 'operators', 'Operadores');
}

function operator_action(array $admin): void {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $pdo = db();
    try {
        if ($action === 'create') {
            $u = trim((string)($_POST['username'] ?? ''));
            $p = (string)($_POST['password'] ?? '');
            if ($u === '' || $p === '') throw new RuntimeException('Usuário e senha obrigatórios.');
            $pdo->prepare('INSERT INTO operators (username, password_hash, name, email, is_admin, status, created_at) VALUES (?,?,?,?,?,1,?)')
                ->execute([$u, password_hash($p, PASSWORD_BCRYPT),
                           trim((string)($_POST['name'] ?? '')), trim((string)($_POST['email'] ?? '')),
                           isset($_POST['is_admin']) ? 1 : 0, now_utc()]);
            page_operators($admin, "Operador “$u” criado."); return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'toggle') {
            $pdo->prepare('UPDATE operators SET status = 1 - status WHERE id = ?')->execute([$id]);
            $pdo->prepare('UPDATE operator_tokens SET revoked = 1 WHERE operator_id = ? AND ? = 0')
                ->execute([$id, (int)$pdo->query("SELECT status FROM operators WHERE id=".(int)$id)->fetchColumn()]);
            page_operators($admin, 'Status atualizado.'); return;
        }
        if ($action === 'reset') {
            $np = (string)($_POST['np'] ?? '');
            if ($np === '') throw new RuntimeException('Informe a nova senha.');
            $pdo->prepare('UPDATE operators SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($np, PASSWORD_BCRYPT), $id]);
            $pdo->prepare('UPDATE operator_tokens SET revoked = 1 WHERE operator_id = ?')->execute([$id]);
            page_operators($admin, 'Senha redefinida (sessões encerradas).'); return;
        }
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM operators WHERE id = ?')->execute([$id]);
            page_operators($admin, 'Operador excluído.'); return;
        }
        throw new RuntimeException('Ação inválida.');
    } catch (Throwable $ex) {
        $msg = $ex instanceof RuntimeException ? $ex->getMessage() : 'Erro: ' . $ex->getMessage();
        page_operators($admin, $msg);
    }
}

// ===========================================================================
// Devices
// ===========================================================================
function page_devices(array $admin): void {
    $rows = db()->query(
        'SELECT *, (last_seen >= UTC_TIMESTAMP() - INTERVAL ' . ONLINE_WINDOW . ' SECOND) AS is_online
         FROM devices ORDER BY is_online DESC, last_seen DESC'
    )->fetchAll();
    ob_start(); ?>
    <div class="card"><h3>Dispositivos (<?= count($rows) ?>)</h3>
    <table class="tbl"><thead><tr>
      <th>ID</th><th>Status</th><th>Host</th><th>Usuário</th><th>SO</th><th>Versão</th><th>IP</th><th>Visto por último</th>
    </tr></thead><tbody>
    <?php foreach ($rows as $d): ?>
      <tr>
        <td class="mono"><?= e($d['peer_id']) ?></td>
        <td><?= ((int)$d['is_online'])
              ? '<span class="badge on">online</span>'
              : '<span class="badge off">offline</span>' ?></td>
        <td><?= e($d['hostname']) ?></td>
        <td><?= e($d['username']) ?></td>
        <td><?= e($d['os']) ?></td>
        <td><?= e($d['version']) ?></td>
        <td class="mono"><?= e(clean_ip($d['last_ip'])) ?></td>
        <td><?= e(fmt_dt($d['last_seen'])) ?></td>
      </tr>
    <?php endforeach; if (!$rows) echo '<tr><td colspan="8" class="muted">Nenhum dispositivo registrado ainda.</td></tr>'; ?>
    </tbody></table></div>
    <?php
    layout(ob_get_clean(), $admin, 'devices', 'Dispositivos');
}

// ===========================================================================
// Connections history
// ===========================================================================
function page_connections(array $admin): void {
    $q    = trim((string)($_GET['q'] ?? ''));
    $days = (int)($_GET['days'] ?? 30);
    if ($days <= 0) $days = 30;
    $sql = "SELECT * FROM connections WHERE started_at >= UTC_TIMESTAMP() - INTERVAL :days DAY";
    $params = [':days' => $days];
    if ($q !== '') {
        $sql .= " AND (device_id LIKE :q OR peer_id LIKE :q OR peer_name LIKE :q)";
        $params[':q'] = "%$q%";
    }
    $sql .= " ORDER BY id DESC LIMIT 500";
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    ob_start(); ?>
    <div class="card">
      <form method="get" action="/connections" class="formrow">
        <input name="q" value="<?= e($q) ?>" placeholder="ID, técnico…">
        <select name="days">
          <?php foreach ([1=>'24h',7=>'7 dias',30=>'30 dias',90=>'90 dias',365=>'1 ano'] as $k=>$lbl): ?>
            <option value="<?= $k ?>" <?= $days===$k?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <button>Filtrar</button>
      </form>
    </div>
    <div class="card"><h3>Histórico de conexões (<?= count($rows) ?><?= count($rows)===500?'+':'' ?>)</h3>
    <?= render_conn_table($rows, true) ?></div>
    <?php
    layout(ob_get_clean(), $admin, 'connections', 'Conexões');
}

function render_conn_table(array $rows, bool $full = false): string {
    ob_start(); ?>
    <table class="tbl"><thead><tr>
      <th>Início</th><th>Fim</th><th>Duração</th><th>Dispositivo</th><th>Técnico (origem)</th><th>Tipo</th><?php if($full) echo '<th>IP</th><th>Nota</th>'; ?>
    </tr></thead><tbody>
    <?php foreach ($rows as $c):
        $dur = conn_duration($c['started_at'] ?? null, $c['ended_at'] ?? null); ?>
      <tr>
        <td><?= e(fmt_dt($c['started_at'])) ?></td>
        <td><?= $c['ended_at'] ? e(fmt_dt($c['ended_at'])) : '<span class="badge on">ativa</span>' ?></td>
        <td><?= e($dur) ?></td>
        <td class="mono"><?= e($c['device_id']) ?></td>
        <td><?= e(($c['peer_name'] ?: $c['peer_id']) ?: '—') ?></td>
        <td><?= e(conn_type_label($c['conn_type'])) ?></td>
        <?php if ($full): ?><td class="mono"><?= e(clean_ip($c['ip'])) ?></td><td><?= e($c['note']) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; if (!$rows) echo '<tr><td colspan="'.($full?8:6).'" class="muted">Nenhuma conexão registrada.</td></tr>'; ?>
    </tbody></table>
    <?php
    return ob_get_clean();
}

// ===========================================================================
// Login audit
// ===========================================================================
function page_audit(array $admin): void {
    $rows = db()->query('SELECT * FROM login_audit ORDER BY id DESC LIMIT 300')->fetchAll();
    ob_start(); ?>
    <div class="card"><h3>Auditoria de login (operadores)</h3>
    <table class="tbl"><thead><tr>
      <th>Data</th><th>Usuário</th><th>Resultado</th><th>IP</th><th>Dispositivo</th>
    </tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e(fmt_dt($r['created_at'])) ?></td>
        <td><?= e($r['username']) ?></td>
        <td><?= ((int)$r['success'])
              ? '<span class="badge on">sucesso</span>'
              : '<span class="badge off">falha</span>' ?></td>
        <td class="mono"><?= e(clean_ip($r['ip'])) ?></td>
        <td class="mono"><?= e($r['device_id']) ?></td>
      </tr>
    <?php endforeach; if (!$rows) echo '<tr><td colspan="5" class="muted">Sem registros.</td></tr>'; ?>
    </tbody></table></div>
    <?php
    layout(ob_get_clean(), $admin, 'audit', 'Auditoria');
}

// ===========================================================================
// Settings (login policy / legacy support)
// ===========================================================================
function page_settings(array $admin, string $flash = ''): void {
    $requireLogin = setting_get('require_login', '0') === '1';
    $csrf = csrf_token();
    ob_start();
    if ($flash) echo '<div class="alert ok">' . e($flash) . '</div>';
    ?>
    <div class="card">
      <h3>Política de login</h3>
      <p class="muted" style="text-align:left">
        Controla se o app <b>Operador</b> (<?= e(PANEL_BRAND) ?>) exige login antes de
        conectar. A mudança chega aos apps já instalados em ~15&nbsp;segundos, sem recompilar.
        Os instaladores <b>legados</b> (compilados sem login) continuam funcionando sempre —
        eles nem falam com este painel.
      </p>
      <form method="post" action="/settings">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label class="switch">
          <input type="checkbox" name="require_login" value="1" <?= $requireLogin ? 'checked' : '' ?>>
          <span>Exigir login e senha no app Operador</span>
        </label>
        <div class="hint">
          <?php if ($requireLogin): ?>
            <span class="badge on">ATIVO</span> Apps Operador exigem login. Pessoas sem conta não conseguem conectar.
          <?php else: ?>
            <span class="badge off">DESLIGADO (modo legado)</span> Login é opcional — compatível com a fase de transição.
          <?php endif; ?>
        </div>
        <div style="margin-top:14px"><button type="submit">Salvar</button></div>
      </form>
    </div>
    <div class="card">
      <h3>Servidor / Integração</h3>
      <table class="tbl">
        <tr><td>API server (embutido nos apps novos)</td><td class="mono"><?= e(API_SERVER_URL) ?></td></tr>
        <tr><td>Servidor de rendezvous (ID)</td><td class="mono"><?= e(ID_SERVER ?: '—') ?></td></tr>
      </table>
    </div>
    <?php
    layout(ob_get_clean(), $admin, 'settings', 'Configurações');
}

function settings_save(array $admin): void {
    check_csrf();
    setting_set('require_login', isset($_POST['require_login']) ? '1' : '0');
    page_settings($admin, 'Configuração salva. Os apps Operador serão atualizados em até ~15s.');
}

// ===========================================================================
// Helpers
// ===========================================================================
function conn_type_label($t): string {
    return [0=>'Área de trabalho',1=>'Arquivos',2=>'Túnel/RDP',3=>'Câmera',4=>'Terminal'][(int)$t] ?? '—';
}
function conn_duration(?string $a, ?string $b): string {
    if (!$a) return '—';
    $end = $b ? strtotime($b) : time();
    $s = max(0, $end - strtotime($a));
    if ($s < 60) return $s . 's';
    if ($s < 3600) return floor($s/60) . 'm ' . ($s%60) . 's';
    return floor($s/3600) . 'h ' . floor(($s%3600)/60) . 'm';
}
function clean_ip(?string $ip): string {
    $ip = (string)$ip;
    return str_starts_with($ip, '::ffff:') ? substr($ip, 7) : $ip;
}

// ===========================================================================
// Layout
// ===========================================================================
function layout(string $body, array $admin, string $active, string $title): void {
    $brand = e(PANEL_BRAND);
    $nav = [
        'dashboard'   => ['/',            'Visão geral'],
        'operators'   => ['/operators',   'Operadores'],
        'devices'     => ['/devices',     'Dispositivos'],
        'connections' => ['/connections', 'Conexões'],
        'audit'       => ['/audit',       'Auditoria'],
        'settings'    => ['/settings',    'Configurações'],
    ];
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' · ' . $brand . '</title><link rel="stylesheet" href="/assets/app.css"></head><body>';
    echo '<aside class="side"><div class="brand">' . $brand . '<span>suporte</span></div><nav>';
    foreach ($nav as $k => [$href, $label]) {
        $cls = $k === $active ? ' class="active"' : '';
        echo "<a href=\"$href\"$cls>" . e($label) . '</a>';
    }
    echo '</nav><div class="side-foot">' . e($admin['username']) . '<br><a href="/logout">sair</a></div></aside>';
    echo '<main><header class="topbar"><h1>' . e($title) . '</h1></header><div class="content">' . $body . '</div></main>';
    echo '</body></html>';
}

function layout_simple(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>' . e($title) . '</title>';
    echo '<link rel="stylesheet" href="/assets/app.css"><div class="content">' . $body . '</div>';
}
