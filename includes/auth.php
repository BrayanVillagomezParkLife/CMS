<?php
declare(strict_types=1);

/**
 * ============================================================
 * PARK LIFE PROPERTIES — auth.php v3
 * Roles dinámicos desde BD, permisos granulares, activación
 * ============================================================
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// ═══════════════════════════════════════════════════════════════════════════
// MÓDULOS DEL SISTEMA (referencia canónica)
// ═══════════════════════════════════════════════════════════════════════════

function getSystemModules(): array
{
    return [
        'dashboard'         => 'Dashboard',
        'propiedades'       => 'Propiedades',
        'habitaciones'      => 'Habitaciones',
        'precios'           => 'Precios',
        'cotizador'         => 'Cotizador',
        'gestion-cotizaciones' => 'Gestión Cotizaciones',
        'imagenes'          => 'Imágenes',
        'imagenes-auditoria'=> 'Auditoría img',
        'amenidades'        => 'Amenidades',
        'faqs'              => 'FAQs',
        'hero'              => 'Hero Slides',
        'prensa'            => 'Prensa',
        'leads'             => 'Leads',
        'strings'           => 'Textos del Sitio',
        'config'            => 'Configuración',
        'usuarios'          => 'Usuarios',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// ROLES DESDE BD
// ═══════════════════════════════════════════════════════════════════════════

function getRoleBySlug(string $slug): ?array
{
    static $cache = [];
    if (isset($cache[$slug])) return $cache[$slug];
    try {
        $role = dbFetchOne("SELECT * FROM admin_roles WHERE slug = :s", [':s' => $slug]);
        if ($role) {
            $role['permisos_array'] = json_decode($role['permisos'], true) ?: [];
            $cache[$slug] = $role;
        }
        return $role;
    } catch (\Throwable $e) { return null; }
}

function getAllRoles(): array
{
    try {
        $roles = dbFetchAll("SELECT * FROM admin_roles ORDER BY orden, nombre");
        foreach ($roles as &$r) $r['permisos_array'] = json_decode($r['permisos'], true) ?: [];
        return $roles;
    } catch (\Throwable $e) { return []; }
}

function getUserPermissions(?string $rolSlug = null, ?string $permisosOverrideJson = null): array
{
    $rolSlug = $rolSlug ?? currentAdminRole();
    $modules = getSystemModules();
    $noAccess = ['ver' => false, 'editar' => false, 'eliminar' => false];

    $role = getRoleBySlug($rolSlug);
    $basePermisos = $role ? $role['permisos_array'] : [];

    $result = [];
    foreach ($modules as $key => $label) {
        $result[$key] = $basePermisos[$key] ?? $noAccess;
        foreach (['ver', 'editar', 'eliminar'] as $a) {
            $result[$key][$a] = (bool)($result[$key][$a] ?? false);
        }
    }

    if ($permisosOverrideJson) {
        $overrides = json_decode($permisosOverrideJson, true);
        if (is_array($overrides)) {
            foreach ($overrides as $modulo => $perms) {
                if (isset($result[$modulo]) && is_array($perms)) {
                    foreach (['ver', 'editar', 'eliminar'] as $a) {
                        if (isset($perms[$a])) $result[$modulo][$a] = (bool)$perms[$a];
                    }
                }
            }
        }
    }
    return $result;
}

function canAccess(string $modulo, string $accion = 'ver'): bool
{
    $permisos = getUserPermissions(currentAdminRole(), $_SESSION['admin_permisos'] ?? null);
    return (bool)($permisos[$modulo][$accion] ?? false);
}

function requirePermission(string $modulo, string $accion = 'ver'): void
{
    requireLogin();
    if (!canAccess($modulo, $accion)) {
        http_response_code(403);
        if (file_exists(BASE_PATH . '/admin/403.php')) { include BASE_PATH . '/admin/403.php'; }
        else { echo '<h1>403 — Acceso denegado</h1>'; }
        exit;
    }
}

function getVisibleModules(): array
{
    $permisos = getUserPermissions(currentAdminRole(), $_SESSION['admin_permisos'] ?? null);
    $visible = [];
    foreach ($permisos as $modulo => $acciones) {
        if (!empty($acciones['ver'])) $visible[] = $modulo;
    }
    return $visible;
}

/**
 * Retorna la URL de la primera página accesible para el usuario actual.
 */
