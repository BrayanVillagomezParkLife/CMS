<?php
declare(strict_types=1);

/**
 * ============================================================
 * api/contact.php
 * Procesa dos tipos de formularios:
 *
 *   form_type = "contacto"      → Sección #contacto del homepage
 *   form_type = "bolsa_trabajo" → Sección #bolsa-trabajo
 *
 * Flujo contacto:
 *   1. Validaciones (CSRF, honeypot, reCAPTCHA, rate limit)
 *   2. INSERT en leads (tipo='contacto')
 *   3. syncToZoho
 *   4. Email notificación interna
 *   5. Email confirmación al cliente
 *   6. WhatsApp al equipo
 *
 * Flujo bolsa_trabajo:
 *   1. Validaciones
 *   2. INSERT en leads (tipo='trabajo')
 *   3. Email notificación interna (sin Zoho)
 *   4. Confirmación al candidato
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

if (session_status() === PHP_SESSION_NONE) session_start();

// ─── CSRF ──────────────────────────────────────────────────────────────────
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    logSecurity('csrf_fail_contact', getClientIp());
    jsonResponse(['success' => false, 'message' => 'Token de seguridad inválido. Recarga la página.'], 403);
}

// ─── Honeypot ──────────────────────────────────────────────────────────────
if (!checkHoneypot()) {
    logSecurity('honeypot_contact', getClientIp());
    jsonResponse(['success' => true, 'message' => 'Solicitud recibida.']);
}

// ─── Rate limit ────────────────────────────────────────────────────────────
if (!checkRateLimit('contact_' . getClientIp(), 5, 600)) {
    logSecurity('rate_limit_contact', getClientIp());
    jsonResponse(['success' => false, 'message' => 'Demasiadas solicitudes. Espera unos minutos.'], 429);
}

// ─── reCAPTCHA ─────────────────────────────────────────────────────────────
$recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
if (APP_ENV === 'production' && !verifyRecaptcha($recaptchaResponse)) {
    logSecurity('recaptcha_fail_contact', getClientIp());
    jsonResponse(['success' => false, 'message' => 'Verificación de seguridad fallida.'], 400);
}

// ─── Tipo de formulario ────────────────────────────────────────────────────
$formType = sanitizeStr($_POST['form_type'] ?? 'contacto');
$allowed  = ['contacto', 'bolsa_trabajo', 'contact_home'];
if (!in_array($formType, $allowed, true)) {
    jsonResponse(['success' => false, 'message' => 'Formulario no reconocido.'], 400);
}
// Normalizar
if ($formType === 'contact_home') $formType = 'contacto';

// ─── Campos comunes ────────────────────────────────────────────────────────
$nombre   = sanitizeStr($_POST['nombre']   ?? '');
$apellido = sanitizeStr($_POST['apellido'] ?? '');
$email    = sanitizeEmail($_POST['email']  ?? '');
$telefono = sanitizeStr($_POST['telefono'] ?? '');
$mensaje  = sanitizeStr($_POST['mensaje']  ?? '');
$ip       = getClientIp();

// UTMs
$utmSource   = sanitizeStr($_POST['utm_source']   ?? $_GET['utm_source']   ?? '');
$utmMedium   = sanitizeStr($_POST['utm_medium']   ?? $_GET['utm_medium']   ?? '');
$utmCampaign = sanitizeStr($_POST['utm_campaign'] ?? $_GET['utm_campaign'] ?? '');

// ─── Validaciones comunes ──────────────────────────────────────────────────
if (!$nombre) {
    jsonResponse(['success' => false, 'message' => 'El nombre es requerido.'], 400);
}
if (!isValidEmail($email)) {
    jsonResponse(['success' => false, 'message' => 'Email inválido.'], 400);
}
if (!$telefono) {
    jsonResponse(['success' => false, 'message' => 'El teléfono es requerido.'], 400);
}
if (empty($_POST['privacidad_ok'])) {
    jsonResponse(['success' => false, 'message' => 'Debes aceptar la Política de Privacidad.'], 400);
}

// ─── Timing ────────────────────────────────────────────────────────────────
if (!validateFormTiming($formType)) {
    logSecurity('timing_fail_contact', getClientIp());
    jsonResponse(['success' => false, 'message' => 'Formulario completado muy rápido.'], 400);
}

// ═══════════════════════════════════════════════════════════════════════════
// RAMA: BOLSA DE TRABAJO
// ═══════════════════════════════════════════════════════════════════════════
if ($formType === 'bolsa_trabajo') {
    $posicion = sanitizeStr($_POST['posicion'] ?? '');

    // Anti-duplicados
    $dup = dbFetchOne(
        "SELECT id FROM leads WHERE email = ? AND tipo = 'trabajo'
         AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1",
        [$email]
    );
    if ($dup) {
        jsonResponse(['success' => true, 'message' => 'Ya recibimos tu solicitud. Te contactaremos si hay match.']);
    }

    $leadId = dbInsert('leads', [
        'tipo'       => 'trabajo',
        'nombre'     => $nombre,
        'apellido'   => $apellido,
        'email'      => $email,
        'telefono'   => $telefono,
        'comentarios' => $posicion ? "Posición: $posicion\n$mensaje" : $mensaje,
        'ip_address' => $ip,
        'lead_source' => 'Sitio Web',
        'in_zoho'    => 3, // No se sincroniza a Zoho
    ]);

    if (!$leadId) {
        jsonResponse(['success' => false, 'message' => 'Error interno. Escríbenos a ' . EMAIL_INFO], 500);
    }

    $mailService = new MailService();
    $mailService->sendJobApplication([
        'nombre'   => $nombre,
        'apellido' => $apellido,
        'email'    => $email,
        'telefono' => $telefono,
        'posicion' => $posicion,
        'mensaje'  => $mensaje,
    ]);

    // Confirmación al candidato
    $mailService->send(
        to:      $email,
        toName:  "$nombre $apellido",
        subject: 'Park Life Properties — Recibimos tu solicitud',
        body:    "<p>Hola <strong>$nombre</strong>, recibimos tu solicitud. La revisaremos y te contactaremos si hay un buen match. ¡Gracias por tu interés!</p>"
    );

    logApp('info', 'Job application guardada', ['id' => $leadId, 'email' => $email, 'posicion' => $posicion]);
    jsonResponse(['success' => true, 'message' => 'Revisaremos tu solicitud y te contactaremos si hay match.']);
}

// ═══════════════════════════════════════════════════════════════════════════
// RAMA: CONTACTO GENERAL
// ═══════════════════════════════════════════════════════════════════════════

// Campos adicionales del formulario de contacto
$propiedadSlug  = sanitizeStr($_POST['propiedad_interes'] ?? '');
$tipoEstancia   = sanitizeStr($_POST['tipo_estancia']     ?? '');
$propiedadId    = (int)($_POST['propiedad_id']            ?? 0);

// Resolver propiedad
$propiedadNombre = '';
if ($propiedadSlug) {
    $prop = getPropiedadBySlug($propiedadSlug);
    if ($prop) {
        $propiedadNombre = $prop['nombre'];
        $propiedadId     = (int)$prop['id'];
    } else {
        $propiedadNombre = $propiedadSlug;
    }
}

// Anti-duplicados
$dup = dbFetchOne(
    "SELECT id FROM leads WHERE email = ? AND tipo = 'contacto'
     AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1",
    [$email]
);
if ($dup) {
    jsonResponse(['success' => true, 'message' => 'Gracias por tu interés. Te contactaremos pronto.']);
}

// ═══════════════════════════════════════════════════════════════════════════
// PASO 1: SYNC CON ZOHO
// ═══════════════════════════════════════════════════════════════════════════
$zohoService  = new ZohoService();
$zohoResult   = $zohoService->syncLead(
    firstName:       $nombre,
    lastName:        $apellido,
    email:           $email,
    phone:           $telefono,
    propiedadNombre: $propiedadNombre ?: 'General',
    propiedadSlug:   $propiedadSlug,
    tipoLead:        'contacto'
);

$zohoFailed    = !$zohoResult['success'];
$zohoLeadId    = $zohoResult['zoho_lead_id'] ?? null;
$ownerZohoId   = $zohoResult['owner_id']     ?? null;
$ownerName     = $zohoResult['owner_name']   ?? 'Equipo Park Life';
$ownerEmail    = $zohoResult['owner_email']  ?? EMAIL_ADMIN;
$ownerWhatsapp = $zohoResult['owner_whatsapp'] ?? FALLBACK_WHATSAPP;
$inZoho        = $zohoFailed ? 3 : 1;

// ═══════════════════════════════════════════════════════════════════════════
// PASO 2: INSERTAR EN BD
// ═══════════════════════════════════════════════════════════════════════════
$leadId = dbInsert('leads', [
    'tipo'             => 'contacto',
    'nombre'           => $nombre,
    'apellido'         => $apellido,
    'email'            => $email,
    'telefono'         => $telefono,
    'propiedad_id'     => $propiedadId ?: null,
    'propiedad_nombre' => $propiedadNombre ?: null,
    'comentarios'      => $tipoEstancia ? "Tipo estancia: $tipoEstancia\n$mensaje" : $mensaje,
    'ip_address'       => $ip,
    'utm_source'       => $utmSource    ?: null,
    'utm_medium'       => $utmMedium    ?: null,
    'utm_campaign'     => $utmCampaign  ?: null,
    'zoho_lead_id'     => $zohoLeadId,
    'zoho_owner_id'    => $ownerZohoId,
    'zoho_owner_name'  => $ownerName,
    'owner_whatsapp'   => $ownerWhatsapp,
    'in_zoho'          => $inZoho,
    'lead_source'      => 'Sitio Web',
]);

if (!$leadId) {
    logApp('error', 'Fallo INSERT contacto', ['email' => $email]);
    jsonResponse(['success' => false, 'message' => 'Error interno. Escríbenos a ' . EMAIL_INFO], 500);
}

logApp('info', 'Contacto guardado', ['id' => $leadId, 'email' => $email]);

$leadData = [
    'id'               => $leadId,
    'tipo'             => 'contacto',
    'nombre'           => $nombre,
    'apellido'         => $apellido,
    'email'            => $email,
    'telefono'         => $telefono,
    'propiedad_nombre' => $propiedadNombre,
    'mensaje'          => $mensaje,
    'comentarios'      => $tipoEstancia ? "Tipo: $tipoEstancia — $mensaje" : $mensaje,
    'zoho_lead_id'     => $zohoLeadId,
];

// ═══════════════════════════════════════════════════════════════════════════
// PASO 3: EMAILS
// ═══════════════════════════════════════════════════════════════════════════
$mailService = new MailService();

$emailAdminOk = $mailService->sendContactNotification(array_merge($leadData, [
    'propiedad_interes' => $propiedadNombre ?: 'General',
    'tipo_estancia'     => $tipoEstancia,
]));
dbExecute("UPDATE leads SET email_admin_enviado = ? WHERE id = ?", [$emailAdminOk ? 1 : 0, $leadId]);

$emailClientOk = $mailService->sendClientConfirmation($leadData);
dbExecute("UPDATE leads SET email_usuario_enviado = ? WHERE id = ?", [$emailClientOk ? 1 : 0, $leadId]);

// ═══════════════════════════════════════════════════════════════════════════
// PASO 4: WHATSAPP
// ═══════════════════════════════════════════════════════════════════════════
$waService = new WhatsAppService();

if ($zohoFailed) {
    $waService->notifyError($nombre, $email, $telefono, $propiedadNombre ?: 'General', $zohoResult['error'] ?? '', $leadId);
} else {
    $waOk = $waService->notifyNewLead(
        clientName:      "$nombre $apellido",
        clientEmail:     $email,
        clientPhone:     $telefono,
        propiedadNombre: $propiedadNombre ?: 'General',
        ownerName:       $ownerName,
        zohoLeadId:      $zohoLeadId,
        comentarios:     $tipoEstancia ? "Tipo: $tipoEstancia — $mensaje" : $mensaje,
        ownerWhatsapp:   $ownerWhatsapp,
        fuente:          'Sitio Web — Contacto'
    );
    dbExecute("UPDATE leads SET whatsapp_enviado = ?, whatsapp_timestamp = NOW() WHERE id = ?", [$waOk ? 1 : 0, $leadId]);
}

// ═══════════════════════════════════════════════════════════════════════════
// RESPUESTA FINAL
// ═══════════════════════════════════════════════════════════════════════════
jsonResponse([
    'success' => true,
    'message' => 'Gracias por tu interés. Te contactaremos pronto.',
    'lead_id' => $leadId,
]);
