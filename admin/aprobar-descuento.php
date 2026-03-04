<?php
/**
 * admin/aprobar-descuento.php  v5.0
 * Endpoint público — Director aprueba/rechaza descuentos via token del email.
 * NO requiere login. Acceso por token único enviado por email.
 *
 * v5.0 — Auditoría completa: IP, User Agent, acción.
 *         Página "ya procesada" con trail de auditoría detallado.
 *         Desglose completo: mantenimiento, servicios, amueblado, mascota, IVA, total contrato.
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// ─── Helpers locales ──────────────────────────────────────────────────────
function _e($str) { return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8'); }
function _money($v) { return '$' . number_format(floatval($v ?? 0), 2); }

/**
 * Obtiene la IP real del visitante (soporta proxies).
 */
function getClientIp(): string
{
    // Orden de prioridad para headers de proxy
    $headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_FORWARDED_FOR',      // Proxy genérico
        'HTTP_X_REAL_IP',            // Nginx proxy
        'REMOTE_ADDR',               // Directo
    ];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            // X-Forwarded-For puede traer múltiples IPs: "client, proxy1, proxy2"
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Parsea el User Agent en algo legible para el trail.
 */
function parseUserAgent(string $ua): string
{
    if (empty($ua)) return 'Desconocido';

    $browser = 'Navegador desconocido';
    $os = 'OS desconocido';

    // Detectar navegador
    if (str_contains($ua, 'Edg/'))        $browser = 'Microsoft Edge';
    elseif (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera'))  $browser = 'Opera';
    elseif (str_contains($ua, 'Chrome/') && !str_contains($ua, 'Edg/'))  $browser = 'Google Chrome';
    elseif (str_contains($ua, 'Firefox/')) $browser = 'Mozilla Firefox';
    elseif (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome/')) $browser = 'Apple Safari';

    // Detectar OS
    if (str_contains($ua, 'Windows'))      $os = 'Windows';
    elseif (str_contains($ua, 'Mac OS'))   $os = 'macOS';
    elseif (str_contains($ua, 'iPhone'))   $os = 'iPhone (iOS)';
    elseif (str_contains($ua, 'iPad'))     $os = 'iPad (iPadOS)';
    elseif (str_contains($ua, 'Android'))  $os = 'Android';
    elseif (str_contains($ua, 'Linux'))    $os = 'Linux';

    return "$browser en $os";
}


// ═══════════════════════════════════════════════════════════════════════════
try {

    $token  = trim($_GET['token'] ?? '');
    $accion = trim($_GET['accion'] ?? '');

    if (!$token || !in_array($accion, ['aprobar', 'rechazar'])) {
        renderPage('error', 'Enlace inválido',
            'El enlace que utilizaste no es válido o está incompleto.', null);
        exit;
    }

    // ── Buscar cotización ──
    $cot = dbFetchOne(
        "SELECT c.*, 
                p.nombre AS prop_nombre, p.ciudad,
                h.nombre AS hab_nombre, h.codigo AS hab_codigo,
                h.num_camas, h.num_banos, h.metros_cuadrados,
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
        renderPage('error', 'No encontrada',
            'La cotización no fue encontrada o el enlace ya expiró.', null);
        exit;
    }

    // ── ¿Ya fue procesada? → Mostrar página de auditoría ──
    if ($cot['estatus'] !== 'pendiente_autorizacion') {
        renderYaProcesada($cot);
        exit;
    }

    // ── Email del aprobador ──
    $emailAprobador = dbFetchValue("SELECT valor FROM config WHERE clave = ?", ['email_aprobador_descuentos']);
    if (!$emailAprobador) $emailAprobador = 'brayan.villagomez@parklife.mx';

    $cotId = intval($cot['id']);

    // ── Datos de auditoría ──
    $auditIp = getClientIp();
    $auditUa = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    // ── APROBAR ──
    if ($accion === 'aprobar') {
        dbExecute(
            "UPDATE cotizaciones SET 
                estatus = 'borrador', 
                autorizado_por = ?, 
                autorizado_at = NOW(),
                autorizacion_notas = 'Descuento aprobado por Director Comercial',
                autorizacion_ip = ?,
                autorizacion_user_agent = ?,
                autorizacion_accion = 'aprobado'
             WHERE id = ?",
            [$emailAprobador, $auditIp, $auditUa, $cotId]
        );
        notificarAgente('aprobado', $cot);
        renderPage('aprobada', 'Descuento Aprobado',
            "Cotización #{$cotId} ha sido aprobada.<br>El agente ya puede enviarla al cliente.", $cot);

    // ── RECHAZAR ──
    } else {
        dbExecute(
            "UPDATE cotizaciones SET 
                estatus = 'rechazada', 
                autorizado_por = ?, 
                autorizado_at = NOW(),
                autorizacion_notas = 'Descuento rechazado por Director Comercial',
                autorizacion_ip = ?,
                autorizacion_user_agent = ?,
                autorizacion_accion = 'rechazado'
             WHERE id = ?",
            [$emailAprobador, $auditIp, $auditUa, $cotId]
        );
        notificarAgente('rechazado', $cot);
        renderPage('rechazada', 'Descuento Rechazado',
            "Cotización #{$cotId} ha sido rechazada.<br>Se notificó al agente.", $cot);
    }

} catch (Throwable $ex) {
    echo '<div style="font-family:monospace;padding:20px;background:#fef2f2;border:2px solid #dc2626;margin:20px;border-radius:8px">';
    echo '<h2 style="color:#dc2626">Error</h2>';
    echo '<p>' . htmlspecialchars($ex->getMessage()) . '</p>';
    echo '<p>' . htmlspecialchars($ex->getFile()) . ':' . $ex->getLine() . '</p>';
    echo '<pre style="font-size:11px">' . htmlspecialchars($ex->getTraceAsString()) . '</pre></div>';
}
exit;


// ═══════════════════════════════════════════════════════════════════════════
// FUNCIONES
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Construye el array de líneas del desglose (reutilizable en página y email).
 */
function buildDesglose(array $c): array
{
    $lineas = [];
    $lineas[] = ['Renta base (tarifa ' . _e($c['tarifa_aplicada']) . ')', _money($c['precio_renta_base']), ''];

    if ((float)($c['descuento_porcentaje'] ?? 0) > 0) {
        $lineas[] = ['Descuento (' . $c['descuento_porcentaje'] . '%)', '-' . _money($c['descuento_monto']), 'discount'];
    }

    $lineas[] = ['Renta neta', _money($c['precio_renta_neta']), 'bold'];

    if ((float)($c['monto_mantenimiento'] ?? 0) > 0) {
        $lineas[] = ['Mantenimiento', '+' . _money($c['monto_mantenimiento']), ''];
    }
    if (!empty($c['inc_servicios']) && (float)($c['monto_servicios'] ?? 0) > 0) {
        $lineas[] = ['Servicios (Full Service)', '+' . _money($c['monto_servicios']), ''];
    }
    if (!empty($c['inc_amueblado']) && (float)($c['monto_amueblado'] ?? 0) > 0) {
        $lineas[] = ['Amueblado', '+' . _money($c['monto_amueblado']), ''];
    }
    if (!empty($c['inc_parking']) && (float)($c['monto_parking'] ?? 0) > 0) {
        $lineas[] = ['Estacionamiento', '+' . _money($c['monto_parking']), ''];
    }
    if (!empty($c['inc_mascota']) && (float)($c['monto_mascota'] ?? 0) > 0) {
        $lineas[] = ['Mascota', '+' . _money($c['monto_mascota']), ''];
    }
    if ((float)($c['monto_iva'] ?? 0) > 0) {
        $ivaLabel = 'IVA (' . (float)($c['iva_porcentaje'] ?? 16) . '%)';
        if (!empty($c['iva_sobre_renta'])) {
            $ivaLabel .= ' sobre servicios y renta';
        } else {
            $ivaLabel .= ' sobre servicios';
        }
        $lineas[] = [$ivaLabel, '+' . _money($c['monto_iva']), 'iva'];
    }

    return $lineas;
}

/**
 * Envía email de notificación al agente con desglose completo.
 */
function notificarAgente(string $tipo, array $cot): void
{
    try {
        require_once __DIR__ . '/../services/MailService.php';
        $mail = new MailService();

        $esAprobado = ($tipo === 'aprobado');
        $color   = $esAprobado ? '#16A34A' : '#DC2626';
        $titulo  = $esAprobado ? 'Descuento Aprobado' : 'Descuento Rechazado';
        $subject = ($esAprobado ? 'Descuento APROBADO' : 'Descuento RECHAZADO')
                 . ' - Cotización #' . $cot['id'];

        $html = '<div style="max-width:600px;margin:0 auto;font-family:Arial,sans-serif;color:#374151">';
        $html .= '<div style="background:' . $color . ';padding:24px;text-align:center;border-radius:12px 12px 0 0">';
        $html .= '<h1 style="color:#fff;margin:0;font-size:20px">' . $titulo . '</h1>';
        $html .= '<p style="color:rgba(255,255,255,.7);margin:4px 0 0;font-size:13px">Cotización #' . $cot['id']
                . ' — ' . _e($cot['prop_nombre']) . ' — ' . _e($cot['hab_nombre']) . '</p>';
        $html .= '</div>';
        $html .= '<div style="padding:24px;background:#fff;border:1px solid #e5e7eb;border-top:none">';

        if ($esAprobado) {
            $html .= '<p style="font-size:15px;margin-bottom:16px">Tu cotización para <strong>'
                   . _e($cot['prop_nombre']) . ' — ' . _e($cot['hab_nombre'])
                   . '</strong> ha sido <strong style="color:#16A34A">aprobada</strong>. '
                   . 'Ya puedes enviarla al cliente por email, WhatsApp o imprimir el PDF.</p>';
        } else {
            $html .= '<p style="font-size:15px;margin-bottom:16px">Tu cotización para <strong>'
                   . _e($cot['prop_nombre']) . ' — ' . _e($cot['hab_nombre'])
                   . '</strong> <strong style="color:#DC2626">no fue aprobada</strong>. '
                   . 'Puedes editarla para ajustar el descuento y reenviar la solicitud.</p>';
        }

        // Info general
        $html .= '<div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px">';
        $html .= '<strong>Cliente:</strong> ' . _e($cot['cliente_nombre'] ?: '-');
        $html .= ' &nbsp;|&nbsp; <strong>Duración:</strong> ' . $cot['duracion_meses'] . ' meses';
        $html .= ' &nbsp;|&nbsp; <strong>Tarifa:</strong> ' . _e($cot['tarifa_aplicada']);
        $html .= '</div>';

        // Desglose completo
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;margin:12px 0">';
        foreach (buildDesglose($cot) as $linea) {
            $style = 'padding:8px 12px;border-bottom:1px solid #f3f4f6;';
            $valStyle = $style;
            if ($linea[2] === 'discount') $valStyle .= 'color:#DC2626;';
            if ($linea[2] === 'bold') { $style .= 'font-weight:700;'; $valStyle .= 'font-weight:700;'; }
            $html .= '<tr><td style="' . $style . 'color:#6b7280">' . $linea[0]
                   . '</td><td style="' . $valStyle . 'text-align:right">' . $linea[1] . '</td></tr>';
        }
        // Total mensual
        $html .= '<tr style="background:#202944"><td style="padding:12px;color:#fff;font-weight:700">TOTAL MENSUAL</td>';
        $html .= '<td style="padding:12px;text-align:right;color:#fff;font-weight:700;font-size:18px">' . _money($cot['subtotal_mensual']) . ' MXN</td></tr>';
        $html .= '</table>';

        // Total contrato
        $html .= '<div style="background:#f8fafc;border-radius:8px;padding:12px;text-align:center;margin:12px 0">';
        $html .= '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:1px">Total contrato (' . $cot['duracion_meses'] . ' meses)</div>';
        $html .= '<div style="font-size:22px;font-weight:700;color:#202944">' . _money($cot['total_contrato']) . ' MXN</div>';
        $html .= '</div>';

        $html .= '<p style="font-size:12px;color:#9ca3af;text-align:center;margin-top:16px">Park Life Properties — Sistema de Cotizaciones</p>';
        $html .= '</div></div>';

        $mail->send(
            to:      $cot['agente_email'],
            toName:  $cot['agente_nombre'] ?: 'Agente',
            subject: $subject,
            body:    $html,
        );
    } catch (Throwable $e) {
        error_log('aprobar-descuento: Error email agente: ' . $e->getMessage());
    }
}


/**
 * Renderiza la página cuando el token ya fue procesado previamente.
 * Muestra auditoría completa: quién, cuándo, IP, navegador.
 */
function renderYaProcesada(array $cot): void
{
    $accionRealizada = $cot['autorizacion_accion'] ?? null;
    
    // Determinar estado basado en la acción guardada o el estatus actual
    if ($accionRealizada === 'aprobado') {
        $estadoTexto = 'APROBADA';
        $bgColor = '#16A34A';
        $icono = '✅';
        $badgeClass = 'background:#dcfce7;color:#166534';
    } elseif ($accionRealizada === 'rechazado') {
        $estadoTexto = 'RECHAZADA';
        $bgColor = '#DC2626';
        $icono = '❌';
        $badgeClass = 'background:#fef2f2;color:#991b1b';
    } else {
        // Fallback: deducir del estatus (para cotizaciones procesadas antes de v5.0)
        $map = [
            'borrador'  => ['APROBADA',  '#16A34A', '✅', 'background:#dcfce7;color:#166534'],
            'enviada'   => ['APROBADA (ya enviada)', '#16A34A', '✅', 'background:#dcfce7;color:#166534'],
            'aceptada'  => ['APROBADA (aceptada)', '#16A34A', '✅', 'background:#dcfce7;color:#166534'],
            'rechazada' => ['RECHAZADA', '#DC2626', '❌', 'background:#fef2f2;color:#991b1b'],
        ];
        $info = $map[$cot['estatus']] ?? ['PROCESADA', '#6B7280', '📋', 'background:#f1f5f9;color:#475569'];
        [$estadoTexto, $bgColor, $icono, $badgeClass] = $info;
    }

    // Datos de auditoría
    $fechaAuth   = !empty($cot['autorizado_at']) ? date('d/m/Y \a \l\a\s H:i:s', strtotime($cot['autorizado_at'])) : 'No registrada';
    $autorPor    = !empty($cot['autorizado_por']) ? _e($cot['autorizado_por']) : 'No registrado';
    $auditIp     = !empty($cot['autorizacion_ip']) ? _e($cot['autorizacion_ip']) : 'No registrada';
    $auditUaRaw  = $cot['autorizacion_user_agent'] ?? '';
    $auditUa     = !empty($auditUaRaw) ? parseUserAgent($auditUaRaw) : 'No registrado';
    $auditUaFull = !empty($auditUaRaw) ? _e($auditUaRaw) : '';
    $auditNotas  = !empty($cot['autorizacion_notas']) ? _e($cot['autorizacion_notas']) : '';

    // Calcular tiempo transcurrido
    $tiempoTranscurrido = '';
    if (!empty($cot['autorizado_at'])) {
        $diff = (new DateTime())->diff(new DateTime($cot['autorizado_at']));
        if ($diff->days === 0 && $diff->h === 0) {
            $tiempoTranscurrido = 'Hace ' . max(1, $diff->i) . ' minuto' . ($diff->i > 1 ? 's' : '');
        } elseif ($diff->days === 0) {
            $tiempoTranscurrido = 'Hace ' . $diff->h . ' hora' . ($diff->h > 1 ? 's' : '');
        } elseif ($diff->days === 1) {
            $tiempoTranscurrido = 'Hace 1 día';
        } else {
            $tiempoTranscurrido = 'Hace ' . $diff->days . ' días';
        }
    }

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Ya procesada — Cotización #<?php echo $cot['id']; ?></title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Segoe UI',system-ui,Arial,sans-serif;background:#f8fafc;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:580px;width:100%;overflow:hidden}
        .hdr{padding:28px 24px;text-align:center;color:#fff}
        .hdr h1{font-size:20px;font-weight:700;margin:0}
        .hdr .sub{font-size:13px;opacity:.75;margin-top:4px}
        .body{padding:24px}
        .status-banner{text-align:center;margin-bottom:20px;padding:16px;border-radius:12px}
        .status-banner .badge{display:inline-block;padding:6px 16px;border-radius:20px;font-size:14px;font-weight:700;letter-spacing:.5px}
        .status-banner .time-ago{font-size:12px;color:#6b7280;margin-top:6px}
        
        /* Auditoría */
        .audit-section{margin-bottom:20px}
        .audit-title{font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:#9ca3af;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:6px}
        .audit-title::after{content:'';flex:1;height:1px;background:#e5e7eb}
        .audit-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        .audit-item{background:#f8fafc;border-radius:10px;padding:12px;border:1px solid #f1f5f9}
        .audit-item.full{grid-column:1/-1}
        .audit-item .label{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;font-weight:600;margin-bottom:2px}
        .audit-item .value{font-size:13px;font-weight:600;color:#1e293b;word-break:break-all}
        .audit-item .value.small{font-size:11px;font-weight:400;color:#64748b}
        .audit-item .value.ip{font-family:'SF Mono',Monaco,'Consolas',monospace;font-size:14px;letter-spacing:.5px}

        /* Info chips */
        .info-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px}
        .info-chip{background:#f1f5f9;border-radius:8px;padding:8px 12px;font-size:12px;color:#64748b;flex:1;min-width:100px;text-align:center}
        .info-chip strong{display:block;color:#1e293b;font-size:13px;margin-top:2px}

        /* Desglose */
        .breakdown{margin-bottom:16px}
        .bk-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
        .bk-row:last-child{border:none}
        .bk-row .lbl{color:#6b7280}
        .bk-row .val{font-weight:500;color:#1f2937}
        .bk-row.discount .val{color:#DC2626}
        .bk-row.bold .lbl,.bk-row.bold .val{font-weight:700}
        .bk-row.iva{border-top:1px dashed #e2e8f0;padding-top:10px;margin-top:2px}
        .total-box{background:#202944;border-radius:12px;padding:16px;text-align:center;margin-bottom:12px}
        .total-box .label{font-size:11px;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:1px}
        .total-box .amount{font-size:28px;font-weight:800;color:#fff;margin-top:2px}
        .total-box .iva-note{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px}
        .contract-box{background:#f8fafc;border-radius:10px;padding:12px;text-align:center;margin-bottom:16px}
        .contract-box .label{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:1px}
        .contract-box .amount{font-size:20px;font-weight:700;color:#202944}

        /* Nota informativa */
        .info-note{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;font-size:13px;color:#1e40af;margin-bottom:16px;line-height:1.5}
        .info-note strong{color:#1e3a8a}

        /* UA tooltip */
        .ua-detail{margin-top:4px;padding:8px 10px;background:#f1f5f9;border-radius:6px;font-size:10px;color:#94a3b8;font-family:'SF Mono',Monaco,'Consolas',monospace;line-height:1.4;word-break:break-all;display:none}
        .ua-toggle{font-size:10px;color:#6366f1;cursor:pointer;text-decoration:underline;margin-left:6px}

        .foot{text-align:center;font-size:12px;color:#9ca3af;padding-top:16px;border-top:1px solid #f3f4f6}
        .foot a{color:#202944;text-decoration:none;font-weight:600}
    </style>
</head>
<body>
<div class="card">
    <div class="hdr" style="background:<?php echo $bgColor; ?>">
        <h1><?php echo $icono; ?> Cotización Ya Procesada</h1>
        <div class="sub">Cotización #<?php echo $cot['id']; ?> — <?php echo _e($cot['prop_nombre']); ?></div>
    </div>
    <div class="body">

        <!-- Banner de estado -->
        <div class="status-banner">
            <div class="badge" style="<?php echo $badgeClass; ?>"><?php echo $estadoTexto; ?></div>
            <?php if ($tiempoTranscurrido): ?>
            <div class="time-ago"><?php echo $tiempoTranscurrido; ?></div>
            <?php endif; ?>
        </div>

        <!-- Nota informativa -->
        <div class="info-note">
            <strong>ℹ️ Esta cotización ya fue procesada.</strong><br>
            No se requiere ninguna acción adicional. Si necesitas modificar la decisión, contacta al administrador del sistema para que ajuste el estatus desde el panel de administración.
        </div>

        <!-- ═══ Sección de Auditoría ═══ -->
        <div class="audit-section">
            <div class="audit-title">Registro de autorización</div>
            <div class="audit-grid">
                <div class="audit-item">
                    <div class="label">Autorizado por</div>
                    <div class="value"><?php echo $autorPor; ?></div>
                </div>
                <div class="audit-item">
                    <div class="label">Fecha y hora</div>
                    <div class="value"><?php echo $fechaAuth; ?></div>
                </div>
                <div class="audit-item">
                    <div class="label">Dirección IP</div>
                    <div class="value ip"><?php echo $auditIp; ?></div>
                </div>
                <div class="audit-item">
                    <div class="label">Dispositivo</div>
                    <div class="value">
                        <?php echo $auditUa; ?>
                        <?php if ($auditUaFull): ?>
                        <span class="ua-toggle" onclick="document.getElementById('ua-full').style.display=document.getElementById('ua-full').style.display==='block'?'none':'block'">ver detalle</span>
                        <div class="ua-detail" id="ua-full"><?php echo $auditUaFull; ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($auditNotas): ?>
                <div class="audit-item full">
                    <div class="label">Notas</div>
                    <div class="value small"><?php echo $auditNotas; ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══ Detalle de la cotización ═══ -->
        <div class="audit-title">Detalle de la cotización</div>

        <!-- Info general -->
        <div class="info-bar">
            <div class="info-chip">Cliente<strong><?php echo _e($cot['cliente_nombre'] ?: '-'); ?></strong></div>
            <div class="info-chip">Unidad<strong><?php echo _e($cot['hab_nombre']); ?><?php echo $cot['hab_codigo'] ? ' (' . _e($cot['hab_codigo']) . ')' : ''; ?></strong></div>
            <div class="info-chip">Duración<strong><?php echo $cot['duracion_meses']; ?> mes<?php echo intval($cot['duracion_meses']) > 1 ? 'es' : ''; ?></strong></div>
        </div>

        <!-- Desglose completo -->
        <div class="breakdown">
        <?php foreach (buildDesglose($cot) as $linea): ?>
            <div class="bk-row <?php echo $linea[2]; ?>">
                <span class="lbl"><?php echo $linea[0]; ?></span>
                <span class="val"><?php echo $linea[1]; ?></span>
            </div>
        <?php endforeach; ?>
        </div>

        <!-- Total mensual -->
        <div class="total-box">
            <div class="label">Total mensual</div>
            <div class="amount"><?php echo _money($cot['subtotal_mensual']); ?> MXN</div>
            <?php if ((float)($cot['monto_iva'] ?? 0) > 0): ?>
            <div class="iva-note">IVA incluido</div>
            <?php endif; ?>
        </div>

        <!-- Total contrato -->
        <div class="contract-box">
            <div class="label">Total contrato (<?php echo $cot['duracion_meses']; ?> meses)</div>
            <div class="amount"><?php echo _money($cot['total_contrato']); ?> MXN</div>
        </div>

        <!-- Agente -->
        <div style="text-align:center;font-size:13px;color:#6b7280;margin-bottom:12px">
            Agente: <strong style="color:#1f2937"><?php echo _e($cot['agente_nombre'] ?: $cot['agente_email']); ?></strong>
        </div>

        <div class="foot">
            <a href="https://parklife.mx">Park Life Properties</a><br>
            Sistema de Cotizaciones
        </div>
    </div>
</div>
</body>
</html>
<?php
}


/**
 * Renderiza la página de resultado estándar (aprobación, rechazo, error).
 */
function renderPage(string $tipo, string $titulo, string $mensaje, ?array $cot): void
{
    $colores = [
        'aprobada'     => '#16A34A',
        'rechazada'    => '#DC2626',
        'error'        => '#DC2626',
    ];
    $bgColor = $colores[$tipo] ?? '#6B7280';
    $iconos  = ['aprobada' => '✅', 'rechazada' => '❌', 'error' => '⚠️'];
    $icono   = $iconos[$tipo] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo strip_tags($titulo); ?> — Park Life</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Segoe UI',system-ui,Arial,sans-serif;background:#f8fafc;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:520px;width:100%;overflow:hidden}
        .hdr{padding:28px 24px;text-align:center;color:#fff}
        .hdr h1{font-size:20px;font-weight:700;margin:0}
        .hdr .sub{font-size:13px;opacity:.75;margin-top:4px}
        .body{padding:24px}
        .msg{text-align:center;font-size:15px;line-height:1.6;color:#374151;margin-bottom:20px}
        .info-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px}
        .info-chip{background:#f1f5f9;border-radius:8px;padding:8px 12px;font-size:12px;color:#64748b;flex:1;min-width:100px;text-align:center}
        .info-chip strong{display:block;color:#1e293b;font-size:13px;margin-top:2px}
        .breakdown{margin-bottom:16px}
        .bk-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
        .bk-row:last-child{border:none}
        .bk-row .lbl{color:#6b7280}
        .bk-row .val{font-weight:500;color:#1f2937}
        .bk-row.discount .val{color:#DC2626}
        .bk-row.bold .lbl,.bk-row.bold .val{font-weight:700}
        .bk-row.iva{border-top:1px dashed #e2e8f0;padding-top:10px;margin-top:2px}
        .total-box{background:#202944;border-radius:12px;padding:16px;text-align:center;margin-bottom:12px}
        .total-box .label{font-size:11px;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:1px}
        .total-box .amount{font-size:28px;font-weight:800;color:#fff;margin-top:2px}
        .total-box .iva-note{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px}
        .contract-box{background:#f8fafc;border-radius:10px;padding:12px;text-align:center;margin-bottom:16px}
        .contract-box .label{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:1px}
        .contract-box .amount{font-size:20px;font-weight:700;color:#202944}
        .foot{text-align:center;font-size:12px;color:#9ca3af;padding-top:16px;border-top:1px solid #f3f4f6}
        .foot a{color:#202944;text-decoration:none;font-weight:600}
    </style>
</head>
<body>
<div class="card">
    <div class="hdr" style="background:<?php echo $bgColor; ?>">
        <h1><?php echo $icono . ' ' . $titulo; ?></h1>
        <?php if ($cot): ?>
        <div class="sub">Cotización #<?php echo $cot['id']; ?> — <?php echo _e($cot['prop_nombre']); ?></div>
        <?php endif; ?>
    </div>
    <div class="body">
        <div class="msg"><?php echo $mensaje; ?></div>

        <?php if ($cot): ?>
        <!-- Info general -->
        <div class="info-bar">
            <div class="info-chip">Cliente<strong><?php echo _e($cot['cliente_nombre'] ?: '-'); ?></strong></div>
            <div class="info-chip">Unidad<strong><?php echo _e($cot['hab_nombre']); ?><?php echo $cot['hab_codigo'] ? ' (' . _e($cot['hab_codigo']) . ')' : ''; ?></strong></div>
            <div class="info-chip">Duración<strong><?php echo $cot['duracion_meses']; ?> mes<?php echo intval($cot['duracion_meses']) > 1 ? 'es' : ''; ?></strong></div>
        </div>

        <!-- Desglose completo -->
        <div class="breakdown">
        <?php foreach (buildDesglose($cot) as $linea): ?>
            <div class="bk-row <?php echo $linea[2]; ?>">
                <span class="lbl"><?php echo $linea[0]; ?></span>
                <span class="val"><?php echo $linea[1]; ?></span>
            </div>
        <?php endforeach; ?>
        </div>

        <!-- Total mensual -->
        <div class="total-box">
            <div class="label">Total mensual</div>
            <div class="amount"><?php echo _money($cot['subtotal_mensual']); ?> MXN</div>
            <?php if ((float)($cot['monto_iva'] ?? 0) > 0): ?>
            <div class="iva-note">IVA incluido</div>
            <?php endif; ?>
        </div>

        <!-- Total contrato -->
        <div class="contract-box">
            <div class="label">Total contrato (<?php echo $cot['duracion_meses']; ?> meses)</div>
            <div class="amount"><?php echo _money($cot['total_contrato']); ?> MXN</div>
        </div>

        <!-- Agente -->
        <div style="text-align:center;font-size:13px;color:#6b7280;margin-bottom:12px">
            Agente: <strong style="color:#1f2937"><?php echo _e($cot['agente_nombre'] ?: $cot['agente_email']); ?></strong>
        </div>
        <?php endif; ?>

        <div class="foot">
            <a href="https://parklife.mx">Park Life Properties</a><br>
            Sistema de Cotizaciones
        </div>
    </div>
</div>
</body>
</html>
<?php
}