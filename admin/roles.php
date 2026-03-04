<?php
declare(strict_types=1);
/**
 * admin/roles.php — Gestión de Roles y Matriz de Permisos
 * Crear/editar/eliminar roles con matriz visual de checkboxes.
 */

require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

requirePermission('usuarios', 'editar');

$csrf    = adminCsrf();
$action  = $_GET['action'] ?? 'list';
$id      = (int)($_GET['id'] ?? 0);
$modules = getSystemModules();

// ═══ ACCIONES POST ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('roles.php', 'error', 'Token inválido.');
    }

    $postAction = $_POST['action'] ?? '';

    // ── Crear rol ──
    if ($postAction === 'create') {
        $nombre      = sanitizeStr($_POST['nombre'] ?? '');
        $slug        = preg_replace('/[^a-z0-9_-]/', '', strtolower(str_replace(' ', '-', $_POST['slug'] ?? '')));
        $descripcion = sanitizeStr($_POST['descripcion'] ?? '', 255);
        $color       = sanitizeStr($_POST['color'] ?? 'bg-gray-100 text-gray-600');

        if (!$nombre || !$slug) {
            adminRedirect('roles.php?action=new', 'error', 'Nombre y slug son requeridos.');
        }

        // Verificar slug único
        $exists = dbFetchValue("SELECT COUNT(*) FROM admin_roles WHERE slug = ?", [$slug]);
        if ($exists > 0) {
            adminRedirect('roles.php?action=new', 'error', 'Ya existe un rol con ese identificador.');
        }

        // Construir JSON de permisos desde la matriz
        $permisos = buildPermisosFromPost($modules);
        $maxOrden = (int)dbFetchValue("SELECT MAX(orden) FROM admin_roles") + 1;

        dbInsert(
            "INSERT INTO admin_roles (nombre, slug, descripcion, permisos, color, es_sistema, orden)
             VALUES (?, ?, ?, ?, ?, 0, ?)",
            [$nombre, $slug, $descripcion, json_encode($permisos), $color, $maxOrden]
        );

        logUserAction(currentAdminId(), 'create_role', "Creó rol: {$nombre} ({$slug})");
        adminRedirect('roles.php', 'success', "Rol \"{$nombre}\" creado.");
    }

    // ── Actualizar rol ──
    if ($postAction === 'update') {
        $roleId      = (int)$_POST['role_id'];
        $nombre      = sanitizeStr($_POST['nombre'] ?? '');
        $descripcion = sanitizeStr($_POST['descripcion'] ?? '', 255);
        $color       = sanitizeStr($_POST['color'] ?? 'bg-gray-100 text-gray-600');

        $permisos = buildPermisosFromPost($modules);

        dbExecute(
            "UPDATE admin_roles SET nombre = ?, descripcion = ?, permisos = ?, color = ? WHERE id = ?",
            [$nombre, $descripcion, json_encode($permisos), $color, $roleId]
        );

        // Limpiar cache estático de getRoleBySlug
        logUserAction(currentAdminId(), 'edit_role', "Editó rol #{$roleId}: {$nombre}");
        adminRedirect('roles.php', 'success', "Rol \"{$nombre}\" actualizado.");
    }

    // ── Eliminar rol ──
    if ($postAction === 'delete') {
        $roleId = (int)$_POST['role_id'];
        $role = dbFetchOne("SELECT * FROM admin_roles WHERE id = ?", [$roleId]);

        if (!$role) adminRedirect('roles.php', 'error', 'Rol no encontrado.');
        if ($role['es_sistema']) adminRedirect('roles.php', 'error', 'No puedes eliminar un rol del sistema.');

        // Verificar que no haya usuarios con este rol
        $usersWithRole = (int)dbFetchValue("SELECT COUNT(*) FROM admin_usuarios WHERE rol = ?", [$role['slug']]);
        if ($usersWithRole > 0) {
            adminRedirect('roles.php', 'error', "No puedes eliminar este rol, tiene {$usersWithRole} usuario(s) asignado(s).");
        }

        dbExecute("DELETE FROM admin_roles WHERE id = ?", [$roleId]);
        logUserAction(currentAdminId(), 'delete_role', "Eliminó rol: {$role['nombre']}");
        adminRedirect('roles.php', 'success', 'Rol eliminado.');
    }
}

// ═══ HELPER: Construir permisos desde POST ═══════════════════════════════════
function buildPermisosFromPost(array $modules): array
{
    $permisos = [];
    foreach ($modules as $key => $label) {
        $permisos[$key] = [
            'ver'      => isset($_POST['perm'][$key]['ver']) ? 1 : 0,
            'editar'   => isset($_POST['perm'][$key]['editar']) ? 1 : 0,
            'eliminar' => isset($_POST['perm'][$key]['eliminar']) ? 1 : 0,
        ];
    }
    return $permisos;
}

