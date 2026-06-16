<?php
declare(strict_types=1);

/** Emit JSON and stop. */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Emit plain text and stop. */
function text_out(string $s, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $s;
    exit;
}

/** Parse JSON request body into an array (empty array if none/invalid). */
function read_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Extract the Bearer token from the Authorization header, if present. */
function bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        foreach ($hdrs as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; } }
    }
    if ($h !== '' && preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return null;
}

/** Best-effort real client IP (behind the local Apache reverse proxy). */
function client_ip(): string {
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') { $p = explode(',', $xff); return trim($p[0]); }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** Current time in UTC for storage. */
function now_utc(): string { return gmdate('Y-m-d H:i:s'); }

/** Format a UTC datetime string for display in DISPLAY_TZ. */
function fmt_dt(?string $utc): string {
    if (!$utc) return '—';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(DISPLAY_TZ));
        return $dt->format('d/m/Y H:i:s');
    } catch (Throwable $e) { return $utc; }
}

/** RFC4122 v4 UUID. */
function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $hex = bin2hex($b);
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
        substr($hex, 16, 4), substr($hex, 20, 12));
}

/** HTML-escape. */
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
