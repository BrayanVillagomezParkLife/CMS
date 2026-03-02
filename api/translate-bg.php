<?php
/**
 * api/translate-bg.php
 * Script que corre en background para traducir y guardar caché.
 * Se ejecuta via CLI, no vía HTTP.
 *
 * Uso: php translate-bg.php <html_tmp> <cache_file> <origin_uri>
 */

// Solo permitir ejecución CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$htmlTmp   = $argv[1] ?? '';
$cacheFile = $argv[2] ?? '';
$originUri = $argv[3] ?? '';

if (!$htmlTmp || !$cacheFile) exit(1);

// Definir BASE_PATH para que config.php funcione
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/includes/config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/translator.php';

// Leer HTML temporal
$html = @file_get_contents($htmlTmp);
if (!$html) exit(1);

// Traducir (puede tomar 30-60s)
$translated = traducirPagina($html, $cacheFile);

// Limpiar temporales
@unlink($htmlTmp);
@unlink($cacheFile . '.lock');