// ═══ FORMULARIO: NUEVO ROL ═══════════════════════════════════════════════════
if ($action === 'new') {
    adminLayoutOpen('Nuevo Rol');
    $emptyPerms = [];
    foreach ($modules as $key => $label) {
        $emptyPerms[$key] = ['ver' => 0, 'editar' => 0, 'eliminar' => 0];
    }
    renderRoleForm('create', null, $emptyPerms, $modules, $csrf);
    adminLayoutClose();
    exit;
}

// ═══ FORMULARIO: EDITAR ROL ══════════════════════════════════════════════════
if ($action === 'edit' && $id) {
    $role = dbFetchOne("SELECT * FROM admin_roles WHERE id = ?", [$id]);
    if (!$role) adminRedirect('roles.php', 'error', 'Rol no encontrado.');

    $role['permisos_array'] = json_decode($role['permisos'], true) ?: [];
    adminLayoutOpen('Editar Rol: ' . $role['nombre']);

    // Asegurar que todos los módulos estén
    $perms = [];
    foreach ($modules as $key => $label) {
        $perms[$key] = $role['permisos_array'][$key] ?? ['ver' => 0, 'editar' => 0, 'eliminar' => 0];
    }

    renderRoleForm('update', $role, $perms, $modules, $csrf);
    adminLayoutClose();
    exit;
}

// ═══ LISTADO DE ROLES ════════════════════════════════════════════════════════
$roles = getAllRoles();

adminLayoutOpen('Roles y Permisos');
?>

<div class="page-header">
    <div>
        <p class="text-sm text-gray-500"><?= count($roles) ?> rol<?= count($roles) != 1 ? 'es' : '' ?> definidos</p>
    </div>
    <div class="flex gap-2">
        <a href="usuarios.php" class="btn-secondary">
            <i data-lucide="users" class="w-4 h-4"></i>Usuarios
        </a>
        <a href="roles.php?action=new" class="btn-primary">
            <i data-lucide="plus" class="w-4 h-4"></i>Nuevo rol
        </a>
    </div>
</div>

<!-- Resumen visual de roles -->
<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
    <?php foreach ($roles as $r):
        $usersCount = (int)dbFetchValue("SELECT COUNT(*) FROM admin_usuarios WHERE rol = ?", [$r['slug']]);
        $permCount  = 0;
        foreach ($r['permisos_array'] as $p) {
            $permCount += ($p['ver'] ?? 0) + ($p['editar'] ?? 0) + ($p['eliminar'] ?? 0);
        }
        $totalPerms = count($modules) * 3;
        $pct = $totalPerms > 0 ? round($permCount / $totalPerms * 100) : 0;
    ?>
    <div class="card hover:shadow-md transition-shadow">
        <div class="flex items-start justify-between mb-3">
            <div>
                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded-full <?= e($r['color']) ?>">
                    <?= e($r['nombre']) ?>
                </span>
                <?php if ($r['es_sistema']): ?>
                    <span class="text-[10px] text-gray-400 ml-1">sistema</span>
                <?php endif; ?>
            </div>
            <a href="roles.php?action=edit&id=<?= $r['id'] ?>"
               class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-pk transition-colors">
                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
            </a>
        </div>
        <p class="text-xs text-gray-500 mb-3"><?= e($r['descripcion'] ?? 'Sin descripción') ?></p>
        <div class="flex items-center justify-between text-xs">
            <span class="text-gray-400"><?= $usersCount ?> usuario<?= $usersCount !== 1 ? 's' : '' ?></span>
            <span class="font-semibold text-gray-600"><?= $permCount ?>/<?= $totalPerms ?> permisos (<?= $pct ?>%)</span>
        </div>
        <!-- Barra de progreso -->
        <div class="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all <?= $pct > 80 ? 'bg-red-400' : ($pct > 50 ? 'bg-yellow-400' : 'bg-green-400') ?>"
                 style="width:<?= $pct ?>%"></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Matriz global (solo lectura) -->
