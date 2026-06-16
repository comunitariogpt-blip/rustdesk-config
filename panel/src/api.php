<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';

// ===========================================================================
// RustDesk-compatible API server.
// api-server baked into the clients = API_SERVER_URL (ver config.php / secrets.env)
// The clients append /api/<endpoint>. This router handles them.
// ===========================================================================
function api_dispatch(string $uri): void {
    $route  = substr($uri, 4);            // strip leading "/api"
    if ($route === '') $route = '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    try {
        switch ("$method $route") {
            case 'GET /login-options':      json_out([]);                 // warmup, no OIDC
            case 'POST /login':             handle_login();               break;
            case 'POST /currentUser':       handle_current_user();        break;
            case 'POST /logout':            handle_logout();              break;
            case 'POST /heartbeat':         handle_heartbeat();           break;
            case 'POST /sysinfo':           handle_sysinfo();             break;
            case 'POST /sysinfo_ver':       handle_sysinfo_ver();         break;
            case 'POST /audit/conn':        handle_audit_conn();          break;
            case 'GET /audit/conn/active':  handle_audit_active();        break;
            case 'PUT /audit':              handle_audit_note();          break;
            default:
                // Unknown endpoints: respond benignly so the client doesn't error-loop.
                json_out(new stdClass());
        }
    } catch (Throwable $ex) {
        error_log('[api] ' . $ex->getMessage());
        json_out(['error' => 'internal error'], 500);
    }
}

