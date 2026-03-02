<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json; charset=UTF-8');

$context  = sanitizeStr($_GET['context'] ?? '');
$allowed  = ['jpg','jpeg','png','webp','gif','svg'];
$basePath = __DIR__ . '/../../pics/';
$files    = [];

function scanFolder(string $dir, string $relBase, array $allowed): array {
    $results = [];
    if (!is_dir($dir)) return $results;
    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;
        $fullPath = $dir . $file;
        if (is_dir($fullPath)) continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) continue;
        $relPath   = $relBase . $file;
        $results[] = [
            'name'    => $file,
            'path'    => 'pics/' . $relPath,
            'size'    => filesize($fullPath),
            'ext'     => $ext,
            'folder'  => trim($relBase, '/'),
            'mtime'   => filemtime($fullPath),
        ];
    }
    return $results;
}

if ($context && $context !== 'general') {
    // Solo imágenes de esa propiedad + general
    $slug = preg_replace('/[^a-z0-9\-]/', '', $context);
    $files = array_merge(
        scanFolder($basePath . 'propiedades/' . $slug . '/', 'propiedades/' . $slug . '/', $allowed),
        scanFolder($basePath . 'general/', 'general/', $allowed),
        scanFolder($basePath, '', $allowed) // raíz para compatibilidad
    );
} else {
    // Todo
    $files = scanFolder($basePath, '', $allowed);
    if (is_dir($basePath . 'general/'))
        $files = array_merge($files, scanFolder($basePath . 'general/', 'general/', $allowed));
    if (is_dir($basePath . 'propiedades/')) {
        foreach (scandir($basePath . 'propiedades/') as $slug) {
            if ($slug === '.' || $slug === '..') continue;
            $files = array_merge($files, scanFolder($basePath . 'propiedades/' . $slug . '/', 'propiedades/' . $slug . '/', $allowed));
        }
    }
}

// Ordenar por más reciente
usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);

// Obtener carpetas disponibles para el selector
$folders = ['general'];
if (is_dir($basePath . 'propiedades/')) {
    foreach (scandir($basePath . 'propiedades/') as $s) {
        if ($s !== '.' && $s !== '..') $folders[] = 'propiedades/' . $s;
    }
}

echo json_encode(['files' => $files, 'total' => count($files), 'folders' => $folders]);

