<?php
declare(strict_types=1);
/**
 * admin/cotizador.php — Cotizador flexible de leasing para asesores.
 *
 * Reglas de negocio:
 *   - Duración: 12, 6, 3 meses predefinidos + custom. Tarifas por rango:
 *       7+ meses  → tarifa 12m
 *       3–6 meses → tarifa 6m
 *       1–2 meses → tarifa 1m
 *   - Mantenimiento siempre incluido (no se desactiva).
 *   - Descuento aplica SOLO sobre renta neta.
 *   - Cada agente ve solo sus cotizaciones; admin (role=superadmin) ve todas.
 */

require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf       = adminCsrf();
$tab        = $_GET['tab'] ?? 'nueva';
$adminId    = (int)($_SESSION['admin_id'] ?? 0);
$adminRole  = $_SESSION['admin_role'] ?? 'editor';
$propiedades = dbFetchAll("SELECT id, nombre FROM propiedades WHERE activo=1 ORDER BY nombre");
$baseUrl = rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'parklife.mx'), '/');

/** Build rich WhatsApp message with full breakdown */
function buildWaMsg(array $c, string $baseUrl): string {
    $f = fn($v) => '$' . number_format((float)$v, 2);
    $nombre = $c['cliente_nombre'] ?: 'cliente';
    $lines = [];
    $lines[] = "Hola {$nombre}, te comparto tu cotización de *Park Life {$c['prop_nombre']}* 🏠";
    $lines[] = "";
    $lines[] = "📋 *Cotización #{$c['id']}*";
    $lines[] = "🏢 {$c['hab_nombre']}";
    $lines[] = "📅 {$c['duracion_meses']} " . ((int)$c['duracion_meses'] === 1 ? 'mes' : 'meses') . " (tarifa {$c['tarifa_aplicada']})";
    $lines[] = "";
    $lines[] = "💰 *Desglose mensual:*";
    $lines[] = "Renta base: {$f($c['precio_renta_base'])}";
    if ((float)$c['descuento_porcentaje'] > 0) {
        $lines[] = "Descuento ({$c['descuento_porcentaje']}%): -{$f($c['descuento_monto'])}";
    }
    $lines[] = "*Renta neta: {$f($c['precio_renta_neta'])}*";
    if ((float)$c['monto_mantenimiento'] > 0) $lines[] = "Mantenimiento: {$f($c['monto_mantenimiento'])}";
    if ($c['inc_servicios'] && (float)$c['monto_servicios'] > 0) $lines[] = "Servicios: {$f($c['monto_servicios'])}";
    if ($c['inc_amueblado'] && (float)$c['monto_amueblado'] > 0) $lines[] = "Amueblado: {$f($c['monto_amueblado'])}";
    if ($c['inc_parking'] && (float)$c['monto_parking'] > 0) $lines[] = "Estacionamiento: {$f($c['monto_parking'])}";
    if ($c['inc_mascota'] && (float)$c['monto_mascota'] > 0) $lines[] = "Mascota: {$f($c['monto_mascota'])}";
    if ((float)($c['monto_iva'] ?? 0) > 0) $lines[] = "IVA ({$c['iva_porcentaje']}%): {$f($c['monto_iva'])}";
    $lines[] = "";
    $lines[] = "✅ *TOTAL MENSUAL: {$f($c['subtotal_mensual'])} MXN*";
    $lines[] = "📄 Total contrato: {$f($c['total_contrato'])} MXN";
    if (!empty($c['token_publico'])) {
        $lines[] = "";
        $lines[] = "🔗 Ver cotización completa:";
        $lines[] = "{$baseUrl}/cotizacion.php?t={$c['token_publico']}";
    }
    $lines[] = "";
    $lines[] = "Quedo a tus órdenes para cualquier duda. 🙂";
    return implode("\n", $lines);
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: Cargar habitaciones por propiedad
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'habitaciones') {
    header('Content-Type: application/json; charset=UTF-8');
    $pid = (int)($_GET['propiedad_id'] ?? 0);
    $habs = dbFetchAll(
        "SELECT id, nombre, codigo,
                precio_mes_12, precio_mes_6, precio_mes_1,
                precio_mantenimiento, precio_servicios,
                precio_amueblado, precio_parking_extra, precio_mascota,
                num_camas, num_banos, metros_cuadrados, tiene_parking
         FROM habitaciones WHERE propiedad_id = ? AND activa = 1 ORDER BY orden, nombre",
        [$pid]
    );
    echo json_encode(['habitaciones' => $habs]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: Cargar cotización para editar
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_cot') {
    header('Content-Type: application/json; charset=UTF-8');
    $cotId = (int)($_GET['cot_id'] ?? 0);
    $where = $adminRole === 'superadmin' ? '' : " AND c.admin_id = $adminId";
    $cot = dbFetchOne(
        "SELECT c.*, p.nombre AS prop_nombre, h.nombre AS hab_nombre
         FROM cotizaciones c
         JOIN propiedades p ON p.id = c.propiedad_id
         JOIN habitaciones h ON h.id = c.habitacion_id
         WHERE c.id = ? $where",
        [$cotId]
    );
    echo json_encode(['cotizacion' => $cot]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// VIEW: Vista imprimible de cotización
// ═════════════════════════════════════════════════════════════════════════════
if ($tab === 'ver' && isset($_GET['id'])) {
    $cotId = (int)$_GET['id'];
    $where = $adminRole === 'superadmin' ? '' : " AND c.admin_id = $adminId";
    $cot = dbFetchOne(
        "SELECT c.*, p.nombre AS prop_nombre, p.ciudad, p.calle, p.colonia,
                h.nombre AS hab_nombre, h.codigo AS hab_codigo,
                h.num_camas, h.num_banos, h.metros_cuadrados, h.imagen_url,
                a.email AS admin_email
         FROM cotizaciones c
         JOIN propiedades p ON p.id = c.propiedad_id
         JOIN habitaciones h ON h.id = c.habitacion_id
         JOIN admin_usuarios a ON a.id = c.admin_id
         WHERE c.id = ? $where",
        [$cotId]
    );
    if (!$cot) adminRedirect('cotizador.php?tab=historial', 'error', 'Cotización no encontrada.');

    $logoUrl = dbFetchValue("SELECT valor FROM config WHERE clave='logo_color'") ?: 'pics/Logo_Parklife.png';
    $tel     = dbFetchValue("SELECT valor FROM config WHERE clave='telefono_principal'") ?: '';
    $email   = dbFetchValue("SELECT valor FROM config WHERE clave='email_contacto'") ?: '';
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Cotización #<?= $cot['id'] ?> — Park Life Properties</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: #202944; font-size: 14px; background: #f5f5f5; }
            .page { max-width: 800px; margin: 20px auto; background: #fff; padding: 32px 24px; box-shadow: 0 2px 20px rgba(0,0,0,.08); border-radius: 8px; }
            .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; padding-bottom: 24px; border-bottom: 2px solid #202944; gap: 16px; flex-wrap: wrap; }
            .logo { height: 40px; }
            .header-right { text-align: right; font-size: 12px; color: #666; }
            .header-right strong { color: #202944; font-size: 16px; display: block; margin-bottom: 4px; }
            .client-box { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 24px; }
            .client-box h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #999; margin-bottom: 12px; }
            .client-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px; }
            .client-grid dt { color: #888; } .client-grid dd { font-weight: 600; }
            .prop-section { margin-bottom: 24px; }
            .prop-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
            .prop-sub { color: #888; font-size: 13px; }
            .prop-meta { display: flex; gap: 16px; margin-top: 8px; font-size: 12px; color: #666; flex-wrap: wrap; }
            .breakdown { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .breakdown th { text-align: left; padding: 10px 12px; background: #202944; color: #fff; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
            .breakdown td { padding: 10px 12px; border-bottom: 1px solid #eee; }
            .breakdown .text-right { text-align: right; }
            .breakdown .total-row td { border-top: 2px solid #202944; font-weight: 700; font-size: 15px; background: #f8f9fa; }
            .breakdown .discount td { color: #b45309; }
            .breakdown .subtotal td { font-weight: 600; border-top: 1px dashed #ccc; }
            .grand-total { text-align: center; margin: 24px 0; padding: 20px; background: #202944; border-radius: 8px; color: #fff; }
            .grand-total .label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: .7; }
            .grand-total .amount { font-size: 32px; font-weight: 800; margin-top: 4px; }
            .grand-total .sub { font-size: 13px; opacity: .6; margin-top: 4px; }
            .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #888; display: flex; justify-content: space-between; }
            .footer a { color: #202944; text-decoration: none; font-weight: 600; }
            .no-print { margin: 20px auto; max-width: 800px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; padding: 0 16px; }
            .no-print button, .no-print a { padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; border: none; white-space: nowrap; }
            .btn-print { background: #202944; color: #fff; }
            .btn-back { background: #e5e7eb; color: #374151; }
            @media print {
                .no-print { display: none; }
                body { background: #fff; margin: 0; padding: 0; }
                .page { box-shadow: none; margin: 0; padding: 20px 28px; max-width: 100%; border-radius: 0; }
                .header { margin-bottom: 16px; padding-bottom: 12px; }
                .client-box { padding: 12px; margin-bottom: 14px; }
                .prop-section { margin-bottom: 12px; }
                .breakdown td, .breakdown th { padding: 6px 10px; }
                .grand-total { margin: 14px 0; padding: 14px; }
                .grand-total .amount { font-size: 24px; }
                .footer { margin-top: 16px; padding-top: 12px; }
            }
        </style>
    </head>
    <body>
        <div class="no-print">
            <button class="btn-print" onclick="window.print()">🖨️ Imprimir / PDF</button>
            <?php if ($cot['cliente_telefono']):
                $waMsg = buildWaMsg($cot, $baseUrl);
                $waTel = preg_replace('/[^0-9]/', '', $cot['cliente_telefono']);
            ?>
            <a class="btn-print" style="background:#25d366" href="https://wa.me/<?= $waTel ?>?text=<?= urlencode($waMsg) ?>" target="_blank">💬 WhatsApp</a>
            <?php endif; ?>
            <?php if ($cot['cliente_email']): ?>
            <a class="btn-print" style="background:#4a90d9" href="mailto:<?= e($cot['cliente_email']) ?>?subject=<?= urlencode('Cotización Park Life ' . $cot['prop_nombre'] . ' #' . $cot['id']) ?>&body=<?= urlencode("Hola {$cot['cliente_nombre']},\n\nTe comparto tu cotización para Park Life {$cot['prop_nombre']}.\n\nUnidad: {$cot['hab_nombre']}\nDuración: {$cot['duracion_meses']} meses\nTotal mensual: $" . number_format((float)$cot['subtotal_mensual'], 2) . " MXN\nTotal contrato: $" . number_format((float)$cot['total_contrato'], 2) . " MXN\n\nQuedo a tus órdenes para cualquier duda.") ?>" target="_blank">✉️ Email</a>
            <?php endif; ?>
            <a class="btn-print" style="background:#b45309" href="cotizador.php?tab=nueva&edit=<?= $cot['id'] ?>">✏️ Editar</a>
            <a class="btn-back" href="cotizador.php?tab=historial">← Historial</a>
        </div>
        <div class="page">
            <div class="header">
                <img src="/<?= e($logoUrl) ?>" alt="Park Life Properties" class="logo">
                <div class="header-right">
                    <strong>Cotización #<?= $cot['id'] ?></strong>
                    <?= date('d/m/Y', strtotime($cot['created_at'])) ?><br>
                    Asesor: <?= e($cot['admin_email']) ?>
                </div>
            </div>

            <?php if ($cot['cliente_nombre']): ?>
            <div class="client-box">
                <h3>Datos del cliente</h3>
                <dl class="client-grid">
                    <dt>Nombre</dt><dd><?= e($cot['cliente_nombre']) ?></dd>
                    <?php if ($cot['cliente_empresa']): ?><dt>Empresa</dt><dd><?= e($cot['cliente_empresa']) ?></dd><?php endif; ?>
                    <?php if ($cot['cliente_email']): ?><dt>Email</dt><dd><?= e($cot['cliente_email']) ?></dd><?php endif; ?>
                    <?php if ($cot['cliente_telefono']): ?><dt>Teléfono</dt><dd><?= e($cot['cliente_telefono']) ?></dd><?php endif; ?>
                </dl>
            </div>
            <?php endif; ?>

            <div class="prop-section">
                <div class="prop-title">Park Life <?= e($cot['prop_nombre']) ?></div>
                <div class="prop-sub"><?= e($cot['hab_nombre']) ?><?= $cot['hab_codigo'] ? ' (' . e($cot['hab_codigo']) . ')' : '' ?></div>
                <div class="prop-meta">
                    <?php if ($cot['metros_cuadrados']): ?><span><?= (float)$cot['metros_cuadrados'] ?> m²</span><?php endif; ?>
                    <?php if ($cot['num_camas']): ?><span><?= (int)$cot['num_camas'] ?> recámara<?= (int)$cot['num_camas'] > 1 ? 's' : '' ?></span><?php endif; ?>
                    <?php if ($cot['num_banos']): ?><span><?= (float)$cot['num_banos'] ?> baño<?= (float)$cot['num_banos'] > 1 ? 's' : '' ?></span><?php endif; ?>
                    <span><strong><?= $cot['duracion_meses'] ?> mes<?= $cot['duracion_meses'] > 1 ? 'es' : '' ?></strong> (tarifa <?= $cot['tarifa_aplicada'] ?>)</span>
                </div>
            </div>

            <table class="breakdown">
                <thead><tr><th>Concepto</th><th class="text-right">Mensual</th></tr></thead>
                <tbody>
                    <tr>
                        <td>Renta mensual (tarifa <?= $cot['tarifa_aplicada'] ?>)</td>
                        <td class="text-right">$<?= number_format((float)$cot['precio_renta_base'], 2) ?></td>
                    </tr>
                    <?php if ((float)$cot['descuento_porcentaje'] > 0): ?>
                    <tr class="discount">
                        <td>Descuento (<?= $cot['descuento_porcentaje'] ?>%)</td>
                        <td class="text-right">-$<?= number_format((float)$cot['descuento_monto'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="subtotal">
                        <td><strong>Renta neta</strong></td>
                        <td class="text-right"><strong>$<?= number_format((float)$cot['precio_renta_neta'], 2) ?></strong></td>
                    </tr>
                    <?php if ((float)$cot['monto_mantenimiento'] > 0): ?>
                    <tr><td>Mantenimiento</td><td class="text-right">$<?= number_format((float)$cot['monto_mantenimiento'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($cot['inc_servicios'] && (float)$cot['monto_servicios'] > 0): ?>
                    <tr><td>Servicios (Full Service)</td><td class="text-right">$<?= number_format((float)$cot['monto_servicios'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($cot['inc_amueblado'] && (float)$cot['monto_amueblado'] > 0): ?>
                    <tr><td>Amueblado</td><td class="text-right">$<?= number_format((float)$cot['monto_amueblado'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($cot['inc_parking'] && (float)$cot['monto_parking'] > 0): ?>
                    <tr><td>Estacionamiento</td><td class="text-right">$<?= number_format((float)$cot['monto_parking'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($cot['inc_mascota'] && (float)$cot['monto_mascota'] > 0): ?>
                    <tr><td>Mascota</td><td class="text-right">$<?= number_format((float)$cot['monto_mascota'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)($cot['monto_iva'] ?? 0) > 0): ?>
                    <tr><td>IVA (<?= (float)($cot['iva_porcentaje'] ?? 16) ?>%) <span style="font-size:10px;color:#888">sobre servicios<?= !empty($cot['iva_sobre_renta']) ? ' y renta' : '' ?></span></td><td class="text-right">$<?= number_format((float)$cot['monto_iva'], 2) ?></td></tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td>TOTAL MENSUAL</td>
                        <td class="text-right">$<?= number_format((float)$cot['subtotal_mensual'], 2) ?> MXN</td>
                    </tr>
                </tbody>
            </table>

            <div class="grand-total">
                <div class="label">Total del contrato (<?= $cot['duracion_meses'] ?> meses)</div>
                <div class="amount">$<?= number_format((float)$cot['total_contrato'], 2) ?> MXN</div>
                <div class="sub">Precios expresados en Pesos Mexicanos. Esta cotización es informativa y no constituye un contrato.</div>
            </div>

            <?php if ($cot['notas']): ?>
            <div style="background:#fffbeb; padding:12px 16px; border-radius:8px; font-size:13px; margin-bottom:20px;">
                <strong>Notas:</strong> <?= nl2br(e($cot['notas'])) ?>
            </div>
            <?php endif; ?>

            <div class="footer">
                <div><a href="https://parklife.mx">www.parklife.mx</a><br><?= e($tel) ?> · <?= e($email) ?></div>
                <div style="text-align:right;">Cotización generada el <?= date('d/m/Y H:i', strtotime($cot['created_at'])) ?></div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// POST HANDLERS
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('cotizador.php', 'error', 'Token inválido.');
    }
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'guardar' || $postAction === 'actualizar') {
        $data = [
            'admin_id'              => $adminId,
            'propiedad_id'          => (int)$_POST['propiedad_id'],
            'habitacion_id'         => (int)$_POST['habitacion_id'],
            'duracion_meses'        => (int)$_POST['duracion_meses'],
            'tarifa_aplicada'       => $_POST['tarifa_aplicada'] ?? '12m',
            'cliente_nombre'        => sanitizeStr($_POST['cliente_nombre'] ?? ''),
            'cliente_email'         => sanitizeStr($_POST['cliente_email'] ?? ''),
            'cliente_telefono'      => sanitizeStr($_POST['cliente_telefono'] ?? ''),
            'cliente_empresa'       => sanitizeStr($_POST['cliente_empresa'] ?? ''),
            'precio_renta_base'     => round((float)$_POST['precio_renta_base'], 2),
            'descuento_porcentaje'  => round((float)$_POST['descuento_porcentaje'], 2),
            'descuento_monto'       => round((float)$_POST['descuento_monto'], 2),
            'precio_renta_neta'     => round((float)$_POST['precio_renta_neta'], 2),
            'inc_mantenimiento'     => 1,
            'inc_servicios'         => isset($_POST['inc_servicios']) ? 1 : 0,
            'inc_amueblado'         => isset($_POST['inc_amueblado']) ? 1 : 0,
            'inc_parking'           => isset($_POST['inc_parking']) ? 1 : 0,
            'inc_mascota'           => isset($_POST['inc_mascota']) ? 1 : 0,
            'monto_mantenimiento'   => round((float)$_POST['monto_mantenimiento'], 2),
            'monto_servicios'       => round((float)$_POST['monto_servicios'], 2),
            'monto_amueblado'       => round((float)$_POST['monto_amueblado'], 2),
            'monto_parking'         => round((float)$_POST['monto_parking'], 2),
            'monto_mascota'         => round((float)$_POST['monto_mascota'], 2),
            'iva_porcentaje'        => 16.00,
            'iva_sobre_renta'       => isset($_POST['iva_sobre_renta']) ? 1 : 0,
            'monto_iva'             => round((float)$_POST['monto_iva'], 2),
            'subtotal_mensual'      => round((float)$_POST['subtotal_mensual'], 2),
            'total_contrato'        => round((float)$_POST['total_contrato'], 2),
            'notas'                 => sanitizeStr($_POST['notas'] ?? ''),
        ];

        if ($postAction === 'actualizar' && !empty($_POST['cot_id'])) {
            $cotId = (int)$_POST['cot_id'];
            $sets = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
            dbExecute("UPDATE cotizaciones SET $sets WHERE id=?", [...array_values($data), $cotId]);
            adminRedirect("cotizador.php?tab=historial", 'success', "Cotización #{$cotId} actualizada.");
        } else {
            $data['estatus'] = 'borrador';
            $data['token_publico'] = bin2hex(random_bytes(16));
            $cols = implode(',', array_keys($data));
            $phs  = implode(',', array_fill(0, count($data), '?'));
            $newId = dbInsert("INSERT INTO cotizaciones ($cols) VALUES ($phs)", array_values($data));
            adminRedirect("cotizador.php?tab=historial", 'success', "Cotización #{$newId} guardada.");
        }
    }

    if ($postAction === 'cambiar_estatus') {
        $cotId  = (int)$_POST['cot_id'];
        $nuevo  = $_POST['nuevo_estatus'] ?? '';
        $validos = ['borrador','enviada','aceptada','rechazada','expirada'];
        if (in_array($nuevo, $validos)) {
            dbExecute("UPDATE cotizaciones SET estatus = ? WHERE id = ?", [$nuevo, $cotId]);
        }
        adminRedirect("cotizador.php?tab=historial", 'success', "Estatus actualizado.");
    }

    if ($postAction === 'eliminar') {
        $cotId = (int)$_POST['cot_id'];
        $where = $adminRole === 'superadmin' ? '' : " AND admin_id = $adminId";
        dbExecute("DELETE FROM cotizaciones WHERE id = ? $where", [$cotId]);
        adminRedirect("cotizador.php?tab=historial", 'success', "Cotización eliminada.");
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// DATA: Historial
// ═════════════════════════════════════════════════════════════════════════════
$cotizaciones = [];
if ($tab === 'historial') {
    $where = $adminRole === 'superadmin' ? '' : "WHERE c.admin_id = $adminId";
    $cotizaciones = dbFetchAll(
        "SELECT c.*, c.token_publico, p.nombre AS prop_nombre, h.nombre AS hab_nombre, a.email AS admin_email
         FROM cotizaciones c
         JOIN propiedades p ON p.id = c.propiedad_id
         JOIN habitaciones h ON h.id = c.habitacion_id
         JOIN admin_usuarios a ON a.id = c.admin_id
         $where ORDER BY c.created_at DESC LIMIT 200"
    );
}

// Edit mode
$editCot = null;
if ($tab === 'nueva' && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $where  = $adminRole === 'superadmin' ? '' : " AND c.admin_id = $adminId";
    $editCot = dbFetchOne("SELECT c.* FROM cotizaciones c WHERE c.id = ? $where", [$editId]);
}

// ═════════════════════════════════════════════════════════════════════════════
// RENDER
// ═════════════════════════════════════════════════════════════════════════════
adminLayoutOpen('Cotizador de Leasing');
?>

<style>
/* Toggle switch */
.toggle-track { position: relative; width: 44px; height: 24px; background: #d1d5db; border-radius: 12px; transition: background .2s; cursor: pointer; flex-shrink: 0; }
.toggle-track.active { background: #202944; }
.toggle-track.locked { background: #202944; opacity: .6; cursor: not-allowed; }
.toggle-dot { position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; background: #fff; border-radius: 50%; transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.toggle-track.active .toggle-dot, .toggle-track.locked .toggle-dot { transform: translateX(20px); }
</style>

<!-- Tabs -->
<div class="flex gap-1 mb-6 border-b border-gray-200">
    <?php
    $tabsDef = ['nueva' => ['Nueva cotización','calculator'], 'historial' => ['Historial','history']];
    if ($editCot) $tabsDef['nueva'][0] = 'Editar #' . $editCot['id'];
    foreach ($tabsDef as $key => [$label, $icon]): $active = $tab === $key; ?>
    <a href="?tab=<?= $key ?>"
       class="flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-colors <?= $active ? 'border-pk text-pk' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
        <i data-lucide="<?= $icon ?>" class="w-4 h-4"></i><?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<?php
// ═════════════════════════════════════════════════════════════════════════════
// TAB: NUEVA / EDITAR
// ═════════════════════════════════════════════════════════════════════════════
if ($tab === 'nueva'): ?>

<form method="post" id="cotForm" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="<?= $editCot ? 'actualizar' : 'guardar' ?>">
    <?php if ($editCot): ?><input type="hidden" name="cot_id" value="<?= $editCot['id'] ?>"><?php endif; ?>

    <div class="grid lg:grid-cols-3 gap-6">

        <!-- ═══ COLUMNA 1: Selección + Cliente ═══ -->
        <div class="space-y-5">

            <div class="card p-5">
                <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i data-lucide="building-2" class="w-4 h-4 text-pk"></i> Selección de unidad
                </h3>
                <div class="space-y-4">
                    <div>
                        <label class="form-label text-xs">Propiedad *</label>
                        <select name="propiedad_id" id="sel-prop" class="form-select" required>
                            <option value="">— Seleccionar —</option>
                            <?php foreach ($propiedades as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($editCot && (int)$editCot['propiedad_id'] === (int)$p['id']) ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label text-xs">Habitación *</label>
                        <select name="habitacion_id" id="sel-hab" class="form-select" required disabled>
                            <option value="">— Primero selecciona propiedad —</option>
                        </select>
                        <div id="hab-info" class="hidden mt-2 p-3 bg-gray-50 rounded-lg text-xs text-gray-600"></div>
                    </div>
                    <div>
                        <label class="form-label text-xs">Duración del contrato *</label>
                        <div class="flex items-center gap-2">
                            <?php foreach ([12, 6, 3] as $d): ?>
                            <button type="button" data-dur="<?= $d ?>"
                                class="dur-btn flex-1 px-3 py-2.5 text-sm font-semibold rounded-xl border-2 border-gray-200 text-gray-500 hover:border-pk hover:text-pk transition-all <?= ($editCot && (int)$editCot['duracion_meses'] === $d) ? 'border-pk text-pk bg-pk/5' : '' ?>"><?= $d ?>m</button>
                            <?php endforeach; ?>
                            <div class="relative flex-1">
                                <input type="number" id="dur-custom" min="1" max="36" placeholder="Otro"
                                    class="form-input text-sm text-center font-semibold w-full"
                                    value="<?= ($editCot && !in_array((int)$editCot['duracion_meses'], [12,6,3])) ? $editCot['duracion_meses'] : '' ?>">
                                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-400">m</span>
                            </div>
                        </div>
                        <input type="hidden" name="duracion_meses" id="inp-dur" value="<?= $editCot ? $editCot['duracion_meses'] : '' ?>">
                        <input type="hidden" name="tarifa_aplicada" id="inp-tarifa" value="<?= $editCot ? $editCot['tarifa_aplicada'] : '' ?>">
                        <div id="tarifa-label" class="text-xs text-pk font-medium mt-1 hidden"></div>
                    </div>
                </div>
            </div>

            <div class="card p-5">
                <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i data-lucide="user" class="w-4 h-4 text-pk"></i> Cliente
                    <span class="text-xs text-gray-400 font-normal">(opcional para borrador)</span>
                </h3>
                <div class="space-y-3">
                    <div><label class="form-label text-xs">Nombre</label><input type="text" name="cliente_nombre" class="form-input text-sm" value="<?= e($editCot['cliente_nombre'] ?? '') ?>"></div>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div><label class="form-label text-xs">Email</label><input type="email" name="cliente_email" class="form-input text-sm" value="<?= e($editCot['cliente_email'] ?? '') ?>"></div>
                        <div><label class="form-label text-xs">Teléfono</label><input type="text" name="cliente_telefono" class="form-input text-sm" value="<?= e($editCot['cliente_telefono'] ?? '') ?>"></div>
                    </div>
                    <div><label class="form-label text-xs">Empresa</label><input type="text" name="cliente_empresa" class="form-input text-sm" value="<?= e($editCot['cliente_empresa'] ?? '') ?>"></div>
                </div>
            </div>
        </div>

        <!-- ═══ COLUMNA 2: Renta + Servicios + Descuento ═══ -->
        <div class="space-y-5">

            <!-- Renta -->
            <div class="card p-5 border-l-4 border-l-pk">
                <h3 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <i data-lucide="banknote" class="w-4 h-4 text-pk"></i> Renta mensual
                </h3>
                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-black text-pk" id="display-renta-base">$0.00</span>
                    <span class="text-sm text-gray-400" id="display-tarifa-tag">—</span>
                </div>
            </div>

            <!-- Servicios con toggles -->
            <div class="card p-5">
                <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i data-lucide="sliders-horizontal" class="w-4 h-4 text-pk"></i> Servicios incluidos
                </h3>

                <!-- Mantenimiento (siempre activo) -->
                <div class="flex items-center justify-between p-3 rounded-xl border border-gray-100 bg-gray-50 mb-2">
                    <div class="flex items-center gap-3">
                        <div class="toggle-track locked"><div class="toggle-dot"></div></div>
                        <input type="hidden" name="inc_mantenimiento" value="1">
                        <div>
                            <span class="text-sm font-medium text-gray-800">Mantenimiento</span>
                            <div class="text-xs text-gray-400">Siempre incluido</div>
                        </div>
                    </div>
                    <span class="text-sm font-semibold text-gray-800 svc-amount" data-svc="mantenimiento">$0.00</span>
                </div>

                <!-- Servicios -->
                <div class="flex items-center justify-between p-3 rounded-xl border border-gray-100 hover:bg-gray-50 mb-2 cursor-pointer svc-row" data-svc="servicios">
                    <div class="flex items-center gap-3">
                        <div class="toggle-track" id="toggle-servicios"><div class="toggle-dot"></div></div>
                        <input type="hidden" name="inc_servicios" id="inp-servicios" value="<?= ($editCot['inc_servicios'] ?? 0) ? '1' : '0' ?>">
                        <div>
                            <span class="text-sm font-medium text-gray-800">Servicios (Full Service)</span>
                            <div class="text-xs text-gray-400">Luz, agua, gas, internet</div>
                        </div>
                    </div>
                    <span class="text-sm font-semibold text-gray-800 svc-amount" data-svc="servicios">$0.00</span>
                </div>

                <!-- Amueblado -->
                <div class="flex items-center justify-between p-3 rounded-xl border border-gray-100 hover:bg-gray-50 mb-2 cursor-pointer svc-row" data-svc="amueblado">
                    <div class="flex items-center gap-3">
                        <div class="toggle-track" id="toggle-amueblado"><div class="toggle-dot"></div></div>
                        <input type="hidden" name="inc_amueblado" id="inp-amueblado" value="<?= ($editCot['inc_amueblado'] ?? 0) ? '1' : '0' ?>">
                        <div>
                            <span class="text-sm font-medium text-gray-800">Amueblado</span>
                            <div class="text-xs text-gray-400">Mobiliario completo</div>
                        </div>
                    </div>
                    <span class="text-sm font-semibold text-gray-800 svc-amount" data-svc="amueblado">$0.00</span>
                </div>

                <!-- Parking -->
                <div class="flex items-center justify-between p-3 rounded-xl border border-gray-100 hover:bg-gray-50 mb-2 cursor-pointer svc-row" data-svc="parking">
                    <div class="flex items-center gap-3">
                        <div class="toggle-track" id="toggle-parking"><div class="toggle-dot"></div></div>
                        <input type="hidden" name="inc_parking" id="inp-parking" value="<?= ($editCot['inc_parking'] ?? 0) ? '1' : '0' ?>">
                        <div>
                            <span class="text-sm font-medium text-gray-800">Estacionamiento</span>
                            <div class="text-xs text-gray-400">Cajón adicional</div>
                        </div>
                    </div>
                    <span class="text-sm font-semibold text-gray-800 svc-amount" data-svc="parking">$0.00</span>
                </div>

                <!-- Mascota -->
                <div class="flex items-center justify-between p-3 rounded-xl border border-gray-100 hover:bg-gray-50 mb-2 cursor-pointer svc-row" data-svc="mascota">
                    <div class="flex items-center gap-3">
                        <div class="toggle-track" id="toggle-mascota"><div class="toggle-dot"></div></div>
                        <input type="hidden" name="inc_mascota" id="inp-mascota" value="<?= ($editCot['inc_mascota'] ?? 0) ? '1' : '0' ?>">
                        <div>
                            <span class="text-sm font-medium text-gray-800">Mascota</span>
                            <div class="text-xs text-gray-400">Cargo mensual</div>
                        </div>
                    </div>
                    <span class="text-sm font-semibold text-gray-800 svc-amount" data-svc="mascota">$0.00</span>
                </div>
            </div>

            <!-- Descuento -->
            <div class="card p-5 border-2 border-amber-100 bg-amber-50/30">
                <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                    <i data-lucide="tag" class="w-4 h-4 text-amber-600"></i> Descuento sobre renta
                </h3>
                <p class="text-xs text-gray-500 mb-3">Solo aplica sobre la <strong>renta mensual</strong>. No afecta mantenimiento ni servicios.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <input type="number" step="0.5" min="0" max="50" id="inp-desc" class="form-input text-sm w-20 sm:w-24 text-center font-bold text-lg" value="<?= $editCot ? $editCot['descuento_porcentaje'] : '0' ?>">
                    <span class="text-gray-500 font-medium">%</span>
                    <div class="flex gap-1 ml-auto flex-wrap">
                        <?php foreach ([5, 10, 15, 20] as $d): ?>
                        <button type="button" onclick="setDesc(<?= $d ?>)" class="px-2 py-1 text-xs rounded-lg bg-amber-100 text-amber-700 hover:bg-amber-200 transition-colors"><?= $d ?>%</button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mt-3 flex justify-between text-sm">
                    <span class="text-gray-500">Ahorro mensual:</span>
                    <span id="desc-monto" class="font-bold text-amber-700">-$0.00</span>
                </div>
            </div>

            <!-- IVA -->
            <div class="card p-5 border-2 border-blue-100 bg-blue-50/30">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="font-bold text-gray-800 flex items-center gap-2">
                        <i data-lucide="receipt" class="w-4 h-4 text-blue-600"></i> IVA (16%)
                    </h3>
                    <span class="text-xs text-blue-600 font-semibold" id="iva-badge">Sobre servicios</span>
                </div>
                <p class="text-xs text-gray-500 mb-3">Se aplica sobre mantenimiento y servicios activos. La renta actualmente <strong>no genera IVA</strong>.</p>
                <div class="flex items-center justify-between p-3 rounded-xl border border-blue-100 bg-white">
                    <div class="flex items-center gap-3">
                        <div class="toggle-track locked"><div class="toggle-dot"></div></div>
                        <span class="text-sm font-medium text-gray-800">IVA sobre servicios</span>
                    </div>
                    <span class="text-sm font-semibold text-blue-700" id="display-iva-monto">$0.00</span>
                </div>
                <!-- Futuro: IVA sobre renta -->
                <div class="flex items-center justify-between p-3 rounded-xl border border-gray-100 mt-2 cursor-pointer svc-row-iva" id="iva-renta-row">
                    <div class="flex items-center gap-3">
                        <div class="toggle-track" id="toggle-iva-renta"><div class="toggle-dot"></div></div>
                        <div>
                            <span class="text-sm font-medium text-gray-800">IVA sobre renta</span>
                            <div class="text-xs text-gray-400">Cuando aplique</div>
                        </div>
                    </div>
                    <span class="text-sm font-semibold text-gray-400" id="display-iva-renta-monto">$0.00</span>
                </div>
            </div>

            <div class="card p-5">
                <label class="form-label text-xs">Notas internas</label>
                <textarea name="notas" class="form-input text-sm" rows="2" placeholder="Ej: Cliente necesita mudanza antes del 15..."><?= e($editCot['notas'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- ═══ COLUMNA 3: Resumen ═══ -->
        <div>
            <div class="card p-5 lg:sticky lg:top-4 border-2 border-pk/20">
                <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i data-lucide="receipt" class="w-4 h-4 text-pk"></i> Resumen
                </h3>

                <div id="resumen-empty" class="py-8 text-center text-gray-400">
                    <i data-lucide="calculator" class="w-10 h-10 mx-auto mb-2 opacity-40"></i>
                    <p class="text-sm">Selecciona propiedad, habitación y duración.</p>
                </div>

                <div id="resumen-content" class="hidden space-y-4">
                    <div class="pb-3 border-b border-gray-100">
                        <div class="text-sm font-bold text-gray-800" id="res-prop">—</div>
                        <div class="text-xs text-gray-500" id="res-hab">—</div>
                        <div class="text-xs text-pk font-semibold mt-1" id="res-dur">—</div>
                    </div>

                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Renta base</span>
                            <span class="font-semibold" id="res-renta-base">$0.00</span>
                        </div>
                        <div class="flex justify-between text-amber-700" id="res-desc-row" style="display:none">
                            <span>Descuento (<span id="res-desc-pct">0</span>%)</span>
                            <span class="font-semibold" id="res-desc-monto">-$0.00</span>
                        </div>
                        <div class="flex justify-between font-bold border-t border-dashed border-gray-200 pt-2">
                            <span class="text-gray-800">Renta neta</span>
                            <span class="text-pk" id="res-renta-neta">$0.00</span>
                        </div>
                    </div>

                    <div class="space-y-1.5 text-sm" id="res-extras"></div>

                    <div id="res-iva-row" class="flex justify-between text-sm pt-1 border-t border-dashed border-gray-200" style="display:none">
                        <span class="text-gray-600">IVA (<span id="res-iva-pct">16</span>%) <span class="text-xs text-gray-400" id="res-iva-sobre">sobre servicios</span></span>
                        <span class="font-semibold text-gray-700" id="res-iva-monto">$0.00</span>
                    </div>

                    <div class="bg-pk/5 rounded-xl p-4 text-center">
                        <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Total mensual</div>
                        <div class="text-3xl font-black text-pk" id="res-total-mensual">$0.00</div>
                        <div class="text-xs text-gray-400 mt-1" id="res-iva-inc" style="display:none">IVA incluido</div>
                    </div>

                    <div class="flex justify-between text-sm bg-gray-50 rounded-xl p-3">
                        <span class="text-gray-600">Total contrato (<span id="res-dur2">0</span>m)</span>
                        <span class="font-bold text-gray-800 text-lg" id="res-total-contrato">$0.00</span>
                    </div>

                    <!-- Hidden POST -->
                    <input type="hidden" name="precio_renta_base" id="h-renta-base">
                    <input type="hidden" name="descuento_porcentaje" id="h-desc-pct">
                    <input type="hidden" name="descuento_monto" id="h-desc-monto">
                    <input type="hidden" name="precio_renta_neta" id="h-renta-neta">
                    <input type="hidden" name="monto_mantenimiento" id="h-monto-mant">
                    <input type="hidden" name="monto_servicios" id="h-monto-serv">
                    <input type="hidden" name="monto_amueblado" id="h-monto-amue">
                    <input type="hidden" name="monto_parking" id="h-monto-park">
                    <input type="hidden" name="monto_mascota" id="h-monto-masc">
                    <input type="hidden" name="iva_sobre_renta" id="h-iva-sobre-renta" value="0">
                    <input type="hidden" name="monto_iva" id="h-monto-iva">
                    <input type="hidden" name="subtotal_mensual" id="h-subtotal">
                    <input type="hidden" name="total_contrato" id="h-total-contrato">

                    <div class="space-y-2 pt-2">
                        <button type="submit" class="btn-primary w-full">
                            <i data-lucide="save" class="w-4 h-4"></i>
                            <?= $editCot ? 'Actualizar cotización' : 'Guardar cotización' ?>
                        </button>
                        <p class="text-xs text-center text-gray-400">Después de guardar podrás enviar por email, WhatsApp o imprimir PDF.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
// ═════════════════════════════════════════════════════════════════════════════
// TAB: HISTORIAL
// ═════════════════════════════════════════════════════════════════════════════
elseif ($tab === 'historial'): ?>

<?php if ($adminRole === 'superadmin'): ?>
<div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-xl text-sm text-blue-800 flex items-center gap-2">
    <i data-lucide="shield" class="w-4 h-4"></i>
    Vista administrador: ves las cotizaciones de <strong>todos los agentes</strong>.
</div>
<?php endif; ?>

<?php if (empty($cotizaciones)): ?>
    <div class="card p-12 text-center text-gray-400">
        <i data-lucide="calculator" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
        <p class="text-lg font-medium">Sin cotizaciones</p>
        <p class="text-sm mt-1">Crea la primera desde "Nueva cotización".</p>
    </div>
<?php else: ?>
    <div class="card p-0 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-gray-100">
                <tr>
                    <th class="table-th hidden sm:table-cell">#</th>
                    <th class="table-th hidden md:table-cell">Fecha</th>
                    <?php if ($adminRole === 'superadmin'): ?><th class="table-th hidden lg:table-cell">Agente</th><?php endif; ?>
                    <th class="table-th">Cliente</th>
                    <th class="table-th">Propiedad</th>
                    <th class="table-th text-center hidden sm:table-cell">Duración</th>
                    <th class="table-th text-right hidden md:table-cell">Renta neta</th>
                    <th class="table-th text-right">Total</th>
                    <th class="table-th text-center">Estatus</th>
                    <th class="table-th"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($cotizaciones as $c):
                    $statusCls = match($c['estatus']) {
                        'borrador'  => 'bg-gray-100 text-gray-600',
                        'enviada'   => 'bg-blue-100 text-blue-700',
                        'aceptada'  => 'bg-green-100 text-green-700',
                        'rechazada' => 'bg-red-100 text-red-700',
                        'expirada'  => 'bg-yellow-100 text-yellow-700',
                        default     => 'bg-gray-100 text-gray-600',
                    };
                ?>
                <tr class="hover:bg-slate-50">
                    <td class="table-td text-gray-400 hidden sm:table-cell"><?= $c['id'] ?></td>
                    <td class="table-td whitespace-nowrap text-gray-500 hidden md:table-cell"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></td>
                    <?php if ($adminRole === 'superadmin'): ?>
                    <td class="table-td text-xs text-gray-500 hidden lg:table-cell"><?= e($c['admin_email']) ?></td>
                    <?php endif; ?>
                    <td class="table-td">
                        <div class="font-medium text-gray-800"><?= e($c['cliente_nombre'] ?: '—') ?></div>
                        <?php if ($c['cliente_empresa']): ?><div class="text-xs text-gray-400"><?= e($c['cliente_empresa']) ?></div><?php endif; ?>
                    </td>
                    <td class="table-td">
                        <div class="text-gray-800"><?= e($c['prop_nombre']) ?></div>
                        <div class="text-xs text-gray-400"><?= e($c['hab_nombre']) ?></div>
                    </td>
                    <td class="table-td text-center hidden sm:table-cell"><?= $c['duracion_meses'] ?>m<br><span class="text-xs text-gray-400">(<?= $c['tarifa_aplicada'] ?>)</span></td>
                    <td class="table-td text-right hidden md:table-cell">
                        $<?= number_format((float)$c['precio_renta_neta'], 2) ?>
                        <?php if ((float)$c['descuento_porcentaje'] > 0): ?>
                        <div class="text-xs text-amber-600">-<?= $c['descuento_porcentaje'] ?>%</div>
                        <?php endif; ?>
                    </td>
                    <td class="table-td text-right font-semibold">$<?= number_format((float)$c['subtotal_mensual'], 2) ?></td>
                    <td class="table-td text-center">
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $statusCls ?>"><?= $c['estatus'] ?></span>
                    </td>
                    <td class="table-td">
                        <div class="flex items-center gap-1 justify-end">
                            <a href="?tab=ver&id=<?= $c['id'] ?>" target="_blank"
                               class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-pk" title="Ver / PDF">
                                <i data-lucide="file-text" class="w-4 h-4"></i>
                            </a>
                            <a href="?tab=nueva&edit=<?= $c['id'] ?>"
                               class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-pk" title="Editar">
                                <i data-lucide="pencil" class="w-4 h-4"></i>
                            </a>
                            <button class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-pk cot-menu-trigger"
                                data-cot-id="<?= $c['id'] ?>"
                                data-cot-estatus="<?= $c['estatus'] ?>"
                                data-cot-tel="<?= e(preg_replace('/[^0-9]/', '', $c['cliente_telefono'] ?? '')) ?>"
                                data-cot-email="<?= e($c['cliente_email'] ?? '') ?>"
                                data-cot-nombre="<?= e($c['cliente_nombre'] ?? '') ?>"
                                data-cot-prop="<?= e($c['prop_nombre']) ?>"
                                data-cot-hab="<?= e($c['hab_nombre']) ?>"
                                data-cot-dur="<?= $c['duracion_meses'] ?>"
                                data-cot-tarifa="<?= $c['tarifa_aplicada'] ?>"
                                data-cot-renta-base="<?= number_format((float)$c['precio_renta_base'], 2) ?>"
                                data-cot-desc-pct="<?= $c['descuento_porcentaje'] ?>"
                                data-cot-desc-monto="<?= number_format((float)$c['descuento_monto'], 2) ?>"
                                data-cot-renta-neta="<?= number_format((float)$c['precio_renta_neta'], 2) ?>"
                                data-cot-mant="<?= number_format((float)$c['monto_mantenimiento'], 2) ?>"
                                data-cot-serv="<?= $c['inc_servicios'] ? number_format((float)$c['monto_servicios'], 2) : '0' ?>"
                                data-cot-amue="<?= $c['inc_amueblado'] ? number_format((float)$c['monto_amueblado'], 2) : '0' ?>"
                                data-cot-park="<?= $c['inc_parking'] ? number_format((float)$c['monto_parking'], 2) : '0' ?>"
                                data-cot-masc="<?= $c['inc_mascota'] ? number_format((float)$c['monto_mascota'], 2) : '0' ?>"
                                data-cot-mensual="<?= number_format((float)$c['subtotal_mensual'], 2) ?>"
                                data-cot-contrato="<?= number_format((float)$c['total_contrato'], 2) ?>"
                                data-cot-token="<?= e($c['token_publico'] ?? '') ?>"
                                data-cot-iva="<?= number_format((float)($c['monto_iva'] ?? 0), 2) ?>"
                                data-cot-iva-pct="<?= (float)($c['iva_porcentaje'] ?? 16) ?>">
                                <i data-lucide="more-vertical" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php endif; ?>

<!-- Panel flotante global para acciones de cotización -->
<div id="cotPanel" class="hidden" style="position:fixed; z-index:50;">
    <div id="cotPanelBg" style="position:fixed;inset:0;z-index:49;"></div>
    <div id="cotPanelContent" class="bg-white shadow-2xl rounded-xl border border-gray-100 w-56" style="position:fixed;z-index:50;">
        <!-- Header -->
        <div class="px-4 py-3 border-b border-gray-100">
            <div class="text-xs font-bold text-gray-800" id="cp-title">Cotización #—</div>
            <div class="text-[11px] text-gray-400" id="cp-sub"></div>
        </div>
        <!-- Enviar -->
        <div class="px-3 pt-2 pb-1"><span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Enviar</span></div>
        <a id="cp-wa" href="#" target="_blank" class="flex items-center gap-2 px-3 py-2 text-xs text-gray-600 hover:bg-green-50 hover:text-green-700 rounded-lg mx-1">
            <i data-lucide="message-circle" class="w-3.5 h-3.5"></i> <span id="cp-wa-label">WhatsApp</span>
        </a>
        <a id="cp-email" href="#" class="flex items-center gap-2 px-3 py-2 text-xs text-gray-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg mx-1">
            <i data-lucide="mail" class="w-3.5 h-3.5"></i> <span id="cp-email-label">Email</span>
        </a>
        <a id="cp-pdf" href="#" target="_blank" class="flex items-center gap-2 px-3 py-2 text-xs text-gray-600 hover:bg-gray-50 rounded-lg mx-1">
            <i data-lucide="printer" class="w-3.5 h-3.5"></i> Imprimir / PDF
        </a>
        <!-- Estatus -->
        <div class="border-t border-gray-100 mt-1 pt-1">
            <div class="px-3 pt-2 pb-1"><span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Cambiar estatus</span></div>
            <div class="flex flex-wrap gap-1 px-3 pb-2" id="cp-status"></div>
        </div>
        <!-- Eliminar -->
        <div class="border-t border-gray-100">
            <form method="post" class="m-0" id="cp-delete-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="cot_id" id="cp-delete-id" value="">
                <button type="submit" class="flex items-center gap-2 w-full px-3 py-2.5 text-xs text-red-500 hover:bg-red-50 rounded-b-xl">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Eliminar
                </button>
            </form>
        </div>
    </div>
</div>
<!-- JS -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<script>
const S = { habitaciones: [], hab: null, dur: 0, descPct: 0, ivaSobreRenta: false, toggles: { servicios:false, amueblado:false, parking:false, mascota:false } };
const fmt = n => '$' + Number(n).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

// ── Toggle switches ──
document.querySelectorAll('.svc-row').forEach(row => {
    const svc = row.dataset.svc;
    const track = document.getElementById('toggle-' + svc);
    const inp   = document.getElementById('inp-' + svc);
    if (!track || !inp) return;

    // Init from edit
    if (inp.value === '1') { track.classList.add('active'); S.toggles[svc] = true; }

    row.addEventListener('click', () => {
        const on = !S.toggles[svc];
        S.toggles[svc] = on;
        track.classList.toggle('active', on);
        inp.value = on ? '1' : '0';
        recalcular();
    });
});

// ── IVA sobre renta toggle ──
(function(){
    const row = document.getElementById('iva-renta-row');
    const track = document.getElementById('toggle-iva-renta');
    if (!row || !track) return;
    <?php if ($editCot && !empty($editCot['iva_sobre_renta'])): ?>
    S.ivaSobreRenta = true; track.classList.add('active');
    <?php endif; ?>
    row.addEventListener('click', () => {
        S.ivaSobreRenta = !S.ivaSobreRenta;
        track.classList.toggle('active', S.ivaSobreRenta);
        recalcular();
    });
})();

// ── Tarifa por rango de duración ──
function getTarifa(meses) {
    if (meses >= 12) return '12m';
    if (meses >= 6)  return '6m';
    return '1m';
}
function getTarifaLabel(tag) {
    return {
        '12m': 'Tarifa 12 meses (contratos de 12+ meses)',
        '6m':  'Tarifa 6 meses (contratos de 6–11 meses)',
        '1m':  'Tarifa 1 mes (contratos de 1–5 meses)'
    }[tag] || '';
}
function getRentaByTarifa(h, tag) {
    return parseFloat({ '12m': h.precio_mes_12, '6m': h.precio_mes_6, '1m': h.precio_mes_1 }[tag]) || 0;
}

// ── Cargar habitaciones ──
document.getElementById('sel-prop')?.addEventListener('change', async function() {
    const pid = this.value, sel = document.getElementById('sel-hab');
    sel.innerHTML = '<option value="">Cargando...</option>'; sel.disabled = true;
    document.getElementById('hab-info').classList.add('hidden');
    S.hab = null; recalcular();
    if (!pid) { sel.innerHTML = '<option value="">— Primero selecciona propiedad —</option>'; return; }
    try {
        const res = await fetch('cotizador.php?ajax=habitaciones&propiedad_id=' + pid);
        const data = await res.json();
        S.habitaciones = data.habitaciones || [];
        sel.innerHTML = '<option value="">— Seleccionar —</option>';
        S.habitaciones.forEach(h => {
            const opt = document.createElement('option');
            opt.value = h.id;
            opt.textContent = h.nombre + (h.codigo ? ` (${h.codigo})` : '');
            sel.appendChild(opt);
        });
        sel.disabled = false;
        // Si estamos editando, seleccionar la habitación
        const editHabId = '<?= $editCot ? $editCot['habitacion_id'] : '' ?>';
        if (editHabId) { sel.value = editHabId; sel.dispatchEvent(new Event('change')); }
    } catch(e) { sel.innerHTML = '<option value="">Error</option>'; }
});

// ── Seleccionar habitación ──
document.getElementById('sel-hab')?.addEventListener('change', function() {
    const hid = parseInt(this.value);
    S.hab = S.habitaciones.find(h => parseInt(h.id) === hid) || null;
    const info = document.getElementById('hab-info');
    if (S.hab) {
        const h = S.hab;
        info.innerHTML = `<div class="flex gap-4">${h.metros_cuadrados ? `<span>${parseFloat(h.metros_cuadrados)}m²</span>` : ''}${h.num_camas ? `<span>${h.num_camas} cama${h.num_camas>1?'s':''}</span>` : ''}${h.num_banos ? `<span>${parseFloat(h.num_banos)} baño${parseFloat(h.num_banos)>1?'s':''}</span>` : ''}${parseInt(h.tiene_parking) ? '<span>🅿️</span>' : ''}</div>`;
        info.classList.remove('hidden');
        // Update service amounts display
        const map = {mantenimiento:'precio_mantenimiento', servicios:'precio_servicios', amueblado:'precio_amueblado', parking:'precio_parking_extra', mascota:'precio_mascota'};
        document.querySelectorAll('.svc-amount').forEach(el => { el.textContent = fmt(parseFloat(h[map[el.dataset.svc]]) || 0); });
    } else { info.classList.add('hidden'); }
    recalcular();
});

// ── Duración: presets + custom ──
document.querySelectorAll('.dur-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.dur-btn').forEach(b => { b.classList.remove('border-pk','text-pk','bg-pk/5'); });
        this.classList.add('border-pk','text-pk','bg-pk/5');
        document.getElementById('dur-custom').value = '';
        S.dur = parseInt(this.dataset.dur);
        document.getElementById('inp-dur').value = S.dur;
        recalcular();
    });
});
document.getElementById('dur-custom')?.addEventListener('input', function() {
    const v = parseInt(this.value);
    if (v > 0) {
        document.querySelectorAll('.dur-btn').forEach(b => { b.classList.remove('border-pk','text-pk','bg-pk/5'); });
        S.dur = v;
        document.getElementById('inp-dur').value = v;
        recalcular();
    }
});

// ── Descuento ──
document.getElementById('inp-desc')?.addEventListener('input', function() { S.descPct = parseFloat(this.value) || 0; recalcular(); });
function setDesc(v) { document.getElementById('inp-desc').value = v; S.descPct = v; recalcular(); }

// ═══ RECALCULAR ═══
function recalcular() {
    const h = S.hab, dur = S.dur;
    const empty = document.getElementById('resumen-empty'), content = document.getElementById('resumen-content');
    if (!h || !dur) { empty?.classList.remove('hidden'); content?.classList.add('hidden'); return; }
    empty?.classList.add('hidden'); content?.classList.remove('hidden');

    const tarifa = getTarifa(dur);
    const rentaBase = getRentaByTarifa(h, tarifa);
    document.getElementById('inp-tarifa').value = tarifa;

    // Display renta prominente
    document.getElementById('display-renta-base').textContent = fmt(rentaBase);
    document.getElementById('display-tarifa-tag').textContent = tarifa;
    const tarifaLabel = document.getElementById('tarifa-label');
    tarifaLabel.textContent = getTarifaLabel(tarifa);
    tarifaLabel.classList.remove('hidden');

    // Descuento SOLO sobre renta
    const descPct = Math.min(Math.max(S.descPct, 0), 50);
    const descMonto = Math.round(rentaBase * descPct / 100 * 100) / 100;
    const rentaNeta = rentaBase - descMonto;

    // Mantenimiento siempre incluido
    const mant = parseFloat(h.precio_mantenimiento) || 0;

    // Servicios opcionales
    const svcMap = { servicios:'precio_servicios', amueblado:'precio_amueblado', parking:'precio_parking_extra', mascota:'precio_mascota' };
    const labels = { servicios:'Servicios', amueblado:'Amueblado', parking:'Estacionamiento', mascota:'Mascota' };
    let totalExtras = mant;
    let extrasHTML = `<div class="flex justify-between text-gray-600"><span>Mantenimiento</span><span class="font-medium">+${fmt(mant)}</span></div>`;
    const montos = { mantenimiento: mant, servicios: 0, amueblado: 0, parking: 0, mascota: 0 };

    for (const [key, campo] of Object.entries(svcMap)) {
        const monto = parseFloat(h[campo]) || 0;
        const activo = S.toggles[key] && monto > 0;
        montos[key] = activo ? monto : 0;
        if (activo) {
            totalExtras += monto;
            extrasHTML += `<div class="flex justify-between text-gray-600"><span>${labels[key]}</span><span class="font-medium">+${fmt(monto)}</span></div>`;
        }
    }

    // ── IVA: aplica sobre servicios (todo menos renta). Futuro: también renta. ──
    const ivaPct = 16;
    const ivaSobreRenta = S.ivaSobreRenta || false;
    let baseIva = totalExtras; // mantenimiento + servicios activos
    if (ivaSobreRenta) baseIva += rentaNeta;
    const montoIva = Math.round(baseIva * ivaPct / 100 * 100) / 100;

    const totalMensual = rentaNeta + totalExtras + montoIva;
    const totalContrato = totalMensual * dur;

    // IVA display in column 2
    document.getElementById('display-iva-monto').textContent = fmt(montoIva);
    const ivaRentaMonto = ivaSobreRenta ? Math.round(rentaNeta * ivaPct / 100 * 100) / 100 : 0;
    document.getElementById('display-iva-renta-monto').textContent = ivaSobreRenta ? fmt(ivaRentaMonto) : '$0.00';
    document.getElementById('iva-badge').textContent = ivaSobreRenta ? 'Sobre servicios y renta' : 'Sobre servicios';

    // UI
    const ps = document.getElementById('sel-prop');
    document.getElementById('res-prop').textContent = ps.options[ps.selectedIndex]?.text || '—';
    document.getElementById('res-hab').textContent = h.nombre + (h.codigo ? ` (${h.codigo})` : '');
    document.getElementById('res-dur').textContent = dur + (dur === 1 ? ' mes' : ' meses') + ' · tarifa ' + tarifa;
    document.getElementById('res-renta-base').textContent = fmt(rentaBase);
    document.getElementById('res-desc-pct').textContent = descPct;
    document.getElementById('res-desc-monto').textContent = '-' + fmt(descMonto);
    document.getElementById('res-desc-row').style.display = descPct > 0 ? '' : 'none';
    document.getElementById('res-renta-neta').textContent = fmt(rentaNeta);
    document.getElementById('res-extras').innerHTML = extrasHTML;

    // IVA row in resumen
    document.getElementById('res-iva-row').style.display = montoIva > 0 ? '' : 'none';
    document.getElementById('res-iva-pct').textContent = ivaPct;
    document.getElementById('res-iva-monto').textContent = '+' + fmt(montoIva);
    document.getElementById('res-iva-sobre').textContent = ivaSobreRenta ? 'sobre servicios y renta' : 'sobre servicios';
    document.getElementById('res-iva-inc').style.display = montoIva > 0 ? '' : 'none';

    document.getElementById('res-total-mensual').textContent = fmt(totalMensual);
    document.getElementById('res-dur2').textContent = dur;
    document.getElementById('res-total-contrato').textContent = fmt(totalContrato);
    document.getElementById('desc-monto').textContent = '-' + fmt(descMonto);

    // Hidden
    document.getElementById('h-renta-base').value = rentaBase.toFixed(2);
    document.getElementById('h-desc-pct').value = descPct.toFixed(2);
    document.getElementById('h-desc-monto').value = descMonto.toFixed(2);
    document.getElementById('h-renta-neta').value = rentaNeta.toFixed(2);
    document.getElementById('h-monto-mant').value = montos.mantenimiento.toFixed(2);
    document.getElementById('h-monto-serv').value = montos.servicios.toFixed(2);
    document.getElementById('h-monto-amue').value = montos.amueblado.toFixed(2);
    document.getElementById('h-monto-park').value = montos.parking.toFixed(2);
    document.getElementById('h-monto-masc').value = montos.mascota.toFixed(2);
    document.getElementById('h-iva-sobre-renta').value = ivaSobreRenta ? '1' : '0';
    document.getElementById('h-monto-iva').value = montoIva.toFixed(2);
    document.getElementById('h-subtotal').value = totalMensual.toFixed(2);
    document.getElementById('h-total-contrato').value = totalContrato.toFixed(2);
}

// ── Init edit mode ──
<?php if ($editCot): ?>
document.addEventListener('DOMContentLoaded', () => {
    S.dur = <?= (int)$editCot['duracion_meses'] ?>;
    S.descPct = <?= (float)$editCot['descuento_porcentaje'] ?>;
    document.getElementById('inp-dur').value = S.dur;
    // Trigger prop load
    const propSel = document.getElementById('sel-prop');
    if (propSel.value) propSel.dispatchEvent(new Event('change'));
    // Set duration button active
    const durBtn = document.querySelector('.dur-btn[data-dur="' + S.dur + '"]');
    if (durBtn) durBtn.classList.add('border-pk','text-pk','bg-pk/5');
    else document.getElementById('dur-custom').value = S.dur;
});
<?php endif; ?>

// ── Panel flotante de acciones (historial) ──
(function(){
    const panel = document.getElementById('cotPanel');
    const content = document.getElementById('cotPanelContent');
    const bg = document.getElementById('cotPanelBg');
    if (!panel) return;

    const csrf = '<?= e($csrf) ?>';
    const statusDefs = [
        {key:'borrador',  cls:'bg-gray-100 text-gray-600 hover:bg-gray-200'},
        {key:'enviada',   cls:'bg-blue-100 text-blue-700 hover:bg-blue-200'},
        {key:'aceptada',  cls:'bg-green-100 text-green-700 hover:bg-green-200'},
        {key:'rechazada', cls:'bg-red-100 text-red-600 hover:bg-red-200'},
        {key:'expirada',  cls:'bg-yellow-100 text-yellow-700 hover:bg-yellow-200'},
    ];

    function closePanel() { panel.classList.add('hidden'); }
    bg?.addEventListener('click', closePanel);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closePanel(); });

    document.querySelectorAll('.cot-menu-trigger').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const d = this.dataset;
            const rect = this.getBoundingClientRect();

            // Position: align right edge to button, below it — mobile-friendly
            let top = rect.bottom + 4;
            let left = rect.right - 224; // 224 = w-56 = 14rem
            if (left < 8) left = 8;
            // On mobile, center if it would go off-screen
            if (window.innerWidth < 640) {
                left = Math.max(8, (window.innerWidth - 224) / 2);
            }
            if (top + 360 > window.innerHeight) top = Math.max(8, rect.top - 360);

            content.style.top = top + 'px';
            content.style.left = left + 'px';

            // Populate
            document.getElementById('cp-title').textContent = 'Cotización #' + d.cotId;
            document.getElementById('cp-sub').textContent = d.cotProp + ' · ' + d.cotHab;
            document.getElementById('cp-pdf').href = '?tab=ver&id=' + d.cotId;
            document.getElementById('cp-delete-id').value = d.cotId;

            // Delete confirm
            document.getElementById('cp-delete-form').onsubmit = function(ev) {
                if (!confirm('¿Eliminar cotización #' + d.cotId + '?')) ev.preventDefault();
            };

            // WhatsApp — rich message with full breakdown
            const waLink = document.getElementById('cp-wa');
            const waLabel = document.getElementById('cp-wa-label');
            if (d.cotTel) {
                const nombre = (d.cotNombre || 'cliente').split(' ')[0];
                let wa = `Hola ${d.cotNombre}, te comparto tu cotización de *Park Life ${d.cotProp}* 🏠\n\n`;
                wa += `📋 *Cotización #${d.cotId}*\n🏢 ${d.cotHab}\n📅 ${d.cotDur} ${d.cotDur==='1'?'mes':'meses'} (tarifa ${d.cotTarifa})\n\n`;
                wa += `💰 *Desglose mensual:*\nRenta base: $${d.cotRentaBase}\n`;
                if (parseFloat(d.cotDescPct) > 0) wa += `Descuento (${d.cotDescPct}%): -$${d.cotDescMonto}\n`;
                wa += `*Renta neta: $${d.cotRentaNeta}*\n`;
                if (parseFloat(d.cotMant) > 0) wa += `Mantenimiento: $${d.cotMant}\n`;
                if (parseFloat(d.cotServ) > 0) wa += `Servicios: $${d.cotServ}\n`;
                if (parseFloat(d.cotAmue) > 0) wa += `Amueblado: $${d.cotAmue}\n`;
                if (parseFloat(d.cotPark) > 0) wa += `Estacionamiento: $${d.cotPark}\n`;
                if (parseFloat(d.cotMasc) > 0) wa += `Mascota: $${d.cotMasc}\n`;
                if (parseFloat(d.cotIva) > 0) wa += `IVA (${d.cotIvaPct}%): $${d.cotIva}\n`;
                wa += `\n✅ *TOTAL MENSUAL: $${d.cotMensual} MXN*\n📄 Total contrato: $${d.cotContrato} MXN\n`;
                if (d.cotToken) wa += `\n🔗 Ver cotización:\n<?= $baseUrl ?>/cotizacion.php?t=${d.cotToken}\n`;
                wa += `\nQuedo a tus órdenes. 🙂`;
                waLink.href = 'https://wa.me/' + d.cotTel + '?text=' + encodeURIComponent(wa);
                waLink.classList.remove('opacity-40','pointer-events-none');
                waLabel.textContent = 'WhatsApp a ' + nombre;
            } else {
                waLink.href = '#';
                waLink.classList.add('opacity-40','pointer-events-none');
                waLabel.textContent = 'WhatsApp (sin teléfono)';
            }

            // Email — rich body
            const emLink = document.getElementById('cp-email');
            const emLabel = document.getElementById('cp-email-label');
            if (d.cotEmail) {
                const nombre = (d.cotNombre || 'cliente').split(' ')[0];
                const subject = encodeURIComponent('Cotización Park Life ' + d.cotProp + ' #' + d.cotId);
                let em = `Hola ${d.cotNombre},\n\nTe comparto tu cotización para Park Life ${d.cotProp}.\n\n`;
                em += `Unidad: ${d.cotHab}\nDuración: ${d.cotDur} meses (tarifa ${d.cotTarifa})\n\n`;
                em += `Renta base: $${d.cotRentaBase}\n`;
                if (parseFloat(d.cotDescPct) > 0) em += `Descuento (${d.cotDescPct}%): -$${d.cotDescMonto}\n`;
                em += `Renta neta: $${d.cotRentaNeta}\n`;
                if (parseFloat(d.cotMant) > 0) em += `Mantenimiento: $${d.cotMant}\n`;
                if (parseFloat(d.cotServ) > 0) em += `Servicios: $${d.cotServ}\n`;
                if (parseFloat(d.cotAmue) > 0) em += `Amueblado: $${d.cotAmue}\n`;
                if (parseFloat(d.cotPark) > 0) em += `Estacionamiento: $${d.cotPark}\n`;
                if (parseFloat(d.cotMasc) > 0) em += `Mascota: $${d.cotMasc}\n`;
                if (parseFloat(d.cotIva) > 0) em += `IVA (${d.cotIvaPct}%): $${d.cotIva}\n`;
                em += `\nTOTAL MENSUAL: $${d.cotMensual} MXN\nTotal contrato: $${d.cotContrato} MXN\n`;
                if (d.cotToken) em += `\nVer cotización: <?= $baseUrl ?>/cotizacion.php?t=${d.cotToken}\n`;
                em += `\nQuedo a tus órdenes.`;
                emLink.href = 'mailto:' + d.cotEmail + '?subject=' + subject + '&body=' + encodeURIComponent(em);
                emLink.classList.remove('opacity-40','pointer-events-none');
                emLabel.textContent = 'Email a ' + nombre;
            } else {
                emLink.href = '#';
                emLink.classList.add('opacity-40','pointer-events-none');
                emLabel.textContent = 'Email (sin correo)';
            }

            // Status pills
            const statusDiv = document.getElementById('cp-status');
            statusDiv.innerHTML = '';
            statusDefs.forEach(s => {
                const form = document.createElement('form');
                form.method = 'post'; form.className = 'm-0 inline';
                form.innerHTML = '<input type="hidden" name="csrf_token" value="' + csrf + '">'
                    + '<input type="hidden" name="action" value="cambiar_estatus">'
                    + '<input type="hidden" name="cot_id" value="' + d.cotId + '">'
                    + '<input type="hidden" name="nuevo_estatus" value="' + s.key + '">'
                    + '<button type="submit" class="px-2 py-0.5 text-[10px] font-semibold rounded-full transition-colors '
                    + (d.cotEstatus === s.key ? 'ring-2 ring-pk ring-offset-1 ' : '') + s.cls + '">'
                    + s.key.charAt(0).toUpperCase() + s.key.slice(1) + '</button>';
                statusDiv.appendChild(form);
            });

            panel.classList.remove('hidden');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    });
})();
</script>

<?php adminLayoutClose(); ?>