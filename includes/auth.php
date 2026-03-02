<?php
declare(strict_types=1);

/**
 * ============================================================
 * PARK LIFE PROPERTIES — auth.php
 * Autenticación segura para el panel de administración
 * ============================================================
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// ─── Inicialización de sesión segura ──────────────────────────────────────────

function initAdminSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_name(ADMIN_SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,                  // Expira al cerrar el navegador
        'path'     => '/admin',
        'domain'   => '',
        'secure'   => !APP_DEBUG,         // Solo HTTPS en producción
        'httponly' => true,               // No accesible desde JS
        'samesite' => 'Strict',
    ]);

    session_start();
}

// ─── AUTENTICACIÓN ────────────────────────────────────────────────────────────

/**
 * Verifica si hay una sesión de admin activa y válida.
 */
function isLoggedIn(): bool
{
    initAdminSession();

    if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_last_activity'])) {
        return false;
    }

    // Timeout por inactividad
    if ((time() - $_SESSION['admin_last_activity']) > ADMIN_SESSION_TIMEOUT) {
        logout();
        return false;
    }

    // Verificar que el usuario sigue activo en BD (cada 5 minutos)
    $now = time();
    if (($now - ($_SESSION['admin_checked_at'] ?? 0)) > 300) {
        $user = dbFetchOne(
            "SELECT id, activo FROM admin_usuarios WHERE id = :id",
            [':id' => $_SESSION['admin_id']]
        );
        if (!$user || !$user['activo']) {
            logout();
            return false;
        }
        $_SESSION['admin_checked_at'] = $now;
    }

    $_SESSION['admin_last_activity'] = time();
    return true;
}

/**
 * Redirige al login si el usuario no está autenticado.
 * Usar al inicio de cada página del admin.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin');
        redirect('/admin/login.php?redirect=' . $redirect);
    }
}

/**
 * Verifica que el usuario tenga el rol mínimo requerido.
 */
function requireRole(string $minRole = 'editor'): void
{
    requireLogin();

    $roles = ['editor' => 1, 'superadmin' => 2];
    $userLevel = $roles[$_SESSION['admin_rol'] ?? 'editor'] ?? 0;
    $required  = $roles[$minRole] ?? 1;

    if ($userLevel < $required) {
        http_response_code(403);
        include BASE_PATH . '/admin/403.php';
        exit;
    }
}

/**
 * Intenta hacer login con email y contraseña.
 *
 * @return array ['success' => bool, 'error' => string]
 */
function attemptLogin(string $email, string $password): array
{
    // Rate limiting: máx 10 intentos fallidos por IP en 15 minutos
    $ip      = getClientIp();
    $lockKey = "login_fail_{$ip}";
    $lockFile = LOGS_PATH . '/' . preg_replace('/[^a-z0-9_]/', '_', $lockKey) . '.json';

    $lockData = ['count' => 0, 'window_start' => time()];
    if (file_exists($lockFile)) {
        $saved = json_decode(file_get_contents($lockFile), true);
        if ($saved && (time() - $saved['window_start']) < 900) {
            $lockData = $saved;
        }
    }

    if ($lockData['count'] >= 10) {
        logSecurity("LOGIN_LOCKED", "IP: {$ip} | Too many attempts");
        return ['success' => false, 'error' => 'Demasiados intentos. Espera 15 minutos.'];
    }

    // Buscar usuario
    $user = dbFetchOne(
        "SELECT * FROM admin_usuarios WHERE email = :email AND activo = 1",
        [':email' => strtolower(trim($email))]
    );

    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Registrar intento fallido
        $lockData['count']++;
        @file_put_contents($lockFile, json_encode($lockData), LOCK_EX);
        logSecurity("LOGIN_FAILED", "Email: {$email} | IP: {$ip}");

        // Delay para dificultar brute-force
        sleep(1);
        return ['success' => false, 'error' => 'Email o contraseña incorrectos.'];
    }

    // Éxito: iniciar sesión
    initAdminSession();
    session_regenerate_id(true);

    $_SESSION['admin_id']            = $user['id'];
    $_SESSION['admin_nombre']        = $user['nombre'];
    $_SESSION['admin_email']         = $user['email'];
    $_SESSION['admin_rol']           = $user['rol'];
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['admin_checked_at']    = time();

    // Actualizar último login
    dbExecute(
        "UPDATE admin_usuarios SET ultimo_login = NOW() WHERE id = :id",
        [':id' => $user['id']]
    );

    // Limpiar lock de IP
    @unlink($lockFile);
    logSecurity("LOGIN_SUCCESS", "User: {$user['email']} | IP: {$ip}");

    // Rehash si es necesario (migración futura de algoritmo)
    if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID);
        dbExecute(
            "UPDATE admin_usuarios SET password_hash = :hash WHERE id = :id",
            [':hash' => $newHash, ':id' => $user['id']]
        );
    }

    return ['success' => true];
}