// ---------------------------------------------------------------------------
// Auth: POST /api/login
// ---------------------------------------------------------------------------
function handle_login(): void {
    $b        = read_json();
    $username = trim((string)($b['username'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $devId    = (string)($b['id'] ?? '');
    $uuid     = (string)($b['uuid'] ?? '');
    $di       = is_array($b['deviceInfo'] ?? null) ? $b['deviceInfo'] : [];
    $os       = (string)($di['os'] ?? '');
    $dname    = (string)($di['name'] ?? '');

    if ($username === '' || $password === '') {
        log_login(null, $username, 0, $devId);
        json_out(['error' => 'Informe usuário e senha.']);
    }

    $st = db()->prepare('SELECT * FROM operators WHERE username = ?');
    $st->execute([$username]);
    $op = $st->fetch();

    if (!$op || !password_verify($password, $op['password_hash'])) {
        log_login($op['id'] ?? null, $username, 0, $devId);
        json_out(['error' => 'Usuário ou senha inválidos.']);
    }
    if ((int)$op['status'] !== 1) {
        log_login((int)$op['id'], $username, 0, $devId);
        json_out(['error' => 'Conta desativada. Contate o administrador.']);
    }

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO operator_tokens
        (operator_id, token, device_id, device_uuid, device_os, device_name, created_at, last_seen)
        VALUES (?,?,?,?,?,?,?,?)')
        ->execute([(int)$op['id'], $token, $devId, $uuid, $os, $dname, now_utc(), now_utc()]);
    db()->prepare('UPDATE operators SET last_login = ? WHERE id = ?')
        ->execute([now_utc(), (int)$op['id']]);
    log_login((int)$op['id'], $username, 1, $devId);

    json_out([
        'access_token' => $token,
        'type'         => 'access_token',
        'user'         => user_payload($op),
    ]);
}

function user_payload(array $op): array {
    return [
        'name'         => $op['username'],
        'display_name' => ($op['name'] ?? '') !== '' ? $op['name'] : $op['username'],
        'email'        => $op['email'] ?? '',
        'note'         => $op['note'] ?? '',
        'status'       => (int)$op['status'],
        'is_admin'     => ((int)$op['is_admin']) === 1,
    ];
}

function log_login(?int $opId, string $username, int $success, string $devId): void {
    db()->prepare('INSERT INTO login_audit
        (operator_id, username, success, ip, device_id, user_agent, created_at)
        VALUES (?,?,?,?,?,?,?)')
        ->execute([$opId, $username, $success, client_ip(), $devId,
                   substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), now_utc()]);
}

// ---------------------------------------------------------------------------
// POST /api/currentUser  — validate token, return user
// ---------------------------------------------------------------------------
function handle_current_user(): void {
    $op = operator_from_token(bearer_token());
    if (!$op) json_out(['error' => 'Token inválido'], 401);
    json_out(user_payload($op));
}

// ---------------------------------------------------------------------------
// POST /api/logout  — revoke token
// ---------------------------------------------------------------------------
function handle_logout(): void {
    $t = bearer_token();
    if ($t) db()->prepare('UPDATE operator_tokens SET revoked = 1 WHERE token = ?')->execute([$t]);
    json_out(['data' => 'logged out']);
}

// ---------------------------------------------------------------------------
// POST /api/heartbeat  — device alive + active connections; push login policy
// ---------------------------------------------------------------------------
function handle_heartbeat(): void {
    $b   = read_json();
    $id  = (string)($b['id'] ?? '');
    if ($id !== '') {
        upsert_device_seen($id, (string)($b['uuid'] ?? ''), (string)($b['ver'] ?? ''));
    }
    // Push the central login policy to the operator apps via the strategy
    // channel (Config::set_options applies arbitrary keys). require_login=1
    // makes the operator app demand login before connecting out; 0 keeps
    // legacy/no-login behaviour. Toggling it in the panel propagates in ~15s.
    $requireLogin = setting_get('require_login', '0') === '1' ? 'Y' : 'N';
    json_out([
        'strategy'    => ['config_options' => ['require-login' => $requireLogin]],
        'modified_at' => setting_updated_epoch('require_login'),
    ]);
}

function upsert_device_seen(string $id, string $uuid, string $ver): void {
    db()->prepare(
        'INSERT INTO devices (peer_id, uuid, version, last_ip, first_seen, last_seen, online)
         VALUES (?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE
           uuid    = VALUES(uuid),
           version = IF(VALUES(version) <> "", VALUES(version), version),
           last_ip = VALUES(last_ip),
           last_seen = VALUES(last_seen),
           online  = 1')
        ->execute([$id, $uuid, $ver, client_ip(), now_utc(), now_utc()]);
}

// ---------------------------------------------------------------------------
// POST /api/sysinfo  — full hardware/OS info
// ---------------------------------------------------------------------------
function handle_sysinfo(): void {
    $b  = read_json();
    $id = (string)($b['id'] ?? '');
    if ($id === '') text_out('');
    $ver = sha1((string)json_encode($b));
    db()->prepare(
        'INSERT INTO devices
           (peer_id, uuid, hostname, username, os, cpu, memory, version, last_ip, sysinfo_ver, first_seen, last_seen, online)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE
           uuid=VALUES(uuid), hostname=VALUES(hostname), username=VALUES(username),
           os=VALUES(os), cpu=VALUES(cpu), memory=VALUES(memory),
           version=IF(VALUES(version)<>"", VALUES(version), version),
           last_ip=VALUES(last_ip), sysinfo_ver=VALUES(sysinfo_ver),
           last_seen=VALUES(last_seen), online=1')
        ->execute([
            $id, (string)($b['uuid'] ?? ''), (string)($b['hostname'] ?? ''),
            (string)($b['username'] ?? ''), (string)($b['os'] ?? ''),
            (string)($b['cpu'] ?? ''), (string)($b['memory'] ?? ''),
            (string)($b['version'] ?? ''), client_ip(), $ver, now_utc(), now_utc(),
        ]);
    text_out($ver);          // client stores this as the sysinfo version
}

// ---------------------------------------------------------------------------
// POST /api/sysinfo_ver  — return stored version so client can skip re-upload
// ---------------------------------------------------------------------------
function handle_sysinfo_ver(): void {
    $b  = read_json();
    $id = (string)($b['id'] ?? '');
    $st = db()->prepare('SELECT sysinfo_ver FROM devices WHERE peer_id = ?');
    $st->execute([$id]);
    text_out((string)($st->fetchColumn() ?: ''));
}

// ---------------------------------------------------------------------------
// POST /api/audit/conn  — connection open/close history
// ---------------------------------------------------------------------------
function handle_audit_conn(): void {
    $b         = read_json();
    $action    = (string)($b['action'] ?? '');
    $id        = (string)($b['id'] ?? '');
    $uuid      = (string)($b['uuid'] ?? '');
    $connId    = isset($b['conn_id'])    ? (int)$b['conn_id'] : null;
    $sessionId = (string)($b['session_id'] ?? '');
    $ip        = (string)($b['ip'] ?? client_ip());
    $type      = isset($b['type']) ? (int)$b['type'] : null;

    [$peerId, $peerName] = parse_peer($b['peer'] ?? null);

    if ($action === 'new') {
        db()->prepare('INSERT INTO connections
            (conn_id, session_id, device_id, device_uuid, peer_id, peer_name, ip, conn_type, started_at, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$connId, $sessionId, $id, $uuid, $peerId, $peerName, $ip, $type, now_utc(), now_utc()]);
    } elseif ($action === 'close') {
        $upd = db()->prepare('UPDATE connections SET ended_at = ?
            WHERE ended_at IS NULL AND (session_id = ? OR (conn_id = ? AND device_id = ?))
            ORDER BY id DESC LIMIT 1');
        $upd->execute([now_utc(), $sessionId, $connId, $id]);
        if ($upd->rowCount() === 0) {
            db()->prepare('INSERT INTO connections
                (conn_id, session_id, device_id, device_uuid, peer_id, peer_name, ip, conn_type, ended_at, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$connId, $sessionId, $id, $uuid, $peerId, $peerName, $ip, $type, now_utc(), now_utc()]);
        }
    }
    json_out(new stdClass());
}

/** peer may be [id, name] or {peer_id, peer_name} / {id, name}. */
function parse_peer($peer): array {
    if (!is_array($peer)) return ['', ''];
    if (array_key_exists(0, $peer)) {
        return [(string)($peer[0] ?? ''), (string)($peer[1] ?? '')];
    }
    return [
        (string)($peer['peer_id'] ?? $peer['id'] ?? ''),
        (string)($peer['peer_name'] ?? $peer['name'] ?? ''),
    ];
}

// ---------------------------------------------------------------------------
// GET /api/audit/conn/active  — issue a GUID for note-taking on a session
// ---------------------------------------------------------------------------
function handle_audit_active(): void {
    $id        = (string)($_GET['id'] ?? '');
    $sessionId = (string)($_GET['session_id'] ?? '');
    $guid      = uuid4();
    db()->prepare('UPDATE connections SET guid = ?
        WHERE guid IS NULL AND (session_id = ? OR device_id = ?)
        ORDER BY id DESC LIMIT 1')
        ->execute([$guid, $sessionId, $id]);
    json_out($guid);            // JSON string
}

// ---------------------------------------------------------------------------
// PUT /api/audit  — attach an operator note to a connection by GUID
// ---------------------------------------------------------------------------
function handle_audit_note(): void {
    $b    = read_json();
    $guid = (string)($b['guid'] ?? '');
    $note = (string)($b['note'] ?? '');
    if ($guid !== '') {
        db()->prepare('UPDATE connections SET note = ? WHERE guid = ?')
            ->execute([substr($note, 0, 500), $guid]);
    }
    json_out(new stdClass());
}
