<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

// Solo admin/superadmin pueden ver usuarios
requirePermission('usuarios', 'ver');

$csrf   = adminCsrf();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ═══ ACCIONES POST ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('usuarios.php', 'error', 'Token inválido.');
    }

    $postAction = $_POST['action'] ?? '';

    // ── Crear usuario ──
    if ($postAction === 'create') {
        requirePermission('usuarios', 'editar');

        $nombre   = sanitizeStr($_POST['nombre'] ?? '');
        $email    = sanitizeEmail($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rol      = $_POST['rol'] ?? 'editor';
        $notas    = sanitizeStr($_POST['notas'] ?? '', 500);
        $sendEmail = !empty($_POST['enviar_email']);

        // Validar que no se cree un rol superior al propio
        if (!isSuperAdmin() && $rol === 'superadmin') {
            adminRedirect('usuarios.php', 'error', 'No puedes crear un superadmin.');
        }

        $result = createAdminUser($nombre, $email, $password, $rol, $sendEmail);

        if ($result['success']) {
            // Guardar notas
            if ($notas) {
                dbExecute("UPDATE admin_usuarios SET notas = :n WHERE id = :id",
                    [':n' => $notas, ':id' => $result['id']]);
            }

            // Enviar email de activación
            if ($sendEmail && !empty($result['token'])) {
                $activationUrl = BASE_URL . '/admin/activar-cuenta.php?t=' . $result['token'];
                $sent = sendActivationEmail($email, $nombre, $activationUrl, $password);

                if ($sent) {
                    adminRedirect('usuarios.php', 'success',
                        "Usuario creado. Email enviado a {$email}. Contraseña temporal: {$password}");
                } else {
                    adminRedirect('usuarios.php', 'warning',
                        "Usuario creado pero NO se pudo enviar email. Contraseña: {$password} | Link: {$activationUrl}");
                }
            }

            adminRedirect('usuarios.php', 'success', "Usuario creado y activado. Contraseña: {$password}");
        }

        adminRedirect('usuarios.php?action=new', 'error', $result['error'] ?? 'Error al crear.');
    }

    // ── Editar usuario ──
    if ($postAction === 'update') {
        requirePermission('usuarios', 'editar');
        $uid = (int)$_POST['user_id'];

        $data = ['nombre' => $_POST['nombre'] ?? '', 'email' => $_POST['email'] ?? ''];

        if (!empty($_POST['rol'])) {
            if (!isSuperAdmin() && $_POST['rol'] === 'superadmin') {
                adminRedirect("usuarios.php?action=edit&id={$uid}", 'error', 'No puedes asignar superadmin.');
            }
            $data['rol'] = $_POST['rol'];
        }

        $data['activo'] = isset($_POST['activo']) ? 1 : 0;

        if (!empty($_POST['notas'])) $data['notas'] = $_POST['notas'];

        $result = updateAdminUser($uid, $data);
        adminRedirect('usuarios.php', $result['success'] ? 'success' : 'error',
            $result['success'] ? 'Usuario actualizado.' : ($result['error'] ?? 'Error.'));
    }

    // ── Reset contraseña forzado ──
    if ($postAction === 'force_reset') {
        requirePermission('usuarios', 'editar');
        $uid = (int)$_POST['user_id'];
        $newPass = $_POST['new_password'] ?? '';

        $result = forceResetPassword($uid, $newPass);
        adminRedirect("usuarios.php?action=edit&id={$uid}",
            $result['success'] ? 'success' : 'error',
            $result['success']
                ? "Contraseña actualizada. Nueva contraseña: {$newPass} — Cópiala antes de cerrar este mensaje."
                : ($result['error'] ?? 'Error.'));
    }

    // ── Desactivar / Activar ──
    if ($postAction === 'toggle_active') {
        requirePermission('usuarios', 'editar');
        $uid = (int)$_POST['user_id'];
        $newState = (int)$_POST['new_state'];

        // No auto-desactivarse
        if ($uid === currentAdminId() && $newState === 0) {
            adminRedirect('usuarios.php', 'error', 'No puedes desactivarte a ti mismo.');
        }

        updateAdminUser($uid, ['activo' => $newState]);
        adminRedirect('usuarios.php', 'success',
            $newState ? 'Usuario activado.' : 'Usuario desactivado.');
    }

    // ── Eliminar ──
    if ($postAction === 'delete') {
        requirePermission('usuarios', 'eliminar');
        $uid = (int)$_POST['user_id'];

        if ($uid === currentAdminId()) {
            adminRedirect('usuarios.php', 'error', 'No puedes eliminarte a ti mismo.');
        }

        dbExecute("DELETE FROM admin_usuarios WHERE id = ?", [$uid]);
        logUserAction(currentAdminId(), 'delete_user', "Eliminó usuario #{$uid}");
        adminRedirect('usuarios.php', 'success', 'Usuario eliminado.');
    }

    // ── Reenviar activación ──
    if ($postAction === 'resend_activation') {
        requirePermission('usuarios', 'editar');
        $uid = (int)$_POST['user_id'];
        $user = dbFetchOne("SELECT * FROM admin_usuarios WHERE id = ?", [$uid]);

        if ($user && empty($user['activado_en'])) {
            $token = bin2hex(random_bytes(32));
            dbExecute("UPDATE admin_usuarios SET token_activacion = :t WHERE id = :id",
                [':t' => $token, ':id' => $uid]);
            $url = BASE_URL . '/admin/activar-cuenta.php?t=' . $token;
            $sent = sendActivationEmail($user['email'], $user['nombre'], $url);

            if ($sent) {
                adminRedirect('usuarios.php', 'success', 'Email de activación reenviado.');
            } else {
                adminRedirect('usuarios.php', 'warning', "No se pudo enviar email. Link: {$url}");
            }
        }
        adminRedirect('usuarios.php', 'error', 'Usuario no encontrado o ya activado.');
    }
}

