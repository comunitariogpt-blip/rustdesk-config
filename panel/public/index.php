<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/helpers.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($uri !== '/') $uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';

if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    require __DIR__ . '/../src/api.php';
    api_dispatch($uri);
} else {
    require __DIR__ . '/../src/admin.php';
    admin_dispatch($uri);
}
