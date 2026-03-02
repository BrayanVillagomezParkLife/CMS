<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf   = adminCsrf();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) adminRedirect('faqs.php', 'error', 'Token inválido.');
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete') {
        dbExecute("DELETE FROM faqs WHERE id = ?", [(int)$_POST['id']]);
        dbCacheInvalidate();
        adminRedirect('faqs.php', 'success', 'FAQ eliminada.');
    }

    $data = [
        'propiedad_id' => $_POST['propiedad_id'] ? (int)$_POST['propiedad_id'] : null,
        'pregunta'     => sanitizeStr($_POST['pregunta'] ?? '', 500),
        'pregunta_en'  => sanitizeStr($_POST['pregunta_en'] ?? '', 500),
        'respuesta'    => sanitizeStr($_POST['respuesta'] ?? '', 2000),
        'respuesta_en' => sanitizeStr($_POST['respuesta_en'] ?? '', 2000),
        'orden'        => (int)($_POST['orden'] ?? 0),
        'activa'       => isset($_POST['activa']) ? 1 : 0,
    ];

    $editId = (int)($_POST['id'] ?? 0);
    if ($editId) {
        dbExecute(
            "UPDATE faqs SET propiedad_id=?,pregunta=?,pregunta_en=?,respuesta=?,respuesta_en=?,orden=?,activa=? WHERE id=?",
            [...array_values($data), $editId]
        );
    } else {
        dbInsert(
            "INSERT INTO faqs (propiedad_id,pregunta,pregunta_en,respuesta,respuesta_en,orden,activa) VALUES (?,?,?,?,?,?,?)",
            array_values($data)
        );
    }
    dbCacheInvalidate();
    adminRedirect('faqs.php', 'success', $editId ? 'FAQ actualizada.' : 'FAQ creada.');
}

$propiedades = dbFetchAll("SELECT id, nombre FROM propiedades WHERE activo=1 ORDER BY nombre");

