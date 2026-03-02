<?php
declare(strict_types=1);

/**
 * ============================================================
 * PARK LIFE PROPERTIES — functions.php
 * Helpers globales: config, propiedades, sanitización, cache
 * ============================================================
 */

require_once __DIR__ . '/db.php';

// ─── CONFIG ───────────────────────────────────────────────────────────────────

/**
 * Retorna todos los valores de la tabla config como array asociativo.
 * Resultado cacheado por CACHE_TTL segundos.
 *
 * @return array ['clave' => 'valor', ...]
 */
function getConfig(): array
{
    return dbCache('config_global', function () {
        $rows = dbFetchAll("SELECT clave, valor FROM config ORDER BY id");
        $config = [];
        foreach ($rows as $row) {
            $config[$row['clave']] = $row['valor'];
        }
        return $config;
    });
}

/**
 * Retorna el valor de una clave específica de config.
 *
 * @param string $key
 * @param mixed  $default Valor si no existe la clave
 */
function cfg(string $key, mixed $default = ''): mixed
{
    $config = getConfig();
    // Si está en inglés, buscar primero {key}_en
    if (defined('APP_LANG') && APP_LANG === 'en') {
        $keyEn = $key . '_en';
        if (isset($config[$keyEn]) && $config[$keyEn] !== '') {
            return $config[$keyEn];
        }
    }
    return $config[$key] ?? $default;
}

/**
 * Actualiza o inserta un valor en la tabla config.
 * Invalida el cache automáticamente.
 */
function setConfig(string $key, string $value): void
{
    dbExecute(
        "INSERT INTO config (clave, valor) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE valor = :v2, updated_at = CURRENT_TIMESTAMP",
        [':k' => $key, ':v' => $value, ':v2' => $value]
    );
    dbCacheInvalidate('config_global');
}


// ─── STRINGS DE SITIO (CMS multiidioma) ───────────────────────────────────────

/**
 * Retorna un string del CMS en el idioma activo.
 * Lee de la tabla strings_sitio — cacheado 30 min.
 *
 * Uso:  s('nav.inicio')          → "Home" si APP_LANG=en, "Inicio" si es
 *       s('form.enviar', 'Enviar')  → fallback si la clave no existe en BD
 */
function s(string $clave, string $fallback = ''): string
{
    static $cache = null;
    if ($cache === null) {
        // v2 = fuerza regeneración si existía cache viejo sin columna 'en'
        $cache = dbCache('strings_sitio_v2', function () {
            $rows = dbFetchAll("SELECT clave, es, en FROM strings_sitio");
            $map  = [];
            foreach ($rows as $r) {
                $map[$r['clave']] = ['es' => (string)($r['es'] ?? ''), 'en' => (string)($r['en'] ?? '')];
            }
            return $map;
        }, 1800);
    }

    if (!isset($cache[$clave])) {
        return $fallback ?: $clave;
    }

    // Detectar idioma: APP_LANG definido por router/index.php, o ?lang=en para testing rápido
    $lang = 'es';
    if (defined('APP_LANG') && APP_LANG === 'en') {
        $lang = 'en';
    } elseif (!defined('APP_LANG') && isset($_GET['lang']) && $_GET['lang'] === 'en') {
        $lang = 'en';
    }

    if ($lang === 'en' && !empty($cache[$clave]['en'])) {
        return $cache[$clave]['en'];
    }
    return $cache[$clave]['es'] ?: ($fallback ?: $clave);
}

/**
 * Retorna los pilares activos de la sección "Por qué nosotros".
 */
function getPilares(): array
{
    return dbCache('pilares_activos', function () {
        return dbFetchAll(
            "SELECT * FROM pilares WHERE activo = 1 ORDER BY orden ASC"
        );
    }, 1800);
}

// ─── MULTIIDIOMA ──────────────────────────────────────────────────────────────

