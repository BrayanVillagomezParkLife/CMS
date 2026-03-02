<?php
declare(strict_types=1);
/**
 * cotizacion.php — Vista pública de cotización compartida por link.
 * URL: /cotizacion.php?t={token_publico}
 * No requiere login. Acceso por token único.
 */

require_once __DIR__ . '/includes/db.php';

$token = $_GET['t'] ?? '';
if (!$token || strlen($token) < 16) {
    http_response_code(404);
    die('Cotización no encontrada.');
}

$cot = dbFetchOne(
    "SELECT c.*, p.nombre AS prop_nombre, p.ciudad,
            h.nombre AS hab_nombre, h.codigo AS hab_codigo,
            h.num_camas, h.num_banos, h.metros_cuadrados, h.imagen_url,
            a.email AS admin_email
     FROM cotizaciones c
     JOIN propiedades p ON p.id = c.propiedad_id
     JOIN habitaciones h ON h.id = c.habitacion_id
     JOIN admin_usuarios a ON a.id = c.admin_id
     WHERE c.token_publico = ?",
    [$token]
);

if (!$cot) {
    http_response_code(404);
    die('Cotización no encontrada o expirada.');
}

$logoUrl = dbFetchValue("SELECT valor FROM config WHERE clave='logo_blanco'") ?: 'pics/Logo_ParkLife_Blanco.png';
$tel     = dbFetchValue("SELECT valor FROM config WHERE clave='telefono_principal'") ?: '';
$emailC  = dbFetchValue("SELECT valor FROM config WHERE clave='email_contacto'") ?: '';
$waNum   = dbFetchValue("SELECT valor FROM config WHERE clave='whatsapp'") ?: '';
$f = fn($v) => '$' . number_format((float)$v, 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cotización Park Life <?= htmlspecialchars($cot['prop_nombre']) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; color: #202944; font-size: 14px; background: #f0f2f5; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 16px rgba(0,0,0,.06); margin-bottom: 16px; }

        /* Header */
        .header { background: #202944; color: #fff; padding: 28px 24px; text-align: center; }
        .header img { height: 32px; margin-bottom: 12px; }
        .header h1 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
        .header .sub { font-size: 13px; opacity: .7; }

        /* Property */
        .prop { padding: 20px 24px; border-bottom: 1px solid #f0f0f0; }
        .prop-name { font-size: 17px; font-weight: 700; margin-bottom: 2px; }
        .prop-unit { color: #888; font-size: 13px; }
        .prop-meta { display: flex; gap: 12px; margin-top: 8px; flex-wrap: wrap; }
        .prop-meta span { font-size: 12px; color: #666; background: #f5f5f5; padding: 4px 10px; border-radius: 20px; }

        /* Breakdown */
        .breakdown { padding: 20px 24px; }
        .breakdown h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #999; margin-bottom: 12px; }
        .line { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f5f5f5; font-size: 14px; }
        .line:last-child { border-bottom: none; }
        .line .label { color: #555; }
        .line .amount { font-weight: 600; }
        .line.discount .label, .line.discount .amount { color: #b45309; }
        .line.subtotal { border-top: 2px dashed #e5e5e5; border-bottom: none; padding-top: 12px; margin-top: 4px; }
        .line.subtotal .label { font-weight: 700; color: #202944; }
        .line.subtotal .amount { font-weight: 800; color: #202944; }

        /* Total */
        .total-card { background: #202944; color: #fff; text-align: center; padding: 28px 24px; }
        .total-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; opacity: .6; }
        .total-amount { font-size: 36px; font-weight: 900; margin: 6px 0; letter-spacing: -1px; }
        .total-sub { font-size: 13px; opacity: .5; }
        .contract { padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; }
        .contract .label { font-size: 13px; color: #666; }
        .contract .amount { font-size: 18px; font-weight: 800; }

        /* Client */
        .client { padding: 20px 24px; }
        .client h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #999; margin-bottom: 10px; }
        .client-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
        .client-row .k { color: #888; } .client-row .v { font-weight: 600; }

        /* Notes */
        .notes { padding: 16px 24px; background: #fffbeb; font-size: 13px; border-top: 1px solid #fde68a; }

        /* Footer */
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #999; }
        .footer a { color: #202944; text-decoration: none; font-weight: 700; }

        /* CTA */
        .cta { padding: 16px 24px 20px; text-align: center; }
        .cta a { display: inline-flex; align-items: center; gap: 8px; padding: 14px 28px; border-radius: 12px; font-size: 15px; font-weight: 700; text-decoration: none; }
        .cta-wa { background: #25d366; color: #fff; }
        .cta-tel { background: #202944; color: #fff; margin-top: 8px; }

        .disclaimer { font-size: 11px; color: #aaa; text-align: center; padding: 12px 24px; }

        @media print {
            body { background: #fff; }
            .wrap { padding: 0; max-width: 100%; }
            .card { box-shadow: none; border-radius: 0; }
            .cta, .footer { display: none; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <!-- Header -->
    <div class="card">
        <div class="header">
            <img src="/<?= htmlspecialchars($logoUrl) ?>" alt="Park Life Properties">
            <h1>Tu cotización</h1>
            <div class="sub"><?= date('d/m/Y', strtotime($cot['created_at'])) ?> · Cotización #<?= $cot['id'] ?></div>
        </div>

        <!-- Property info -->
        <div class="prop">
            <div class="prop-name">Park Life <?= htmlspecialchars($cot['prop_nombre']) ?></div>
            <div class="prop-unit"><?= htmlspecialchars($cot['hab_nombre']) ?><?= $cot['hab_codigo'] ? ' (' . htmlspecialchars($cot['hab_codigo']) . ')' : '' ?></div>
            <div class="prop-meta">
                <?php if ($cot['metros_cuadrados']): ?><span><?= (float)$cot['metros_cuadrados'] ?> m²</span><?php endif; ?>
                <?php if ($cot['num_camas']): ?><span><?= (int)$cot['num_camas'] ?> recámara<?= (int)$cot['num_camas'] > 1 ? 's' : '' ?></span><?php endif; ?>
                <?php if ($cot['num_banos']): ?><span><?= (float)$cot['num_banos'] ?> baño<?= (float)$cot['num_banos'] > 1 ? 's' : '' ?></span><?php endif; ?>
                <span><?= $cot['duracion_meses'] ?> <?= (int)$cot['duracion_meses'] === 1 ? 'mes' : 'meses' ?></span>
            </div>
        </div>

        <!-- Breakdown -->
        <div class="breakdown">
            <h3>Desglose mensual</h3>
            <div class="line">
                <span class="label">Renta mensual (tarifa <?= $cot['tarifa_aplicada'] ?>)</span>
                <span class="amount"><?= $f($cot['precio_renta_base']) ?></span>
            </div>
            <?php if ((float)$cot['descuento_porcentaje'] > 0): ?>
            <div class="line discount">
                <span class="label">Descuento (<?= $cot['descuento_porcentaje'] ?>%)</span>
                <span class="amount">-<?= $f($cot['descuento_monto']) ?></span>
            </div>
            <?php endif; ?>
            <div class="line subtotal">
                <span class="label">Renta neta</span>
                <span class="amount"><?= $f($cot['precio_renta_neta']) ?></span>
            </div>
            <?php if ((float)$cot['monto_mantenimiento'] > 0): ?>
            <div class="line"><span class="label">Mantenimiento</span><span class="amount"><?= $f($cot['monto_mantenimiento']) ?></span></div>
            <?php endif; ?>
            <?php if ($cot['inc_servicios'] && (float)$cot['monto_servicios'] > 0): ?>
            <div class="line"><span class="label">Servicios</span><span class="amount"><?= $f($cot['monto_servicios']) ?></span></div>
            <?php endif; ?>
            <?php if ($cot['inc_amueblado'] && (float)$cot['monto_amueblado'] > 0): ?>
            <div class="line"><span class="label">Amueblado</span><span class="amount"><?= $f($cot['monto_amueblado']) ?></span></div>
            <?php endif; ?>
            <?php if ($cot['inc_parking'] && (float)$cot['monto_parking'] > 0): ?>
            <div class="line"><span class="label">Estacionamiento</span><span class="amount"><?= $f($cot['monto_parking']) ?></span></div>
            <?php endif; ?>
            <?php if ($cot['inc_mascota'] && (float)$cot['monto_mascota'] > 0): ?>
            <div class="line"><span class="label">Mascota</span><span class="amount"><?= $f($cot['monto_mascota']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)($cot['monto_iva'] ?? 0) > 0): ?>
            <div class="line" style="border-top:1px dashed #e5e5e5;padding-top:10px;margin-top:4px;">
                <span class="label">IVA (<?= (float)($cot['iva_porcentaje'] ?? 16) ?>%) <span style="font-size:11px;color:#aaa;">sobre servicios<?= !empty($cot['iva_sobre_renta']) ? ' y renta' : '' ?></span></span>
                <span class="amount"><?= $f($cot['monto_iva']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Total -->
        <div class="total-card">
            <div class="total-label">Total mensual</div>
            <div class="total-amount"><?= $f($cot['subtotal_mensual']) ?></div>
            <div class="total-sub">Pesos Mexicanos</div>
        </div>
        <div class="contract">
            <span class="label">Total contrato (<?= $cot['duracion_meses'] ?> <?= (int)$cot['duracion_meses'] === 1 ? 'mes' : 'meses' ?>)</span>
            <span class="amount"><?= $f($cot['total_contrato']) ?> MXN</span>
        </div>

        <?php if ($cot['cliente_nombre']): ?>
        <div class="client">
            <h3>Preparada para</h3>
            <div class="client-row"><span class="k">Nombre</span><span class="v"><?= htmlspecialchars($cot['cliente_nombre']) ?></span></div>
            <?php if ($cot['cliente_empresa']): ?><div class="client-row"><span class="k">Empresa</span><span class="v"><?= htmlspecialchars($cot['cliente_empresa']) ?></span></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($cot['notas']): ?>
        <div class="notes"><strong>Nota:</strong> <?= nl2br(htmlspecialchars($cot['notas'])) ?></div>
        <?php endif; ?>

        <!-- CTA -->
        <div class="cta">
            <?php if ($waNum): ?>
            <a href="https://wa.me/<?= $waNum ?>?text=<?= urlencode("Hola, me interesa la cotización #{$cot['id']} de Park Life {$cot['prop_nombre']}. ¿Me pueden dar más información?") ?>" class="cta-wa" target="_blank">
                💬 Contactar por WhatsApp
            </a><br>
            <?php endif; ?>
            <?php if ($tel): ?>
            <a href="tel:<?= htmlspecialchars($tel) ?>" class="cta-tel">📞 Llamar: <?= htmlspecialchars($tel) ?></a>
            <?php endif; ?>
        </div>

        <div class="disclaimer">
            Esta cotización es informativa y no constituye un contrato.<br>Precios sujetos a disponibilidad.
        </div>
    </div>

    <div class="footer">
        <a href="https://parklife.mx">www.parklife.mx</a><br>
        <?= htmlspecialchars($tel) ?> · <?= htmlspecialchars($emailC) ?>
    </div>
</div>
</body>
</html>