// ── FORMULARIO ────────────────────────────────────────────────────────────────
if (in_array($action, ['new','edit'])) {
    $faq = $id ? dbFetchOne("SELECT * FROM faqs WHERE id=?", [$id]) : null;
    if ($action === 'edit' && !$faq) adminRedirect('faqs.php', 'error', 'FAQ no encontrada.');
    adminLayoutOpen($faq ? 'Editar FAQ' : 'Nueva FAQ');
    $v = $faq ?? [];
    ?>
    <div class="mb-6"><a href="faqs.php" class="text-sm text-gray-500 hover:text-pk flex items-center gap-1"><i data-lucide="arrow-left" class="w-4 h-4"></i>Volver</a></div>

    <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-xl flex items-center gap-3 text-sm text-blue-700">
        <i data-lucide="globe" class="w-4 h-4 flex-shrink-0"></i>
        Los campos <strong>EN</strong> se muestran a visitantes en inglés. Usa <strong>Auto-traducir</strong> para sugerir una traducción.
    </div>

    <form method="post" class="space-y-5 max-w-3xl">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($v['id'] ?? 0) ?>">

        <div class="card space-y-5">
            <div>
                <label class="form-label">Propiedad <span class="text-gray-400 font-normal">(vacío = global)</span></label>
                <select name="propiedad_id" class="form-select">
                    <option value="">Global (todas las propiedades)</option>
                    <?php foreach ($propiedades as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= ($v['propiedad_id'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Pregunta ES + EN -->
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Pregunta *</label>
                    <input type="text" name="pregunta" id="pregunta" required class="form-input"
                           value="<?= e($v['pregunta'] ?? '') ?>" placeholder="¿Cuál es el plazo mínimo?">
                </div>
                <div>
                    <label class="form-label flex items-center gap-2">
                        <span class="text-blue-600">🇺🇸 Pregunta EN</span>
                        <button type="button" onclick="autoTranslate('pregunta','pregunta_en')"
                                class="text-xs px-2 py-0.5 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded-full">🤖 Auto-traducir</button>
                    </label>
                    <input type="text" name="pregunta_en" id="pregunta_en" class="form-input border-blue-200"
                           value="<?= e($v['pregunta_en'] ?? '') ?>">
                </div>
            </div>

            <!-- Respuesta ES + EN -->
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Respuesta *</label>
                    <textarea name="respuesta" id="respuesta" required class="form-textarea" rows="5"><?= e($v['respuesta'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="form-label flex items-center gap-2">
                        <span class="text-blue-600">🇺🇸 Respuesta EN</span>
                        <button type="button" onclick="autoTranslate('respuesta','respuesta_en')"
                                class="text-xs px-2 py-0.5 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded-full">🤖 Auto-traducir</button>
                    </label>
                    <textarea name="respuesta_en" id="respuesta_en" class="form-textarea border-blue-200" rows="5"><?= e($v['respuesta_en'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="flex gap-5">
                <div class="w-24">
                    <label class="form-label">Orden</label>
                    <input type="number" name="orden" class="form-input" value="<?= $v['orden'] ?? 0 ?>" min="0">
                </div>
                <div class="flex items-end pb-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="activa" class="w-4 h-4 accent-pk" <?= !empty($v['activa']) ? 'checked' : '' ?>>
                        <span class="text-sm font-medium text-gray-700">Activa</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i>Guardar</button>
            <a href="faqs.php" class="btn-secondary">Cancelar</a>
        </div>
    </form>

    <script>
    async function autoTranslate(sourceId, targetId) {
        const source = document.getElementById(sourceId);
        const target = document.getElementById(targetId);
        const texto  = source?.value.trim();
        if (!texto) { alert('El campo en español está vacío.'); return; }

        const btn = event.target;
        btn.disabled = true;
        btn.textContent = 'Traduciendo…';

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
        finally {
            btn.disabled = false;
            btn.textContent = '🤖 Auto-traducir';
        }
    }
    </script>
    <?php adminLayoutClose(); exit;
}

// ── LISTADO ───────────────────────────────────────────────────────────────────
$faqs = dbFetchAll(
    "SELECT f.*, p.nombre AS prop_nombre FROM faqs f
     LEFT JOIN propiedades p ON f.propiedad_id = p.id
     ORDER BY f.propiedad_id IS NULL DESC, p.nombre, f.orden, f.id"
);
adminLayoutOpen('FAQs');
?>
<div class="flex justify-end mb-5">
    <a href="?action=new" class="btn-primary"><i data-lucide="plus" class="w-4 h-4"></i>Nueva FAQ</a>
</div>
<div class="card p-0 overflow-hidden">
    <table class="w-full">
        <thead class="bg-slate-50 border-b border-gray-100">
            <tr>
                <th class="table-th">Pregunta</th>
                <th class="table-th">Propiedad</th>
                <th class="table-th text-center">EN</th>
                <th class="table-th text-center">Orden</th>
                <th class="table-th text-center">Estado</th>
                <th class="table-th"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            <?php foreach ($faqs as $f): ?>
            <tr class="hover:bg-slate-50">
                <td class="table-td max-w-xs">
                    <div class="font-medium text-gray-800 truncate"><?= e($f['pregunta']) ?></div>
                    <?php if ($f['pregunta_en'] ?? ''): ?>
                    <div class="text-xs text-blue-400 truncate"><?= e($f['pregunta_en']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="table-td text-sm text-gray-500"><?= $f['prop_nombre'] ? e($f['prop_nombre']) : '<span class="text-gray-300">Global</span>' ?></td>
                <td class="table-td text-center text-xs <?= !empty($f['pregunta_en']) ? 'text-green-600' : 'text-gray-300' ?>">
                    <?= !empty($f['pregunta_en']) ? '✓' : '—' ?>
                </td>
                <td class="table-td text-center text-sm text-gray-400"><?= $f['orden'] ?></td>
                <td class="table-td text-center">
                    <span class="inline-flex text-xs px-2 py-0.5 rounded-full font-medium <?= $f['activa'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400' ?>">
                        <?= $f['activa'] ? 'Activa' : 'Inactiva' ?>
                    </span>
                </td>
                <td class="table-td text-right">
                    <div class="flex items-center gap-2 justify-end">
                        <a href="?action=edit&id=<?= $f['id'] ?>" class="btn-sm btn-secondary"><i data-lucide="pencil" class="w-3.5 h-3.5"></i>Editar</a>
                        <form method="post" onsubmit="return confirm('¿Eliminar esta FAQ?')">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $f['id'] ?>">
                            <button type="submit" class="btn-sm btn-danger"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php adminLayoutClose(); ?>
