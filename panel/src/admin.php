<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';

const ONLINE_WINDOW = 90; // seconds since last heartbeat to count as online

// ===========================================================================
// Menu e permissao — fonte unica
// ===========================================================================
// O menu da barra lateral e a lista de rotas restritas sao a MESMA coisa: se
// fossem dois arrays separados eles divergiriam no primeiro item novo, e um
// item some do menu mas continua acessivel pela barra de enderecos. Quem so
// tem 'adminOnly' => false e o que o perfil Tecnico enxerga.
function panel_nav(): array {   // navkey => [href, label, adminOnly]
    return [
        'dashboard'   => ['/',            'Visão geral',   false],
        'devices'     => ['/devices',     'Dispositivos',  false],
        'users'       => ['/users',       'Usuários',      true],
        'operators'   => ['/operators',   'Operadores',    true],
        'connections' => ['/connections', 'Conexões',      true],
        'audit'       => ['/audit',       'Auditoria',     true],
        'settings'    => ['/settings',    'Configurações', true],
    ];
}

/** True quando a rota existe no menu e exige perfil de administrador. */
function route_is_admin_only(string $uri): bool {
    foreach (panel_nav() as [$href, $label, $adminOnly]) {
        if ($href === $uri) return $adminOnly;
    }
    return false;
}

