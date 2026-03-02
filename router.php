<?php
/**
 * router.php — Desarrollo local con php -S
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

$lang      = 'es';
$prefix    = '';
$originUri = $uri;

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

// API
if (preg_match('#^/api/(.+)$#', $uri, $m)) {
    $file = __DIR__ . '/api/' . $m[1];
    if (file_exists($file)) { require $file; exit; }
    http_response_code(404); echo '{"error":"Not found"}'; exit;
}

// Admin
if (preg_match('#^/admin(/.*)?$#', $uri, $m)) {
    $path = $m[1] ?? '/index.php';
    if ($path === '/' || $path === '') $path = '/index.php';
    $file = __DIR__ . '/admin' . $path;
    if (!pathinfo($path, PATHINFO_EXTENSION)) $file .= '.php';
    if (file_exists($file)) { require $file; exit; }
    http_response_code(404); require __DIR__ . '/pages/404.php'; exit;
}

// Estáticas
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

// Home
if ($uri === '/') { require __DIR__ . '/pages/home.php'; exit; }

// Propiedad por slug
if (preg_match('#^/([a-z0-9][a-z0-9\-]{1,98})$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/pages/propiedad.php';
    exit;
}

http_response_code(404);
$f = __DIR__ . '/pages/404.php';
if (file_exists($f)) { require $f; } else { echo '<h1>404</h1>'; }
exit;
