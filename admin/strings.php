<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf    = adminCsrf();
$section = $_GET['section'] ?? 'nav';
$action  = $_GET['action']  ?? 'list';

$secciones = [
    'nav'        => ['icono' => 'navigation',   'label' => 'Navegación'],
    'hero'       => ['icono' => 'layout',        'label' => 'Hero / Portada'],
    'booking'    => ['icono' => 'calendar',      'label' => 'Motor de Reservas'],
    'stats'      => ['icono' => 'bar-chart-2',   'label' => 'Estadísticas'],
    'portafolio' => ['icono' => 'grid',          'label' => 'Portafolio'],
    'prensa'     => ['icono' => 'newspaper',     'label' => 'Prensa'],
    'nosotros'   => ['icono' => 'users',         'label' => 'Nosotros'],
    'faq'        => ['icono' => 'help-circle',   'label' => 'FAQ'],
    'contacto'   => ['icono' => 'mail',          'label' => 'Contacto'],
    'formulario' => ['icono' => 'file-text',     'label' => 'Formularios'],
    'propiedad'  => ['icono' => 'home',          'label' => 'Página Propiedad'],
    'footer'     => ['icono' => 'align-bottom',  'label' => 'Footer'],
    'js'         => ['icono' => 'code',          'label' => 'Mensajes JS'],
];

// ── GUARDAR STRING ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_string') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect("strings.php?section=$section", 'error', 'Token inválido.');
    }
    $id = (int)($_POST['id'] ?? 0);
    $es = trim($_POST['es'] ?? '');
    $en = trim($_POST['en'] ?? '');

    if ($id) {
        dbExecute(
            "UPDATE strings_sitio SET es = ?, en = ? WHERE id = ?",
            [$es, $en, $id]
        );
        dbCacheInvalidate('strings_sitio_all');
        adminRedirect("strings.php?section=$section", 'success', 'String actualizado.');
    }
}

// ── GUARDAR PILAR ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_pilar') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('strings.php?section=nosotros', 'error', 'Token inválido.');
    }
    $pid = (int)($_POST['pid'] ?? 0);
    $data = [
        'icono'         => sanitizeStr($_POST['icono']         ?? ''),
        'titulo'        => sanitizeStr($_POST['titulo']        ?? ''),
        'titulo_en'     => sanitizeStr($_POST['titulo_en']     ?? ''),
        'descripcion'   => sanitizeStr($_POST['descripcion']   ?? '', 500),
        'descripcion_en'=> sanitizeStr($_POST['descripcion_en']?? '', 500),
        'orden'         => (int)($_POST['orden'] ?? 0),
        'activo'        => isset($_POST['activo']) ? 1 : 0,
    ];
    if ($pid) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
        dbExecute("UPDATE pilares SET $sets WHERE id = ?", [...array_values($data), $pid]);
    } else {
        $cols = implode(', ', array_keys($data));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        dbInsert("INSERT INTO pilares ($cols) VALUES ($phs)", array_values($data));
    }
    dbCacheInvalidate('pilares_activos');
    adminRedirect('strings.php?section=nosotros', 'success', 'Pilar guardado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete_pilar') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('strings.php?section=nosotros', 'error', 'Token inválido.');
    }
    dbExecute("DELETE FROM pilares WHERE id = ?", [(int)($_POST['pid'] ?? 0)]);
    dbCacheInvalidate('pilares_activos');
    adminRedirect('strings.php?section=nosotros', 'success', 'Pilar eliminado.');
}

