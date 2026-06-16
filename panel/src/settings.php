<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function setting_get(string $k, ?string $default = null): ?string {
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function setting_set(string $k, string $v): void {
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

/** Epoch of the last change to a setting (used as heartbeat strategy modified_at). */
function setting_updated_epoch(string $k): int {
    $st = db()->prepare('SELECT UNIX_TIMESTAMP(updated_at) FROM settings WHERE k = ?');
    $st->execute([$k]);
    return (int)($st->fetchColumn() ?: 0);
}