/**
 * Devuelve el valor del campo en el idioma activo.
 * Si APP_LANG=en y existe campo_en con valor → devuelve campo_en.
 * En cualquier otro caso → devuelve campo (español, siempre disponible).
 *
 * Uso: dbVal($row, 'nombre')       →  $row['nombre_en'] o $row['nombre']
 *      dbVal($row, 'descripcion')  →  $row['descripcion_en'] o $row['descripcion']
 */

/**
 * Retorna texto en el idioma activo.
 * Uso: ui('Por Días', 'By Day')
 * Sin catálogos, sin archivos — los dos textos conviven en el código.
 */
function ui(string $es, string $en = ''): string
{
    if (defined('APP_LANG') && APP_LANG === 'en') {
        return $en ?: $es;
    }
    return $es;
}

function dbVal(array $row, string $campo, string $default = ''): string
{
    if (defined('APP_LANG') && APP_LANG === 'en') {
        $campoEn = $campo . '_en';
        if (!empty($row[$campoEn])) {
            return $row[$campoEn];
        }
    }
    return $row[$campo] ?? $default;
}

// ─── PROPIEDADES ──────────────────────────────────────────────────────────────

/**
 * Retorna todas las propiedades activas (cacheado).
 */
function getPropiedadesActivas(): array
{
    return dbCache('propiedades_activas', function () {
        return dbFetchAll(
            "SELECT p.*, d.slug AS destino_slug, d.nombre AS destino_nombre
             FROM propiedades p
             LEFT JOIN destinos d ON d.id = p.destino_id
             WHERE p.activo = 1
             ORDER BY p.orden ASC, p.nombre ASC"
        );
    });
}

/**
 * Retorna una propiedad por su slug.
 *
 * @param string $slug
 * @return array|null
 */
function getPropiedadBySlug(string $slug): ?array
{
    return dbCache("propiedad_{$slug}", function () use ($slug) {
        return dbFetchOne(
            "SELECT p.*, d.slug AS destino_slug, d.nombre AS destino_nombre,
                    d.id AS destino_id
             FROM propiedades p
             LEFT JOIN destinos d ON d.id = p.destino_id
             WHERE p.slug = :slug AND p.activo = 1",
            [':slug' => $slug]
        );
    }, 1800); // 30 min
}

/**
 * Retorna las imágenes de una propiedad por tipo.
 *
 * @param int    $propiedadId
 * @param string $tipo 'hero' | 'galeria' | 'card' | 'og' | 'zona' | '' (todas)
 */
function getImagenesByPropiedad(int $propiedadId, string $tipo = ''): array
{
    $sql = "SELECT * FROM propiedad_imagenes 
            WHERE propiedad_id = :id AND activa = 1";
    $params = [':id' => $propiedadId];

    if ($tipo !== '') {
        $sql .= " AND tipo = :tipo";
        $params[':tipo'] = $tipo;
    }

    $sql .= " ORDER BY orden ASC";
    return dbFetchAll($sql, $params);
}

/**
 * Retorna la primera imagen de un tipo específico, o null.
 */
function getImagenPrincipal(int $propiedadId, string $tipo = 'hero'): ?array
{
    return dbFetchOne(
        "SELECT * FROM propiedad_imagenes 
         WHERE propiedad_id = :id AND tipo = :tipo AND activa = 1
         ORDER BY orden ASC LIMIT 1",
        [':id' => $propiedadId, ':tipo' => $tipo]
    );
}

/**
 * Retorna las amenidades de una propiedad.
 */
function getAmenidadesByPropiedad(int $propiedadId): array
{
    return dbFetchAll(
        "SELECT ac.*, pa.descripcion_custom
         FROM propiedad_amenidades pa
         JOIN amenidades_catalogo ac ON ac.id = pa.amenidad_id
         WHERE pa.propiedad_id = :id AND ac.activa = 1
         ORDER BY ac.nombre ASC",
        [':id' => $propiedadId]
    );
}

/**
 * Retorna las habitaciones activas de una propiedad.
 */
