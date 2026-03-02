<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf = adminCsrf();
requireRole('superadmin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) adminRedirect('config.php', 'error', 'Token inválido.');

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save_config') {
        $campos = [
            'nombre_empresa','email_info','email_admin','telefono_ventas',
            'whatsapp_ventas','logo_blanco','logo_color','color_primary',
            'google_tag','facebook_pixel','recaptcha_site_key',
            'cloudbeds_default','cloudbeds_api_key','deepl_api_key',
            'zoho_client_id','zoho_org_id',
            'wa_phone_id','wa_token','wa_template','whatsapp_notif_fijos',
        ];
        foreach ($campos as $campo) {
            $val = sanitizeStr($_POST[$campo] ?? '');
            if ($val !== '') {
                setConfig($campo, $val);
            }
        }
        dbCacheInvalidate();
        adminRedirect('config.php', 'success', 'Configuración guardada.');
    }

    if ($postAction === 'clear_cache') {
        dbCacheInvalidate();
        adminRedirect('config.php', 'success', 'Cache limpiado correctamente.');
    }

    // ── JEFES: agregar ──
    if ($postAction === 'add_jefe') {
        $nombre   = sanitizeStr($_POST['jefe_nombre'] ?? '');
        $telefono = sanitizeStr($_POST['jefe_telefono'] ?? '');
        $email    = sanitizeEmail($_POST['jefe_email'] ?? '');
        if ($nombre && $telefono) {
            dbInsert('notif_jefes', ['nombre' => $nombre, 'telefono' => $telefono, 'email' => $email, 'activo' => 1]);
            adminRedirect('config.php#jefes', 'success', 'Jefe ' . $nombre . ' agregado.');
        }
        adminRedirect('config.php#jefes', 'error', 'Nombre y teléfono son obligatorios.');
    }

    // ── JEFES: editar ──
    if ($postAction === 'edit_jefe') {
        $id       = (int)($_POST['jefe_id'] ?? 0);
        $nombre   = sanitizeStr($_POST['jefe_nombre'] ?? '');
        $telefono = sanitizeStr($_POST['jefe_telefono'] ?? '');
        $email    = sanitizeEmail($_POST['jefe_email'] ?? '');
        $activo   = isset($_POST['jefe_activo']) ? 1 : 0;
        if ($id && $nombre && $telefono) {
            db()->prepare("UPDATE notif_jefes SET nombre=?, telefono=?, email=?, activo=? WHERE id=?")
               ->execute([$nombre, $telefono, $email, $activo, $id]);
            adminRedirect('config.php#jefes', 'success', 'Jefe actualizado.');
        }
        adminRedirect('config.php#jefes', 'error', 'Datos inválidos.');
    }

    // ── JEFES: eliminar ──
    if ($postAction === 'delete_jefe') {
        $id = (int)($_POST['jefe_id'] ?? 0);
        if ($id) {
            db()->prepare("DELETE FROM notif_jefes WHERE id=?")->execute([$id]);
            adminRedirect('config.php#jefes', 'success', 'Jefe eliminado.');
        }
    }

    if ($postAction === 'save_notif_reps') {
        // Guardar estado notif_activo y teléfono de cada rep
        $repsData = $_POST['rep'] ?? [];
        $activosIds = array_keys($repsData);
        // Primero desactivar todos
        db()->exec("UPDATE reps SET notif_activo = 0");
        // Activar los marcados y guardar teléfono
        foreach ($repsData as $repId => $data) {
            $repId = (int)$repId;
            $tel   = sanitizeStr($data['telefono'] ?? '');
            db()->prepare("UPDATE reps SET notif_activo = 1, telefono = ? WHERE id = ?")
                ->execute([$tel, $repId]);
        }
        adminRedirect('config.php#notif', 'success', 'Notificaciones actualizadas.');
    }

    if ($postAction === 'create_admin') {
        $nombre   = sanitizeStr($_POST['nuevo_nombre'] ?? '');
        $email    = sanitizeEmail($_POST['nuevo_email'] ?? '');
        $password = $_POST['nuevo_password'] ?? '';
        $rol      = $_POST['nuevo_rol'] ?? 'editor';

        $result = createAdminUser($nombre, $email, $password, $rol);
        if ($result['success']) {
            adminRedirect('config.php#usuarios', 'success', 'Usuario creado.');
        }
        adminRedirect('config.php#usuarios', 'error', $result['message']);
    }
}

