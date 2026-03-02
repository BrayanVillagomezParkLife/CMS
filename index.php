<?php
/**
 * index.php — Entry point Park Life Properties
 * Detecta idioma y despacha rutas (Apache lo usa via .htaccess)
 * En desarrollo local usar: php -S localhost:8000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

// ── Detectar idioma ───────────────────────────────────────────────────────────
$lang   = 'es';
$prefix = '';

if ($uri === '/en' || $uri === '/en/' || strpos($uri, '/en/') === 0) {
    $lang   = 'en';
    $prefix = '/en';
    if ($uri === '/en' || $uri === '/en/') {
        $uri = '/';
    } else {
        $uri = substr($uri, 3);
        if ($uri === '' || $uri === '/') $uri = '/';
    }
}

define('APP_LANG',    $lang);
define('LANG_PREFIX', $prefix);

// ── Archivos estáticos ────────────────────────────────────────────────────────
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// ── API ───────────────────────────────────────────────────────────────────────
if (preg_match('#^/api/(.+)$#', $uri, $m)) {
    $file = __DIR__ . '/api/' . $m[1];
    if (file_exists($file)) { require $file; exit; }
    http_response_code(404); echo '{"error":"Not found"}'; exit;
}

// ── Admin ─────────────────────────────────────────────────────────────────────
if (preg_match('#^/admin(/.*)?$#', $uri, $m)) {
    $path = $m[1] ?? '/index.php';
    if ($path === '/' || $path === '') $path = '/index.php';
    $file = __DIR__ . '/admin' . $path;
    if (!pathinfo($path, PATHINFO_EXTENSION)) $file .= '.php';
    if (file_exists($file)) { require $file; exit; }
    http_response_code(404); require __DIR__ . '/pages/404.php'; exit;
}

// ── Páginas estáticas ─────────────────────────────────────────────────────────
$staticRoutes = [
    '/legal'        => 'pages/legal.php',
    '/legal.php'    => 'pages/legal.php',
    '/terminos'     => 'pages/terminos.php',
    '/terminos.php' => 'pages/terminos.php',
    '/sitemap.xml'  => 'sitemap.php',
    '/robots.txt'   => 'robots.php',
];
if (isset($staticRoutes[$uri])) {
    $file = __DIR__ . '/' . $staticRoutes[$uri];
    if (file_exists($file)) { require $file; exit; }
}

// ── Homepage ──────────────────────────────────────────────────────────────────
if ($uri === '/') {
    require __DIR__ . '/pages/home.php';
    exit;
}

// ── Slug de propiedad ─────────────────────────────────────────────────────────
if (preg_match('#^/([a-z0-9][a-z0-9\-]{1,98})$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/pages/propiedad.php';
    exit;
}

// ── 404 ───────────────────────────────────────────────────────────────────────
http_response_code(404);
$file404 = __DIR__ . '/pages/404.php';
if (file_exists($file404)) { require $file404; } else { echo '<h1>404 Not Found</h1>'; }