<div class="card p-0">
    <div class="px-5 py-4 border-b border-gray-100">
        <h2 class="font-bold text-gray-800 flex items-center gap-2">
            <i data-lucide="grid-3x3" class="w-4 h-4 text-pk"></i>Matriz de permisos
        </h2>
        <p class="text-xs text-gray-400 mt-1">Vista general de todos los roles y sus permisos. Haz clic en un rol para editarlo.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-slate-50">
                <tr>
                    <th class="table-th sticky left-0 bg-slate-50 z-10 min-w-[140px]">Módulo</th>
                    <?php foreach ($roles as $r): ?>
                    <th class="table-th text-center" colspan="3">
                        <a href="roles.php?action=edit&id=<?= $r['id'] ?>" class="hover:text-pk transition-colors">
                            <span class="inline-block px-1.5 py-0.5 rounded-full <?= e($r['color']) ?> text-[10px]"><?= e($r['nombre']) ?></span>
                        </a>
                    </th>
                    <?php endforeach; ?>
                </tr>
                <tr class="border-b border-gray-200">
                    <th class="table-th sticky left-0 bg-slate-50 z-10"></th>
                    <?php foreach ($roles as $r): ?>
                    <th class="px-1 py-1 text-center text-[9px] text-gray-400 font-normal">V</th>
                    <th class="px-1 py-1 text-center text-[9px] text-gray-400 font-normal">E</th>
                    <th class="px-1 py-1 text-center text-[9px] text-gray-400 font-normal border-r border-gray-100">D</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($modules as $key => $label): ?>
                <tr>
                    <td class="table-td font-medium sticky left-0 bg-white z-10"><?= e($label) ?></td>
                    <?php foreach ($roles as $r):
                        $p = $r['permisos_array'][$key] ?? [];
                    ?>
                    <td class="px-1 py-2 text-center"><?= !empty($p['ver']) ? '<span class="text-green-500">●</span>' : '<span class="text-gray-200">○</span>' ?></td>
                    <td class="px-1 py-2 text-center"><?= !empty($p['editar']) ? '<span class="text-blue-500">●</span>' : '<span class="text-gray-200">○</span>' ?></td>
                    <td class="px-1 py-2 text-center border-r border-gray-100"><?= !empty($p['eliminar']) ? '<span class="text-red-500">●</span>' : '<span class="text-gray-200">○</span>' ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100 flex items-center gap-4 text-[10px] text-gray-400">
        <span><span class="text-green-500">●</span> V = Ver</span>
        <span><span class="text-blue-500">●</span> E = Editar</span>
        <span><span class="text-red-500">●</span> D = Eliminar</span>
        <span><span class="text-gray-200">○</span> = Sin acceso</span>
    </div>
</div>

<?php
adminLayoutClose();

