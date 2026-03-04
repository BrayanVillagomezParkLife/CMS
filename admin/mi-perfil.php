<?php
declare(strict_types=1);
/**
 * admin/mi-perfil.php
 * Página de perfil personal — cualquier usuario logueado puede:
 *   - Ver su info y permisos
 *   - Cambiar su nombre
 *   - Cambiar su contraseña
 */

require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf = adminCsrf();

// ═══ CAMBIAR NOMBRE ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_name') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('mi-perfil.php', 'error', 'Token inválido.');
    }
    $nombre = sanitizeStr($_POST['nombre'] ?? '');
    if (strlen($nombre) < 2) {
        adminRedirect('mi-perfil.php', 'error', 'El nombre debe tener al menos 2 caracteres.');
    }
    dbExecute("UPDATE admin_usuarios SET nombre = :n WHERE id = :id",
        [':n' => $nombre, ':id' => currentAdminId()]);
    $_SESSION['admin_nombre'] = $nombre;
    logUserAction(currentAdminId(), 'update_profile', "Cambió su nombre a: {$nombre}");
    adminRedirect('mi-perfil.php', 'success', 'Nombre actualizado.');
}

// ═══ CAMBIAR CONTRASEÑA ══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('mi-perfil.php', 'error', 'Token inválido.');
    }
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new !== $confirm) {
        adminRedirect('mi-perfil.php', 'error', 'Las contraseñas no coinciden.');
    }

    $result = changeAdminPassword(currentAdminId(), $current, $new);
    adminRedirect('mi-perfil.php',
        $result['success'] ? 'success' : 'error',
        $result['success'] ? 'Contraseña actualizada.' : ($result['error'] ?? 'Error.'));
}

// ═══ DATOS ═══════════════════════════════════════════════════════════════════
$me = dbFetchOne("SELECT * FROM admin_usuarios WHERE id = ?", [currentAdminId()]);
$rl = getRolLabel($me['rol']);

// Permisos del rol actual
$permisos = getUserPermissions($me['rol'], $me['permisos'] ?? null);

// Actividad reciente
$logs = [];
try {
    $logs = dbFetchAll(
        "SELECT accion, detalle, ip, created_at FROM admin_user_log WHERE admin_id = ? ORDER BY created_at DESC LIMIT 20",
        [currentAdminId()]
    );
} catch (\Throwable $e) {}

adminLayoutOpen('Mi Perfil');
?>

