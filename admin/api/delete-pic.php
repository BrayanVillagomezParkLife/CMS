<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']); exit;
}

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']); exit;
}

$path = $_POST['path'] ?? '';

// Validar que sea una ruta dentro de pics/
if (!$path || !str_starts_with($path, 'pics/')) {
    echo json_encode(['success' => false, 'error' => 'Ruta inválida.']); exit;
}

// Sanitizar: no permitir ../ ni caracteres peligrosos
if (str_contains($path, '..') || str_contains($path, "\0")) {
    echo json_encode(['success' => false, 'error' => 'Ruta no permitida.']); exit;
}

$fullPath = __DIR__ . '/../../' . $path;
$realBase = realpath(__DIR__ . '/../../pics/');
$realFile = realpath(dirname($fullPath)) . '/' . basename($fullPath);

// Verificar que el archivo realmente está dentro de pics/
if (!$realBase || !str_starts_with($realFile, $realBase)) {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado.']); exit;
}

if (!file_exists($fullPath)) {
    echo json_encode(['success' => false, 'error' => 'El archivo no existe.']); exit;
}

// Eliminar registros en BD que referencien este archivo
$deleted_db = 0;
try {
    $refs = dbFetchAll("SELECT id FROM propiedad_imagenes WHERE url = ?", [$path]);
    foreach ($refs as $ref) {
        dbExecute("DELETE FROM propiedad_imagenes WHERE id = ?", [$ref['id']]);
        $deleted_db++;
    }
    if ($deleted_db > 0) dbCacheInvalidate();
} catch (Exception $e) {
    // Continuar aunque falle BD — lo importante es borrar el archivo
}

// Eliminar archivo físico
if (@unlink($fullPath)) {
    echo json_encode([
        'success'    => true,
        'deleted_db' => $deleted_db,
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'No se pudo eliminar el archivo.']);
}