// ═══ HELPER: Enviar email de activación ═══════════════════════════════════════
function sendActivationEmail(string $email, string $nombre, string $url, string $password = ''): bool
{
    try {
        // Intentar usar MailService si existe
        $mailServiceFile = __DIR__ . '/../services/MailService.php';
        if (file_exists($mailServiceFile)) {
            require_once $mailServiceFile;
            $ms = new MailService();

            $passLine = $password ? "<p><strong>Contraseña temporal:</strong> {$password}</p>" : '';
            $body = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>
                    <div style='background:#202944;padding:30px;text-align:center;border-radius:12px 12px 0 0'>
                        <h1 style='color:#fff;margin:0;font-size:24px'>Park Life Properties</h1>
                        <p style='color:#BAC4B9;margin:8px 0 0;font-size:14px'>Panel de Administración</p>
                    </div>
                    <div style='background:#fff;padding:30px;border:1px solid #e5e7eb;border-top:none'>
                        <h2 style='color:#202944;margin:0 0 16px'>¡Hola {$nombre}!</h2>
                        <p style='color:#374151;line-height:1.6'>Se ha creado tu cuenta en el panel de administración de Park Life Properties. Para activarla, haz clic en el botón:</p>
                        {$passLine}
                        <div style='text-align:center;margin:24px 0'>
                            <a href='{$url}'
                               style='display:inline-block;background:#202944;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:600;font-size:16px'>
                                Activar mi cuenta
                            </a>
                        </div>
                        <p style='color:#9ca3af;font-size:12px;margin-top:24px'>Si no solicitaste esta cuenta, ignora este email. El enlace expira cuando se use por primera vez.</p>
                    </div>
                    <div style='text-align:center;padding:16px;color:#9ca3af;font-size:11px'>
                        Park Life Properties © " . date('Y') . "
                    </div>
                </div>";

            return $ms->sendRaw($email, 'Activa tu cuenta — Park Life Admin', $body);
        }

        // Fallback: mail() nativo
        $headers  = "From: Park Life Admin <" . cfg('email_admin', 'noreply@parklife.mx') . ">\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        return mail($email, 'Activa tu cuenta - Park Life Admin',
            "<p>Hola {$nombre}, activa tu cuenta: <a href='{$url}'>{$url}</a></p>", $headers);

    } catch (\Throwable $e) {
        logApp('error', "sendActivationEmail failed: " . $e->getMessage());
        return false;
    }
}

