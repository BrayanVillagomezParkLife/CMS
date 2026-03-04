<?php
declare(strict_types=1);

/**
 * ============================================================
 * PARK LIFE PROPERTIES — config.php
 * Variables de entorno, constantes globales y configuración
 * ============================================================
 *
 * ⚠️  CAMPOS QUE DEBES COMPLETAR (marcados con TODO):
 *      1. DB_USER  → usuario MySQL real del servidor
 *      2. DB_PASS  → contraseña MySQL real del servidor
 *      3. SMTP_USER → cuenta Gmail que envía los correos
 *
 * El resto ya está completo con valores reales del proyecto.
 * ============================================================
 */

// ─── Prevenir acceso directo ──────────────────────────────────────────────────
if (!defined('PARKLIFE_LOADED')) {
    define('PARKLIFE_LOADED', true);
}

// ─── Entorno ──────────────────────────────────────────────────────────────────
// Cambiar a 'production' al hacer deploy final
define('APP_ENV',     'development');   // 'development' | 'production'
define('APP_DEBUG',   APP_ENV === 'development');
define('APP_VERSION', '2026.1.0');

// ─── Rutas base ───────────────────────────────────────────────────────────────
define('BASE_URL',     'https://www.parklife.mx');
define('BASE_PATH',    dirname(__DIR__));
define('UPLOADS_PATH', BASE_PATH . '/uploads');
define('LOGS_PATH',    BASE_PATH . '/logs');
define('CACHE_PATH',   BASE_PATH . '/cache');

// ─── Base de Datos ────────────────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'parklife_2026');
define('DB_USER',    'root');         // TODO: usuario MySQL del servidor
define('DB_PASS',    '$Admin2025');         // TODO: contraseña MySQL del servidor
define('DB_CHARSET', 'utf8mb4');

// ─── SMTP / PHPMailer (Gmail) ─────────────────────────────────────────────────
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      465);
define('SMTP_SECURE',    'ssl');
define('SMTP_USER',      'correo@parklife.mx'); // TODO: cuenta Gmail que envía
define('SMTP_PASS',      'bqzc owul when ccku');   // App password de Gmail
define('SMTP_FROM',      'info@parklife.mx');
define('SMTP_FROM_NAME', 'Park Life Properties');

// Emails internos
define('EMAIL_INFO',    'info@parklife.mx');
define('EMAIL_LEADS',   'leads@parklife.mx');
define('EMAIL_PRENSA',  'prensa@parklife.mx');
define('EMAIL_BCC',     'brayan.villagomez@parklife.mx');
define('EMAIL_ADMIN',   'brayan.villagomez@parklife.mx');
define('FALLBACK_WHATSAPP', '525534765452');
define('EMAIL_NOREPLY', SMTP_FROM);
define('BCC_EMAIL',     EMAIL_BCC);

// ─── SendGrid (respaldo / transaccionales) ────────────────────────────────────
define('SENDGRID_API_KEY', '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');

// ─── Zoho CRM ─────────────────────────────────────────────────────────────────
define('ZOHO_CLIENT_ID',     '1000.964REPV13357J1UPZCR8M6IPMYT8VJ');
define('ZOHO_CLIENT_SECRET', 'c1544ead7763147e01a448c686b5e570621822dc4e');
define('ZOHO_REFRESH_TOKEN', '1000.1b050205186be81cbf44afa407afbefa.2188bd4ff0c3d49d6b996f818eba34f5');
define('ZOHO_API_DOMAIN',    'https://www.zohoapis.com');
define('ZOHO_ORG_ID',        '832957970');
define('ZOHO_CRM_URL',       'https://crm.zoho.com/crm/org' . ZOHO_ORG_ID . '/tab/Leads/');

// Owner fijo Querétaro
define('ZOHO_KARLA_ID', '5993209000013421001');

