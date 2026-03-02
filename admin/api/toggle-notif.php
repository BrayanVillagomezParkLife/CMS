<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/middleware.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
}

$data  = json_decode(file_get_contents('php://input'), true) ?? [];
$tipo  = $data['tipo']  ?? '';   // 'jefe' | 'rep'
$id    = (int)($data['id']    ?? 0);
$valor = (int)($data['valor'] ?? 0);  // 0 | 1

if (!$id || !in_array($tipo, ['jefe', 'rep'], true)) {
    echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']); exit;
}

try {
    if ($tipo === 'jefe') {
        db()->prepare("UPDATE notif_jefes SET activo = ? WHERE id = ?")
           ->execute([$valor, $id]);
    } else {
        db()->prepare("UPDATE reps SET notif_activo = ? WHERE id = ?")
           ->execute([$valor, $id]);
    }
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}