// Cargar config actual
$conf = getConfig();

// Reps para notificaciones
$reps = dbFetchAll("SELECT id, nombre, apellido, email, telefono, activo, COALESCE(notif_activo, 1) as notif_activo FROM reps ORDER BY nombre");

// Jefes: siempre en copia
$jefes = [];
try {
    $jefes = dbFetchAll("SELECT * FROM notif_jefes ORDER BY nombre");
} catch (\Throwable $e) { /* tabla aún no existe */ }

// Admins
$admins = dbFetchAll("SELECT id, nombre, email, rol, activo, ultimo_login FROM admin_usuarios ORDER BY rol, nombre");

adminLayoutOpen('Configuración');
?>

<div class="space-y-6">

    <!-- Configuración general -->
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save_config">
        <div class="card mb-5">
            <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                <i data-lucide="building-2" class="w-4 h-4 text-pk"></i>Datos de la empresa
            </h2>
            <div class="grid sm:grid-cols-2 gap-5">
                <div><label class="form-label">Nombre empresa</label><input type="text" name="nombre_empresa" class="form-input" value="<?= e($conf['nombre_empresa'] ?? '') ?>"></div>
                <div><label class="form-label">Email info (público)</label><input type="email" name="email_info" class="form-input" value="<?= e($conf['email_info'] ?? '') ?>"></div>
                <div><label class="form-label">Email admin (notificaciones)</label><input type="email" name="email_admin" class="form-input" value="<?= e($conf['email_admin'] ?? '') ?>"></div>
                <div><label class="form-label">Teléfono ventas</label><input type="text" name="telefono_ventas" class="form-input" value="<?= e($conf['telefono_ventas'] ?? '') ?>"></div>
                <div><label class="form-label">WhatsApp ventas (con cód. país)</label><input type="text" name="whatsapp_ventas" class="form-input" value="<?= e($conf['whatsapp_ventas'] ?? '') ?>" placeholder="525512345678"></div>
            </div>
        </div>

        <div class="card mb-5">
            <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                <i data-lucide="image" class="w-4 h-4 text-pk"></i>Logos y Colores
            </h2>
            <div class="grid sm:grid-cols-2 gap-5">
                <div><label class="form-label">Logo blanco (ruta)</label><input type="text" name="logo_blanco" class="form-input font-mono text-sm" value="<?= e($conf['logo_blanco'] ?? '') ?>" placeholder="pics/Logo_ParkLife_Blanco.png"></div>
                <div><label class="form-label">Logo color (ruta)</label><input type="text" name="logo_color" class="form-input font-mono text-sm" value="<?= e($conf['logo_color'] ?? '') ?>" placeholder="pics/Logo_Parklife.png"></div>
                <div>
                    <label class="form-label">Color principal</label>
                    <div class="flex gap-2">
                        <input type="color" name="color_primary" class="w-12 h-10 rounded-lg border border-gray-200 cursor-pointer" value="<?= e($conf['color_primary'] ?? '#202944') ?>">
                        <input type="text" class="form-input flex-1 font-mono text-sm" value="<?= e($conf['color_primary'] ?? '#202944') ?>" readonly>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-5">
            <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                <i data-lucide="code" class="w-4 h-4 text-pk"></i>Tracking y Analytics
            </h2>
            <div class="grid sm:grid-cols-2 gap-5">
                <div><label class="form-label">Google Tag ID</label><input type="text" name="google_tag" class="form-input font-mono text-sm" value="<?= e($conf['google_tag'] ?? '') ?>" placeholder="GTM-XXXXXXX"></div>
                <div><label class="form-label">Facebook Pixel ID</label><input type="text" name="facebook_pixel" class="form-input font-mono text-sm" value="<?= e($conf['facebook_pixel'] ?? '') ?>"></div>
                <div><label class="form-label">reCAPTCHA Site Key</label><input type="text" name="recaptcha_site_key" class="form-input font-mono text-sm" value="<?= e($conf['recaptcha_site_key'] ?? '') ?>"></div>
                <div><label class="form-label">Cloudbeds código default</label><input type="text" name="cloudbeds_default" class="form-input font-mono text-sm" value="<?= e($conf['cloudbeds_default'] ?? '') ?>" placeholder="b5VW89"></div>
            </div>
        </div>

        <div class="card mb-5">
            <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                <i data-lucide="link" class="w-4 h-4 text-pk"></i>Integraciones API
            </h2>
            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label class="form-label">Zoho Client ID</label>
                    <input type="text" name="zoho_client_id" class="form-input font-mono text-xs" value="<?= e($conf['zoho_client_id'] ?? '') ?>">
                    <?php if (!empty($conf['zoho_client_id'])): ?>
                        <p class="text-xs text-green-600 mt-1">✓ Configurado</p>
                    <?php else: ?>
                        <p class="text-xs text-red-400 mt-1">⚠ Sin Client ID — Zoho no funcionará</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">Zoho Org ID</label>
                    <input type="text" name="zoho_org_id" class="form-input font-mono text-xs" value="<?= e($conf['zoho_org_id'] ?? '') ?>">
                    <?php if (!empty($conf['zoho_org_id'])): ?>
                        <p class="text-xs text-green-600 mt-1">✓ Configurado: <?= e($conf['zoho_org_id']) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">WhatsApp Phone ID</label>
                    <input type="text" name="wa_phone_id" class="form-input font-mono text-xs" value="<?= e($conf['wa_phone_id'] ?? '') ?>">
                    <?php if (!empty($conf['wa_phone_id'])): ?>
                        <p class="text-xs text-green-600 mt-1">✓ Configurado: <?= e($conf['wa_phone_id']) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">WhatsApp Token</label>
                    <input type="password" name="wa_token" class="form-input font-mono text-xs" placeholder="Dejar vacío para no cambiar">
                    <?php if (!empty($conf['wa_token'])): ?>
                        <p class="text-xs text-green-600 mt-1">✓ Token configurado</p>
                    <?php else: ?>
                        <p class="text-xs text-red-400 mt-1">⚠ Sin token — WhatsApp no funcionará</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">Plantilla WhatsApp</label>
                    <input type="text" name="wa_template" class="form-input font-mono text-sm" value="<?= e($conf['wa_template'] ?? 'avisacomerciales3') ?>">
                </div>
            </div>
        </div>

        <div class="card mb-5 border border-amber-100">
            <h2 class="font-bold text-gray-800 mb-1 flex items-center gap-2">
                <i data-lucide="zap" class="w-4 h-4 text-amber-500"></i>Cloudbeds API
                <span class="text-xs font-normal text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full">Tarifas dinámicas</span>
            </h2>
            <p class="text-xs text-gray-400 mb-4">Con el API Key configurado, los precios "Por noche desde" en cada propiedad se actualizan en tiempo real desde Cloudbeds.</p>
            <div class="grid sm:grid-cols-2 gap-5">
                <div class="sm:col-span-2">
                    <label class="form-label">Cloudbeds API Key</label>
                    <input type="password" name="cloudbeds_api_key" class="form-input font-mono text-xs"
                           placeholder="Dejar vacío para no cambiar"
                           autocomplete="new-password">
                    <p class="text-xs text-gray-400 mt-1">
                        Genera tu API Key en Cloudbeds → Apps &amp; Marketplace → API Access.
                        <?php if (!empty($conf['cloudbeds_api_key'])): ?>
                        <span class="text-green-600 font-medium">✓ API Key configurada</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <label class="form-label">Código widget default</label>
                    <input type="text" name="cloudbeds_default" class="form-input font-mono text-sm" value="<?= e($conf['cloudbeds_default'] ?? '') ?>" placeholder="b5VW89">
                </div>
            </div>
        </div>

        <!-- DeepL Traducción -->
        <div class="card mb-5" id="deepl">
            <h2 class="font-bold text-gray-800 mb-1 flex items-center gap-2">
                <i data-lucide="languages" class="w-4 h-4 text-blue-500"></i>DeepL
                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-semibold ml-1">Traducción automática EN</span>
            </h2>
            <p class="text-xs text-gray-400 mb-4">Traduce automáticamente el contenido de las propiedades al inglés. Plan gratuito: 500,000 caracteres/mes.
               <a href="https://www.deepl.com/pro-api" target="_blank" class="text-blue-500 underline">Obtener API Key gratis →</a>
            </p>
            <div>
                <label class="form-label">DeepL API Key</label>
                <input type="password" name="deepl_api_key" class="form-input font-mono text-xs"
                    autocomplete="off" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx">
                <p class="text-xs text-gray-400 mt-1">
                    <?php if (!empty($conf['deepl_api_key'])): ?>
                        <span class="text-green-600 font-semibold">✓ API Key configurada</span> — el contenido se traduce automáticamente con cache de 30 días.
                    <?php else: ?>
                        Sin API Key: el sitio en inglés mostrará el contenido en español como fallback.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <button type="submit" class="btn-primary px-8 py-3">
            <i data-lucide="save" class="w-4 h-4"></i>Guardar configuración
        </button>
    </form>


        <!-- ─── JEFES SIEMPRE EN COPIA ─── -->
        <div class="card mb-5 border border-blue-100" id="jefes">
            <h2 class="font-bold text-gray-800 mb-1 flex items-center gap-2">
                <i data-lucide="user-check" class="w-4 h-4 text-blue-600"></i>
                Jefes — Siempre en copia
                <span class="text-xs font-normal text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full">Todas las notificaciones</span>
            </h2>
            <p class="text-xs text-gray-400 mb-4">Estos contactos reciben copia de <strong>todas</strong> las notificaciones de leads, independientemente de qué asesor esté asignado. Puedes activar o desactivar cada uno individualmente.</p>

            <!-- Lista de jefes -->
            <div class="space-y-2 mb-4" id="jefesLista">
            <?php foreach ($jefes as $j): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl border <?= $j['activo'] ? 'border-blue-200 bg-blue-50/40' : 'border-gray-200 bg-gray-50' ?>">
                    <div class="w-9 h-9 rounded-full bg-blue-700 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                        <?= strtoupper(substr($j['nombre'], 0, 2)) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-800"><?= e($j['nombre']) ?></p>
                        <p class="text-xs text-gray-400"><?= e($j['email'] ?: '—') ?></p>
                    </div>
                    <span class="font-mono text-xs text-gray-500 hidden sm:block"><?= e($j['telefono']) ?></span>
                    <!-- Toggle activo -->
                    <button type="button"
                        class="notif-toggle w-10 h-6 rounded-full relative transition-all duration-200 cursor-pointer border-0 flex-shrink-0 <?= $j['activo'] ? 'bg-blue-500' : 'bg-gray-300' ?>"
                        data-tipo="jefe"
                        data-id="<?= $j['id'] ?>"
                        data-activo="<?= (int)$j['activo'] ?>"
                        title="<?= $j['activo'] ? 'Desactivar' : 'Activar' ?>">
                        <span class="absolute top-1 w-4 h-4 bg-white rounded-full shadow transition-all duration-200 <?= $j['activo'] ? 'right-1' : 'left-1' ?>"></span>
                    </button>
                    <!-- Editar -->
                    <button onclick="openEditJefe(<?= htmlspecialchars(json_encode($j)) ?>)"
                            class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition-colors" title="Editar">
                        <i data-lucide="pencil" class="w-4 h-4"></i>
                    </button>
                    <!-- Eliminar -->
                    <form method="POST" onsubmit="return confirm('¿Eliminar a <?= e($j['nombre']) ?>?')">
                        <input type="hidden" name="csrf_token" value="<?= adminCsrf() ?>">
                        <input type="hidden" name="action"   value="delete_jefe">
                        <input type="hidden" name="jefe_id"  value="<?= $j['id'] ?>">
                        <button type="submit" class="p-1.5 rounded-lg hover:bg-red-50 text-gray-300 hover:text-red-500 transition-colors" title="Eliminar">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (empty($jefes)): ?>
                <p class="text-sm text-gray-400 text-center py-3">Sin jefes registrados. Agrega uno abajo.</p>
            <?php endif; ?>
            </div>

            <!-- Formulario agregar jefe -->
            <div class="border-t border-gray-100 pt-4">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-3">Agregar jefe</p>
                <form method="POST" class="grid sm:grid-cols-3 gap-3">
                    <input type="hidden" name="csrf_token" value="<?= adminCsrf() ?>">
                    <input type="hidden" name="action"     value="add_jefe">
                    <div>
                        <label class="form-label">Nombre *</label>
                        <input type="text" name="jefe_nombre" class="form-input" placeholder="Ej: Cayetano López" required>
                    </div>
                    <div>
                        <label class="form-label">Teléfono * (con cód. país)</label>
                        <input type="text" name="jefe_telefono" class="form-input font-mono text-sm" placeholder="525513531288" required>
                    </div>
                    <div>
                        <label class="form-label">Email (opcional)</label>
                        <input type="email" name="jefe_email" class="form-input" placeholder="jefe@parklife.mx">
                    </div>
                    <div class="sm:col-span-3">
                        <button type="submit" class="btn-primary px-6 py-2 text-sm">
                            <i data-lucide="plus" class="w-4 h-4"></i>Agregar jefe
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ─── NOTIFICACIONES WHATSAPP ─── -->
        <div class="card mb-5 border border-green-100" id="notif">
            <h2 class="font-bold text-gray-800 mb-1 flex items-center gap-2">
                <i data-lucide="message-circle" class="w-4 h-4 text-green-600"></i>
                Notificaciones WhatsApp
                <span class="text-xs font-normal text-green-700 bg-green-50 px-2 py-0.5 rounded-full">Destinatarios de leads</span>
            </h2>
            <p class="text-xs text-gray-400 mb-4">Activa o desactiva quién recibe la notificación de WhatsApp cuando entra un nuevo lead. El asesor asignado siempre recibe la suya. Los marcados aquí reciben <strong>todas</strong> las notificaciones como copia.</p>

            <div class="space-y-2 mb-4">
                <?php foreach ($reps as $rep): ?>
                    <?php $initiales = strtoupper(substr($rep['nombre'],0,1) . substr($rep['apellido'],0,1)); ?>
                    <div class="flex items-center gap-3 p-3 rounded-xl border <?= $rep['notif_activo'] ? 'border-green-200 bg-green-50/50' : 'border-gray-200 bg-gray-50' ?> transition-all" id="repRow<?= $rep['id'] ?>">
                        <!-- Avatar -->
                        <div class="w-9 h-9 rounded-full bg-park-blue flex items-center justify-center text-white text-xs font-bold flex-shrink-0" style="background:#202944">
                            <?= e($initiales) ?>
                        </div>
                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-800"><?= e($rep['nombre'] . ' ' . $rep['apellido']) ?></p>
                            <p class="text-xs text-gray-400"><?= e($rep['email']) ?></p>
                        </div>
                        <!-- Teléfono editable -->
                        <div class="flex-shrink-0">
                            <input type="text"
                                   name="rep[<?= $rep['id'] ?>][telefono]"
                                   value="<?= e($rep['telefono'] ?? '') ?>"
                                   placeholder="525512345678"
                                   class="form-input font-mono text-xs w-40 py-1.5"
                                   <?= $rep['notif_activo'] ? '' : 'disabled' ?>>
                        </div>
                        <!-- Toggle -->
                        <button type="button"
                            class="notif-toggle w-10 h-6 rounded-full relative transition-all duration-200 cursor-pointer border-0 flex-shrink-0 <?= $rep['notif_activo'] ? 'bg-green-500' : 'bg-gray-300' ?>"
                            data-tipo="rep"
                            data-id="<?= $rep['id'] ?>"
                            data-activo="<?= (int)$rep['notif_activo'] ?>"
                            title="<?= $rep['notif_activo'] ? 'Desactivar' : 'Activar' ?>">
                            <span class="absolute top-1 w-4 h-4 bg-white rounded-full shadow transition-all duration-200 <?= $rep['notif_activo'] ? 'right-1' : 'left-1' ?>"></span>
                        </button>
                    </div>
                <?php endforeach; ?>
                </div>

                <?php if (empty($reps)): ?>
                    <p class="text-sm text-gray-400 text-center py-4">No hay asesores registrados.</p>
                <?php endif; ?>
            </div>
        </div>

    <!-- Cache -->
    <div class="card" id="cache">
        <h2 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <i data-lucide="zap" class="w-4 h-4 text-pk"></i>Cache del sitio
        </h2>
        <p class="text-sm text-gray-600 mb-4">El cache almacena consultas frecuentes de la BD para acelerar el sitio. Límpialo cuando hagas cambios que no se reflejen de inmediato.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="clear_cache">
            <button type="submit" class="btn-secondary">
                <i data-lucide="refresh-cw" class="w-4 h-4"></i>Limpiar cache ahora
            </button>
        </form>
    </div>

    <!-- Usuarios admin -->
    <div class="card" id="usuarios">
        <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
            <i data-lucide="users" class="w-4 h-4 text-pk"></i>Usuarios del panel
        </h2>
        <div class="overflow-x-auto mb-6">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-gray-100">
                    <tr>
                        <th class="table-th">Nombre</th>
                        <th class="table-th">Email</th>
                        <th class="table-th">Rol</th>
                        <th class="table-th">Último login</th>
                        <th class="table-th text-center">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($admins as $admin): ?>
                    <tr>
                        <td class="table-td font-medium"><?= e($admin['nombre']) ?></td>
                        <td class="table-td text-gray-500"><?= e($admin['email']) ?></td>
                        <td class="table-td">
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $admin['rol'] === 'superadmin' ? 'bg-pk text-white' : 'bg-slate-100 text-gray-600' ?>">
                                <?= e($admin['rol']) ?>
                            </span>
                        </td>
                        <td class="table-td text-gray-400 text-xs">
                            <?= $admin['ultimo_login'] ? date('d/m/Y H:i', strtotime($admin['ultimo_login'])) : 'Nunca' ?>
                        </td>
                        <td class="table-td text-center"><?= badgeStatus((int)$admin['activo']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Crear usuario -->
        <details class="border border-gray-200 rounded-xl overflow-hidden">
            <summary class="px-5 py-3 cursor-pointer text-sm font-semibold text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <i data-lucide="user-plus" class="w-4 h-4 text-pk"></i>Crear nuevo usuario
            </summary>
            <div class="p-5 border-t border-gray-100">
                <form method="post" class="grid sm:grid-cols-2 gap-4">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="create_admin">
                    <div><label class="form-label text-xs">Nombre</label><input type="text" name="nuevo_nombre" required class="form-input text-sm"></div>
                    <div><label class="form-label text-xs">Email</label><input type="email" name="nuevo_email" required class="form-input text-sm"></div>
                    <div><label class="form-label text-xs">Contraseña (mín 8 chars)</label><input type="password" name="nuevo_password" required minlength="8" class="form-input text-sm"></div>
                    <div>
                        <label class="form-label text-xs">Rol</label>
                        <select name="nuevo_rol" class="form-select text-sm">
                            <option value="editor">Editor</option>
                            <option value="superadmin">Super Admin</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary text-sm"><i data-lucide="user-plus" class="w-4 h-4"></i>Crear usuario</button>
                    </div>
                </form>
            </div>
        </details>
    </div>

</div>

<!-- Modal editar jefe -->
<div id="modalEditJefe" class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <i data-lucide="pencil" class="w-4 h-4 text-blue-600"></i> Editar jefe
        </h3>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= adminCsrf() ?>">
            <input type="hidden" name="action"   value="edit_jefe">
            <input type="hidden" name="jefe_id"  id="edit_jefe_id">
            <input type="hidden" name="jefe_activo" value="1">
            <div>
                <label class="form-label">Nombre *</label>
                <input type="text" name="jefe_nombre" id="edit_jefe_nombre" class="form-input" required>
            </div>
            <div>
                <label class="form-label">Teléfono * (con cód. país, sin +)</label>
                <input type="text" name="jefe_telefono" id="edit_jefe_telefono" class="form-input font-mono text-sm" required>
            </div>
            <div>
                <label class="form-label">Email</label>
                <input type="email" name="jefe_email" id="edit_jefe_email" class="form-input">
            </div>
            <div class="flex gap-2 pt-2">
                <button type="submit" class="btn-primary px-6 py-2 text-sm flex-1">
                    <i data-lucide="save" class="w-4 h-4"></i>Guardar
                </button>
                <button type="button" onclick="closeEditJefe()"
                    class="px-6 py-2 text-sm rounded-xl border border-gray-200 hover:bg-gray-50 font-semibold text-gray-600">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── TOGGLES AJAX (jefes + reps) ──
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.notif-toggle');
    if (!btn) return;

    const tipo   = btn.dataset.tipo;
    const id     = btn.dataset.id;
    const activo = parseInt(btn.dataset.activo);
    const nuevo  = activo === 1 ? 0 : 1;
    const isJefe = tipo === 'jefe';

    // Feedback visual inmediato
    btn.disabled = true;
    btn.style.opacity = '0.6';

    fetch('/admin/api/toggle-notif.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tipo, id: parseInt(id), valor: nuevo })
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            // Actualizar estado visual
            btn.dataset.activo = nuevo;
            const span = btn.querySelector('span');
            if (nuevo === 1) {
                btn.classList.remove('bg-gray-300');
                btn.classList.add(isJefe ? 'bg-blue-500' : 'bg-green-500');
                if (span) { span.classList.remove('left-1'); span.classList.add('right-1'); }
            } else {
                btn.classList.remove('bg-blue-500', 'bg-green-500');
                btn.classList.add('bg-gray-300');
                if (span) { span.classList.remove('right-1'); span.classList.add('left-1'); }
            }
            // Actualizar fondo de la fila
            const row = btn.closest('.flex.items-center');
            if (row) {
                if (nuevo === 1) {
                    row.classList.remove('border-gray-200', 'bg-gray-50');
                    row.classList.add(isJefe ? 'border-blue-200' : 'border-green-200',
                                     isJefe ? 'bg-blue-50/40'   : 'bg-green-50/50');
                } else {
                    row.classList.remove('border-blue-200', 'bg-blue-50/40', 'border-green-200', 'bg-green-50/50');
                    row.classList.add('border-gray-200', 'bg-gray-50');
                }
                // Habilitar/deshabilitar input teléfono (solo en reps)
                if (!isJefe) {
                    const input = row.querySelector('input[type="text"]');
                    if (input) input.disabled = nuevo !== 1;
                }
            }
        } else {
            alert('Error al guardar: ' + (d.msg || 'intenta de nuevo'));
        }
    })
    .catch(() => alert('Error de conexión'))
    .finally(() => { btn.disabled = false; btn.style.opacity = '1'; });
});

