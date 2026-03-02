<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
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

if (empty($_FILES['file']['tmp_name']) || !isset($_FILES['file'])) {
    $phpError = $_FILES['file']['error'] ?? -1;
    $msg = match($phpError) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite permitido por el servidor. Reduce el tamaño de la imagen.',
        UPLOAD_ERR_NO_FILE  => 'No se seleccionó ningún archivo.',
        UPLOAD_ERR_PARTIAL  => 'La subida fue interrumpida. Intenta de nuevo.',
        default             => 'No se recibió ningún archivo (error ' . $phpError . ').',
    };
    echo json_encode(['success' => false, 'error' => $msg]); exit;
}

$file    = $_FILES['file'];
$context = sanitizeStr($_POST['context'] ?? 'general'); // 'general' o slug de propiedad
$allowed = ['image/jpeg','image/png','image/webp','image/gif','image/svg+xml'];
$mime    = mime_content_type($file['tmp_name']);

if (!in_array($mime, $allowed)) {
    echo json_encode(['error' => 'Tipo de archivo no permitido. Usa JPG, PNG, WebP, GIF o SVG.']); exit;
}

if ($file['size'] > 12 * 1024 * 1024) {
    echo json_encode(['error' => 'El archivo es demasiado grande (máx 12MB).']); exit;
}

// Determinar carpeta destino
$subfolder = ($context === 'general') ? 'general' : 'propiedades/' . preg_replace('/[^a-z0-9\-]/', '', $context);
$dir       = __DIR__ . '/../../pics/' . $subfolder . '/';

if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true)) {
        echo json_encode(['error' => 'No se pudo crear la carpeta destino.']); exit;
    }
}

// Nombre del archivo
$ext      = match($mime) {
    'image/webp'     => 'webp',
    'image/png'      => 'png',
    'image/gif'      => 'gif',
    'image/svg+xml'  => 'svg',
    default          => 'jpg',
};

// Intentar preservar nombre original limpio
$originalName = pathinfo($file['name'], PATHINFO_FILENAME);
$originalName = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $originalName));
$originalName = substr($originalName, 0, 60);

$filename = $originalName . '_' . time() . '.' . $ext;
$dest     = $dir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['error' => 'Error al guardar el archivo.']); exit;
}

$path = 'pics/' . $subfolder . '/' . $filename;

echo json_encode([
    'success'  => true,
    'path'     => $path,
    'name'     => $filename,
    'size'     => filesize($dest),
    'ext'      => $ext,
    'subfolder'=> $subfolder,
]);