function deny_page(): void {
    http_response_code(403);
    layout_simple('Acesso restrito',
        '<h2>Acesso restrito</h2>
         <p class="muted">Esta área é exclusiva de administradores do painel.
         Fale com um administrador se precisar de acesso.</p>
         <p><a href="/">Voltar para a visão geral</a></p>');
}

// ===========================================================================
// Router
// ===========================================================================
function admin_dispatch(string $uri): void {
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($uri === '/login')  { $m === 'POST' ? do_login() : page_login(); return; }
    if ($uri === '/logout') { do_logout(); return; }

    $admin = require_admin();
    // Vale para GET e POST de uma vez so: nenhuma tela restrita precisa repetir
    // a checagem, e um POST forjado para /users ou /settings para aqui.
    if (route_is_admin_only($uri) && !is_panel_admin($admin)) { deny_page(); return; }

    switch ($uri) {
        case '/':            page_dashboard($admin);   break;
        case '/users':       $m === 'POST' ? users_action($admin) : page_users($admin); break;
        case '/operators':   $m === 'POST' ? operator_action($admin) : page_operators($admin); break;
        case '/devices':     $m === 'POST' ? device_action($admin) : page_devices($admin); break;
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
<label>Senha</label>
<div class="pw-field">
  <input type="password" name="password" id="pw" required>
  <button type="button" class="pw-eye" id="pw-eye" title="Mostrar senha" aria-label="Mostrar senha" aria-pressed="false" aria-controls="pw">
    <svg class="ico-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    <svg class="ico-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20C5 20 1 12 1 12a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
  </button>
</div>
<button type="submit">Entrar</button>
</form></div>
<script>
(function () {
  var f = document.querySelector('.pw-field');
  var i = document.getElementById('pw');
  var b = document.getElementById('pw-eye');
  b.addEventListener('click', function () {
    var show = i.type === 'password';
    i.type = show ? 'text' : 'password';
    f.classList.toggle('show', show);
    b.setAttribute('aria-pressed', show ? 'true' : 'false');
    b.title = show ? 'Ocultar senha' : 'Mostrar senha';
    b.setAttribute('aria-label', b.title);
    i.focus();
  });
})();
</script>
</body></html>
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
    // Dispositivos inativados ficam de fora dos KPIs: eles existem justamente
    // para tirar PCs antigos da vista.
    $devTotal = (int)$pdo->query('SELECT COUNT(*) FROM devices WHERE active = 1')->fetchColumn();
    $devOnline = (int)$pdo->query(
        'SELECT COUNT(*) FROM devices WHERE active = 1 AND last_seen >= UTC_TIMESTAMP() - INTERVAL ' . ONLINE_WINDOW . ' SECOND'
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
// Usuarios do painel (tabela admins) — so administradores chegam aqui
// ===========================================================================
function page_users(array $admin, string $flash = '', bool $openNew = false): void {
    $users = db()->query('SELECT * FROM admins ORDER BY username')->fetchAll();
    $csrf  = csrf_token();
    $me    = (int)$admin['id'];
    ob_start();
    if ($flash) echo '<div class="alert ok">' . e($flash) . '</div>';
    ?>
    <div class="card">
      <?php /* O cartao comeca fechado: so o botao aparece. Reabrir/fechar e
               so pelo botao, e recarregar a pagina volta ao estado fechado.
               $openNew deixa aberto quando um cadastro acabou de falhar, para
               a pessoa nao perder o que digitou de vista. */ ?>
      <button type="button" id="nu-toggle" aria-expanded="<?= $openNew ? 'true' : 'false' ?>" aria-controls="nu-box">
        Novo usuário
      </button>
      <div id="nu-box" <?= $openNew ? '' : 'hidden' ?>>
        <p class="muted" style="margin-top:14px">
          Contas deste painel. Não confunda com <a href="/operators">Operadores</a>,
          que são as contas usadas para entrar no app Operador do
          <?= e(PANEL_BRAND) ?> — são cadastros separados.
        </p>
        <form method="post" action="/users" class="formrow">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="create">
          <input name="username" placeholder="usuário" required>
          <input name="name" placeholder="nome">
          <input name="password" placeholder="senha" required>
          <select name="role">
            <option value="tecnico">Técnico</option>
            <option value="admin">Administrador</option>
          </select>
          <button type="submit">Criar</button>
        </form>
        <ul class="hint roles">
          <li><b>Administrador</b> — vê e faz tudo, inclusive cadastrar usuários.</li>
          <li><b>Técnico</b> — vê apenas a visão geral e a lista de dispositivos
            ativos, com o nome e a estrela que o administrador definiu; não pode
            renomear, favoritar, inativar nem excluir dispositivo nenhum.</li>
        </ul>
      </div>
    </div>
    <script>
    (function () {
      var b = document.getElementById('nu-toggle'),
          box = document.getElementById('nu-box');
      b.addEventListener('click', function () {
        var open = box.hidden;
        box.hidden = !open;
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) box.querySelector('input[name=username]').focus();
      });
    })();
    </script>
    <div class="card"><h3>Usuários do painel</h3>
    <table class="tbl"><thead><tr>
      <th>Usuário</th><th>Nome</th><th>Perfil</th><th>Criado em</th><th>Último login</th><th>Ações</th>
    </tr></thead><tbody>
    <?php foreach ($users as $u):
        $uid    = (int)$u['id'];
        $isSelf = $uid === $me;
        $isAdm  = is_panel_admin($u); ?>
      <tr>
        <td><?= e($u['username']) ?><?= $isSelf ? ' <span class="muted-inline">(você)</span>' : '' ?></td>
        <td><?= e($u['name']) ?></td>
        <td><span class="badge <?= $isAdm ? 'on' : 'off' ?>"><?= e(role_label($u['role'] ?? null)) ?></span></td>
        <td><?= e(fmt_dt($u['created_at'])) ?></td>
        <td><?= e(fmt_dt($u['last_login'])) ?></td>
        <td class="actions">
          <form method="post" action="/users" onsubmit="return this.np.value!==''">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="id" value="<?= $uid ?>">
            <input name="np" placeholder="nova senha" size="10">
            <button>Resetar</button>
          </form>
          <?php /* A propria conta nao troca de perfil nem se exclui: e isso que
                   garante que sempre sobre pelo menos um administrador. */ ?>
          <?php if (!$isSelf): ?>
            <form method="post" action="/users">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="role">
              <input type="hidden" name="id" value="<?= $uid ?>">
              <select name="role">
                <option value="tecnico" <?= $isAdm ? '' : 'selected' ?>>Técnico</option>
                <option value="admin" <?= $isAdm ? 'selected' : '' ?>>Administrador</option>
              </select>
              <button>Alterar perfil</button>
            </form>
            <form method="post" action="/users" onsubmit="return confirm('Excluir o usuário <?= e($u['username']) ?>? Ele perde o acesso ao painel; os dispositivos não são afetados.')">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $uid ?>">
              <button class="danger">Excluir</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <p class="muted" style="margin-top:10px">
      O <b>apelido</b> e a <b>estrela</b> dos dispositivos são globais: valem para
      todas as contas e continuam lá depois de excluir um usuário. Um usuário não
      pode alterar o próprio perfil nem se excluir — peça a outro administrador.
    </p></div>
    <?php
    layout(ob_get_clean(), $admin, 'users', 'Usuários');
}

function users_action(array $admin): void {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $me  = (int)$admin['id'];
    $pdo = db();
    try {
        if ($action === 'create') {
            $u = trim((string)($_POST['username'] ?? ''));
            $p = (string)($_POST['password'] ?? '');
            if ($u === '' || $p === '') throw new RuntimeException('Usuário e senha obrigatórios.');
            $pdo->prepare('INSERT INTO admins (username, password_hash, name, role, created_at) VALUES (?,?,?,?,?)')
                ->execute([$u, password_hash($p, PASSWORD_BCRYPT),
                           trim((string)($_POST['name'] ?? '')),
                           posted_role(), now_utc()]);
            page_users($admin, "Usuário “$u” criado como " . role_label(posted_role()) . '.'); return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'reset') {
            $np = (string)($_POST['np'] ?? '');
            if ($np === '') throw new RuntimeException('Informe a nova senha.');
            $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($np, PASSWORD_BCRYPT), $id]);
            page_users($admin, 'Senha redefinida.'); return;
        }
        // As duas travas abaixo sao o que impede o painel de ficar sem nenhum
        // administrador: como ninguem se rebaixa nem se apaga, o ultimo admin
        // sempre continua de pe.
        if ($action === 'role') {
            if ($id === $me) throw new RuntimeException('Você não pode alterar o próprio perfil.');
            $r = posted_role();
            $pdo->prepare('UPDATE admins SET role = ? WHERE id = ?')->execute([$r, $id]);
            page_users($admin, 'Perfil atualizado para ' . role_label($r) . '.'); return;
        }
        if ($action === 'delete') {
            if ($id === $me) throw new RuntimeException('Você não pode excluir a própria conta.');
            $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
            page_users($admin, 'Usuário excluído.'); return;
        }
        throw new RuntimeException('Ação inválida.');
    } catch (Throwable $ex) {
        $msg = $ex instanceof RuntimeException ? $ex->getMessage() : 'Erro: ' . $ex->getMessage();
        // Cadastro que falhou reabre o formulario junto com a mensagem.
        page_users($admin, $msg, $action === 'create');
    }
}

/** Perfil vindo do formulario; qualquer coisa fora da lista vira Tecnico. */
function posted_role(): string {
    return ((string)($_POST['role'] ?? '')) === 'admin' ? 'admin' : 'tecnico';
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
function page_devices(array $admin, string $flash = ''): void {
    // Tecnico nao inativa nem reativa nada, entao um PC inativado (= descartado)
    // so lhe apareceria como linha morta que ele nao pode resolver: para ele a
    // consulta ja sai filtrada e o seletor de situacao nem existe.
    $isAdm = is_panel_admin($admin);

    // Traz a lista inteira (inclusive inativos): a filtragem acontece no
    // navegador, sem recarregar. Favoritos e ativos primeiro. Apelido e
    // favorito sao colunas de devices, globais: todo mundo ve o mesmo nome.
    $rows = db()->query(
        'SELECT d.*, (d.last_seen >= UTC_TIMESTAMP() - INTERVAL ' . ONLINE_WINDOW . ' SECOND) AS is_online
         FROM devices d'
        . ($isAdm ? '' : ' WHERE d.active = 1') .
        ' ORDER BY d.active DESC, d.favorite DESC, is_online DESC, d.last_seen DESC'
    )->fetchAll();
    $csrf = csrf_token();

    // Estado inicial dos filtros vindo da URL, para o link ser compartilhavel.
    // '' = todos; situacao ('sit') vem como 'all' na URL porque o padrao e ativos.
    $fq  = trim((string)($_GET['q'] ?? ''));
    $fSt = (string)($_GET['status'] ?? '');
    $fFv = (string)($_GET['fav'] ?? '');
    $fSi = (string)($_GET['sit'] ?? '1');
    if ($fSi === 'all') $fSi = '';
    if (!in_array($fSt, ['', '0', '1'], true)) $fSt = '';
    if (!in_array($fFv, ['', '0', '1'], true)) $fFv = '';
    if (!in_array($fSi, ['', '0', '1'], true)) $fSi = '1';

    ob_start();
    if ($flash) echo '<div class="alert ok">' . e($flash) . '</div>';
    ?>
    <div class="card">
      <div class="formrow filters">
        <input id="f-q" value="<?= e($fq) ?>" placeholder="Buscar por ID, nome ou host…" size="28">
        <select id="f-status">
          <?php foreach (['' => 'Online e offline', '1' => 'Só online', '0' => 'Só offline'] as $k => $lbl): ?>
            <?php /* (string)$k: chaves numericas viram int no array PHP */ ?>
            <option value="<?= $k ?>" <?= (string)$k === $fSt ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <select id="f-fav">
          <?php foreach (['' => 'Favoritos e não favoritos', '1' => 'Só favoritos', '0' => 'Só não favoritos'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= (string)$k === $fFv ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($isAdm): ?>
        <select id="f-sit">
          <?php foreach (['1' => 'Ativos', '0' => 'Inativos', '' => 'Ativos e inativos'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= (string)$k === $fSi ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="button" id="f-clear">Limpar</button>
      </div>
    </div>
    <div class="card"><h3>Dispositivos (<span id="dev-count"><?= count($rows) ?></span>)</h3>
    <table class="tbl"><thead><tr>
      <th></th><th>ID / Nome</th><th>Status</th><th>Senha</th><th>Visto por último</th><th>Ações</th>
    </tr></thead><tbody id="dev-tbody">
    <?php foreach ($rows as $d):
        $act   = (int)$d['active'];
        $fav   = (int)$d['favorite'];
        $alias = (string)($d['alias'] ?? '');
        $label = $alias !== '' ? $alias : (string)$d['peer_id']; ?>
      <tr<?= $act ? '' : ' class="row-off"' ?>
          data-search="<?= e(mb_strtolower($d['peer_id'] . ' ' . $alias . ' ' . (string)$d['hostname'])) ?>"
          data-online="<?= (int)$d['is_online'] ?>" data-fav="<?= $fav ?>" data-active="<?= $act ?>">
        <td>
          <?php /* Nome e estrela valem para todos, entao so administrador
                   altera. O tecnico ve a estrela como texto, sem formulario. */ ?>
          <?php if ($isAdm): ?>
          <form method="post" action="/devices" data-keep>
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="fav">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="star<?= $fav ? ' on' : '' ?>" title="<?= $fav ? 'Remover dos favoritos' : 'Marcar como favorito' ?>"><?= $fav ? '★' : '☆' ?></button>
          </form>
          <?php else: ?>
          <span class="star<?= $fav ? ' on' : '' ?>" title="<?= $fav ? 'Favorito' : 'Não favorito' ?>"><?= $fav ? '★' : '☆' ?></span>
          <?php endif; ?>
        </td>
        <td class="dev-cell">
          <div class="dev-head">
            <span class="dev-name<?= $alias === '' ? ' muted-inline' : '' ?>"><?= e($alias !== '' ? $alias : 'sem nome') ?></span>
            <?php if ($isAdm): ?><a href="#" class="edit-alias" title="Editar nome">✎</a><?php endif; ?>
          </div>
          <div class="mono dev-peer"><?= e($d['peer_id']) ?></div>
          <?php if ($isAdm): ?>
          <form method="post" action="/devices" class="alias-form" hidden data-keep>
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="alias">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <input class="alias" name="alias" value="<?= e($alias) ?>" placeholder="dar um nome…" maxlength="190">
            <button>Salvar</button>
          </form>
          <?php endif; ?>
        </td>
        <td><?= ((int)$d['is_online'])
              ? '<span class="badge on">online</span>'
              : '<span class="badge off">offline</span>' ?>
          <?= $act ? '' : ' <span class="badge off">inativo</span>' ?></td>
        <td class="mono">
          <?php $pw = (string)($d['conn_password'] ?? ''); if ($pw !== ''): ?>
            <span class="pw" data-pw="<?= e($pw) ?>">••••••</span>
            <a href="#" class="pw-toggle" title="Mostrar/ocultar">👁</a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= e(fmt_dt($d['last_seen'])) ?></td>
        <td class="actions">
          <?php /* Todo o inventario vai num unico atributo JSON: o popup e
                   montado no navegador, sem consulta nem endpoint novo. */ ?>
          <button type="button" class="details" data-dev="<?= e(json_encode([
              'peer'   => (string)$d['peer_id'],
              'name'   => $alias,
              'online' => (int)$d['is_online'],
              'active' => $act,
              'host'   => (string)$d['hostname'],
              'user'   => (string)$d['username'],
              'os'     => (string)$d['os'],
              'cpu'    => (string)$d['cpu'],
              'mem'    => (string)$d['memory'],
              'ver'    => (string)$d['version'],
              'ip'     => clean_ip($d['last_ip']),
              'uuid'   => (string)$d['uuid'],
              'pw'     => (string)($d['conn_password'] ?? ''),
              'pwAt'   => fmt_dt($d['conn_password_at']),
              'first'  => fmt_dt($d['first_seen']),
              'last'   => fmt_dt($d['last_seen']),
          ], JSON_UNESCAPED_UNICODE)) ?>">Detalhes</button>
          <?php /* Inativar e excluir sao globais — so administrador. Esconder o
                   botao nao e protecao: device_action() recusa a acao de novo. */ ?>
          <?php if ($isAdm): ?>
          <form method="post" action="/devices" data-keep>
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button><?= $act ? 'Inativar' : 'Reativar' ?></button>
          </form>
          <form method="post" action="/devices" data-keep onsubmit="return confirm('Excluir definitivamente o dispositivo <?= e($label) ?>? O histórico de conexões é preservado, mas ele volta a aparecer se o PC mandar heartbeat de novo. Para apenas tirá-lo da lista, use Inativar.')">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="danger">Excluir</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach;
      if (!$rows) echo '<tr><td colspan="6" class="muted">Nenhum dispositivo registrado ainda.</td></tr>'; ?>
      <tr id="no-match" style="display:none"><td colspan="6" class="muted">Nenhum dispositivo corresponde ao filtro.</td></tr>
    </tbody></table>
    <dialog id="dev-modal" class="modal">
      <div class="modal-head">
        <div>
          <h3 id="dm-title"></h3>
          <div id="dm-badges"></div>
        </div>
        <button type="button" class="modal-x" id="dm-close" aria-label="Fechar">✕</button>
      </div>
      <div class="modal-body">
        <dl class="dm-grid">
          <dt>Host</dt><dd id="dm-host"></dd>
          <dt>Usuário</dt><dd id="dm-user"></dd>
          <dt>Sistema</dt><dd id="dm-os"></dd>
          <dt>CPU</dt><dd id="dm-cpu"></dd>
          <dt>Memória</dt><dd id="dm-mem"></dd>
          <dt>Versão</dt><dd id="dm-ver"></dd>
          <dt>IP</dt><dd id="dm-ip" class="mono"></dd>
          <dt>UUID</dt><dd id="dm-uuid" class="mono"></dd>
          <dt>Senha</dt><dd id="dm-pw"></dd>
          <dt>Primeiro contato</dt><dd id="dm-first"></dd>
          <dt>Visto por último</dt><dd id="dm-last"></dd>
        </dl>
      </div>
    </dialog>
    <p class="muted" style="margin-top:10px">
      A senha exibida é a de <strong>uso único</strong> que aparece na tela do
      dispositivo, reportada a cada heartbeat (~15 s). Ela muda quando o cliente
      é reiniciado. Dispositivos configurados apenas com senha permanente
      mostram “—”: essa senha é guardada com hash e não pode ser recuperada.
    </p>
    <p class="muted">
      O <strong>nome</strong> e a <strong>estrela</strong> valem para todo mundo: o
      dispositivo se chama a mesma coisa em todas as contas do painel.
      <?php if ($isAdm): ?>
      O mesmo vale para <strong>inativar</strong> — tira o PC da lista sem apagar
      nada, e ele continua inativo mesmo que volte a se conectar, até ser reativado
      aqui.
      <?php else: ?>
      Nomear, favoritar, inativar e excluir dispositivos são ações de administrador:
      esta lista mostra só os dispositivos ativos.
      <?php endif; ?>
    </p></div>
    <script>
    // Olho da senha: por delegacao, porque o olho do popup nasce depois do load.
    document.addEventListener('click', function (ev) {
      var a = ev.target.closest && ev.target.closest('.pw-toggle');
      if (!a) return;
      ev.preventDefault();
      var s = a.previousElementSibling;
      var shown = s.dataset.shown === '1';
      s.textContent = shown ? '••••••' : s.dataset.pw;
      s.dataset.shown = shown ? '0' : '1';
    });

    // Lapis: mostra o formulario de apelido dentro da propria celula.
    (function () {
      function edit(cell, on) {
        cell.classList.toggle('editing', on);
        cell.querySelector('.alias-form').hidden = !on;
        if (on) { var i = cell.querySelector('input.alias'); i.focus(); i.select(); }
      }
      document.addEventListener('click', function (ev) {
        var a = ev.target.closest && ev.target.closest('.edit-alias');
        if (!a) return;
        ev.preventDefault();
        edit(a.closest('.dev-cell'), true);
      });
      document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape' || !ev.target.matches || !ev.target.matches('input.alias')) return;
        ev.target.value = ev.target.defaultValue;
        edit(ev.target.closest('.dev-cell'), false);
      });
    })();

    // Detalhes: o popup e preenchido com o JSON que ja veio no data-dev.
    (function () {
      var dlg = document.getElementById('dev-modal');
      var campos = {
        host: 'dm-host', user: 'dm-user', os: 'dm-os', cpu: 'dm-cpu', mem: 'dm-mem',
        ver: 'dm-ver', ip: 'dm-ip', uuid: 'dm-uuid', first: 'dm-first', last: 'dm-last'
      };

      function put(id, v) {
        // textContent em tudo: nao existe helper de escape no lado JS.
        document.getElementById(id).textContent = (v && String(v).trim() !== '') ? v : '—';
      }
      function badge(txt, on) {
        var s = document.createElement('span');
        s.className = 'badge ' + (on ? 'on' : 'off');
        s.textContent = txt;
        return s;
      }

      document.addEventListener('click', function (ev) {
        var b = ev.target.closest && ev.target.closest('.details');
        if (!b) return;
        var d = JSON.parse(b.dataset.dev);

        document.getElementById('dm-title').textContent = d.name ? d.name + ' — ' + d.peer : d.peer;
        var bd = document.getElementById('dm-badges');
        bd.textContent = '';
        bd.appendChild(badge(d.online ? 'online' : 'offline', d.online));
        if (!d.active) bd.appendChild(badge('inativo', 0));

        for (var k in campos) put(campos[k], d[k]);

        var pw = document.getElementById('dm-pw');
        pw.textContent = '';
        if (d.pw) {
          var s = document.createElement('span');
          s.className = 'pw'; s.dataset.pw = d.pw; s.textContent = '••••••';
          var a = document.createElement('a');
          a.href = '#'; a.className = 'pw-toggle'; a.title = 'Mostrar/ocultar'; a.textContent = '👁';
          pw.appendChild(s); pw.appendChild(a);
          if (d.pwAt && d.pwAt !== '—') {
            var small = document.createElement('small');
            small.className = 'muted-inline';
            small.textContent = ' atualizada em ' + d.pwAt;
            pw.appendChild(small);
          }
        } else {
          pw.textContent = '—';
        }
        dlg.showModal();
      });

      document.getElementById('dm-close').addEventListener('click', function () { dlg.close(); });
      // Clique fora do conteudo (no backdrop) tem o proprio <dialog> como alvo.
      dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
    })();

    (function () {
      var q  = document.getElementById('f-q'),
          st = document.getElementById('f-status'),
          fv = document.getElementById('f-fav'),
          // Nao existe para o perfil Tecnico: a lista dele ja vem so com ativos.
          si = document.getElementById('f-sit'),
          siVal = function () { return si ? si.value : ''; },
          cnt = document.getElementById('dev-count'),
          none = document.getElementById('no-match'),
          rows = [].slice.call(document.querySelectorAll('#dev-tbody tr[data-search]'));

      function apply() {
        var term = q.value.trim().toLowerCase(), sv = siVal(), n = 0;
        rows.forEach(function (r) {
          var ok = (term === '' || r.dataset.search.indexOf(term) !== -1)
                && (st.value === '' || st.value === r.dataset.online)
                && (fv.value === '' || fv.value === r.dataset.fav)
                && (sv === '' || sv === r.dataset.active);
          r.style.display = ok ? '' : 'none';
          if (ok) n++;
        });
        cnt.textContent = n;
        none.style.display = (rows.length && n === 0) ? '' : 'none';
        // Reflete os filtros na URL para poder favoritar/compartilhar o link.
        var p = new URLSearchParams();
        if (term) p.set('q', q.value.trim());
        if (st.value) p.set('status', st.value);
        if (fv.value) p.set('fav', fv.value);
        if (si && sv !== '1') p.set('sit', sv === '' ? 'all' : sv);
        var s = p.toString();
        history.replaceState(null, '', '/devices' + (s ? '?' + s : ''));
      }

      [q, st, fv, si].forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', apply);
        el.addEventListener('change', apply);
      });
      document.getElementById('f-clear').addEventListener('click', function () {
        q.value = ''; st.value = ''; fv.value = '';
        if (si) si.value = '1';
        apply();
      });
      // As acoes voltam para /devices com os mesmos filtros na query string
      // (o PHP le $_GET tambem em POST), entao a tela reaparece como estava.
      document.addEventListener('submit', function (ev) {
        if (ev.target.hasAttribute('data-keep')) ev.target.action = '/devices' + location.search;
      });
      apply();
    })();
    </script>
    <?php
    layout(ob_get_clean(), $admin, 'devices', 'Dispositivos');
}