function getHabitacionesByPropiedad(int $propiedadId): array
{
    return dbFetchAll(
        "SELECT * FROM habitaciones 
         WHERE propiedad_id = :id AND activa = 1
         ORDER BY orden ASC, precio_mes_1 ASC",
        [':id' => $propiedadId]
    );
}

/**
 * Retorna FAQs: globales + las de la propiedad específica.
 *
 * @param int|null $propiedadId null = solo globales
 */
function getFaqs(?int $propiedadId = null): array
{
    if ($propiedadId !== null) {
        return dbFetchAll(
            "SELECT * FROM faqs 
             WHERE (propiedad_id = :id OR propiedad_id IS NULL) AND activa = 1
             ORDER BY propiedad_id DESC, orden ASC",
            [':id' => $propiedadId]
        );
    }

    return dbFetchAll(
        "SELECT * FROM faqs WHERE propiedad_id IS NULL AND activa = 1 ORDER BY orden ASC"
    );
}

/**
 * Retorna artículos de prensa activos.
 */
function getPrensa(int $limit = 6): array
{
    return dbFetchAll(
        "SELECT * FROM prensa WHERE activo = 1 ORDER BY fecha_publicacion DESC LIMIT :limit",
        [':limit' => $limit]
    );
}

/**
 * Retorna hero slides activos.
 */
function getHeroSlides(): array
{
    return dbCache('hero_slides', function () {
        return dbFetchAll(
            "SELECT hs.*, p.slug AS propiedad_slug, p.nombre AS propiedad_nombre
             FROM hero_slides hs
             LEFT JOIN propiedades p ON p.id = hs.propiedad_id
             WHERE hs.activo = 1
             ORDER BY hs.orden ASC"
        );
    });
}

/**
 * Retorna propiedades agrupadas por destino para el navbar.
 */
function getPropiedadesPorDestino(): array
{
    return dbCache('propiedades_por_destino_v2', function () {
        $propiedades = dbFetchAll(
            "SELECT p.id, p.slug, p.nombre, p.ciudad, p.colonia,
                    d.slug AS destino_slug, d.nombre AS destino_nombre,
                    d.nombre_en AS destino_nombre_en
             FROM propiedades p
             LEFT JOIN destinos d ON d.id = p.destino_id
             WHERE p.activo = 1
             ORDER BY d.orden ASC, p.orden ASC"
        );

        $grouped = [];
        foreach ($propiedades as $prop) {
            $key = $prop['destino_slug'] ?? 'otras';
            $grouped[$key]['nombre']    = $prop['destino_nombre']    ?? 'Otras';
            $grouped[$key]['nombre_en'] = $prop['destino_nombre_en'] ?? '';
            $grouped[$key]['propiedades'][] = $prop;
        }
        return $grouped;
    });
}

// ─── SANITIZACIÓN ─────────────────────────────────────────────────────────────

/**
 * Escapa output HTML de forma segura.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitiza un string de input (trim + strip_tags).
 */
function sanitizeStr(mixed $value, int $maxLen = 255): string
{
    $str = trim(strip_tags((string)($value ?? '')));
    return mb_substr($str, 0, $maxLen, 'UTF-8');
}

/**
 * Sanitiza un email.
 */
function sanitizeEmail(mixed $value): string
{
    return strtolower(trim(filter_var((string)($value ?? ''), FILTER_SANITIZE_EMAIL)));
}

/**
 * Sanitiza un número de teléfono (solo dígitos, +, espacios, guiones).
 */
function sanitizePhone(mixed $value): string
{
    return preg_replace('/[^\d\+\-\s\(\)]/', '', (string)($value ?? ''));
}

/**
 * Valida que un email tenga formato correcto.
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// ─── FORMATOS ─────────────────────────────────────────────────────────────────

/**
 * Formatea un precio en MXN.
 *
 * @param float  $amount
 * @param string $currency
 * @param bool   $showDecimals
 */
function formatPrice(float $amount, string $currency = 'MXN', bool $showDecimals = false): string
{
    $decimals = $showDecimals ? 2 : 0;
    $formatted = number_format($amount, $decimals, '.', ',');
    return "\${$formatted} {$currency}";
}