// ═══ FORMULARIO: NUEVO USUARIO ═══════════════════════════════════════════════
if ($action === 'new') {
    requirePermission('usuarios', 'editar');
    adminLayoutOpen('Nuevo Usuario');
    $allRoles = getAllRoles();
    // Si no es superadmin, no puede asignar superadmin
    $rolesDisponibles = isSuperAdmin() ? $allRoles : array_filter($allRoles, fn($r) => $r['slug'] !== 'superadmin');
    ?>

    <a href="usuarios.php" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-pk mb-4">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver
    </a>

    <form method="post" class="max-w-2xl">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="create">

        <div class="card space-y-5">
            <h2 class="font-bold text-gray-800 flex items-center gap-2">
                <i data-lucide="user-plus" class="w-4 h-4 text-pk"></i>Crear usuario
            </h2>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Nombre completo *</label>
                    <input type="text" name="nombre" required class="form-input" placeholder="Juan Pérez">
                </div>
                <div>
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" required class="form-input" placeholder="juan@parklife.mx">
                </div>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Contraseña temporal * <span class="text-gray-400 font-normal">(mín. 10 chars)</span></label>
                    <input type="text" name="password" required minlength="10" class="form-input font-mono text-sm"
                           value="<?= 'PL' . bin2hex(random_bytes(4)) . '!' ?>">
                    <p class="text-xs text-gray-400 mt-1">Se genera automáticamente. El usuario la cambiará al activar.</p>
                </div>
                <div>
                    <label class="form-label">Rol * <a href="roles.php" class="text-pk text-xs ml-1 hover:underline">Gestionar roles →</a></label>
                    <select name="rol" class="form-select">
                        <?php foreach ($rolesDisponibles as $r): ?>
                        <option value="<?= e($r['slug']) ?>" <?= $r['slug'] === 'editor' ? 'selected' : '' ?>>
                            <?= e($r['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Info de permisos por rol (dinámica) -->
            <div class="bg-slate-50 rounded-xl p-4">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Roles disponibles</p>
                <div class="grid sm:grid-cols-2 gap-2 text-xs text-gray-600">
                    <?php foreach ($allRoles as $r): ?>
                    <div>
                        <span class="inline-block px-1.5 py-0.5 text-[10px] font-semibold rounded-full <?= e($r['color']) ?>"><?= e($r['nombre']) ?></span>
                        <span class="text-gray-400 ml-1"><?= e($r['descripcion'] ?? '') ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label class="form-label">Notas internas</label>
                <textarea name="notas" class="form-textarea" rows="2" placeholder="Ej: Asesor Condesa, temporal para proyecto X..."></textarea>
            </div>

            <label class="flex items-center gap-3 cursor-pointer">
                <input type="checkbox" name="enviar_email" value="1" checked
                       class="w-4 h-4 rounded border-gray-300 text-pk focus:ring-pk">
                <span class="text-sm text-gray-700">Enviar email de activación (el usuario debe confirmar antes de entrar)</span>
            </label>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="btn-primary px-6 py-2.5">
                    <i data-lucide="user-plus" class="w-4 h-4"></i>Crear usuario
                </button>
                <a href="usuarios.php" class="btn-secondary px-6 py-2.5">Cancelar</a>
            </div>
        </div>
    </form>

    <?php
    adminLayoutClose();
    exit;
}

// ═══ FORMULARIO: EDITAR USUARIO ══════════════════════════════════════════════
if ($action === 'edit' && $id) {
    requirePermission('usuarios', 'editar');
    $user = dbFetchOne("SELECT * FROM admin_usuarios WHERE id = ?", [$id]);
    if (!$user) adminRedirect('usuarios.php', 'error', 'Usuario no encontrado.');

    // No editar superadmin si no eres superadmin
    if ($user['rol'] === 'superadmin' && !isSuperAdmin()) {
        adminRedirect('usuarios.php', 'error', 'No puedes editar un superadmin.');
    }

    $rolesDisponibles = getAllRoles();
    if (!isSuperAdmin()) {
        $rolesDisponibles = array_filter($rolesDisponibles, fn($r) => $r['slug'] !== 'superadmin');
    }

    // Log reciente del usuario
    $logs = [];
    try {
        $logs = dbFetchAll(
            "SELECT accion, detalle, ip, created_at FROM admin_user_log WHERE admin_id = ? ORDER BY created_at DESC LIMIT 15",
            [$id]
        );
    } catch (\Throwable $e) {}

    adminLayoutOpen('Editar Usuario');
    ?>

    <a href="usuarios.php" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-pk mb-4">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver
    </a>

    <div class="grid lg:grid-cols-3 gap-6">

        <!-- Columna principal -->
        <div class="lg:col-span-2 space-y-6">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" value="<?= $id ?>">

                <div class="card space-y-5">
                    <h2 class="font-bold text-gray-800 flex items-center gap-2">
                        <i data-lucide="user-cog" class="w-4 h-4 text-pk"></i>Datos del usuario
                    </h2>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Nombre</label>
                            <input type="text" name="nombre" required class="form-input" value="<?= e($user['nombre']) ?>">
                        </div>
                        <div>
                            <label class="form-label">Email</label>
                            <input type="email" name="email" required class="form-input" value="<?= e($user['email']) ?>">
                        </div>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Rol <a href="roles.php" class="text-pk text-xs ml-1 hover:underline">Gestionar →</a></label>
                            <select name="rol" class="form-select" <?= ($id === currentAdminId()) ? 'disabled' : '' ?>>
                                <?php foreach ($rolesDisponibles as $r): ?>
                                <option value="<?= e($r['slug']) ?>" <?= $user['rol'] === $r['slug'] ? 'selected' : '' ?>><?= e($r['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($id === currentAdminId()): ?>
                                <p class="text-xs text-gray-400 mt-1">No puedes cambiar tu propio rol.</p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="form-label">Estado</label>
                            <label class="flex items-center gap-2 mt-2 cursor-pointer">
                                <input type="checkbox" name="activo" value="1"
                                       <?= $user['activo'] ? 'checked' : '' ?>
                                       <?= ($id === currentAdminId()) ? 'disabled' : '' ?>
                                       class="w-4 h-4 rounded border-gray-300 text-pk focus:ring-pk">
                                <span class="text-sm">Cuenta activa</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Notas internas</label>
                        <textarea name="notas" class="form-textarea" rows="2"><?= e($user['notas'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn-primary">
                        <i data-lucide="save" class="w-4 h-4"></i>Guardar cambios
                    </button>
                </div>
            </form>

            <!-- Reset contraseña -->
            <?php if (canAccess('usuarios', 'editar') && $id !== currentAdminId()): ?>
            <div class="card">
                <h2 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i data-lucide="key" class="w-4 h-4 text-pk"></i>Resetear contraseña
                </h2>
                <form method="post" id="reset-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="force_reset">
                    <input type="hidden" name="user_id" value="<?= $id ?>">
                    <div class="flex flex-col sm:flex-row items-start sm:items-end gap-3">
                        <div class="flex-1 w-full sm:w-auto">
                            <label class="form-label text-xs">Nueva contraseña (min. 10 chars)</label>
                            <div class="flex gap-2">
                                <input type="text" name="new_password" id="reset-pass" required minlength="10"
                                       class="form-input font-mono text-sm flex-1" placeholder="Escribe o genera una...">
                                <button type="button" onclick="generatePass()" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-xs font-medium transition-colors whitespace-nowrap">
                                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5 inline-block mr-1"></i>Generar
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn-danger" onclick="return confirmReset()">
                            <i data-lucide="key" class="w-4 h-4"></i>Resetear
                        </button>
                    </div>
                    <p id="reset-preview" class="text-xs text-gray-400 mt-2 hidden">
                        Contraseña a asignar: <span id="reset-preview-text" class="font-mono font-semibold text-gray-700"></span>
                    </p>
                </form>
            </div>
            <script>
            function generatePass() {
                const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
                let pass = 'PL';
                for (let i = 0; i < 8; i++) pass += chars[Math.floor(Math.random() * chars.length)];
                pass += '!';
                document.getElementById('reset-pass').value = pass;
                document.getElementById('reset-preview').classList.remove('hidden');
                document.getElementById('reset-preview-text').textContent = pass;
            }
            function confirmReset() {
                const pass = document.getElementById('reset-pass').value;
                if (!pass || pass.length < 10) { alert('La contraseña debe tener al menos 10 caracteres.'); return false; }
                return confirm('Se asignara la contraseña:\n\n' + pass + '\n\nLa contraseña actual dejara de funcionar. ¿Continuar?');
            }
            </script>
            <?php endif; ?>
        </div>

        <!-- Sidebar derecho -->
        <div class="space-y-6">
            <!-- Info rápida -->
            <div class="card">
                <h3 class="font-bold text-gray-800 mb-3 text-sm">Info</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-400">ID</dt>
                        <dd class="font-mono text-gray-600">#<?= $id ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-400">Creado</dt>
                        <dd class="text-gray-600"><?= date('d/m/Y', strtotime($user['created_at'])) ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-400">Activado</dt>
                        <dd class="text-gray-600"><?= $user['activado_en'] ? date('d/m/Y H:i', strtotime($user['activado_en'])) : '<span class="text-orange-500">Pendiente</span>' ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-400">Último login</dt>
                        <dd class="text-gray-600"><?= $user['ultimo_login'] ? date('d/m/Y H:i', strtotime($user['ultimo_login'])) : 'Nunca' ?></dd>
                    </div>
                    <?php if (!empty($user['invitado_por'])):
                        $inviter = dbFetchOne("SELECT nombre FROM admin_usuarios WHERE id = ?", [(int)$user['invitado_por']]);
                    ?>
                    <div class="flex justify-between">
                        <dt class="text-gray-400">Invitado por</dt>
                        <dd class="text-gray-600"><?= e($inviter['nombre'] ?? '—') ?></dd>
                    </div>
                    <?php endif; ?>
                </dl>

                <?php if (empty($user['activado_en']) && $id !== currentAdminId()): ?>
                <form method="post" class="mt-4">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="resend_activation">
                    <input type="hidden" name="user_id" value="<?= $id ?>">
                    <button type="submit" class="btn-secondary w-full text-xs justify-center">
                        <i data-lucide="mail" class="w-3.5 h-3.5"></i>Reenviar activación
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Activity log -->
            <?php if (!empty($logs)): ?>
            <div class="card">
                <h3 class="font-bold text-gray-800 mb-3 text-sm">Actividad reciente</h3>
                <div class="space-y-2 max-h-80 overflow-y-auto">
                    <?php foreach ($logs as $log): ?>
                    <div class="text-xs border-l-2 border-gray-200 pl-3 py-1">
                        <span class="font-semibold text-gray-700"><?= e($log['accion']) ?></span>
                        <?php if ($log['detalle']): ?>
                            <span class="text-gray-400">— <?= e(mb_strimwidth($log['detalle'], 0, 60, '...')) ?></span>
                        <?php endif; ?>
                        <div class="text-gray-300 mt-0.5"><?= date('d/m H:i', strtotime($log['created_at'])) ?> · <?= e($log['ip'] ?? '') ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    adminLayoutClose();
    exit;
}

// ═══ LISTADO ═════════════════════════════════════════════════════════════════
$search  = sanitizeStr($_GET['q'] ?? '');
$filtroRol = $_GET['rol'] ?? '';
$filtroActivo = $_GET['activo'] ?? '';

$where  = ['1=1'];
$params = [];

if ($search)       { $where[] = '(nombre LIKE ? OR email LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
if ($filtroRol)    { $where[] = 'rol = ?'; $params[] = $filtroRol; }
if ($filtroActivo !== '') { $where[] = 'activo = ?'; $params[] = (int)$filtroActivo; }

$whereStr = implode(' AND ', $where);
$users = dbFetchAll(
    "SELECT * FROM admin_usuarios WHERE {$whereStr} ORDER BY FIELD(rol,'superadmin','admin','comercial','editor','viewer'), nombre",
    $params
);

adminLayoutOpen('Usuarios');
?>

<div class="page-header">
    <div>
        <p class="text-sm text-gray-500"><?= count($users) ?> usuario<?= count($users) != 1 ? 's' : '' ?></p>
    </div>
    <?php if (canAccess('usuarios', 'editar')): ?>
    <a href="usuarios.php?action=new" class="btn-primary">
        <i data-lucide="user-plus" class="w-4 h-4"></i>Nuevo usuario
    </a>
    <?php endif; ?>
</div>

<!-- Filtros -->
<div class="card mb-5">
    <form method="get" class="flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[150px]">
            <label class="form-label text-xs">Buscar</label>
            <input type="text" name="q" value="<?= e($search) ?>" class="form-input text-sm" placeholder="Nombre o email...">
        </div>
        <div>
            <label class="form-label text-xs">Rol</label>
            <select name="rol" class="form-select text-sm" style="min-width:130px">
                <option value="">Todos</option>
                <?php foreach (getAllRoles() as $r): ?>
                <option value="<?= e($r['slug']) ?>" <?= $filtroRol === $r['slug'] ? 'selected' : '' ?>><?= e($r['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label text-xs">Estado</label>
            <select name="activo" class="form-select text-sm" style="min-width:110px">
                <option value="">Todos</option>
                <option value="1" <?= $filtroActivo === '1' ? 'selected' : '' ?>>Activos</option>
                <option value="0" <?= $filtroActivo === '0' ? 'selected' : '' ?>>Inactivos</option>
            </select>
        </div>
        <button type="submit" class="btn-secondary text-sm"><i data-lucide="search" class="w-4 h-4"></i>Filtrar</button>
        <?php if ($search || $filtroRol || $filtroActivo !== ''): ?>
            <a href="usuarios.php" class="text-xs text-gray-400 hover:text-pk">Limpiar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabla -->
<div class="card p-0">
    <div class="table-wrap">
        <table class="w-full">
            <thead class="bg-slate-50 border-b border-gray-100">
                <tr>
                    <th class="table-th">Usuario</th>
                    <th class="table-th">Email</th>
                    <th class="table-th">Rol</th>
                    <th class="table-th hide-mobile">Último login</th>
                    <th class="table-th hide-mobile">Activación</th>
                    <th class="table-th text-center">Estado</th>
                    <th class="table-th text-center">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($users as $u):
                    $rl = getRolLabel($u['rol']);
                    $isMe = ($u['id'] == currentAdminId());
                ?>
                <tr class="<?= !$u['activo'] ? 'opacity-50' : '' ?> <?= $isMe ? 'bg-blue-50/30' : '' ?>">
                    <td class="table-td">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-pk/10 flex items-center justify-center flex-shrink-0">
                                <span class="text-pk font-bold text-xs"><?= strtoupper(mb_substr($u['nombre'], 0, 1)) ?></span>
                            </div>
                            <div class="min-w-0">
                                <div class="font-medium text-gray-800 truncate">
                                    <?= e($u['nombre']) ?>
                                    <?= $isMe ? '<span class="text-xs text-blue-500 ml-1">(tú)</span>' : '' ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="table-td text-gray-500 cell-truncate"><?= e($u['email']) ?></td>
                    <td class="table-td">
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $rl['color'] ?>"><?= $rl['label'] ?></span>
                    </td>
                    <td class="table-td text-gray-400 text-xs hide-mobile">
                        <?= $u['ultimo_login'] ? date('d/m/Y H:i', strtotime($u['ultimo_login'])) : 'Nunca' ?>
                    </td>
                    <td class="table-td text-xs hide-mobile">
                        <?php if (!empty($u['activado_en'])): ?>
                            <span class="text-green-600">Activada <?= date('d/m/Y', strtotime($u['activado_en'])) ?></span>
                        <?php else: ?>
                            <span class="text-orange-500">Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td class="table-td text-center"><?= badgeStatus((int)$u['activo']) ?></td>
                    <td class="table-td text-center">
                        <?php if (canAccess('usuarios', 'editar')): ?>
                        <div class="flex items-center justify-center gap-1">
                            <a href="usuarios.php?action=edit&id=<?= $u['id'] ?>"
                               class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-pk transition-colors"
                               title="Editar">
                                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                            </a>
                            <?php if (!$isMe): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="new_state" value="<?= $u['activo'] ? 0 : 1 ?>">
                                <button type="submit"
                                        class="p-1.5 rounded-lg hover:bg-gray-100 transition-colors <?= $u['activo'] ? 'text-orange-500 hover:text-orange-600' : 'text-green-500 hover:text-green-600' ?>"
                                        title="<?= $u['activo'] ? 'Desactivar' : 'Activar' ?>"
                                        data-confirm="<?= $u['activo'] ? '¿Desactivar este usuario? No podrá iniciar sesión.' : '¿Activar este usuario?' ?>">
                                    <i data-lucide="<?= $u['activo'] ? 'user-x' : 'user-check' ?>" class="w-3.5 h-3.5"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php adminLayoutClose(); ?>