function device_action(array $admin): void {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id  = (int)($_POST['id'] ?? 0);
    $pdo = db();
    try {
        // Todas as acoes sao globais: nome, estrela, situacao e exclusao valem
        // para todos os admins, e por isso so administrador executa. Checagem
        // aqui, e nao so no HTML, porque o POST pode vir forjado.
        if (!is_panel_admin($admin)) { deny_page(); return; }
        if ($action === 'fav') {
            $pdo->prepare('UPDATE devices SET favorite = 1 - favorite WHERE id = ?')->execute([$id]);
            page_devices($admin, 'Favorito atualizado.'); return;
        }
        if ($action === 'alias') {
            $v = trim((string)($_POST['alias'] ?? ''));
            $v = $v === '' ? null : mb_substr($v, 0, 190);
            $pdo->prepare('UPDATE devices SET alias = ? WHERE id = ?')->execute([$v, $id]);
            page_devices($admin, $v === null ? 'Nome removido.' : 'Nome salvo.'); return;
        }
        if ($action === 'toggle') {
            $pdo->prepare('UPDATE devices SET active = 1 - active WHERE id = ?')->execute([$id]);
            page_devices($admin, 'Situação do dispositivo atualizada.'); return;
        }
        if ($action === 'delete') {
            // O nome e a estrela sao colunas da propria linha: somem junto.
            $pdo->prepare('DELETE FROM devices WHERE id = ?')->execute([$id]);
            page_devices($admin, 'Dispositivo excluído.'); return;
        }
        throw new RuntimeException('Ação inválida.');
    } catch (Throwable $ex) {
        $msg = $ex instanceof RuntimeException ? $ex->getMessage() : 'Erro: ' . $ex->getMessage();
        page_devices($admin, $msg);
    }
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
    $isAdm = is_panel_admin($admin);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' · ' . $brand . '</title><link rel="stylesheet" href="/assets/app.css"></head><body>';
    echo '<aside class="side"><div class="brand">' . $brand . '<span>suporte</span></div><nav>';
    foreach (panel_nav() as $k => [$href, $label, $adminOnly]) {
        if ($adminOnly && !$isAdm) continue;
        $cls = $k === $active ? ' class="active"' : '';
        echo "<a href=\"$href\"$cls>" . e($label) . '</a>';
    }
    echo '</nav><div class="side-foot">' . e($admin['username'])
       . '<br><span class="muted-inline">' . e(role_label($admin['role'] ?? null)) . '</span>'
       . '<br><a href="/logout">sair</a></div></aside>';
    echo '<main><header class="topbar"><h1>' . e($title) . '</h1></header><div class="content">' . $body . '</div></main>';
    echo '</body></html>';
}

function layout_simple(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>' . e($title) . '</title>';
    echo '<link rel="stylesheet" href="/assets/app.css"><div class="content">' . $body . '</div>';
}