/**
 * Genera una URL absoluta.
 */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Genera la URL de una propiedad.
 */
function propiedadUrl(string $slug): string
{
    return url($slug);
}

/**
 * Genera la URL de una imagen de upload.
 */
function uploadUrl(string $path): string
{
    return url('uploads/' . ltrim($path, '/'));
}

/**
 * Trunca un texto a N caracteres respetando palabras.
 */
function truncate(string $text, int $length = 150, string $suffix = '...'): string
{
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    $truncated = mb_substr($text, 0, $length - mb_strlen($suffix));
    $lastSpace = mb_strrpos($truncated, ' ');
    if ($lastSpace !== false) {
        $truncated = mb_substr($truncated, 0, $lastSpace);
    }
    return $truncated . $suffix;
}

// ─── SEGURIDAD ────────────────────────────────────────────────────────────────

/**
 * Verifica honeypot fields. Retorna true si el request es legítimo.
 */
function checkHoneypot(): bool
{
    $honeypotFields = [
        'website', 'confirm_email', 'phone_confirm',
        'company_url', 'full_address', 'backup_contact'
    ];

    foreach ($honeypotFields as $field) {
        if (!empty($_POST[$field])) {
            logSecurity("HONEYPOT_TRIGGERED", "Field: {$field} | IP: " . getClientIp());
            return false;
        }
    }
    return true;
}

/**
 * Valida el timing del formulario (anti-bot).
 *
 * @param string $formType 'contact' | 'quote' | 'campaign'
 */
function validateFormTiming(string $formType = 'contact'): bool
{
    if (empty($_POST['form_timestamp'])) {
        return true; // No bloquear si no hay timestamp
    }

    $minTimes = ['contact' => 3, 'quote' => 5, 'campaign' => 2];
    $minRequired = $minTimes[$formType] ?? 3;
    $elapsed = time() - (int)$_POST['form_timestamp'];

    if ($elapsed < $minRequired) {
        logSecurity("TIMING_BLOCKED", "Form: {$formType} | Elapsed: {$elapsed}s | IP: " . getClientIp());
        return false;
    }
    return true;
}

/**
 * Verifica reCAPTCHA v2.
 */
function verifyRecaptcha(?string $response): bool
{
    if (empty($response)) {
        return false;
    }

    $data = http_build_query([
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $response,
        'remoteip' => getClientIp(),
    ]);

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $data,
            'timeout' => 10,
        ]
    ]);

    $result = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);

    if ($result === false) {
        // Error de red → permitir (no penalizar al usuario)
        logSecurity("RECAPTCHA_NETWORK_ERROR", "IP: " . getClientIp());
        return true;
    }

    $json = json_decode($result, true);
    return isset($json['success']) && $json['success'] === true;
}

/**
 * Rate limiting por IP usando archivos.
 *
 * @param string $action Identificador de la acción ('send_lead', 'contact', etc.)
 * @return bool true = permitido, false = bloqueado
 */
function checkRateLimit(string $action = 'default'): bool
{
    $ip   = getClientIp();
    $key  = preg_replace('/[^a-z0-9_]/', '_', "rl_{$action}_{$ip}");
    $file = LOGS_PATH . "/{$key}.json";

    $data = ['count' => 0, 'window_start' => time()];

    if (file_exists($file)) {
        $saved = json_decode(file_get_contents($file), true);
        if ($saved && (time() - $saved['window_start']) < RATE_LIMIT_WINDOW) {
            $data = $saved;
        }
    }

    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    if ($data['count'] > RATE_LIMIT_MAX) {
        logSecurity("RATE_LIMIT_EXCEEDED", "Action: {$action} | IP: {$ip} | Count: {$data['count']}");
        return false;
    }
    return true;
}

/**
 * Genera un CSRF token y lo guarda en sesión.
 */
function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida el CSRF token del formulario.
 */