function openEditJefe(j) {
    document.getElementById('edit_jefe_id').value       = j.id;
    document.getElementById('edit_jefe_nombre').value   = j.nombre;
    document.getElementById('edit_jefe_telefono').value = j.telefono;
    document.getElementById('edit_jefe_email').value    = j.email || '';
    document.getElementById('modalEditJefe').classList.remove('hidden');
}
function closeEditJefe() {
    document.getElementById('modalEditJefe').classList.add('hidden');
}
document.getElementById('modalEditJefe').addEventListener('click', function(e) {
    if (e.target === this) closeEditJefe();
});

// Toggle habilita/deshabilita el input de teléfono en tiempo real
document.querySelectorAll('.rep-toggle').forEach(chk => {
    chk.addEventListener('change', function() {
        const repId = this.dataset.rep;
        const row   = document.getElementById('repRow' + repId);
        const input = row.querySelector('input[type="text"]');
        if (this.checked) {
            row.classList.remove('border-gray-200', 'bg-gray-50');
            row.classList.add('border-green-200', 'bg-green-50/50');
            if (input) input.disabled = false;
        } else {
            row.classList.remove('border-green-200', 'bg-green-50/50');
            row.classList.add('border-gray-200', 'bg-gray-50');
            if (input) input.disabled = true;
        }
    });
});
</script>
<?php adminLayoutClose(); ?>