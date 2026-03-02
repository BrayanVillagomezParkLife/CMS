<?php
/**
 * includes/lang.php
 * ─────────────────────────────────────────────────────────────
 * Helpers de URL para el sistema bilingüe.
 *
 * La traducción ES→EN la hace DeepL sobre el HTML completo
 * (ver router.php + includes/translator.php).
 * No hay catálogos, no hay t(), no hay tx().
 * Escribe todo en español — DeepL se encarga del resto.
 * ─────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

if (!defined('APP_LANG'))    define('APP_LANG',    'es');
if (!defined('LANG_PREFIX')) define('LANG_PREFIX', '');

/** URL interna respetando el prefijo de idioma actual */
function langUrl(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return LANG_PREFIX . $path;
}

/** URL del mismo contenido en el otro idioma (para el switcher) */
function langSwitch(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (APP_LANG === 'en') {
        return preg_replace('#^/en#', '', $uri) ?: '/';
    }
    return '/en' . $uri;
}

/** Atributo lang del <html> */
function htmlLang(): string
{
    return APP_LANG === 'en' ? 'en' : 'es';
}

/**
 * Stubs de compatibilidad — previenen Fatal Error si algún
 * template todavía llama a t() o tx() durante la transición.
 * Devuelven el valor original sin modificar.
 */
if (!function_exists('t')) {
    function t(string $key, string $fallback = ''): string {
        return $fallback ?: $key;
    }
}

if (!function_exists('tx')) {
    function tx(mixed $text): string {
        return (string)($text ?? '');
    }
}