function validateCsrfToken(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $valid = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    if (!$valid) {
        logSecurity("CSRF_INVALID", "IP: " . getClientIp());
    }
    return $valid;
}

/**
 * Retorna la IP real del cliente (considerando proxies).
 */
function getClientIp(): string
{
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

// ─── LOGGING ──────────────────────────────────────────────────────────────────

/**
 * Escribe en el log de seguridad.
 */
function logSecurity(string $event, string $detail = ''): void
{
    $line = sprintf("[%s] %s | %s\n", date('Y-m-d H:i:s'), $event, $detail);
    @file_put_contents(LOGS_PATH . '/security.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Escribe en el log general de la app.
 */
function logApp(string $level, string $message, array $context = []): void
{
    $ctx  = empty($context) ? '' : ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    $line = sprintf("[%s] [%s] %s%s\n", date('Y-m-d H:i:s'), strtoupper($level), $message, $ctx);
    @file_put_contents(LOGS_PATH . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

// ─── UTILIDADES ───────────────────────────────────────────────────────────────

/**
 * Retorna la URL canónica actual.
 */
function currentUrl(): string
{
    $proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'parklife.mx';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    return "{$proto}://{$host}{$uri}";
}

/**
 * Detecta si el request actual es AJAX.
 */
function isAjax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

/**
 * Responde con JSON y termina la ejecución.
 */
function jsonResponse(array $data, int $statusCode = 200): never
{
    // Limpiar cualquier output espurio (warnings, notices) antes del JSON
    if (ob_get_level()) ob_clean();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Redirige a una URL y termina.
 */
function redirect(string $url, int $code = 302): never
{
    header("Location: {$url}", true, $code);
    exit;
}

/**
 * Genera una miniatura de imagen usando GD.
 *
 * @param string $sourcePath Ruta absoluta del archivo origen
 * @param string $destPath   Ruta absoluta del archivo destino
 * @param int    $targetW    Ancho objetivo
 * @param int    $targetH    Alto objetivo
 * @param bool   $crop       Si se recorta al aspecto exacto
 * @return bool
 */
function resizeImage(string $sourcePath, string $destPath, int $targetW, int $targetH, bool $crop = true): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }

    $info = @getimagesize($sourcePath);
    if (!$info) {
        return false;
    }

    [$srcW, $srcH, $type] = $info;

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
        IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
        IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
        default        => false,
    };

    if (!$src) {
        return false;
    }

    if ($crop) {
        // Calcular crop centrado
        $srcAspect = $srcW / $srcH;
        $tgtAspect = $targetW / $targetH;

        if ($srcAspect > $tgtAspect) {
            $cropH = $srcH;
            $cropW = (int)($srcH * $tgtAspect);
        } else {
            $cropW = $srcW;
            $cropH = (int)($srcW / $tgtAspect);
        }

        $cropX = (int)(($srcW - $cropW) / 2);
        $cropY = (int)(($srcH - $cropH) / 2);
    } else {
        // Sin crop: escalar manteniendo aspecto
        $ratio  = min($targetW / $srcW, $targetH / $srcH);
        $targetW = (int)($srcW * $ratio);
        $targetH = (int)($srcH * $ratio);
        $cropX = $cropY = $cropW = $cropH = 0;
    }

    $dst = imagecreatetruecolor($targetW, $targetH);

    // Preservar transparencia para PNG/WebP
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
    }

    if ($crop) {
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);
    } else {
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);
    }

    // Guardar en WebP (mejor compresión)
    $ext    = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
    $result = match ($ext) {
        'webp'  => imagewebp($dst, $destPath, 85),
        'png'   => imagepng($dst, $destPath, 8),
        default => imagejpeg($dst, $destPath, 85),
    };

    imagedestroy($src);
    imagedestroy($dst);

    return (bool)$result;
}

/**
 * Genera un slug URL-friendly a partir de un string.
 */
function slugify(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $map  = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
              'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', trim($text));
    return trim($text, '-');
}