function getFirstAccessiblePage(): string
{
    $moduleToFile = [
        'dashboard'         => 'index.php',
        'propiedades'       => 'propiedades.php',
        'habitaciones'      => 'habitaciones.php',
        'precios'           => 'precios.php',
        'cotizador'         => 'cotizador.php',
        'gestion-cotizaciones' => 'Gestión Cotizaciones',
        'imagenes'          => 'imagenes.php',
        'imagenes-auditoria'=> 'imagenes-auditoria.php',
        'amenidades'        => 'amenidades.php',
        'faqs'              => 'faqs.php',
        'hero'              => 'hero.php',
        'prensa'            => 'prensa.php',
        'leads'             => 'leads.php',
        'strings'           => 'strings.php',
        'config'            => 'config.php',
        'usuarios'          => 'usuarios.php',
    ];

    foreach ($moduleToFile as $modulo => $file) {
        if (canAccess($modulo, 'ver')) return $file;
    }

    return 'mi-perfil.php'; // Último recurso
}

function getRolLabel(string $slug): array
{
    $role = getRoleBySlug($slug);
    if ($role) return ['label' => $role['nombre'], 'color' => $role['color'] ?? 'bg-gray-100 text-gray-600'];
    return ['label' => ucfirst($slug), 'color' => 'bg-gray-100 text-gray-600'];
}

// ═══════════════════════════════════════════════════════════════════════════
// SESIONES
// ═══════════════════════════════════════════════════════════════════════════

function initAdminSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;
    session_name(ADMIN_SESSION_NAME);
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'domain' => '',
        'secure' => !APP_DEBUG, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function isLoggedIn(): bool
{
    initAdminSession();
    if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_last_activity'])) return false;
    if ((time() - $_SESSION['admin_last_activity']) > ADMIN_SESSION_TIMEOUT) { logout(); return false; }

    $now = time();
    if (($now - ($_SESSION['admin_checked_at'] ?? 0)) > 300) {
        $user = dbFetchOne("SELECT id, activo, rol, permisos FROM admin_usuarios WHERE id = :id", [':id' => $_SESSION['admin_id']]);
        if (!$user || !$user['activo']) { logout(); return false; }
        $_SESSION['admin_rol']      = $user['rol'];
        $_SESSION['admin_permisos'] = $user['permisos'];
        $_SESSION['admin_checked_at'] = $now;
    }
    $_SESSION['admin_last_activity'] = time();
    return true;
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin');
        redirect('/admin/login.php?redirect=' . $redirect);
    }
}