<div class="grid lg:grid-cols-3 gap-6">

    <!-- Columna principal -->
    <div class="lg:col-span-2 space-y-6">

        <!-- Info + nombre -->
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="update_name">
            <div class="card">
                <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                    <i data-lucide="user" class="w-4 h-4 text-pk"></i>Datos personales
                </h2>
                <div class="grid sm:grid-cols-2 gap-4 mb-5">
                    <div>
                        <label class="form-label">Nombre</label>
                        <input type="text" name="nombre" required class="form-input" value="<?= e($me['nombre']) ?>">
                    </div>
                    <div>
                        <label class="form-label">Email</label>
                        <input type="email" class="form-input bg-gray-50" value="<?= e($me['email']) ?>" disabled>
                        <p class="text-xs text-gray-400 mt-1">Para cambiar el email, contacta a un administrador.</p>
                    </div>
                </div>
                <button type="submit" class="btn-primary">
                    <i data-lucide="save" class="w-4 h-4"></i>Guardar nombre
                </button>
            </div>
        </form>

        <!-- Cambiar contraseña -->
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="card">
                <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                    <i data-lucide="key" class="w-4 h-4 text-pk"></i>Cambiar contraseña
                </h2>
                <div class="space-y-4 max-w-md">
                    <div>
                        <label class="form-label">Contraseña actual</label>
                        <input type="password" name="current_password" required class="form-input">
                    </div>
                    <div>
                        <label class="form-label">Nueva contraseña <span class="text-gray-400 font-normal">(mín. 10 caracteres)</span></label>
                        <input type="password" name="new_password" required minlength="10" class="form-input"
                               id="newPwd" oninput="checkPwdStrength(this.value)">
                        <div id="pwd-strength" class="mt-1.5 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                            <div id="pwd-bar" class="h-full rounded-full transition-all duration-300" style="width:0%"></div>
                        </div>
                        <p id="pwd-text" class="text-xs text-gray-400 mt-1"></p>
                    </div>
                    <div>
                        <label class="form-label">Confirmar nueva contraseña</label>
                        <input type="password" name="confirm_password" required minlength="10" class="form-input"
                               id="confirmPwd" oninput="checkMatch()">
                        <p id="pwd-match" class="text-xs mt-1 hidden"></p>
                    </div>
                </div>
                <button type="submit" class="btn-primary mt-5">
                    <i data-lucide="shield-check" class="w-4 h-4"></i>Actualizar contraseña
                </button>
            </div>
        </form>

        <!-- Mis permisos -->
        <div class="card">
            <h2 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i data-lucide="lock" class="w-4 h-4 text-pk"></i>Mis permisos
                <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $rl['color'] ?>"><?= $rl['label'] ?></span>
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="table-th">Módulo</th>
                            <th class="table-th text-center">Ver</th>
                            <th class="table-th text-center">Editar</th>
                            <th class="table-th text-center">Eliminar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php
                        $iconYes = '<span class="text-green-500">✓</span>';
                        $iconNo  = '<span class="text-gray-300">—</span>';
                        $moduloLabels = [
                            'dashboard' => 'Dashboard', 'propiedades' => 'Propiedades', 'habitaciones' => 'Habitaciones',
                            'precios' => 'Precios', 'cotizador' => 'Cotizador', 'imagenes' => 'Imágenes',
                            'imagenes-auditoria' => 'Auditoría img', 'amenidades' => 'Amenidades', 'faqs' => 'FAQs',
                            'hero' => 'Hero Slides', 'prensa' => 'Prensa', 'leads' => 'Leads',
                            'strings' => 'Textos', 'config' => 'Configuración', 'usuarios' => 'Usuarios',
                        ];
                        foreach ($permisos as $mod => $acc): ?>
                        <tr>
                            <td class="table-td font-medium"><?= $moduloLabels[$mod] ?? ucfirst($mod) ?></td>
                            <td class="table-td text-center"><?= $acc['ver'] ? $iconYes : $iconNo ?></td>
                            <td class="table-td text-center"><?= $acc['editar'] ? $iconYes : $iconNo ?></td>
                            <td class="table-td text-center"><?= $acc['eliminar'] ? $iconYes : $iconNo ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Sidebar derecho -->
    <div class="space-y-6">
        <!-- Resumen rápido -->
        <div class="card">
            <div class="text-center mb-4">
                <div class="w-16 h-16 rounded-full bg-pk/10 flex items-center justify-center mx-auto mb-3">
                    <span class="text-pk font-bold text-2xl"><?= strtoupper(mb_substr($me['nombre'], 0, 1)) ?></span>
                </div>
                <h3 class="font-bold text-gray-800"><?= e($me['nombre']) ?></h3>
                <p class="text-sm text-gray-400"><?= e($me['email']) ?></p>
                <span class="inline-block mt-2 px-3 py-1 text-xs font-semibold rounded-full <?= $rl['color'] ?>">
                    <?= $rl['label'] ?>
                </span>
            </div>
            <dl class="space-y-2 text-sm border-t border-gray-100 pt-4">
                <div class="flex justify-between">
                    <dt class="text-gray-400">Cuenta creada</dt>
                    <dd class="text-gray-600"><?= date('d/m/Y', strtotime($me['created_at'])) ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-400">Último login</dt>
                    <dd class="text-gray-600"><?= $me['ultimo_login'] ? date('d/m/Y H:i', strtotime($me['ultimo_login'])) : 'N/A' ?></dd>
                </div>
            </dl>
        </div>

        <!-- Actividad -->
        <?php if (!empty($logs)): ?>
        <div class="card">
            <h3 class="font-bold text-gray-800 mb-3 text-sm">Mi actividad reciente</h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                <?php foreach ($logs as $log): ?>
                <div class="text-xs border-l-2 border-gray-200 pl-3 py-1">
                    <span class="font-semibold text-gray-700"><?= e($log['accion']) ?></span>
                    <?php if ($log['detalle']): ?>
                        <span class="text-gray-400">— <?= e(mb_strimwidth($log['detalle'], 0, 50, '...')) ?></span>
                    <?php endif; ?>
                    <div class="text-gray-300 mt-0.5"><?= date('d/m H:i', strtotime($log['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function checkPwdStrength(pwd) {
    let score = 0;
    if (pwd.length >= 10) score++;
    if (pwd.length >= 14) score++;
    if (/[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^A-Za-z0-9]/.test(pwd)) score++;

    const bar = document.getElementById('pwd-bar');
    const txt = document.getElementById('pwd-text');
    const levels = [
        { w: '10%', c: 'bg-red-400', t: 'Muy débil' },
        { w: '30%', c: 'bg-orange-400', t: 'Débil' },
        { w: '55%', c: 'bg-yellow-400', t: 'Aceptable' },
        { w: '80%', c: 'bg-green-400', t: 'Buena' },
        { w: '100%', c: 'bg-green-600', t: 'Fuerte' },
    ];
    const lvl = levels[Math.min(score, levels.length - 1)];
    bar.style.width = lvl.w;
    bar.className = 'h-full rounded-full transition-all duration-300 ' + lvl.c;
    txt.textContent = pwd.length > 0 ? lvl.t : '';
}

function checkMatch() {
    const n = document.getElementById('newPwd').value;
    const c = document.getElementById('confirmPwd').value;
    const el = document.getElementById('pwd-match');
    if (c.length === 0) { el.classList.add('hidden'); return; }
    el.classList.remove('hidden');
    if (n === c) { el.textContent = '✓ Coinciden'; el.className = 'text-xs mt-1 text-green-600'; }
    else         { el.textContent = '✗ No coinciden'; el.className = 'text-xs mt-1 text-red-500'; }
}
</script>

<?php adminLayoutClose(); ?>
