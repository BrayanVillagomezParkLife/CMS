<?php
declare(strict_types=1);
/**
 * admin/includes/audit.php
 * Función de auditoría para registrar cambios en el CMS.
 * Requiere: tabla admin_audit en la BD.
 * 
 * Uso:
 *   logAudit('UPDATE_PRECIO', 'habitaciones', $id, $antes, $despues, 'Incremento 5%');
 */

/**
 * Registra un cambio en la tabla admin_audit.
 *
 * @param string     $accion       Tipo de acción (UPDATE_PRECIO, BULK_UPDATE, TOGGLE_ACTIVA, etc.)
 * @param string     $tabla        Tabla afectada
 * @param int|null   $registroId   ID del registro modificado (null si es masivo)
 * @param array|null $antes        Datos antes del cambio
 * @param array|null $despues      Datos después del cambio
 * @param string     $notas        Justificación o detalle adicional
 */
function logAudit(
    string $accion,
    string $tabla,
    ?int $registroId = null,
    ?array $antes = null,
    ?array $despues = null,
    string $notas = ''
): void {
    try {
        $adminId    = $_SESSION['admin_id'] ?? null;
        $adminEmail = $_SESSION['admin_email'] ?? 'sistema';
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        dbInsert(
            "INSERT INTO admin_audit (admin_id, admin_email, accion, tabla, registro_id, datos_antes, datos_despues, notas, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $adminId,
                $adminEmail,
                $accion,
                $tabla,
                $registroId,
                $antes ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
                $despues ? json_encode($despues, JSON_UNESCAPED_UNICODE) : null,
                $notas ?: null,
                $ip
            ]
        );
    } catch (\Throwable $e) {
        // La auditoría nunca debe romper el flujo principal
        error_log("AUDIT ERROR: " . $e->getMessage());
    }
}

/**
 * Retorna el historial de auditoría con filtros opcionales.
 *
 * @param array $filtros ['tabla' => '', 'admin_id' => 0, 'accion' => '', 'desde' => '', 'hasta' => '']
 * @param int   $limit
 * @param int   $offset
 * @return array
 */
function getAuditLog(array $filtros = [], int $limit = 50, int $offset = 0): array
{
    $where  = [];
    $params = [];

    if (!empty($filtros['tabla'])) {
        $where[]  = "a.tabla = ?";
        $params[] = $filtros['tabla'];
    }
    if (!empty($filtros['admin_id'])) {
        $where[]  = "a.admin_id = ?";
        $params[] = (int)$filtros['admin_id'];
    }
    if (!empty($filtros['accion'])) {
        $where[]  = "a.accion = ?";
        $params[] = $filtros['accion'];
    }
    if (!empty($filtros['desde'])) {
        $where[]  = "a.created_at >= ?";
        $params[] = $filtros['desde'] . ' 00:00:00';
    }
    if (!empty($filtros['hasta'])) {
        $where[]  = "a.created_at <= ?";
        $params[] = $filtros['hasta'] . ' 23:59:59';
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = (int)dbFetchValue(
        "SELECT COUNT(*) FROM admin_audit a $whereSQL",
        $params
    );

    $rows = dbFetchAll(
        "SELECT a.* FROM admin_audit a
         $whereSQL
         ORDER BY a.created_at DESC
         LIMIT $limit OFFSET $offset",
        $params
    );

    return ['rows' => $rows, 'total' => $total];
}