function requireRole(string $minRole = 'editor'): void
{
    requireLogin();
    $userRole = getRoleBySlug($_SESSION['admin_rol'] ?? 'viewer');
    $requiredRole = getRoleBySlug($minRole);
    if (($userRole['orden'] ?? 99) > ($requiredRole['orden'] ?? 1)) {
        http_response_code(403);
        include BASE_PATH . '/admin/403.php';
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// LOGIN / LOGOUT
// ═══════════════════════════════════════════════════════════════════════════

function attemptLogin(string $email, string $password): array
{
    $ip = getClientIp();
    $lockFile = LOGS_PATH . '/' . preg_replace('/[^a-z0-9_]/', '_', "login_fail_{$ip}") . '.json';
    $lockData = ['count' => 0, 'window_start' => time()];
    if (file_exists($lockFile)) {
        $saved = json_decode(file_get_contents($lockFile), true);
        if ($saved && (time() - $saved['window_start']) < 900) $lockData = $saved;
    }
    if ($lockData['count'] >= 10) {
        logSecurity("LOGIN_LOCKED", "IP: {$ip}");
        return ['success' => false, 'error' => 'Demasiados intentos. Espera 15 minutos.'];
    }

    $user = dbFetchOne("SELECT * FROM admin_usuarios WHERE email = :email AND activo = 1", [':email' => strtolower(trim($email))]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        $lockData['count']++;
        @file_put_contents($lockFile, json_encode($lockData), LOCK_EX);
        logSecurity("LOGIN_FAILED", "Email: {$email} | IP: {$ip}");
        sleep(1);
        return ['success' => false, 'error' => 'Email o contraseña incorrectos.'];
    }

    if (empty($user['activado_en']) && !empty($user['token_activacion'])) {
        return ['success' => false, 'error' => 'Tu cuenta aún no ha sido activada. Revisa tu correo.'];
    }

    initAdminSession();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_nombre'] = $user['nombre'];
    $_SESSION['admin_email'] = $user['email'];
    $_SESSION['admin_rol'] = $user['rol'];
    $_SESSION['admin_permisos'] = $user['permisos'] ?? null;
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['admin_checked_at'] = time();

    dbExecute("UPDATE admin_usuarios SET ultimo_login = NOW() WHERE id = :id", [':id' => $user['id']]);
    @unlink($lockFile);
    logUserAction($user['id'], 'login', 'Login exitoso');

    if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
        $h = password_hash($password, PASSWORD_ARGON2ID);
        dbExecute("UPDATE admin_usuarios SET password_hash = :hash WHERE id = :id", [':hash' => $h, ':id' => $user['id']]);
    }
    return ['success' => true];
}

function logout(): void
{
    initAdminSession();
    if (isset($_SESSION['admin_id'])) logUserAction((int)$_SESSION['admin_id'], 'logout', 'Cierre de sesión');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ═══════════════════════════════════════════════════════════════════════════
// DATOS DEL USUARIO ACTUAL
// ═══════════════════════════════════════════════════════════════════════════

function currentAdminId(): int { return (int)($_SESSION['admin_id'] ?? 0); }
function currentAdminName(): string { return $_SESSION['admin_nombre'] ?? 'Admin'; }
function currentAdminRole(): string { return $_SESSION['admin_rol'] ?? 'viewer'; }
function currentAdminEmail(): string { return $_SESSION['admin_email'] ?? ''; }
function isSuperAdmin(): bool { return currentAdminRole() === 'superadmin'; }
function isAdmin(): bool { return in_array(currentAdminRole(), ['superadmin', 'admin']); }

// ═══════════════════════════════════════════════════════════════════════════
// GESTIÓN DE USUARIOS
// ═══════════════════════════════════════════════════════════════════════════

function createAdminUser(string $nombre, string $email, string $password, string $rol = 'editor', bool $requireActivation = true): array
{
    $role = getRoleBySlug($rol);
    if (!$role) return ['success' => false, 'error' => 'Rol no existe.'];
    if (!isValidEmail($email)) return ['success' => false, 'error' => 'Email inválido.'];
    if (strlen($password) < 10) return ['success' => false, 'error' => 'Mínimo 10 caracteres.'];

    $exists = dbFetchValue("SELECT COUNT(*) FROM admin_usuarios WHERE email = :email", [':email' => strtolower($email)]);
    if ($exists > 0) return ['success' => false, 'error' => 'Ya existe un usuario con ese email.'];

    $hash = password_hash($password, PASSWORD_ARGON2ID);
    $token = $requireActivation ? bin2hex(random_bytes(32)) : null;

    $id = dbInsert(
        "INSERT INTO admin_usuarios (nombre, email, password_hash, rol, activo, token_activacion, activado_en, invitado_por)
         VALUES (:n, :e, :h, :r, :a, :t, :act, :inv)",
        [':n' => $nombre, ':e' => strtolower($email), ':h' => $hash, ':r' => $rol,
         ':a' => $requireActivation ? 0 : 1, ':t' => $token,
         ':act' => $requireActivation ? null : date('Y-m-d H:i:s'), ':inv' => currentAdminId() ?: null]
    );
    logUserAction(currentAdminId(), 'create_user', "Creó usuario: {$email} (rol: {$rol})");
    return ['success' => true, 'id' => $id, 'token' => $token];
}

function activateAccount(string $token): array
{
    if (strlen($token) < 32) return ['success' => false, 'error' => 'Token inválido.'];
    $user = dbFetchOne("SELECT id, email, nombre FROM admin_usuarios WHERE token_activacion = :t AND activado_en IS NULL", [':t' => $token]);
    if (!$user) return ['success' => false, 'error' => 'Token inválido o cuenta ya activada.'];
    dbExecute("UPDATE admin_usuarios SET activo = 1, activado_en = NOW(), token_activacion = NULL WHERE id = :id", [':id' => $user['id']]);
    logUserAction($user['id'], 'activate', 'Cuenta activada');
    return ['success' => true, 'nombre' => $user['nombre'], 'email' => $user['email']];
}

function generatePasswordResetToken(string $email): array
{
    $user = dbFetchOne("SELECT id, nombre FROM admin_usuarios WHERE email = :e AND activo = 1", [':e' => strtolower(trim($email))]);
    if (!$user) return ['success' => true, 'message' => 'Si el email existe, recibirás instrucciones.'];
    $token = bin2hex(random_bytes(32));
    dbExecute("UPDATE admin_usuarios SET token_reset = :t, token_reset_expira = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = :id",
        [':t' => $token, ':id' => $user['id']]);
    logUserAction($user['id'], 'reset_password_request', 'Solicitó reset');
    return ['success' => true, 'token' => $token, 'nombre' => $user['nombre'], 'email' => strtolower(trim($email))];
}

function resetPasswordWithToken(string $token, string $newPassword): array
{
    if (strlen($newPassword) < 10) return ['success' => false, 'error' => 'Mínimo 10 caracteres.'];
    $user = dbFetchOne("SELECT id FROM admin_usuarios WHERE token_reset = :t AND token_reset_expira > NOW() AND activo = 1", [':t' => $token]);
    if (!$user) return ['success' => false, 'error' => 'Token inválido o expirado.'];
    $h = password_hash($newPassword, PASSWORD_ARGON2ID);
    dbExecute("UPDATE admin_usuarios SET password_hash = :h, token_reset = NULL, token_reset_expira = NULL WHERE id = :id", [':h' => $h, ':id' => $user['id']]);
    logUserAction($user['id'], 'reset_password', 'Contraseña reseteada');
    return ['success' => true];
}

function changeAdminPassword(int $userId, string $currentPassword, string $newPassword): array
{
    if (strlen($newPassword) < 10) return ['success' => false, 'error' => 'Mínimo 10 caracteres.'];
    $user = dbFetchOne("SELECT * FROM admin_usuarios WHERE id = :id AND activo = 1", [':id' => $userId]);
    if (!$user || !password_verify($currentPassword, $user['password_hash']))
        return ['success' => false, 'error' => 'Contraseña actual incorrecta.'];
    $h = password_hash($newPassword, PASSWORD_ARGON2ID);
    dbExecute("UPDATE admin_usuarios SET password_hash = :hash WHERE id = :id", [':hash' => $h, ':id' => $userId]);
    logUserAction($userId, 'change_password', 'Cambió su contraseña');
    return ['success' => true];
}

function updateAdminUser(int $userId, array $data): array
{
    $user = dbFetchOne("SELECT * FROM admin_usuarios WHERE id = :id", [':id' => $userId]);
    if (!$user) return ['success' => false, 'error' => 'Usuario no encontrado.'];
    $sets = []; $params = [':id' => $userId];
    if (isset($data['nombre']))  { $sets[] = 'nombre = :nombre';  $params[':nombre'] = sanitizeStr($data['nombre']); }
    if (isset($data['email'])) {
        $email = strtolower(sanitizeEmail($data['email']));
        if ($email !== $user['email']) {
            $ex = dbFetchValue("SELECT COUNT(*) FROM admin_usuarios WHERE email = :e AND id != :uid", [':e' => $email, ':uid' => $userId]);
            if ($ex > 0) return ['success' => false, 'error' => 'Email ya en uso.'];
            $sets[] = 'email = :email'; $params[':email'] = $email;
        }
    }
    if (isset($data['rol']))    { $sets[] = 'rol = :rol';       $params[':rol'] = $data['rol']; }
    if (isset($data['activo'])) { $sets[] = 'activo = :activo'; $params[':activo'] = (int)$data['activo']; }
    if (array_key_exists('permisos', $data)) {
        $sets[] = 'permisos = :permisos';
        $params[':permisos'] = is_string($data['permisos']) ? $data['permisos'] : json_encode($data['permisos']);
    }
    if (isset($data['notas'])) { $sets[] = 'notas = :notas'; $params[':notas'] = sanitizeStr($data['notas'], 500); }
    if (empty($sets)) return ['success' => false, 'error' => 'Sin cambios.'];
    dbExecute("UPDATE admin_usuarios SET " . implode(', ', $sets) . " WHERE id = :id", $params);
    logUserAction(currentAdminId(), 'edit_user', "Editó usuario #{$userId}");
    return ['success' => true];
}

function forceResetPassword(int $userId, string $newPassword): array
{
    if (strlen($newPassword) < 10) return ['success' => false, 'error' => 'Mínimo 10 caracteres.'];
    $h = password_hash($newPassword, PASSWORD_ARGON2ID);
    dbExecute("UPDATE admin_usuarios SET password_hash = :h WHERE id = :id", [':h' => $h, ':id' => $userId]);
    logUserAction(currentAdminId(), 'force_reset_password', "Reseteó contraseña #{$userId}");
    return ['success' => true];
}

// ═══════════════════════════════════════════════════════════════════════════
// AUDITORÍA
// ═══════════════════════════════════════════════════════════════════════════

function logUserAction(int $adminId, string $accion, string $detalle = ''): void
{
    try {
        dbInsert("INSERT INTO admin_user_log (admin_id, accion, detalle, ip, user_agent) VALUES (:aid, :acc, :det, :ip, :ua)",
            [':aid' => $adminId, ':acc' => $accion, ':det' => $detalle,
             ':ip' => getClientIp(), ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    } catch (\Throwable $e) {}
}