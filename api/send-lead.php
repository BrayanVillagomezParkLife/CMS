<?php
declare(strict_types=1);
ob_start(); // capturar cualquier output espurio antes del JSON

/**
 * ============================================================
 * api/send-lead.php
 * Procesa leads del widget "Por Meses" del hero booking engine
 *
 * Flujo:
 *   1. Validaciones (método, CSRF, honeypot, reCAPTCHA, rate limit)
 *   2. Sanitizar y validar campos
 *   3. syncToZoho → obtiene owner asignado
 *   4. INSERT en tabla `leads`
 *   5. Email notificación interna (asesor en CC)
 *   6. Email confirmación al cliente
 *   7. WhatsApp al equipo (template avisacomerciales3)
 *   8. Responder JSON {success, message}
 *
 * En caso de fallo de Zoho:
 *   - Lead se guarda igual en BD (in_zoho = 3)
 *   - WhatsApp de alerta solo a Brayan
 *   - El cliente recibe respuesta exitosa (error silencioso)
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/ZohoService.php';
require_once __DIR__ . '/../services/WhatsAppService.php';
require_once __DIR__ . '/../services/MailService.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

// ─── Iniciar sesión para CSRF ──────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ─── CSRF ──────────────────────────────────────────────────────────────────
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    logSecurity('csrf_fail_lead', getClientIp());
    jsonResponse(['success' => false, 'message' => 'Token de seguridad inválido. Recarga la página.'], 403);
}

// ─── Honeypot ──────────────────────────────────────────────────────────────
if (!checkHoneypot()) {
    logSecurity('honeypot_lead', getClientIp());
    jsonResponse(['success' => true, 'message' => 'Solicitud recibida.']); // Silencioso para bots
}

// ─── Rate limit: máx 5 leads por IP en 10 min ──────────────────────────────
if (!checkRateLimit('lead_' . getClientIp(), 5, 600)) {
    logSecurity('rate_limit_lead', getClientIp());
    jsonResponse(['success' => false, 'message' => 'Demasiadas solicitudes. Espera unos minutos.'], 429);
}

// ─── Timing anti-bot ───────────────────────────────────────────────────────
if (!validateFormTiming('lead_mensual')) {
    logSecurity('timing_fail_lead', getClientIp());
    jsonResponse(['success' => false, 'message' => 'Formulario completado muy rápido. Intenta de nuevo.'], 400);
}

// ─── reCAPTCHA ─────────────────────────────────────────────────────────────
$recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
if (APP_ENV === 'production' && !verifyRecaptcha($recaptchaResponse)) {
    logSecurity('recaptcha_fail_lead', getClientIp());
    jsonResponse(['success' => false, 'message' => 'Verificación de seguridad fallida. Intenta de nuevo.'], 400);
}

// ─── Sanitizar campos ──────────────────────────────────────────────────────
$nombre        = sanitizeStr($_POST['nombre'] ?? '');
$email         = sanitizeEmail($_POST['email'] ?? '');
$telefono      = sanitizeStr($_POST['telefono'] ?? '');
$propiedadSlug = sanitizeStr($_POST['propiedad_slug'] ?? '');
$propiedadId   = (int)($_POST['propiedad_id'] ?? 0);
$duracion      = (int)($_POST['duracion'] ?? 1);
$durLabel      = $duracion >= 13 ? '+12 meses' : "$duracion " . ($duracion === 1 ? 'mes' : 'meses');
$mascota       = !empty($_POST['mascota'])   ? 1 : 0;
$amueblado     = !empty($_POST['amueblado']) ? 1 : 0;
$comentarios   = sanitizeStr($_POST['comentarios'] ?? '');
$mesEntrada    = sanitizeStr($_POST['mes_entrada'] ?? '');
$ip            = getClientIp();

// UTMs
$utmSource   = sanitizeStr($_POST['utm_source']   ?? $_GET['utm_source']   ?? '');
$utmMedium   = sanitizeStr($_POST['utm_medium']   ?? $_GET['utm_medium']   ?? '');
$utmCampaign = sanitizeStr($_POST['utm_campaign'] ?? $_GET['utm_campaign'] ?? '');
$utmContent  = sanitizeStr($_POST['utm_content']  ?? $_GET['utm_content']  ?? '');

// ─── Validaciones básicas ──────────────────────────────────────────────────
if (!isValidEmail($email)) {
    jsonResponse(['success' => false, 'message' => 'Email inválido.'], 400);
}
if (strlen($telefono) < 8) {
    jsonResponse(['success' => false, 'message' => 'Teléfono requerido.'], 400);
}
if (!$propiedadSlug && !$propiedadId) {
    jsonResponse(['success' => false, 'message' => 'Selecciona una propiedad.'], 400);
}

// ─── Privacidad obligatoria ────────────────────────────────────────────────
if (empty($_POST['privacidad_ok'])) {
    jsonResponse(['success' => false, 'message' => 'Debes aceptar la Política de Privacidad.'], 400);
}

// ─── Resolver propiedad desde BD ───────────────────────────────────────────
$propiedad = null;
if ($propiedadSlug) {
    $propiedad = getPropiedadBySlug($propiedadSlug);
}
if (!$propiedad && $propiedadId) {
    $propiedad = dbFetchOne("SELECT * FROM propiedades WHERE id = ? AND activo = 1", [$propiedadId]);
}
if (!$propiedad) {
    // Fallback: guardar igual sin propiedad
    $propiedadNombre = sanitizeStr($_POST['propiedad_nombre'] ?? $propiedadSlug);
} else {
    $propiedadNombre = $propiedad['nombre'];
    $propiedadId     = (int)$propiedad['id'];
}

// ─── Anti-duplicados: mismo email+propiedad en los últimos 5 min ───────────
$existingLead = dbFetchOne(
    "SELECT id FROM leads 
     WHERE email = ? AND propiedad_id = ? AND tipo = 'meses'
     AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
     LIMIT 1",
    [$email, $propiedadId]
);
if ($existingLead) {
    // Silencioso: el usuario ya mandó la misma solicitud recientemente
    logApp('info', 'Duplicate lead bloqueado', ['email' => $email, 'propiedad_id' => $propiedadId]);
    jsonResponse(['success' => true, 'message' => 'Recibimos tu solicitud. Te contactamos en menos de 2 hrs.']);
}

// ═══════════════════════════════════════════════════════════════════════════
// PASO 1: INSERT EN NUESTRA BD
// Lead seguro desde el inicio — si Zoho falla, no se pierde nada
// ═══════════════════════════════════════════════════════════════════════════
$leadId = dbInsert('leads', [
    'tipo'             => 'meses',
    'nombre'           => $nombre ?: null,
    'email'            => $email,
    'telefono'         => $telefono,
    'propiedad_id'     => $propiedadId ?: null,
    'propiedad_nombre' => $propiedadNombre,
    'duracion_meses'   => $duracion,
    'mascota'          => $mascota,
    'amueblado'        => $amueblado,
    'mes_entrada'      => $mesEntrada  ?: null,
    'comentarios'      => $comentarios ?: null,
    'ip_address'       => $ip,
    'utm_source'       => $utmSource   ?: null,
    'utm_medium'       => $utmMedium   ?: null,
    'utm_campaign'     => $utmCampaign ?: null,
    'utm_content'      => $utmContent  ?: null,
    'in_zoho'          => 0,   // pendiente hasta confirmar Zoho
    'lead_source'      => 'Sitio Web',
]);

if (!$leadId) {
    logApp('error', 'Fallo INSERT lead', ['email' => $email]);
    jsonResponse(['success' => false, 'message' => 'Error interno. Escríbenos a ' . EMAIL_INFO], 500);
}

logApp('info', 'Lead guardado en BD', ['id' => $leadId, 'email' => $email, 'propiedad' => $propiedadNombre]);

// ═══════════════════════════════════════════════════════════════════════════
// PASO 2: SYNC CON ZOHO
// Obtiene el asesor asignado (owner) para notificaciones
// ═══════════════════════════════════════════════════════════════════════════
$zohoService = new ZohoService();
$zohoResult  = $zohoService->syncLead(
    firstName:       $nombre ?: $telefono,
    lastName:        '',
    email:           $email,
    phone:           $telefono,
    propiedadNombre: $propiedadNombre,
    propiedadSlug:   $propiedadSlug,
    tipoLead:        'meses',
    duracionLabel:   $durLabel
);

$zohoFailed    = !$zohoResult['success'];
$zohoLeadId    = $zohoResult['zoho_lead_id']    ?? null;
$ownerZohoId   = $zohoResult['owner_id']        ?? null;
$ownerName     = $zohoResult['owner_name']      ?? 'Equipo Park Life';
$ownerEmail    = $zohoResult['owner_email']     ?? EMAIL_ADMIN;
$ownerWhatsapp = $zohoResult['owner_whatsapp']  ?? FALLBACK_WHATSAPP;
$inZoho        = $zohoFailed ? 3 : 1;

// PASO 2b: Actualizar lead con datos de Zoho y owner
dbExecute(
    "UPDATE leads SET
        zoho_lead_id    = ?,
        zoho_owner_id   = ?,
        zoho_owner_name = ?,
        owner_whatsapp  = ?,
        in_zoho         = ?
     WHERE id = ?",
    [$zohoLeadId, $ownerZohoId, $ownerName, $ownerWhatsapp, $inZoho, $leadId]
);

logApp('info', $zohoFailed ? 'Zoho falló — lead en BD con in_zoho=3' : 'Zoho OK',
    ['lead_id' => $leadId, 'zoho_lead_id' => $zohoLeadId, 'owner' => $ownerName]);

// ─── Datos comunes para emails y WA ───────────────────────────────────────
$leadData = [
    'id'               => $leadId,
    'tipo'             => 'meses',
    'nombre'           => $nombre,
    'duracion_meses'   => $duracion,
    'apellido'         => '',
    'email'            => $email,
    'telefono'         => $telefono,
    'propiedad_nombre' => $propiedadNombre,
    'mascota'          => $mascota,
    'amueblado'        => $amueblado,
    'comentarios'      => $comentarios,
    'zoho_lead_id'     => $zohoLeadId,
];

// ═══════════════════════════════════════════════════════════════════════════
// PASO 3: EMAILS
// ═══════════════════════════════════════════════════════════════════════════
$mailService   = new MailService();
$emailAdminOk  = $mailService->sendLeadNotification($leadData, $ownerEmail, $ownerName);
dbExecute("UPDATE leads SET email_admin_enviado = ? WHERE id = ?", [$emailAdminOk ? 1 : 0, $leadId]);

$emailClientOk = $mailService->sendClientConfirmation($leadData);
dbExecute("UPDATE leads SET email_usuario_enviado = ? WHERE id = ?", [$emailClientOk ? 1 : 0, $leadId]);

// ═══════════════════════════════════════════════════════════════════════════
// PASO 4: WHATSAPP
// ═══════════════════════════════════════════════════════════════════════════
$waService = new WhatsAppService();
if ($zohoFailed) {
    $waService->notifyError($nombre, $email, $telefono, $propiedadNombre,
        $zohoResult['error'] ?? 'Error desconocido', $leadId);
} else {
    $waOk = $waService->notifyNewLead(
        clientName:      $nombre,
        clientEmail:     $email,
        clientPhone:     $telefono,
        propiedadNombre: $propiedadNombre,
        ownerName:       $ownerName,
        zohoLeadId:      $zohoLeadId,
        comentarios:     $comentarios,
        ownerWhatsapp:   $ownerWhatsapp,
        fuente:          'Sitio Web — Por Meses (' . $durLabel . ')'
    );
    dbExecute("UPDATE leads SET whatsapp_enviado = ?, whatsapp_timestamp = NOW() WHERE id = ?",
        [$waOk ? 1 : 0, $leadId]);
}

// ═══════════════════════════════════════════════════════════════════════════
// PASO 5: COTIZACIÓN — precios según duración para mostrar en modal
// ═══════════════════════════════════════════════════════════════════════════
$cotizacion  = [];
$precioDesde = null;

if ($propiedadId) {
    try {
        // Columna de precio según duración (whitelisted — no viene del usuario)
        $colPrecio = in_array($duracion, range(12, 99)) ? 'precio_mes_12'
                   : ($duracion >= 6 ? 'precio_mes_6' : 'precio_mes_1');

        $habs = dbFetchAll(
            "SELECT nombre, num_camas as capacidad, metros_cuadrados,
                    {$colPrecio} as precio_mes,
                    precio_mantenimiento, precio_servicios, precio_mascota
             FROM habitaciones
             WHERE propiedad_id = ? AND activa = 1
             ORDER BY {$colPrecio} ASC",
            [$propiedadId]
        );

        foreach ($habs as $h) {
            $base = (float)($h['precio_mes'] ?? 0);
            if ($base <= 0) continue;
            $total = $base
                   + ((float)($h['precio_mantenimiento'] ?? 0) * 1.16)
                   + ((float)($h['precio_servicios']     ?? 0) * 1.16);
            if ($mascota) $total += (float)($h['precio_mascota'] ?? 0);
            $cotizacion[] = [
                'nombre'       => $h['nombre'],
                'capacidad'    => $h['capacidad'],
                'metros'       => $h['metros_cuadrados'],
                'precio'       => round($total),
                'mejor_precio' => false,
            ];
        }

        if (!empty($cotizacion)) {
            $precioDesde = min(array_column($cotizacion, 'precio'));
            foreach ($cotizacion as &$row) {
                $row['mejor_precio'] = ($row['precio'] === $precioDesde);
            }
            unset($row);
        }

    } catch (\Throwable $e) {
        logApp('error', 'Error calculando cotización', [
            'lead_id'      => $leadId,
            'propiedad_id' => $propiedadId,
            'error'        => $e->getMessage(),
        ]);
        // No bloquear — el lead ya fue guardado, solo no habrá cotización en el modal
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// RESPUESTA FINAL
// ═══════════════════════════════════════════════════════════════════════════
jsonResponse([
    'success'          => true,
    'message'          => 'Recibimos tu solicitud. Te contactamos en menos de 2 hrs.',
    'lead_id'          => $leadId,
    'cotizacion'       => [
        'nombre'       => $nombre,
        'propiedad'    => $propiedadNombre,
        'duracion'     => $durLabel,
        'precio_desde' => $precioDesde,
        'habitaciones' => $cotizacion,
        'asesor'       => $ownerName,
        'amueblado'    => (bool)$amueblado,
        'mascota'      => (bool)$mascota,
    ],
]);