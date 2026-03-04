<?php
/**
 * admin/aprobar-descuento.php
 * Endpoint público — Director aprueba/rechaza descuentos via token del email.
 * v1.4 — Sin strict_types, con try-catch completo
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token  = trim($_GET['token'] ?? '');
$accion = trim($_GET['accion'] ?? '');

if (!$token || !in_array($accion, ['aprobar', 'rechazar'])) {
    mostrarPagina('error', 'Enlace inválido', 'El enlace que utilizaste no es válido o está incompleto.', null);
    exit;
}

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    mostrarPagina('error', 'Token inválido', 'El token de autorización no tiene un formato válido.', null);
    exit;
}

// ── Buscar cotización ──
$cot = dbFetchOne(
    "SELECT c.*, 
            p.nombre AS prop_nombre, 
            h.nombre AS hab_nombre, 
            h.codigo AS hab_codigo,
            a.email  AS agente_email, 
            a.nombre AS agente_nombre
     FROM cotizaciones c
     JOIN propiedades p    ON p.id = c.propiedad_id
     JOIN habitaciones h   ON h.id = c.habitacion_id
     JOIN admin_usuarios a ON a.id = c.admin_id
     WHERE c.autorizacion_token = ? 
     LIMIT 1",
    [$token]
);

if (!$cot) {
    mostrarPagina('error', 'No encontrada', 'La cotización no fue encontrada o el enlace ya expiró.', null);
    exit;
}

// ── ¿Ya procesada? ──
if ($cot['estatus'] !== 'pendiente_autorizacion') {
    $map = [
        'borrador'  => 'aprobada y desbloqueada',
        'rechazada' => 'rechazada',
        'enviada'   => 'aprobada y ya enviada al cliente',
        'aceptada'  => 'aceptada por el cliente',
    ];
    $estadoTexto = $map[$cot['estatus']] ?? ('procesada (' . $cot['estatus'] . ')');
    $extra = '';
    if (!empty($cot['autorizado_at'])) {
        $extra = '<br><br>Procesada el ' . date('d/m/Y H:i', strtotime($cot['autorizado_at']));
    }
    mostrarPagina('ya_procesada', 'Ya procesada',
        "Esta cotización ya fue {$estadoTexto}. No se requiere acción adicional." . $extra, $cot);
    exit;
}

// ── Obtener email aprobador ──
$row = dbFetchOne("SELECT valor FROM config WHERE clave = ?", ['email_aprobador_descuentos']);
$emailAprobador = (!empty($row['valor'])) ? $row['valor'] : 'brayan.villagomez@parklife.mx';

// ── Procesar ──
require_once __DIR__ . '/../services/MailService.php';
$mailSvc = new MailService();

if ($accion === 'aprobar') {
    dbExecute(
        "UPDATE cotizaciones SET estatus = 'borrador', autorizado_por = ?, autorizado_at = NOW(),
         autorizacion_notas = 'Descuento aprobado por Director Comercial' WHERE id = ?",
        [$emailAprobador, intval($cot['id'])]
    );

    try {
        $mailSvc->send(
            to:      $cot['agente_email'],
            toName:  $cot['agente_nombre'] ?: 'Agente',
            subject: "Descuento APROBADO - Cotización #{$cot['id']}",
            body:    buildNotifHtml('aprobado', $cot),
        );
    } catch (Exception $e) {
        // Email falla pero la aprobación ya se hizo - no bloquear
    }

    mostrarPagina('aprobada', '✅ Descuento Aprobado',
        "Cotización #{$cot['id']} ha sido aprobada. El agente ya puede enviarla al cliente.", $cot);

} else {
    dbExecute(
        "UPDATE cotizaciones SET estatus = 'rechazada', autorizado_por = ?, autorizado_at = NOW(),
         autorizacion_notas = 'Descuento rechazado por Director Comercial' WHERE id = ?",
        [$emailAprobador, intval($cot['id'])]
    );

    try {
        $mailSvc->send(
            to:      $cot['agente_email'],
            toName:  $cot['agente_nombre'] ?: 'Agente',
            subject: "Descuento RECHAZADO - Cotización #{$cot['id']}",
            body:    buildNotifHtml('rechazado', $cot),
        );
    } catch (Exception $e) {
        // Email falla pero el rechazo ya se hizo
    }

    mostrarPagina('rechazada', '❌ Descuento Rechazado',
        "Cotización #{$cot['id']} ha sido rechazada. Se notificó al agente.", $cot);
}

} catch (Throwable $e) {
    // Atrapar CUALQUIER error y mostrarlo
    echo '<div style="font-family:monospace;padding:20px;background:#fef2f2;border:2px solid #dc2626;margin:20px;border-radius:8px">';
    echo '<h2 style="color:#dc2626">Error en aprobar-descuento.php</h2>';
    echo '<p><strong>Mensaje:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><strong>Archivo:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>';
    echo '<pre style="background:#fff;padding:12px;border-radius:4px;overflow-x:auto;font-size:12px">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</div>';
}

exit;

// ═════════════════════════════════════════════════════════════════════════════
// FUNCIONES
// ═════════════════════════════════════════════════════════════════════════════

function fmtMoney($v) {
    return '$' . number_format(floatval($v), 2);
}

function buildNotifHtml($tipo, $cot) {
    $esAprobado = $tipo === 'aprobado';
    $color = $esAprobado ? '#16A34A' : '#DC2626';
    $icono = $esAprobado ? '✅' : '❌';
    $titulo = $esAprobado ? 'Descuento Aprobado' : 'Descuento Rechazado';

    $html = '<div style="max-width:560px;margin:0 auto;font-family:Arial,sans-serif;color:#374151">';
    $html .= '<div style="background:' . $color . ';padding:24px;text-align:center;border-radius:12px 12px 0 0">';
    $html .= '<h1 style="color:#fff;margin:0;font-size:20px">' . $icono . ' ' . $titulo . '</h1>';
    $html .= '<p style="color:rgba(255,255,255,.7);margin:4px 0 0;font-size:13px">Cotización #' . $cot['id'] . '</p>';
    $html .= '</div>';
    $html .= '<div style="padding:24px;background:#fff;border:1px solid #e5e7eb;border-top:none">';

    if ($esAprobado) {
        $html .= '<p style="font-size:15px;margin-bottom:16px">Tu cotización para <strong>'
               . htmlspecialchars($cot['prop_nombre']) . ' — ' . htmlspecialchars($cot['hab_nombre'])
               . '</strong> ha sido <strong style="color:#16A34A">aprobada</strong>.</p>';
        $html .= '<p style="font-size:14px;margin-bottom:16px">Ya puedes enviarla al cliente por email, WhatsApp o imprimir el PDF.</p>';
    } else {
        $html .= '<p style="font-size:15px;margin-bottom:16px">Tu cotización para <strong>'
               . htmlspecialchars($cot['prop_nombre']) . ' — ' . htmlspecialchars($cot['hab_nombre'])
               . '</strong> <strong style="color:#DC2626">no fue aprobada</strong>.</p>';
        $html .= '<p style="font-size:14px;margin-bottom:16px">Puedes editarla para ajustar el descuento y generar una nueva solicitud.</p>';
    }

    $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;margin:16px 0">';
    $rows = [
        ['Cliente',       htmlspecialchars($cot['cliente_nombre'] ?: '—')],
        ['Descuento',     '<strong style="color:#DC2626">' . $cot['descuento_porcentaje'] . '%</strong> (-' . fmtMoney($cot['descuento_monto']) . ')'],
        ['Renta neta',    fmtMoney($cot['precio_renta_neta'])],
        ['Total mensual', '<strong>' . fmtMoney($cot['subtotal_mensual']) . ' MXN</strong>'],
    ];
    foreach ($rows as $r) {
        $html .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280">' . $r[0]
               . '</td><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb">' . $r[1] . '</td></tr>';
    }
    $html .= '</table>';
    $html .= '<p style="font-size:12px;color:#9ca3af;text-align:center;margin-top:16px">Park Life Properties</p>';
    $html .= '</div></div>';
    return $html;
}

function mostrarPagina($tipo, $titulo, $mensaje, $cot) {
    $colores = [
        'aprobada'     => '#16A34A',
        'rechazada'    => '#DC2626',
        'ya_procesada' => '#6B7280',
        'error'        => '#DC2626',
    ];
    $bgColor = $colores[$tipo] ?? '#DC2626';
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= strip_tags($titulo) ?> — Park Life</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Segoe UI',system-ui,Arial,sans-serif;background:#f8fafc;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#374151}
        .card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:480px;width:100%;overflow:hidden}
        .card-header{background:<?= $bgColor ?>;padding:28px 24px;text-align:center;color:#fff}
        .card-header h1{font-size:20px;font-weight:700;margin:0}
        .card-header p{font-size:13px;opacity:.75;margin-top:4px}
        .card-body{padding:24px}
        .msg{text-align:center;font-size:15px;line-height:1.6;color:#374151;margin-bottom:20px}
        .details{background:#f8fafc;border-radius:12px;padding:16px;margin-bottom:16px}
        .row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f3f4f6;font-size:14px}
        .row:last-child{border:none}
        .lbl{color:#6b7280}.val{font-weight:600;color:#1f2937}
        .val.red{color:#DC2626}
        .foot{text-align:center;font-size:12px;color:#9ca3af;margin-top:20px;padding-top:16px;border-top:1px solid #f3f4f6}
        .foot a{color:#202944;text-decoration:none;font-weight:600}
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <h1><?= $titulo ?></h1>
        <?php if ($cot): ?><p>Cotización #<?= $cot['id'] ?></p><?php endif; ?>
    </div>
    <div class="card-body">
        <div class="msg"><?= $mensaje ?></div>
        <?php if ($cot): ?>
        <div class="details">
            <div class="row"><span class="lbl">Cliente</span><span class="val"><?= htmlspecialchars($cot['cliente_nombre'] ?: '—') ?></span></div>
            <div class="row"><span class="lbl">Propiedad</span><span class="val"><?= htmlspecialchars($cot['prop_nombre']) ?></span></div>
            <div class="row"><span class="lbl">Unidad</span><span class="val"><?= htmlspecialchars($cot['hab_nombre']) ?><?= $cot['hab_codigo'] ? ' (' . htmlspecialchars($cot['hab_codigo']) . ')' : '' ?></span></div>
            <div class="row"><span class="lbl">Duración</span><span class="val"><?= $cot['duracion_meses'] ?> mes<?= intval($cot['duracion_meses']) > 1 ? 'es' : '' ?></span></div>
            <div class="row"><span class="lbl">Renta base</span><span class="val"><?= fmtMoney($cot['precio_renta_base']) ?></span></div>
            <div class="row"><span class="lbl">Descuento</span><span class="val red"><?= $cot['descuento_porcentaje'] ?>% (-<?= fmtMoney($cot['descuento_monto']) ?>)</span></div>
            <div class="row"><span class="lbl">Renta neta</span><span class="val"><?= fmtMoney($cot['precio_renta_neta']) ?></span></div>
            <div class="row"><span class="lbl">Total mensual</span><span class="val" style="font-size:16px"><?= fmtMoney($cot['subtotal_mensual']) ?> MXN</span></div>
            <div class="row"><span class="lbl">Agente</span><span class="val"><?= htmlspecialchars($cot['agente_nombre'] ?: $cot['agente_email']) ?></span></div>
        </div>
        <?php endif; ?>
        <div class="foot"><a href="https://parklife.mx">Park Life Properties</a><br>Sistema de Cotizaciones</div>
    </div>
</div>
</body>
</html>
<?php
}