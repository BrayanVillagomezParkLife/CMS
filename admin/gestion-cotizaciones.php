<?php
declare(strict_types=1);
/**
 * admin/gestion-cotizaciones.php — Módulo de Gestión de Cotizaciones
 * Solo accesible para superadmin / director comercial.
 *
 * Funcionalidades:
 *   - Vista global de TODAS las cotizaciones (todos los agentes)
 *   - Filtros: búsqueda texto, agente, estatus, propiedad, rango de fechas
 *   - Detalle con auditoría completa (quién aprobó, IP, navegador, fecha)
 *   - Acciones admin: revocar aprobación, forzar estatus, re-enviar solicitud, eliminar
 *   - Mini-dashboard con estadísticas rápidas
 *
 * v1.0 — 2026-03-04
 */

require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

// ── Solo superadmin ──
if (!isSuperAdmin()) {
    http_response_code(403);
    if (file_exists(__DIR__ . '/403.php')) { include __DIR__ . '/403.php'; }
    else { echo '<h1>403 — Solo administradores</h1>'; }
    exit;
}

$csrf      = adminCsrf();
$adminId   = currentAdminId();
$detailId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ═════════════════════════════════════════════════════════════════════════════
// POST HANDLERS (Acciones administrativas)
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('gestion-cotizaciones.php', 'error', 'Token CSRF inválido.');
    }

    $action = $_POST['action'] ?? '';
    $cotId  = (int)($_POST['cot_id'] ?? 0);

    // ── Revocar aprobación: vuelve a pendiente_autorizacion con nuevo token ──
    if ($action === 'revocar_aprobacion' && $cotId) {
        $cot = dbFetchOne("SELECT id, estatus, descuento_porcentaje, autorizado_por FROM cotizaciones WHERE id=?", [$cotId]);
        if (!$cot) adminRedirect('gestion-cotizaciones.php', 'error', 'Cotización no encontrada.');

        $nuevoToken = bin2hex(random_bytes(32));
        $motivo = sanitizeStr($_POST['motivo'] ?? 'Revocado por administrador');

        dbExecute(
            "UPDATE cotizaciones SET 
                estatus = 'pendiente_autorizacion',
                autorizacion_token = ?,
                autorizado_por = NULL,
                autorizado_at = NULL,
                autorizacion_ip = NULL,
                autorizacion_user_agent = NULL,
                autorizacion_accion = NULL,
                autorizacion_notas = ?
             WHERE id = ?",
            [$nuevoToken, "Aprobación revocada: {$motivo}", $cotId]
        );

        logUserAction($adminId, 'revoke_approval', "Revocó aprobación cotización #{$cotId}. Motivo: {$motivo}");
        adminRedirect("gestion-cotizaciones.php?id={$cotId}", 'success',
            "Aprobación revocada. Cotización #{$cotId} vuelve a pendiente con nuevo token.");
    }

    // ── Forzar estatus ──
    if ($action === 'forzar_estatus' && $cotId) {
        $nuevoEstatus = $_POST['nuevo_estatus'] ?? '';
        $validos = ['borrador','pendiente_autorizacion','enviada','aceptada','rechazada','expirada'];
        if (!in_array($nuevoEstatus, $validos)) {
            adminRedirect("gestion-cotizaciones.php?id={$cotId}", 'error', 'Estatus no válido.');
        }

        $motivo = sanitizeStr($_POST['motivo'] ?? '');
        $notaAnterior = dbFetchValue("SELECT autorizacion_notas FROM cotizaciones WHERE id=?", [$cotId]) ?? '';
        $notaFinal = $notaAnterior ? $notaAnterior . ' | ' : '';
        $notaFinal .= "Estatus forzado a '{$nuevoEstatus}' por " . currentAdminEmail()
                     . ' el ' . date('d/m/Y H:i')
                     . ($motivo ? ". Motivo: {$motivo}" : '');

        dbExecute(
            "UPDATE cotizaciones SET estatus = ?, autorizacion_notas = ? WHERE id = ?",
            [$nuevoEstatus, $notaFinal, $cotId]
        );

        logUserAction($adminId, 'force_status', "Forzó estatus cotización #{$cotId} a '{$nuevoEstatus}'" . ($motivo ? ". Motivo: {$motivo}" : ''));
        adminRedirect("gestion-cotizaciones.php?id={$cotId}", 'success',
            "Estatus de cotización #{$cotId} cambiado a '{$nuevoEstatus}'.");
    }

    // ── Re-enviar solicitud de aprobación ──
    if ($action === 'reenviar_solicitud' && $cotId) {
        $cot = dbFetchOne("SELECT * FROM cotizaciones WHERE id=?", [$cotId]);
        if (!$cot) adminRedirect('gestion-cotizaciones.php', 'error', 'Cotización no encontrada.');
        if ((float)($cot['descuento_porcentaje'] ?? 0) <= 0) {
            adminRedirect("gestion-cotizaciones.php?id={$cotId}", 'error', 'Esta cotización no tiene descuento.');
        }

        // Generar nuevo token si no tiene o si ya fue procesado
        $nuevoToken = bin2hex(random_bytes(32));
        dbExecute(
            "UPDATE cotizaciones SET 
                estatus = 'pendiente_autorizacion',
                autorizacion_token = ?,
                autorizado_por = NULL,
                autorizado_at = NULL,
                autorizacion_ip = NULL,
                autorizacion_user_agent = NULL,
                autorizacion_accion = NULL,
                autorizacion_notas = 'Re-enviada solicitud por administrador'
             WHERE id = ?",
            [$nuevoToken, $cotId]
        );

        // Enviar email de solicitud directamente
        require_once __DIR__ . '/../services/MailService.php';
        $mailSvc = new MailService();

        $baseUrl = rtrim(($_SERVER['REQUEST_SCHEME'] ?? (str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost') ? 'http' : 'https'))
                 . '://' . ($_SERVER['HTTP_HOST'] ?? 'parklife.mx'), '/');

        $approveUrl = $baseUrl . '/admin/aprobar-descuento.php?token=' . $nuevoToken . '&accion=aprobar';
        $rejectUrl  = $baseUrl . '/admin/aprobar-descuento.php?token=' . $nuevoToken . '&accion=rechazar';

        $prop = dbFetchOne("SELECT nombre FROM propiedades WHERE id=?", [(int)$cot['propiedad_id']]);
        $hab  = dbFetchOne("SELECT nombre, codigo FROM habitaciones WHERE id=?", [(int)$cot['habitacion_id']]);
        $agente = dbFetchOne("SELECT nombre, email FROM admin_usuarios WHERE id=?", [(int)$cot['admin_id']]);

        $f = fn($v) => '$' . number_format((float)$v, 2);
        $descPct = $cot['descuento_porcentaje'];
        $emailAprobador = dbFetchValue("SELECT valor FROM config WHERE clave='email_aprobador_descuentos'")
                        ?: 'brayan.villagomez@parklife.mx';

        $html = '<div style="max-width:600px;margin:0 auto;font-family:Arial,sans-serif">';
        $html .= '<div style="background:#DC2626;padding:20px;text-align:center;border-radius:12px 12px 0 0">';
        $html .= '<h1 style="color:#fff;margin:0;font-size:18px">🔔 Solicitud de Descuento (Re-envío)</h1>';
        $html .= '<p style="color:rgba(255,255,255,.8);margin:4px 0 0;font-size:13px">Cotización #' . $cotId . ' requiere tu aprobación</p></div>';
        $html .= '<div style="padding:24px;background:#fff;border:1px solid #e5e7eb">';
        $html .= '<p><strong>' . htmlspecialchars($agente['nombre'] ?? $agente['email'] ?? 'Agente') . '</strong> generó una cotización con descuento del <strong style="color:#DC2626;font-size:18px">' . $descPct . '%</strong></p>';
        $html .= '<p style="font-size:13px;color:#6b7280">Re-enviado por administrador: ' . htmlspecialchars(currentAdminEmail()) . '</p>';

        $html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:16px 0">';
        $tableRows = [
            ['Cliente',       htmlspecialchars($cot['cliente_nombre'] ?: 'Sin nombre')],
            ['Propiedad',     htmlspecialchars($prop['nombre'] ?? '')],
            ['Unidad',        htmlspecialchars(($hab['nombre'] ?? '') . ($hab['codigo'] ? ' (' . $hab['codigo'] . ')' : ''))],
            ['Duración',      $cot['duracion_meses'] . ' meses (tarifa ' . $cot['tarifa_aplicada'] . ')'],
            ['Renta base',    $f($cot['precio_renta_base'])],
            ['Descuento (' . $descPct . '%)', '<strong style="color:#DC2626">-' . $f($cot['descuento_monto']) . '</strong>'],
            ['Renta neta',    '<strong>' . $f($cot['precio_renta_neta']) . '</strong>'],
        ];
        if ((float)($cot['monto_mantenimiento'] ?? 0) > 0) $tableRows[] = ['Mantenimiento', '+' . $f($cot['monto_mantenimiento'])];
        if (!empty($cot['inc_servicios']) && (float)($cot['monto_servicios'] ?? 0) > 0) $tableRows[] = ['Servicios', '+' . $f($cot['monto_servicios'])];
        if (!empty($cot['inc_amueblado']) && (float)($cot['monto_amueblado'] ?? 0) > 0) $tableRows[] = ['Amueblado', '+' . $f($cot['monto_amueblado'])];
        if (!empty($cot['inc_parking']) && (float)($cot['monto_parking'] ?? 0) > 0) $tableRows[] = ['Estacionamiento', '+' . $f($cot['monto_parking'])];
        if (!empty($cot['inc_mascota']) && (float)($cot['monto_mascota'] ?? 0) > 0) $tableRows[] = ['Mascota', '+' . $f($cot['monto_mascota'])];
        if ((float)($cot['monto_iva'] ?? 0) > 0) $tableRows[] = ['IVA (' . ($cot['iva_porcentaje'] ?? 16) . '%)', '+' . $f($cot['monto_iva'])];
        $tableRows[] = ['<strong>Total mensual</strong>', '<strong>' . $f($cot['subtotal_mensual']) . ' MXN</strong>'];
        $tableRows[] = ['Total contrato (' . $cot['duracion_meses'] . 'm)', '<strong>' . $f($cot['total_contrato']) . ' MXN</strong>'];

        $alt = false;
        foreach ($tableRows as $r) {
            $bg = $alt ? ' style="background:#f8fafc"' : '';
            $html .= "<tr{$bg}><td style=\"padding:10px;border:1px solid #e5e7eb\"><strong>{$r[0]}</strong></td>"
                   . "<td style=\"padding:10px;border:1px solid #e5e7eb\">{$r[1]}</td></tr>";
            $alt = !$alt;
        }
        $html .= '</table>';
        $html .= '<div style="text-align:center;margin:24px 0">';
        $html .= '<a href="' . $approveUrl . '" style="display:inline-block;background:#16A34A;color:#fff;padding:14px 40px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;margin-right:12px">✓ APROBAR</a>';
        $html .= '<a href="' . $rejectUrl . '" style="display:inline-block;background:#DC2626;color:#fff;padding:14px 40px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px">✗ RECHAZAR</a>';
        $html .= '</div>';
        $html .= '<p style="font-size:12px;color:#9ca3af;text-align:center">Este enlace es único y seguro.</p>';
        $html .= '</div></div>';

        try {
            $mailSvc->send(
                to:       $emailAprobador,
                toName:   'Director Comercial',
                subject:  "🔔 [Re-envío] Descuento {$descPct}% — Cotización #{$cotId}",
                body:     $html,
            );
            $emailOk = true;
        } catch (Throwable $e) {
            $emailOk = false;
        }

        logUserAction($adminId, 'resend_approval', "Re-envió solicitud de aprobación cotización #{$cotId}");

        $msg = "Solicitud re-enviada para cotización #{$cotId}.";
        if (!$emailOk) $msg .= ' (Nota: el email pudo no haberse enviado — verificar SMTP)';
        adminRedirect("gestion-cotizaciones.php?id={$cotId}", $emailOk ? 'success' : 'info', $msg);
    }

    // ── Eliminar ──
    if ($action === 'eliminar' && $cotId) {
        dbExecute("DELETE FROM cotizaciones WHERE id = ?", [$cotId]);
        logUserAction($adminId, 'delete_quote', "Eliminó cotización #{$cotId} desde gestión");
        adminRedirect('gestion-cotizaciones.php', 'success', "Cotización #{$cotId} eliminada.");
    }

    // ── Actualizar configuración de aprobador ──
    if ($action === 'actualizar_aprobador') {
        $nuevoEmail = sanitizeStr($_POST['email_aprobador'] ?? '');
        $nuevoNombre = sanitizeStr($_POST['nombre_aprobador'] ?? '');

        if (!$nuevoEmail || !filter_var($nuevoEmail, FILTER_VALIDATE_EMAIL)) {
            adminRedirect('gestion-cotizaciones.php', 'error', 'Email de aprobador no válido.');
        }

        // Upsert email
        $existe = dbFetchValue("SELECT COUNT(*) FROM config WHERE clave='email_aprobador_descuentos'");
        if ($existe) {
            dbExecute("UPDATE config SET valor = ? WHERE clave = 'email_aprobador_descuentos'", [$nuevoEmail]);
        } else {
            dbExecute("INSERT INTO config (clave, valor) VALUES ('email_aprobador_descuentos', ?)", [$nuevoEmail]);
        }

        // Upsert nombre
        $existeN = dbFetchValue("SELECT COUNT(*) FROM config WHERE clave='nombre_aprobador_descuentos'");
        if ($existeN) {
            dbExecute("UPDATE config SET valor = ? WHERE clave = 'nombre_aprobador_descuentos'", [$nuevoNombre]);
        } else {
            dbExecute("INSERT INTO config (clave, valor) VALUES ('nombre_aprobador_descuentos', ?)", [$nuevoNombre]);
        }

        logUserAction($adminId, 'update_config', "Actualizó aprobador de descuentos: {$nuevoNombre} <{$nuevoEmail}>");
        adminRedirect('gestion-cotizaciones.php', 'success', "Aprobador actualizado: {$nuevoNombre} ({$nuevoEmail})");
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// DETAIL VIEW
// ═════════════════════════════════════════════════════════════════════════════
if ($detailId > 0) {
    $cot = dbFetchOne(
        "SELECT c.*, 
                p.nombre AS prop_nombre, p.ciudad,
                h.nombre AS hab_nombre, h.codigo AS hab_codigo,
                h.num_camas, h.num_banos, h.metros_cuadrados,
                a.nombre AS agente_nombre, a.email AS agente_email
         FROM cotizaciones c
         JOIN propiedades p ON p.id = c.propiedad_id
         JOIN habitaciones h ON h.id = c.habitacion_id
         JOIN admin_usuarios a ON a.id = c.admin_id
         WHERE c.id = ?",
        [$detailId]
    );
    if (!$cot) adminRedirect('gestion-cotizaciones.php', 'error', 'Cotización no encontrada.');

    adminLayoutOpen('Cotización #' . $cot['id']);

    $f = fn($v) => '$' . number_format((float)$v, 2);
    $isPendiente = $cot['estatus'] === 'pendiente_autorizacion';
    $tieneDescuento = (float)($cot['descuento_porcentaje'] ?? 0) > 0;
    $tieneAprobacion = !empty($cot['autorizado_por']);

    // Estatus badge
    $statusMap = [
        'borrador'                => ['bg-gray-100 text-gray-600', 'Borrador'],
        'pendiente_autorizacion'  => ['bg-amber-100 text-amber-700', 'Pend. autorización'],
        'enviada'                 => ['bg-blue-100 text-blue-700', 'Enviada'],
        'aceptada'                => ['bg-green-100 text-green-700', 'Aceptada'],
        'rechazada'               => ['bg-red-100 text-red-700', 'Rechazada'],
        'expirada'                => ['bg-yellow-100 text-yellow-700', 'Expirada'],
    ];
    $descAprobado = ($cot['estatus'] === 'borrador' && $tieneAprobacion);
    if ($descAprobado) {
        $statusCls = 'bg-emerald-100 text-emerald-700';
        $statusLabel = 'Desc. aprobado';
    } else {
        [$statusCls, $statusLabel] = $statusMap[$cot['estatus']] ?? ['bg-gray-100 text-gray-600', $cot['estatus']];
    }
    ?>

    <a href="gestion-cotizaciones.php<?= isset($_GET['back']) ? '?' . e($_GET['back']) : '' ?>"
       class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-pk mb-4">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver al listado
    </a>

    <!-- Header -->
    <div class="card p-5 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-bold text-gray-800">Cotización #<?= $cot['id'] ?></h2>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full <?= $statusCls ?>"><?= $statusLabel ?></span>
                </div>
                <p class="text-sm text-gray-500 mt-1">
                    <?= e($cot['prop_nombre']) ?> — <?= e($cot['hab_nombre']) ?><?= $cot['hab_codigo'] ? ' (' . e($cot['hab_codigo']) . ')' : '' ?>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="cotizador.php?tab=ver&id=<?= $cot['id'] ?>" target="_blank"
                   class="btn-secondary text-xs"><i data-lucide="file-text" class="w-3.5 h-3.5"></i>Ver PDF</a>
                <a href="cotizador.php?tab=nueva&edit=<?= $cot['id'] ?>"
                   class="btn-secondary text-xs"><i data-lucide="pencil" class="w-3.5 h-3.5"></i>Editar</a>
            </div>
        </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">

        <!-- ═══ COL 1-2: Detalle + Desglose ═══ -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Info del cliente -->
            <div class="card p-5">
                <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                    <i data-lucide="user" class="w-4 h-4 text-pk"></i> Cliente
                </h3>
                <div class="grid sm:grid-cols-2 gap-3 text-sm">
                    <div><span class="text-gray-400">Nombre:</span> <strong><?= e($cot['cliente_nombre'] ?: '—') ?></strong></div>
                    <div><span class="text-gray-400">Email:</span> <strong><?= e($cot['cliente_email'] ?: '—') ?></strong></div>
                    <div><span class="text-gray-400">Teléfono:</span> <strong><?= e($cot['cliente_telefono'] ?: '—') ?></strong></div>
                    <div><span class="text-gray-400">Empresa:</span> <strong><?= e($cot['cliente_empresa'] ?: '—') ?></strong></div>
                </div>
            </div>

            <!-- Desglose financiero -->
            <div class="card p-5">
                <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                    <i data-lucide="receipt" class="w-4 h-4 text-pk"></i> Desglose financiero
                </h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">Renta base (tarifa <?= e($cot['tarifa_aplicada']) ?>)</span>
                        <span class="font-medium"><?= $f($cot['precio_renta_base']) ?></span>
                    </div>
                    <?php if ($tieneDescuento): ?>
                    <div class="flex justify-between py-2 border-b border-gray-100 text-amber-700">
                        <span>Descuento (<?= $cot['descuento_porcentaje'] ?>%)</span>
                        <span class="font-medium">-<?= $f($cot['descuento_monto']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between py-2 border-b border-dashed border-gray-200 font-bold">
                        <span class="text-gray-800">Renta neta</span>
                        <span class="text-pk"><?= $f($cot['precio_renta_neta']) ?></span>
                    </div>
                    <?php if ((float)$cot['monto_mantenimiento'] > 0): ?>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">Mantenimiento</span>
                        <span class="font-medium">+<?= $f($cot['monto_mantenimiento']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($cot['inc_servicios'] && (float)$cot['monto_servicios'] > 0): ?>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">Servicios (Full Service)</span>
                        <span class="font-medium">+<?= $f($cot['monto_servicios']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($cot['inc_amueblado'] && (float)$cot['monto_amueblado'] > 0): ?>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">Amueblado</span>
                        <span class="font-medium">+<?= $f($cot['monto_amueblado']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($cot['inc_parking'] && (float)$cot['monto_parking'] > 0): ?>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">Estacionamiento</span>
                        <span class="font-medium">+<?= $f($cot['monto_parking']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($cot['inc_mascota'] && (float)$cot['monto_mascota'] > 0): ?>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-600">Mascota</span>
                        <span class="font-medium">+<?= $f($cot['monto_mascota']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ((float)($cot['monto_iva'] ?? 0) > 0): ?>
                    <div class="flex justify-between py-2 border-b border-dashed border-gray-200">
                        <span class="text-gray-600">IVA (<?= (float)($cot['iva_porcentaje'] ?? 16) ?>%)
                            <span class="text-xs text-gray-400"><?= !empty($cot['iva_sobre_renta']) ? 'sobre servicios y renta' : 'sobre servicios' ?></span>
                        </span>
                        <span class="font-medium">+<?= $f($cot['monto_iva']) ?></span>
                    </div>
                    <?php endif; ?>

                    <!-- Totales -->
                    <div class="bg-pk/5 rounded-xl p-4 mt-3">
                        <div class="flex justify-between items-center">
                            <span class="font-bold text-gray-800">TOTAL MENSUAL</span>
                            <span class="text-2xl font-black text-pk"><?= $f($cot['subtotal_mensual']) ?> <span class="text-sm font-semibold">MXN</span></span>
                        </div>
                    </div>
                    <div class="flex justify-between bg-gray-50 rounded-xl p-3 mt-2">
                        <span class="text-gray-600">Total contrato (<?= $cot['duracion_meses'] ?> meses)</span>
                        <span class="font-bold text-gray-800 text-lg"><?= $f($cot['total_contrato']) ?> MXN</span>
                    </div>
                </div>
            </div>

            <!-- Notas -->
            <?php if (!empty($cot['notas'])): ?>
            <div class="card p-5 bg-amber-50/50 border-amber-100">
                <h3 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <i data-lucide="sticky-note" class="w-4 h-4 text-amber-600"></i> Notas
                </h3>
                <p class="text-sm text-gray-700"><?= nl2br(e($cot['notas'])) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══ COL 3: Auditoría + Acciones ═══ -->
        <div class="space-y-6">

            <!-- Info general -->
            <div class="card p-5">
                <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                    <i data-lucide="info" class="w-4 h-4 text-pk"></i> Información
                </h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-400">Agente</span><span class="font-medium"><?= e($cot['agente_nombre'] ?: $cot['agente_email']) ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Duración</span><span class="font-medium"><?= $cot['duracion_meses'] ?> meses (<?= $cot['tarifa_aplicada'] ?>)</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Creada</span><span class="font-medium"><?= date('d/m/Y H:i', strtotime($cot['created_at'])) ?></span></div>
                    <?php if (!empty($cot['token_publico'])): ?>
                    <div class="flex justify-between"><span class="text-gray-400">Token público</span><span class="font-medium text-xs text-gray-500 truncate max-w-[120px]"><?= e(substr($cot['token_publico'], 0, 12)) ?>…</span></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Auditoría de aprobación -->
            <?php if ($tieneDescuento): ?>
            <div class="card p-5 <?= $isPendiente ? 'border-2 border-amber-200 bg-amber-50/30' : ($tieneAprobacion ? 'border-2 border-emerald-200 bg-emerald-50/30' : '') ?>">
                <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                    <i data-lucide="shield-check" class="w-4 h-4 <?= $isPendiente ? 'text-amber-600' : 'text-emerald-600' ?>"></i>
                    Autorización de descuento
                </h3>

                <!-- Aprobador configurado -->
                <?php
                    $cfgEmail  = dbFetchValue("SELECT valor FROM config WHERE clave='email_aprobador_descuentos'") ?: 'brayan.villagomez@parklife.mx';
                    $cfgNombre = dbFetchValue("SELECT valor FROM config WHERE clave='nombre_aprobador_descuentos'") ?: 'Director Comercial';
                ?>
                <div class="flex items-center gap-2 mb-3 p-2 bg-white rounded-lg border border-gray-100 text-xs">
                    <i data-lucide="user-check" class="w-3.5 h-3.5 text-pk flex-shrink-0"></i>
                    <span class="text-gray-500">Aprobador:</span>
                    <span class="font-medium text-gray-800"><?= e($cfgNombre) ?></span>
                    <span class="text-gray-400">&lt;<?= e($cfgEmail) ?>&gt;</span>
                </div>

                <?php if ($isPendiente): ?>
                <div class="text-center py-4">
                    <div class="text-3xl mb-2">⏳</div>
                    <p class="text-sm font-semibold text-amber-700">Pendiente de aprobación</p>
                    <p class="text-xs text-gray-500 mt-1">Descuento del <?= $cot['descuento_porcentaje'] ?>% requiere autorización</p>
                </div>
                <?php elseif ($tieneAprobacion): ?>
                <div class="space-y-3 text-sm">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-lg"><?= ($cot['autorizacion_accion'] ?? '') === 'rechazado' ? '❌' : '✅' ?></span>
                        <span class="font-bold <?= ($cot['autorizacion_accion'] ?? '') === 'rechazado' ? 'text-red-700' : 'text-emerald-700' ?>">
                            <?= ($cot['autorizacion_accion'] ?? '') === 'rechazado' ? 'RECHAZADO' : 'APROBADO' ?>
                        </span>
                    </div>

                    <div class="grid gap-2">
                        <div class="bg-white rounded-lg p-3 border border-gray-100">
                            <div class="text-[10px] uppercase text-gray-400 font-semibold tracking-wider">Autorizado por</div>
                            <div class="font-medium text-gray-800"><?= e($cot['autorizado_por']) ?></div>
                        </div>
                        <div class="bg-white rounded-lg p-3 border border-gray-100">
                            <div class="text-[10px] uppercase text-gray-400 font-semibold tracking-wider">Fecha y hora</div>
                            <div class="font-medium text-gray-800">
                                <?= !empty($cot['autorizado_at']) ? date('d/m/Y H:i:s', strtotime($cot['autorizado_at'])) : '—' ?>
                            </div>
                        </div>
                        <?php if (!empty($cot['autorizacion_ip'])): ?>
                        <div class="bg-white rounded-lg p-3 border border-gray-100">
                            <div class="text-[10px] uppercase text-gray-400 font-semibold tracking-wider">Dirección IP</div>
                            <div class="font-medium font-mono text-gray-800"><?= e($cot['autorizacion_ip']) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($cot['autorizacion_user_agent'])): ?>
                        <div class="bg-white rounded-lg p-3 border border-gray-100">
                            <div class="text-[10px] uppercase text-gray-400 font-semibold tracking-wider">Dispositivo</div>
                            <div class="font-medium text-gray-800 text-xs break-all"><?= e($cot['autorizacion_user_agent']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <p class="text-sm text-gray-500">Descuento del <?= $cot['descuento_porcentaje'] ?>% — sin registro de autorización.</p>
                <?php endif; ?>

                <?php if (!empty($cot['autorizacion_notas'])): ?>
                <div class="mt-3 p-3 bg-white rounded-lg border border-gray-100">
                    <div class="text-[10px] uppercase text-gray-400 font-semibold tracking-wider">Notas de autorización</div>
                    <div class="text-xs text-gray-700 mt-1"><?= nl2br(e($cot['autorizacion_notas'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ═══ ACCIONES ADMIN ═══ -->
            <div class="card p-5">
                <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i data-lucide="shield" class="w-4 h-4 text-pk"></i> Acciones de administrador
                </h3>

                <div class="space-y-3">

                    <!-- Revocar aprobación -->
                    <?php if ($tieneAprobacion && $cot['estatus'] !== 'pendiente_autorizacion'): ?>
                    <div class="p-3 bg-amber-50 rounded-xl border border-amber-100">
                        <div class="text-xs font-semibold text-amber-800 mb-2">🔄 Revocar aprobación</div>
                        <p class="text-[11px] text-amber-700 mb-2">Invalida la aprobación actual, genera un nuevo token y vuelve a pedir autorización.</p>
                        <form method="post" onsubmit="return confirm('¿Revocar la aprobación de esta cotización? Se generará un nuevo token de autorización.')">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="revocar_aprobacion">
                            <input type="hidden" name="cot_id" value="<?= $cot['id'] ?>">
                            <input type="text" name="motivo" placeholder="Motivo (opcional)" class="form-input text-xs mb-2">
                            <button type="submit" class="w-full px-3 py-2 text-xs font-semibold rounded-lg bg-amber-500 text-white hover:bg-amber-600 transition-colors">
                                <i data-lucide="rotate-ccw" class="w-3 h-3 inline"></i> Revocar y re-solicitar
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Re-enviar solicitud -->
                    <?php if ($tieneDescuento && $isPendiente): ?>
                    <div class="p-3 bg-blue-50 rounded-xl border border-blue-100">
                        <div class="text-xs font-semibold text-blue-800 mb-2">📧 Re-enviar solicitud</div>
                        <p class="text-[11px] text-blue-700 mb-2">Genera nuevo token y re-envía el email de aprobación al Director Comercial.</p>
                        <form method="post" onsubmit="return confirm('¿Re-enviar la solicitud de aprobación con un nuevo token?')">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="reenviar_solicitud">
                            <input type="hidden" name="cot_id" value="<?= $cot['id'] ?>">
                            <button type="submit" class="w-full px-3 py-2 text-xs font-semibold rounded-lg bg-blue-500 text-white hover:bg-blue-600 transition-colors">
                                <i data-lucide="send" class="w-3 h-3 inline"></i> Re-enviar email de aprobación
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Forzar estatus -->
                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-200">
                        <div class="text-xs font-semibold text-gray-800 mb-2">⚡ Forzar estatus</div>
                        <p class="text-[11px] text-gray-500 mb-2">Cambia el estatus directamente sin pasar por el flujo normal.</p>
                        <form method="post" onsubmit="return confirm('¿Forzar cambio de estatus? Esta acción queda registrada en el log.')">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="forzar_estatus">
                            <input type="hidden" name="cot_id" value="<?= $cot['id'] ?>">
                            <select name="nuevo_estatus" class="form-select text-xs mb-2">
                                <?php foreach (['borrador','pendiente_autorizacion','enviada','aceptada','rechazada','expirada'] as $s):
                                    if ($s === $cot['estatus']) continue; ?>
                                <option value="<?= $s ?>"><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="motivo" placeholder="Motivo (opcional)" class="form-input text-xs mb-2">
                            <button type="submit" class="w-full px-3 py-2 text-xs font-semibold rounded-lg bg-gray-600 text-white hover:bg-gray-700 transition-colors">
                                <i data-lucide="zap" class="w-3 h-3 inline"></i> Aplicar cambio de estatus
                            </button>
                        </form>
                    </div>

                    <!-- Eliminar -->
                    <div class="border-t border-gray-200 pt-3">
                        <form method="post" onsubmit="return confirm('⚠️ ¿Eliminar permanentemente la cotización #<?= $cot['id'] ?>? Esta acción no se puede deshacer.')">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="eliminar">
                            <input type="hidden" name="cot_id" value="<?= $cot['id'] ?>">
                            <button type="submit" class="w-full px-3 py-2 text-xs font-semibold rounded-lg bg-white text-red-500 border border-red-200 hover:bg-red-50 transition-colors">
                                <i data-lucide="trash-2" class="w-3 h-3 inline"></i> Eliminar cotización
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
    adminLayoutClose();
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// LIST VIEW: Filtros + Tabla + Estadísticas
// ═════════════════════════════════════════════════════════════════════════════

// Datos para filtros
$agentes     = dbFetchAll("SELECT DISTINCT a.id, a.nombre, a.email FROM admin_usuarios a INNER JOIN cotizaciones c ON c.admin_id = a.id ORDER BY a.nombre");
$propiedades = dbFetchAll("SELECT id, nombre FROM propiedades WHERE activo=1 ORDER BY nombre");

// Parámetros de filtro
$fBuscar    = trim($_GET['buscar'] ?? '');
$fAgente    = (int)($_GET['agente'] ?? 0);
$fEstatus   = $_GET['estatus'] ?? '';
$fPropiedad = (int)($_GET['propiedad'] ?? 0);
$fDesde     = $_GET['desde'] ?? '';
$fHasta     = $_GET['hasta'] ?? '';

// Construir query con filtros
$where = [];
$params = [];

if ($fBuscar) {
    $where[] = "(c.cliente_nombre LIKE ? OR c.cliente_email LIKE ? OR c.cliente_empresa LIKE ? OR c.id = ?)";
    $params[] = "%{$fBuscar}%";
    $params[] = "%{$fBuscar}%";
    $params[] = "%{$fBuscar}%";
    $params[] = is_numeric($fBuscar) ? (int)$fBuscar : 0;
}
if ($fAgente)    { $where[] = "c.admin_id = ?"; $params[] = $fAgente; }
if ($fEstatus)   { $where[] = "c.estatus = ?"; $params[] = $fEstatus; }
if ($fPropiedad) { $where[] = "c.propiedad_id = ?"; $params[] = $fPropiedad; }
if ($fDesde)     { $where[] = "DATE(c.created_at) >= ?"; $params[] = $fDesde; }
if ($fHasta)     { $where[] = "DATE(c.created_at) <= ?"; $params[] = $fHasta; }

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cotizaciones = dbFetchAll(
    "SELECT c.*, p.nombre AS prop_nombre, h.nombre AS hab_nombre, 
            a.nombre AS agente_nombre, a.email AS agente_email
     FROM cotizaciones c
     JOIN propiedades p ON p.id = c.propiedad_id
     JOIN habitaciones h ON h.id = c.habitacion_id
     JOIN admin_usuarios a ON a.id = c.admin_id
     {$whereSQL}
     ORDER BY c.created_at DESC
     LIMIT 500",
    $params
);

// Estadísticas rápidas
$stats = dbFetchOne(
    "SELECT 
        COUNT(*) AS total,
        SUM(estatus = 'pendiente_autorizacion') AS pendientes,
        SUM(estatus = 'enviada') AS enviadas,
        SUM(estatus = 'aceptada') AS aceptadas,
        SUM(descuento_porcentaje > 0) AS con_descuento,
        COALESCE(AVG(CASE WHEN descuento_porcentaje > 0 THEN descuento_porcentaje END), 0) AS avg_descuento,
        COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) THEN subtotal_mensual END), 0) AS monto_mes
     FROM cotizaciones"
);

// Construir querystring para back-link desde detalle
$backQs = http_build_query(array_filter([
    'buscar' => $fBuscar, 'agente' => $fAgente ?: '', 'estatus' => $fEstatus,
    'propiedad' => $fPropiedad ?: '', 'desde' => $fDesde, 'hasta' => $fHasta
]));

adminLayoutOpen('Gestión de Cotizaciones');
?>

<!-- ═══ Mini-dashboard ═══ -->
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-6">
    <div class="card p-4 text-center">
        <div class="text-2xl font-black text-gray-800"><?= (int)$stats['total'] ?></div>
        <div class="text-[11px] text-gray-400 uppercase tracking-wider">Total</div>
    </div>
    <div class="card p-4 text-center <?= (int)$stats['pendientes'] > 0 ? 'border-2 border-amber-200 bg-amber-50/30' : '' ?>">
        <div class="text-2xl font-black <?= (int)$stats['pendientes'] > 0 ? 'text-amber-600' : 'text-gray-400' ?>"><?= (int)$stats['pendientes'] ?></div>
        <div class="text-[11px] text-gray-400 uppercase tracking-wider">Pendientes</div>
    </div>
    <div class="card p-4 text-center">
        <div class="text-2xl font-black text-blue-600"><?= (int)$stats['enviadas'] ?></div>
        <div class="text-[11px] text-gray-400 uppercase tracking-wider">Enviadas</div>
    </div>
    <div class="card p-4 text-center">
        <div class="text-2xl font-black text-green-600"><?= (int)$stats['aceptadas'] ?></div>
        <div class="text-[11px] text-gray-400 uppercase tracking-wider">Aceptadas</div>
    </div>
    <div class="card p-4 text-center">
        <div class="text-2xl font-black text-amber-600"><?= number_format((float)$stats['avg_descuento'], 1) ?>%</div>
        <div class="text-[11px] text-gray-400 uppercase tracking-wider">Desc. prom</div>
    </div>
    <div class="card p-4 text-center">
        <div class="text-lg font-black text-pk">$<?= number_format((float)$stats['monto_mes'], 0) ?></div>
        <div class="text-[11px] text-gray-400 uppercase tracking-wider">Cotizado/mes</div>
    </div>
</div>

<!-- ═══ Configuración del aprobador ═══ -->
<?php
$cfgAprobadorEmail  = dbFetchValue("SELECT valor FROM config WHERE clave='email_aprobador_descuentos'") ?: 'brayan.villagomez@parklife.mx';
$cfgAprobadorNombre = dbFetchValue("SELECT valor FROM config WHERE clave='nombre_aprobador_descuentos'") ?: 'Director Comercial';
?>
<div class="card mb-6 overflow-hidden">
    <button type="button" onclick="document.getElementById('cfg-aprobador').classList.toggle('hidden'); this.querySelector('.chevron').classList.toggle('rotate-180')"
            class="w-full flex items-center justify-between px-5 py-3 text-left hover:bg-gray-50 transition-colors">
        <div class="flex items-center gap-3">
            <i data-lucide="user-check" class="w-4 h-4 text-pk"></i>
            <div>
                <span class="text-sm font-semibold text-gray-800">Aprobador de descuentos</span>
                <span class="text-xs text-gray-400 ml-2"><?= e($cfgAprobadorNombre) ?> &lt;<?= e($cfgAprobadorEmail) ?>&gt;</span>
            </div>
        </div>
        <i data-lucide="chevron-down" class="w-4 h-4 text-gray-400 chevron transition-transform"></i>
    </button>
    <div id="cfg-aprobador" class="hidden border-t border-gray-100 px-5 py-4 bg-gray-50/50">
        <p class="text-xs text-gray-500 mb-3">Esta persona recibe los emails de solicitud de autorización cuando un agente genera una cotización con descuento.</p>
        <form method="post" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="actualizar_aprobador">
            <div class="w-48">
                <label class="form-label text-xs">Nombre</label>
                <input type="text" name="nombre_aprobador" value="<?= e($cfgAprobadorNombre) ?>" class="form-input text-sm" placeholder="Ej: Ricardo Rosique">
            </div>
            <div class="flex-1 min-w-[220px]">
                <label class="form-label text-xs">Email del aprobador</label>
                <input type="email" name="email_aprobador" value="<?= e($cfgAprobadorEmail) ?>" class="form-input text-sm" required placeholder="email@parklife.mx">
            </div>
            <button type="submit" class="btn-primary text-sm">
                <i data-lucide="save" class="w-3.5 h-3.5"></i> Guardar
            </button>
        </form>
    </div>
</div>

<!-- ═══ Filtros ═══ -->
<form method="get" class="card p-4 mb-6">
    <div class="flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[180px]">
            <label class="form-label text-xs">Buscar</label>
            <input type="text" name="buscar" value="<?= e($fBuscar) ?>" placeholder="Nombre, email, empresa o # cotización"
                   class="form-input text-sm">
        </div>
        <div class="w-40">
            <label class="form-label text-xs">Agente</label>
            <select name="agente" class="form-select text-sm">
                <option value="">Todos</option>
                <?php foreach ($agentes as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $fAgente === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['nombre'] ?: $a['email']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="w-40">
            <label class="form-label text-xs">Estatus</label>
            <select name="estatus" class="form-select text-sm">
                <option value="">Todos</option>
                <?php foreach (['borrador','pendiente_autorizacion','enviada','aceptada','rechazada','expirada'] as $s): ?>
                <option value="<?= $s ?>" <?= $fEstatus === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="w-40">
            <label class="form-label text-xs">Propiedad</label>
            <select name="propiedad" class="form-select text-sm">
                <option value="">Todas</option>
                <?php foreach ($propiedades as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $fPropiedad === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="w-32">
            <label class="form-label text-xs">Desde</label>
            <input type="date" name="desde" value="<?= e($fDesde) ?>" class="form-input text-sm">
        </div>
        <div class="w-32">
            <label class="form-label text-xs">Hasta</label>
            <input type="date" name="hasta" value="<?= e($fHasta) ?>" class="form-input text-sm">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary text-sm"><i data-lucide="search" class="w-3.5 h-3.5"></i>Filtrar</button>
            <a href="gestion-cotizaciones.php" class="btn-secondary text-sm"><i data-lucide="x" class="w-3.5 h-3.5"></i></a>
        </div>
    </div>
    <?php if ($fBuscar || $fAgente || $fEstatus || $fPropiedad || $fDesde || $fHasta): ?>
    <div class="mt-2 text-xs text-gray-500">
        Mostrando <?= count($cotizaciones) ?> resultado<?= count($cotizaciones) !== 1 ? 's' : '' ?>
        <?php if (count($cotizaciones) >= 500): ?><span class="text-amber-600">(límite alcanzado, refina los filtros)</span><?php endif; ?>
    </div>
    <?php endif; ?>
</form>

<!-- ═══ Tabla de resultados ═══ -->
<?php if (empty($cotizaciones)): ?>
    <div class="card p-12 text-center text-gray-400">
        <i data-lucide="search-x" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
        <p class="text-lg font-medium">Sin resultados</p>
        <p class="text-sm mt-1">Ajusta los filtros o <a href="gestion-cotizaciones.php" class="text-pk hover:underline">ver todas</a>.</p>
    </div>
<?php else: ?>
    <div class="card p-0 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-gray-100">
                <tr>
                    <th class="table-th">#</th>
                    <th class="table-th hidden md:table-cell">Fecha</th>
                    <th class="table-th">Agente</th>
                    <th class="table-th">Cliente</th>
                    <th class="table-th hidden sm:table-cell">Propiedad</th>
                    <th class="table-th text-center hidden sm:table-cell">Dur.</th>
                    <th class="table-th text-right hidden md:table-cell">Renta neta</th>
                    <th class="table-th text-right">Total/mes</th>
                    <th class="table-th text-center">Estatus</th>
                    <th class="table-th text-center hidden lg:table-cell">Auditoría</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($cotizaciones as $c):
                $isPend  = $c['estatus'] === 'pendiente_autorizacion';
                $sMap = [
                    'borrador'                => ['bg-gray-100 text-gray-600', 'Borrador'],
                    'pendiente_autorizacion'  => ['bg-amber-100 text-amber-700', 'Pend. autorizac.'],
                    'enviada'                 => ['bg-blue-100 text-blue-700', 'Enviada'],
                    'aceptada'                => ['bg-green-100 text-green-700', 'Aceptada'],
                    'rechazada'               => ['bg-red-100 text-red-700', 'Rechazada'],
                    'expirada'                => ['bg-yellow-100 text-yellow-700', 'Expirada'],
                ];
                $dAprobado = ($c['estatus'] === 'borrador' && !empty($c['autorizado_por']));
                if ($dAprobado) { $sCls = 'bg-emerald-100 text-emerald-700'; $sLbl = 'Desc. aprobado'; }
                else { [$sCls, $sLbl] = $sMap[$c['estatus']] ?? ['bg-gray-100 text-gray-600', $c['estatus']]; }
                $blur = $isPend ? 'style="filter:blur(5px);user-select:none"' : '';
            ?>
                <tr class="hover:bg-slate-50 cursor-pointer" onclick="window.location='gestion-cotizaciones.php?id=<?= $c['id'] ?>&back=<?= urlencode($backQs) ?>'">
                    <td class="table-td font-mono text-gray-400"><?= $c['id'] ?></td>
                    <td class="table-td whitespace-nowrap text-gray-500 hidden md:table-cell"><?= date('d/m/y H:i', strtotime($c['created_at'])) ?></td>
                    <td class="table-td">
                        <div class="text-xs font-medium text-gray-700"><?= e($c['agente_nombre'] ?: $c['agente_email']) ?></div>
                    </td>
                    <td class="table-td">
                        <div class="font-medium text-gray-800"><?= e($c['cliente_nombre'] ?: '—') ?></div>
                        <?php if ($c['cliente_empresa']): ?><div class="text-xs text-gray-400"><?= e($c['cliente_empresa']) ?></div><?php endif; ?>
                    </td>
                    <td class="table-td hidden sm:table-cell">
                        <div class="text-gray-800"><?= e($c['prop_nombre']) ?></div>
                        <div class="text-xs text-gray-400"><?= e($c['hab_nombre']) ?></div>
                    </td>
                    <td class="table-td text-center hidden sm:table-cell">
                        <?= $c['duracion_meses'] ?>m
                        <?php if ((float)$c['descuento_porcentaje'] > 0): ?>
                        <div class="text-xs text-amber-600">-<?= $c['descuento_porcentaje'] ?>%</div>
                        <?php endif; ?>
                    </td>
                    <td class="table-td text-right hidden md:table-cell">
                        <span <?= $blur ?>>$<?= number_format((float)$c['precio_renta_neta'], 2) ?></span>
                    </td>
                    <td class="table-td text-right font-semibold">
                        <span <?= $blur ?>>$<?= number_format((float)$c['subtotal_mensual'], 2) ?></span>
                    </td>
                    <td class="table-td text-center">
                        <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full <?= $sCls ?>"><?= $sLbl ?></span>
                    </td>
                    <td class="table-td text-center hidden lg:table-cell">
                        <?php if (!empty($c['autorizado_por'])): ?>
                        <span class="text-emerald-500" title="Autorizado: <?= e($c['autorizado_por']) ?>">
                            <i data-lucide="shield-check" class="w-4 h-4 inline"></i>
                        </span>
                        <?php elseif ($isPend): ?>
                        <span class="text-amber-400"><i data-lucide="clock" class="w-4 h-4 inline"></i></span>
                        <?php else: ?>
                        <span class="text-gray-300">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php adminLayoutClose(); ?>