// ═══════════════════════════════════════════════════════════════════════════════
// FUNCIÓN: Renderizar formulario de rol con matriz de checkboxes
// ═══════════════════════════════════════════════════════════════════════════════
function renderRoleForm(string $formAction, ?array $role, array $perms, array $modules, string $csrf): void
{
    $isEdit = ($formAction === 'update');
    $isSystem = $isEdit && !empty($role['es_sistema']);

    $colorOptions = [
        'bg-red-100 text-red-700'       => 'Rojo',
        'bg-purple-100 text-purple-700' => 'Morado',
        'bg-blue-100 text-blue-700'     => 'Azul',
        'bg-green-100 text-green-700'   => 'Verde',
        'bg-yellow-100 text-yellow-700' => 'Amarillo',
        'bg-orange-100 text-orange-700' => 'Naranja',
        'bg-pink-100 text-pink-700'     => 'Rosa',
        'bg-cyan-100 text-cyan-700'     => 'Cian',
        'bg-gray-100 text-gray-600'     => 'Gris',
        'bg-slate-800 text-white'       => 'Oscuro',
    ];
    ?>

    <a href="roles.php" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-pk mb-4">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver a roles
    </a>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="<?= $formAction ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
        <?php endif; ?>

        <!-- Datos del rol -->
        <div class="card mb-6">
            <h2 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i data-lucide="shield" class="w-4 h-4 text-pk"></i>
                <?= $isEdit ? 'Editar rol' : 'Nuevo rol' ?>
            </h2>

            <div class="grid sm:grid-cols-3 gap-4">
                <div>
                    <label class="form-label">Nombre del rol *</label>
                    <input type="text" name="nombre" required class="form-input"
                           value="<?= e($role['nombre'] ?? '') ?>" placeholder="Ej: Marketing">
                </div>
                <div>
                    <label class="form-label">Identificador (slug) *</label>
                    <?php if ($isEdit): ?>
                        <input type="text" class="form-input bg-gray-50" value="<?= e($role['slug'] ?? '') ?>" disabled>
                        <p class="text-xs text-gray-400 mt-1">No se puede cambiar.</p>
                    <?php else: ?>
                        <input type="text" name="slug" required class="form-input font-mono text-sm"
                               placeholder="marketing" pattern="[a-z0-9_-]+"
                               title="Solo minúsculas, números, guiones y guiones bajos">
                        <p class="text-xs text-gray-400 mt-1">Solo minúsculas, sin espacios. Ej: ventas-jr</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">Color del badge</label>
                    <select name="color" class="form-select text-sm">
                        <?php foreach ($colorOptions as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= ($role['color'] ?? '') === $val ? 'selected' : '' ?>>
                            <?= $lbl ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mt-4">
                <label class="form-label">Descripción</label>
                <input type="text" name="descripcion" class="form-input" maxlength="255"
                       value="<?= e($role['descripcion'] ?? '') ?>" placeholder="Breve descripción del rol...">
            </div>
        </div>

        <!-- ═══ MATRIZ DE PERMISOS ═══ -->
        <div class="card p-0 mb-6">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h2 class="font-bold text-gray-800 flex items-center gap-2">
                        <i data-lucide="grid-3x3" class="w-4 h-4 text-pk"></i>Matriz de permisos
                    </h2>
                    <p class="text-xs text-gray-400 mt-1">Activa o desactiva cada permiso por módulo</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" onclick="matrixSelectAll()" class="text-xs px-3 py-1.5 bg-green-50 text-green-700 rounded-lg hover:bg-green-100 transition-colors font-medium">
                        Activar todo
                    </button>
                    <button type="button" onclick="matrixDeselectAll()" class="text-xs px-3 py-1.5 bg-red-50 text-red-700 rounded-lg hover:bg-red-100 transition-colors font-medium">
                        Desactivar todo
                    </button>
                    <button type="button" onclick="matrixReadOnly()" class="text-xs px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors font-medium">
                        Solo lectura
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full" id="perm-matrix">
                    <thead class="bg-slate-50 border-b border-gray-200">
                        <tr>
                            <th class="table-th min-w-[180px] sticky left-0 bg-slate-50 z-10">Módulo</th>
                            <th class="table-th text-center w-24 cursor-pointer hover:bg-green-50 transition-colors" onclick="toggleColumn('ver')">
                                <div class="flex flex-col items-center gap-0.5">
                                    <i data-lucide="eye" class="w-3.5 h-3.5 text-green-600"></i>
                                    <span class="text-[10px] text-green-600 font-medium">VER</span>
                                </div>
                            </th>
                            <th class="table-th text-center w-24 cursor-pointer hover:bg-blue-50 transition-colors" onclick="toggleColumn('editar')">
                                <div class="flex flex-col items-center gap-0.5">
                                    <i data-lucide="pencil" class="w-3.5 h-3.5 text-blue-600"></i>
                                    <span class="text-[10px] text-blue-600 font-medium">EDITAR</span>
                                </div>
                            </th>
                            <th class="table-th text-center w-24 cursor-pointer hover:bg-red-50 transition-colors" onclick="toggleColumn('eliminar')">
                                <div class="flex flex-col items-center gap-0.5">
                                    <i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-600"></i>
                                    <span class="text-[10px] text-red-600 font-medium">ELIMINAR</span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php foreach ($modules as $key => $label):
                            $v = !empty($perms[$key]['ver']);
                            $e = !empty($perms[$key]['editar']);
                            $d = !empty($perms[$key]['eliminar']);
                        ?>
                        <tr class="hover:bg-gray-50/50 transition-colors perm-row" data-module="<?= e($key) ?>">
                            <td class="table-td sticky left-0 bg-white z-10">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-800"><?= e($label) ?></span>
                                </div>
                            </td>
                            <td class="table-td text-center">
                                <label class="perm-toggle">
                                    <input type="checkbox" name="perm[<?= e($key) ?>][ver]" value="1"
                                           <?= $v ? 'checked' : '' ?> class="perm-cb perm-ver" data-col="ver">
                                    <span class="perm-switch perm-switch-green"></span>
                                </label>
                            </td>
                            <td class="table-td text-center">
                                <label class="perm-toggle">
                                    <input type="checkbox" name="perm[<?= e($key) ?>][editar]" value="1"
                                           <?= $e ? 'checked' : '' ?> class="perm-cb perm-editar" data-col="editar">
                                    <span class="perm-switch perm-switch-blue"></span>
                                </label>
                            </td>
                            <td class="table-td text-center">
                                <label class="perm-toggle">
                                    <input type="checkbox" name="perm[<?= e($key) ?>][eliminar]" value="1"
                                           <?= $d ? 'checked' : '' ?> class="perm-cb perm-eliminar" data-col="eliminar">
                                    <span class="perm-switch perm-switch-red"></span>
                                </label>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Counter -->
            <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
                <div class="flex items-center gap-4 text-[10px] text-gray-400">
                    <span>Clic en cabecera de columna para activar/desactivar toda la columna</span>
                </div>
                <span id="perm-counter" class="text-xs font-semibold text-gray-600"></span>
            </div>
        </div>

        <!-- Acciones -->
        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary px-8 py-2.5">
                <i data-lucide="save" class="w-4 h-4"></i><?= $isEdit ? 'Guardar cambios' : 'Crear rol' ?>
            </button>
            <a href="roles.php" class="btn-secondary px-6 py-2.5">Cancelar</a>

            <?php if ($isEdit && !$isSystem): ?>
            <div class="ml-auto">
                <button type="button" onclick="confirmDeleteRole()" class="btn-danger px-4 py-2.5">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>Eliminar rol
                </button>
            </div>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($isEdit && !$isSystem): ?>
    <!-- Form oculto para eliminar -->
    <form id="delete-form" method="post" class="hidden">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
    </form>
    <?php endif; ?>

    <style>
    /* Toggle switches */
    .perm-toggle {
        display: inline-flex;
        align-items: center;
        cursor: pointer;
    }
    .perm-toggle input { display: none; }
    .perm-switch {
        width: 36px;
        height: 20px;
        border-radius: 10px;
        background: #e5e7eb;
        position: relative;
        transition: background 0.2s;
    }
    .perm-switch::after {
        content: '';
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: white;
        position: absolute;
        top: 2px;
        left: 2px;
        transition: transform 0.2s;
        box-shadow: 0 1px 2px rgba(0,0,0,0.15);
    }
    .perm-toggle input:checked + .perm-switch-green  { background: #22c55e; }
    .perm-toggle input:checked + .perm-switch-blue   { background: #3b82f6; }
    .perm-toggle input:checked + .perm-switch-red    { background: #ef4444; }
    .perm-toggle input:checked + .perm-switch::after  { transform: translateX(16px); }

    /* Hover en filas */
    .perm-row:hover td { background: #f8fafc !important; }
    </style>

    <script>
    // Contar permisos activos
    function updateCounter() {
        const total = document.querySelectorAll('.perm-cb').length;
        const checked = document.querySelectorAll('.perm-cb:checked').length;
        const pct = total > 0 ? Math.round(checked / total * 100) : 0;
        const el = document.getElementById('perm-counter');
        if (el) el.textContent = checked + '/' + total + ' permisos (' + pct + '%)';
    }

    // Activar/Desactivar toda una columna
    function toggleColumn(col) {
        const cbs = document.querySelectorAll('.perm-' + col);
        const allChecked = Array.from(cbs).every(cb => cb.checked);
        cbs.forEach(cb => cb.checked = !allChecked);
        updateCounter();
    }

    // Botones rápidos
    function matrixSelectAll() {
        document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = true);
        updateCounter();
    }
    function matrixDeselectAll() {
        document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = false);
        updateCounter();
    }
    function matrixReadOnly() {
        document.querySelectorAll('.perm-ver').forEach(cb => cb.checked = true);
        document.querySelectorAll('.perm-editar').forEach(cb => cb.checked = false);
        document.querySelectorAll('.perm-eliminar').forEach(cb => cb.checked = false);
        updateCounter();
    }

    // Confirmar eliminación
    function confirmDeleteRole() {
        if (confirm('¿Eliminar este rol? Esta acción no se puede deshacer.')) {
            document.getElementById('delete-form').submit();
        }
    }

    // Lógica: si activas "editar" sin "ver", activa "ver" automáticamente
    document.querySelectorAll('.perm-cb').forEach(cb => {
        cb.addEventListener('change', function() {
            const row = this.closest('.perm-row');
            const ver = row.querySelector('.perm-ver');
            const editar = row.querySelector('.perm-editar');
            const eliminar = row.querySelector('.perm-eliminar');

            // Si activas editar o eliminar, activar ver
            if ((this === editar || this === eliminar) && this.checked) {
                ver.checked = true;
            }
            // Si activas eliminar, activar editar
            if (this === eliminar && this.checked) {
                editar.checked = true;
            }
            // Si desactivas ver, desactivar todo
            if (this === ver && !this.checked) {
                editar.checked = false;
                eliminar.checked = false;
            }
            // Si desactivas editar, desactivar eliminar
            if (this === editar && !this.checked) {
                eliminar.checked = false;
            }

            updateCounter();
        });
    });

    // Inicializar
    updateCounter();
    </script>

    <?php
}
?>