/**
 * Cierra la sesión del admin.
 */
function logout(): void
{
    initAdminSession();

    if (isset($_SESSION['admin_email'])) {
        logSecurity("LOGOUT", "User: {$_SESSION['admin_email']} | IP: " . getClientIp());
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

// ─── DATOS DEL USUARIO ACTUAL ─────────────────────────────────────────────────

/**
 * Retorna el ID del admin logueado.
 */
function currentAdminId(): int
{
    return (int)($_SESSION['admin_id'] ?? 0);
}

/**
 * Retorna el nombre del admin logueado.
 */
function currentAdminName(): string
{
    return $_SESSION['admin_nombre'] ?? 'Admin';
}

/**
 * Retorna el rol del admin logueado.
 */
function currentAdminRole(): string
{
    return $_SESSION['admin_rol'] ?? 'editor';
}

/**
 * Verifica si el admin actual es superadmin.
 */
function isSuperAdmin(): bool
{
    return currentAdminRole() === 'superadmin';
}

// ─── GESTIÓN DE USUARIOS ──────────────────────────────────────────────────────

/**
 * Crea un nuevo usuario administrador.
 *
 * @return array ['success' => bool, 'id' => int, 'error' => string]
 */
function createAdminUser(string $nombre, string $email, string $password, string $rol = 'editor'): array
{
    if (!in_array($rol, ['editor', 'superadmin'])) {
        return ['success' => false, 'error' => 'Rol inválido.'];
    }

    if (!isValidEmail($email)) {
        return ['success' => false, 'error' => 'Email inválido.'];
    }

    if (strlen($password) < 10) {
        return ['success' => false, 'error' => 'La contraseña debe tener al menos 10 caracteres.'];
    }

    // Verificar que el email no exista
    $exists = dbFetchValue(
        "SELECT COUNT(*) FROM admin_usuarios WHERE email = :email",
        [':email' => strtolower($email)]
    );

    if ($exists > 0) {
        return ['success' => false, 'error' => 'Ya existe un usuario con ese email.'];
    }

    $hash = password_hash($password, PASSWORD_ARGON2ID);

    $id = dbInsert(
        "INSERT INTO admin_usuarios (nombre, email, password_hash, rol) VALUES (:n, :e, :h, :r)",
        [':n' => $nombre, ':e' => strtolower($email), ':h' => $hash, ':r' => $rol]
    );

    logApp('info', "Admin user created: {$email} | Role: {$rol}");
    return ['success' => true, 'id' => $id];
}

/**
 * Cambia la contraseña de un usuario admin.
 */
function changeAdminPassword(int $userId, string $currentPassword, string $newPassword): array
{
    if (strlen($newPassword) < 10) {
        return ['success' => false, 'error' => 'La nueva contraseña debe tener al menos 10 caracteres.'];
    }

    $user = dbFetchOne(
        "SELECT * FROM admin_usuarios WHERE id = :id AND activo = 1",
        [':id' => $userId]
    );

    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Contraseña actual incorrecta.'];
    }

    $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
    dbExecute(
        "UPDATE admin_usuarios SET password_hash = :hash WHERE id = :id",
        [':hash' => $hash, ':id' => $userId]
    );

    logApp('info', "Password changed for user ID: {$userId}");
    return ['success' => true];
}