// ─── WhatsApp (Facebook Graph API) ───────────────────────────────────────────
define('WA_TOKEN',    'EAAYhElbnqmYBPess2CUqIhWxBGPYljC3bvYFe2RTYbNXNxH5TPHx4ZA5wpIj4XOtshZBKHlffS4VSrKHtnefcIhvIYa0zZCRZCXocZBh5cwg0gigzo2ZBvbRw0fi30Ay9WbzEYxTv5OGaKGjp3RrRsYNc2JvUZBieCoNo0kZA0XZBesZBUCBcZAPtWuv8blZBle5U0rTgwZDZD');
define('WA_PHONE_ID', '721891017668071');
define('WA_TEMPLATE', 'avisacomerciales3');

// Números que SIEMPRE reciben notificación de lead (sin +)
define('WA_BRAYAN',   '525560559592');
define('WA_RICARDO',  '525543772460');
define('WA_CAYETANO', '525513531288');

// ─── reCAPTCHA v2 ─────────────────────────────────────────────────────────────
define('RECAPTCHA_SITE_KEY',   '6Ld5GlYrAAAAAHy16h-QpXIkeBf_635TOLT_JlST');
define('RECAPTCHA_SECRET_KEY', '6Ld5GlYrAAAAAP6GNj46uPI590qjiH8lZCNmT9CL');

// ─── Cloudbeds ────────────────────────────────────────────────────────────────
define('CLOUDBEDS_DEFAULT_CODE', 'i5lEbX');
define('CLOUDBEDS_BASE_URL',     'https://hotels.cloudbeds.com/reservation/');
// API Key para tarifas dinámicas — se configura en admin/configuracion.php


// ─── Cache ────────────────────────────────────────────────────────────────────
define('CACHE_TTL',    300);   // 5 min
define('CACHE_ACTIVE', true);

// ─── Rate Limiting ────────────────────────────────────────────────────────────
define('RATE_LIMIT_MAX',    5);
define('RATE_LIMIT_WINDOW', 60);

// ─── Admin ────────────────────────────────────────────────────────────────────
define('ADMIN_SESSION_NAME',    'parklife_admin');
define('ADMIN_SESSION_TIMEOUT', 7200);  // 2 horas

// ─── Imágenes ─────────────────────────────────────────────────────────────────
define('IMG_HERO_W',       1920);
define('IMG_HERO_H',       1080);
define('IMG_CARD_W',       600);
define('IMG_CARD_H',       400);
define('IMG_GALLERY_W',    1200);
define('IMG_GALLERY_H',    800);
define('IMG_OG_W',         1200);
define('IMG_OG_H',         630);
define('IMG_MAX_SIZE',     10 * 1024 * 1024);   // 10 MB
define('IMG_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// ─── Paginación ───────────────────────────────────────────────────────────────
define('ITEMS_PER_PAGE', 25);

// ─── PHP Timezone / Locale ────────────────────────────────────────────────────
date_default_timezone_set('America/Mexico_City');
setlocale(LC_TIME, 'es_MX.UTF-8', 'es_MX', 'es');

// ─── Error handling ───────────────────────────────────────────────────────────
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', LOGS_PATH . '/php_errors.log');
}

// ─── Asegurar directorios necesarios ──────────────────────────────────────────
foreach ([LOGS_PATH, CACHE_PATH, UPLOADS_PATH] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ─── Idioma — detectar desde URL si router.php no lo definió ─────────────────
if (!defined('APP_LANG')) {
    $__uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if ($__uri === '/en' || str_starts_with($__uri, '/en/')) {
        define('APP_LANG',    'en');
        define('LANG_PREFIX', '/en');
    } else {
        define('APP_LANG',    'es');
        define('LANG_PREFIX', '');
    }
    unset($__uri);
}
if (!defined('LANG_PREFIX')) define('LANG_PREFIX', '');
require_once __DIR__ . '/lang.php';


// Autoload de Composer (PHPMailer)
if (file_exists(BASE_PATH . '/vendor/autoload.php')) {
    require_once BASE_PATH . '/vendor/autoload.php';
}