// ── RENDER ────────────────────────────────────────────────────────────────────
adminLayoutOpen('Textos del Sitio');
?>
<div class="flex gap-6">

    <!-- Sidebar secciones -->
    <div class="w-52 flex-shrink-0">
        <nav class="space-y-1">
            <?php foreach ($secciones as $key => $sec): ?>
            <a href="?section=<?= $key ?>"
               class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors
                      <?= $section === $key ? 'bg-pk text-white' : 'text-gray-600 hover:bg-gray-100' ?>">
                <i data-lucide="<?= $sec['icono'] ?>" class="w-4 h-4 flex-shrink-0"></i>
                <?= $sec['label'] ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <!-- Sección especial: pilares -->
        <div class="mt-4 pt-4 border-t border-gray-100">
            <a href="?section=nosotros&tab=pilares"
               class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors
                      <?= ($section === 'nosotros' && ($_GET['tab'] ?? '') === 'pilares') ? 'bg-pk text-white' : 'text-gray-600 hover:bg-gray-100' ?>">
                <i data-lucide="layout-grid" class="w-4 h-4 flex-shrink-0"></i>
                Pilares / Features
            </a>
        </div>
    </div>

    <!-- Contenido -->
    <div class="flex-1 min-w-0">

        <?php
        $tab = $_GET['tab'] ?? '';

        // ── PILARES ──
        if ($section === 'nosotros' && $tab === 'pilares'):
            $pilares = dbFetchAll("SELECT * FROM pilares ORDER BY orden");
            $editPilar = $action === 'edit' ? dbFetchOne("SELECT * FROM pilares WHERE id = ?", [(int)($_GET['id'] ?? 0)]) : null;
        ?>
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-bold text-gray-800 text-lg">Pilares / Features</h2>
            <a href="?section=nosotros&tab=pilares&action=edit" class="btn-primary">
                <i data-lucide="plus" class="w-4 h-4"></i>Nuevo pilar
            </a>
        </div>

        <?php if ($action === 'edit'): ?>
        <div class="card mb-6">
            <h3 class="font-semibold text-gray-700 mb-4"><?= $editPilar ? 'Editar pilar' : 'Nuevo pilar' ?></h3>
            <form method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="_action" value="save_pilar">
                <input type="hidden" name="pid" value="<?= (int)($editPilar['id'] ?? 0) ?>">
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Ícono Lucide</label>
                        <input type="text" name="icono" class="form-input" value="<?= e($editPilar['icono'] ?? 'star') ?>" placeholder="sparkles">
                        <p class="text-xs text-gray-400 mt-1">Ver íconos: <a href="https://lucide.dev/icons" target="_blank" class="text-pk">lucide.dev/icons</a></p>
                    </div>
                    <div>
                        <label class="form-label">Orden</label>
                        <input type="number" name="orden" class="form-input" value="<?= $editPilar['orden'] ?? 0 ?>" min="0">
                    </div>
                    <div>
                        <label class="form-label">Título ES</label>
                        <input type="text" name="titulo" id="pilar_titulo" class="form-input" value="<?= e($editPilar['titulo'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label class="form-label flex items-center gap-2">
                            <span class="text-blue-600">🇺🇸 Título EN</span>
                            <button type="button" onclick="autoTranslate('pilar_titulo','pilar_titulo_en')"
                                class="text-xs px-2 py-0.5 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded-full">🤖 Auto</button>
                        </label>
                        <input type="text" name="titulo_en" id="pilar_titulo_en" class="form-input border-blue-200" value="<?= e($editPilar['titulo_en'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="form-label">Descripción ES</label>
                        <textarea name="descripcion" id="pilar_desc" class="form-textarea" rows="3"><?= e($editPilar['descripcion'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="form-label flex items-center gap-2">
                            <span class="text-blue-600">🇺🇸 Descripción EN</span>
                            <button type="button" onclick="autoTranslate('pilar_desc','pilar_desc_en')"
                                class="text-xs px-2 py-0.5 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded-full">🤖 Auto</button>
                        </label>
                        <textarea name="descripcion_en" id="pilar_desc_en" class="form-textarea border-blue-200" rows="3"><?= e($editPilar['descripcion_en'] ?? '') ?></textarea>
                    </div>
                </div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="activo" class="w-4 h-4 accent-pk" <?= !empty($editPilar['activo']) ? 'checked' : '' ?>>
                    <span class="text-sm">Activo</span>
                </label>
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i>Guardar</button>
                    <a href="?section=nosotros&tab=pilares" class="btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="card p-0 overflow-hidden">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-gray-100">
                    <tr>
                        <th class="table-th">Ícono</th>
                        <th class="table-th">Título</th>
                        <th class="table-th">EN</th>
                        <th class="table-th text-center">Orden</th>
                        <th class="table-th text-center">Activo</th>
                        <th class="table-th"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($pilares as $p): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="table-td"><i data-lucide="<?= e($p['icono']) ?>" class="w-5 h-5 text-pk"></i></td>
                        <td class="table-td font-medium"><?= e($p['titulo']) ?></td>
                        <td class="table-td text-gray-400 text-sm"><?= e($p['titulo_en'] ?? '—') ?></td>
                        <td class="table-td text-center text-sm"><?= $p['orden'] ?></td>
                        <td class="table-td text-center">
                            <span class="text-xs px-2 py-0.5 rounded-full <?= $p['activo'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400' ?>">
                                <?= $p['activo'] ? '✓' : '—' ?>
                            </span>
                        </td>
                        <td class="table-td text-right">
                            <div class="flex gap-2 justify-end">
                                <a href="?section=nosotros&tab=pilares&action=edit&id=<?= $p['id'] ?>" class="btn-sm btn-secondary"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
                                <form method="post" onsubmit="return confirm('¿Eliminar?')">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="_action" value="delete_pilar">
                                    <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn-sm btn-danger"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php else:
        // ── STRINGS POR SECCIÓN ──
        $strings = dbFetchAll(
            "SELECT * FROM strings_sitio WHERE seccion = ? ORDER BY clave",
            [$section]
        );
        $secLabel = $secciones[$section]['label'] ?? $section;
        ?>

        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="font-bold text-gray-800 text-lg"><?= $secLabel ?></h2>
                <p class="text-sm text-gray-400"><?= count($strings) ?> textos en esta sección</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-blue-600 bg-blue-50 px-3 py-1.5 rounded-lg">
                    🇺🇸 Los campos EN se muestran cuando el visitante navega en inglés
                </span>
            </div>
        </div>

        <div class="space-y-3">
            <?php foreach ($strings as $str): ?>
            <div class="card" id="str-<?= $str['id'] ?>">
                <div class="flex items-start gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-3">
                            <code class="text-xs bg-gray-100 px-2 py-0.5 rounded font-mono text-gray-500"><?= e($str['clave']) ?></code>
                            <?php if ($str['descripcion']): ?>
                            <span class="text-xs text-gray-400"><?= e($str['descripcion']) ?></span>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="grid sm:grid-cols-2 gap-3">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="_action" value="save_string">
                            <input type="hidden" name="id" value="<?= $str['id'] ?>">
                            <div>
                                <label class="form-label text-xs">🇲🇽 Español</label>
                                <?php if (strlen($str['es']) > 80): ?>
                                <textarea name="es" id="es_<?= $str['id'] ?>" class="form-textarea text-sm" rows="2"><?= e($str['es']) ?></textarea>
                                <?php else: ?>
                                <input type="text" name="es" id="es_<?= $str['id'] ?>" class="form-input text-sm" value="<?= e($str['es']) ?>">
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="form-label text-xs flex items-center gap-2">
                                    <span class="text-blue-600">🇺🇸 English</span>
                                    <button type="button"
                                            onclick="autoTranslate('es_<?= $str['id'] ?>','en_<?= $str['id'] ?>')"
                                            class="text-xs px-2 py-0.5 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded-full transition-colors">
                                        🤖 Auto
                                    </button>
                                </label>
                                <?php if (strlen($str['en']) > 80 || strlen($str['es']) > 80): ?>
                                <textarea name="en" id="en_<?= $str['id'] ?>" class="form-textarea text-sm border-blue-200" rows="2"><?= e($str['en']) ?></textarea>
                                <?php else: ?>
                                <input type="text" name="en" id="en_<?= $str['id'] ?>" class="form-input text-sm border-blue-200" value="<?= e($str['en']) ?>">
                                <?php endif; ?>
                            </div>
                            <div class="sm:col-span-2 flex justify-end">
                                <button type="submit"
                                        class="btn-sm btn-primary text-xs">
                                    <i data-lucide="save" class="w-3.5 h-3.5"></i>Guardar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>
</div>

<script>
async function autoTranslate(sourceId, targetId) {
    const source = document.getElementById(sourceId);
    const target = document.getElementById(targetId);
    const texto  = source?.value.trim();
    if (!texto) { alert('El campo en español está vacío.'); return; }

    const btn = event.target;
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '...';

    const fd = new FormData();
    fd.append('texto', texto);
    try {
        const r = await fetch('/api/auto-translate.php', { method:'POST', body:fd, credentials:'same-origin' });
        const d = await r.json();
        if (d.traduccion) {
            target.value = d.traduccion;
            target.style.background = '#f0fdf4';
            setTimeout(() => target.style.background = '', 2000);
        } else {
            alert('Error: ' + (d.error || 'Sin respuesta'));
        }
    } catch(e) { alert('Error de conexión'); }
    finally { btn.disabled = false; btn.textContent = orig; }
}
</script>

<?php adminLayoutClose(); ?>
