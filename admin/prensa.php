<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf   = adminCsrf();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('prensa.php', 'error', 'Token inválido.');
    }
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete') {
        $delId = (int)$_POST['id'];
        $nota  = dbFetchOne("SELECT imagen_url FROM prensa WHERE id=?", [$delId]);
        if ($nota && $nota['imagen_url'] && !str_starts_with($nota['imagen_url'], 'http')) {
            $file = __DIR__ . '/../' . $nota['imagen_url'];
            if (file_exists($file)) @unlink($file);
        }
        dbExecute("DELETE FROM prensa WHERE id=?", [$delId]);
        dbCacheInvalidate();
        adminRedirect('prensa.php', 'success', 'Nota eliminada.');
    }

    // Guardar
    $editId = (int)($_POST['id'] ?? 0);

    // Manejar imagen de logo del medio
    $imagenUrl = sanitizeStr($_POST['imagen_url_actual'] ?? '');
    if (!empty($_FILES['imagen']['tmp_name'])) {
        $file    = $_FILES['imagen'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
        $mime    = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowed)) {
            adminRedirect('prensa.php', 'error', 'Tipo no permitido (JPG, PNG, WebP, SVG).');
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            adminRedirect('prensa.php', 'error', 'Imagen demasiado grande (máx 5MB).');
        }
        $ext      = match($mime) { 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', default => 'jpg' };
        $filename = 'prensa_' . time() . '_' . rand(100, 999) . '.' . $ext;
        $dir      = __DIR__ . '/../pics/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            if ($imagenUrl && !str_starts_with($imagenUrl, 'http')) {
                $old = __DIR__ . '/../' . $imagenUrl;
                if (file_exists($old)) @unlink($old);
            }
            $imagenUrl = 'pics/' . $filename;
        }
    }

    $fechaPublicacion = $_POST['fecha_publicacion'] ?? '';
    if (!$fechaPublicacion || !strtotime($fechaPublicacion)) {
        $fechaPublicacion = null;
    }

    $data = [
        'medio'              => sanitizeStr($_POST['medio'] ?? ''),
        'titulo'             => sanitizeStr($_POST['titulo'] ?? '', 500),
        'extracto'           => sanitizeStr($_POST['extracto'] ?? '', 1000),
        'url_articulo'       => sanitizeStr($_POST['url_articulo'] ?? '', 500),
        'imagen_url'         => $imagenUrl ?: null,
        'fecha_publicacion'  => $fechaPublicacion,
        'activo'             => isset($_POST['activo']) ? 1 : 0,
        'orden'              => (int)($_POST['orden'] ?? 0),
    ];

    if (!$data['medio'] || !$data['titulo']) {
        adminRedirect('prensa.php?action=' . ($editId ? 'edit&id=' . $editId : 'new'), 'error', 'Medio y título son obligatorios.');
    }

    if ($editId) {
        $sets = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        dbExecute("UPDATE prensa SET $sets WHERE id=?", [...array_values($data), $editId]);
        dbCacheInvalidate();
        adminRedirect('prensa.php', 'success', 'Nota actualizada.');
    } else {
        $cols = implode(',', array_keys($data));
        $phs  = implode(',', array_fill(0, count($data), '?'));
        dbInsert("INSERT INTO prensa ($cols) VALUES ($phs)", array_values($data));
        dbCacheInvalidate();
        adminRedirect('prensa.php', 'success', 'Nota creada.');
    }
}

// ── FORMULARIO ────────────────────────────────────────────────────────────────
if (in_array($action, ['new', 'edit'])) {
    $nota = $id ? dbFetchOne("SELECT * FROM prensa WHERE id=?", [$id]) : null;
    if ($action === 'edit' && !$nota) adminRedirect('prensa.php', 'error', 'Nota no encontrada.');
    $v = $nota ?? [];

    adminLayoutOpen($nota ? 'Editar nota de prensa' : 'Nueva nota de prensa');
    ?>
    <div class="mb-6">
        <a href="prensa.php" class="text-sm text-gray-500 hover:text-pk flex items-center gap-1 w-fit">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver a prensa
        </a>
    </div>

    <form method="post" enctype="multipart/form-data" class="space-y-5 max-w-2xl">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($v['id'] ?? 0) ?>">
        <input type="hidden" name="imagen_url_actual" value="<?= e($v['imagen_url'] ?? '') ?>">

        <div class="card space-y-5">
            <h2 class="font-bold text-gray-800 flex items-center gap-2">
                <i data-lucide="newspaper" class="w-4 h-4 text-pk"></i>Datos de la nota
            </h2>

            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label class="form-label">Medio / Publicación *</label>
                    <input type="text" name="medio" required class="form-input"
                           value="<?= e($v['medio'] ?? '') ?>"
                           placeholder="El Universal, Forbes, Expansión...">
                </div>
                <div>
                    <label class="form-label">Fecha de publicación</label>
                    <input type="date" name="fecha_publicacion" class="form-input"
                           value="<?= e($v['fecha_publicacion'] ?? '') ?>">
                </div>
            </div>

            <div>
                <label class="form-label">Título del artículo *</label>
                <input type="text" name="titulo" required class="form-input"
                       value="<?= e($v['titulo'] ?? '') ?>"
                       placeholder="Park Life Properties llega a Querétaro con nuevo desarrollo premium">
            </div>

            <div>
                <label class="form-label">Extracto <span class="text-gray-400 font-normal">(resumen corto que aparece en el sitio)</span></label>
                <textarea name="extracto" class="form-textarea" rows="3"
                          placeholder="Breve descripción del artículo..."><?= e($v['extracto'] ?? '') ?></textarea>
            </div>

            <div>
                <label class="form-label">URL del artículo original</label>
                <input type="url" name="url_articulo" class="form-input"
                       value="<?= e($v['url_articulo'] ?? '') ?>"
                       placeholder="https://www.eluniversal.com.mx/...">
            </div>
        </div>

        <!-- Logo del medio -->
        <div class="card">
            <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                <i data-lucide="image" class="w-4 h-4 text-pk"></i>Logo o imagen del medio
            </h2>

            <?php if (!empty($v['imagen_url'])): ?>
            <div class="mb-4 h-24 bg-slate-50 rounded-xl border border-gray-100 flex items-center justify-center p-4">
                <img src="<?= '/' . e($v['imagen_url']) ?>" alt="Logo" class="max-h-full max-w-full object-contain" id="img-preview">
            </div>
            <?php else: ?>
            <div class="mb-4 h-24 bg-slate-50 rounded-xl border-2 border-dashed border-gray-200 flex items-center justify-center" id="img-placeholder">
                <p class="text-sm text-gray-400">Vista previa del logo</p>
            </div>
            <?php endif; ?>

            <div>
                <label class="form-label">
                    <?= $nota ? 'Cambiar imagen' : 'Subir logo' ?>
                    <span class="text-gray-400 font-normal">(JPG, PNG, WebP, SVG · máx 5MB)</span>
                </label>
                <input type="file" name="imagen" accept="image/*" class="form-input py-2" onchange="previewLogo(this)">
            </div>
            <p class="text-xs text-gray-400 mt-2">También puedes usar una URL externa en su lugar — déjalo vacío y el sistema usará el nombre del medio como texto.</p>
        </div>

        <!-- Opciones -->
        <div class="card">
            <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                <i data-lucide="sliders" class="w-4 h-4 text-pk"></i>Opciones
            </h2>
            <div class="flex gap-8 items-center">
                <div class="w-28">
                    <label class="form-label">Orden</label>
                    <input type="number" name="orden" class="form-input" value="<?= $v['orden'] ?? 0 ?>" min="0">
                </div>
                <div class="flex items-end pb-0.5">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="activo" class="w-4 h-4 accent-pk rounded"
                               <?= !isset($v['activo']) || $v['activo'] ? 'checked' : '' ?>>
                        <span class="text-sm font-medium text-gray-700">Nota activa (visible en el sitio)</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="btn-primary px-8 py-3">
                <i data-lucide="save" class="w-4 h-4"></i>
                <?= $nota ? 'Guardar cambios' : 'Crear nota' ?>
            </button>
            <a href="prensa.php" class="btn-secondary px-6 py-3">Cancelar</a>
            <?php if (!empty($v['url_articulo'])): ?>
            <a href="<?= e($v['url_articulo']) ?>" target="_blank" class="btn-secondary px-6 py-3 ml-auto">
                <i data-lucide="external-link" class="w-4 h-4"></i>Ver artículo
            </a>
            <?php endif; ?>
        </div>
    </form>

    <script>
    function previewLogo(input) {
        if (!input.files || !input.files[0]) return;
        const reader = new FileReader();
        reader.onload = e => {
            let prev = document.getElementById('img-preview') || document.getElementById('img-placeholder');
            if (!prev) return;
            const wrap = prev.closest('.card div') || prev.parentElement;
            const img  = document.createElement('img');
            img.src    = e.target.result;
            img.className = 'max-h-full max-w-full object-contain';
            img.id     = 'img-preview';
            prev.replaceWith(img);
        };
        reader.readAsDataURL(input.files[0]);
    }
    </script>
    <?php
    adminLayoutClose();
    exit;
}

// ── LISTADO ────────────────────────────────────────────────────────────────────
$notas = dbFetchAll(
    "SELECT * FROM prensa ORDER BY orden, fecha_publicacion DESC, id DESC"
);

adminLayoutOpen('Prensa');
?>

<div class="flex items-center justify-between mb-6">
    <p class="text-sm text-gray-500"><?= count($notas) ?> nota<?= count($notas) != 1 ? 's' : '' ?> de prensa</p>
    <a href="?action=new" class="btn-primary">
        <i data-lucide="plus" class="w-4 h-4"></i>Nueva nota
    </a>
</div>

<?php if (empty($notas)): ?>
<div class="card text-center py-20">
    <i data-lucide="newspaper" class="w-14 h-14 mx-auto mb-4 text-gray-200"></i>
    <h3 class="font-bold text-gray-500 mb-2">Sin notas de prensa</h3>
    <p class="text-gray-400 text-sm mb-6">Las notas aparecen en la sección "En los medios" del sitio web.</p>
    <a href="?action=new" class="btn-primary">
        <i data-lucide="plus" class="w-4 h-4"></i>Agregar primera nota
    </a>
</div>
<?php else: ?>

<div class="card p-0 overflow-hidden">
    <table class="w-full">
        <thead class="bg-slate-50 border-b border-gray-100">
            <tr>
                <th class="table-th">Logo</th>
                <th class="table-th">Medio / Título</th>
                <th class="table-th">Fecha</th>
                <th class="table-th text-center">Orden</th>
                <th class="table-th text-center">Estado</th>
                <th class="table-th"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            <?php foreach ($notas as $nota): ?>
            <tr class="hover:bg-slate-50 transition-colors">
                <!-- Logo -->
                <td class="table-td w-20">
                    <?php if ($nota['imagen_url']): ?>
                    <div class="w-14 h-10 bg-white rounded-lg border border-gray-100 flex items-center justify-center p-1.5 overflow-hidden">
                        <img src="<?= '/' . e($nota['imagen_url']) ?>"
                             alt="<?= e($nota['medio']) ?>"
                             class="max-w-full max-h-full object-contain">
                    </div>
                    <?php else: ?>
                    <div class="w-14 h-10 bg-slate-100 rounded-lg flex items-center justify-center">
                        <i data-lucide="newspaper" class="w-4 h-4 text-gray-400"></i>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Info -->
                <td class="table-td max-w-xs">
                    <div class="font-semibold text-pk text-sm"><?= e($nota['medio']) ?></div>
                    <div class="text-gray-700 text-sm truncate mt-0.5"><?= e($nota['titulo']) ?></div>
                    <?php if ($nota['url_articulo']): ?>
                    <a href="<?= e($nota['url_articulo']) ?>" target="_blank"
                       class="text-xs text-gray-400 hover:text-pk flex items-center gap-1 mt-0.5 w-fit">
                        <i data-lucide="external-link" class="w-3 h-3"></i>Ver artículo
                    </a>
                    <?php endif; ?>
                </td>

                <!-- Fecha -->
                <td class="table-td text-sm text-gray-500 whitespace-nowrap">
                    <?= $nota['fecha_publicacion'] ? date('d/m/Y', strtotime($nota['fecha_publicacion'])) : '—' ?>
                </td>

                <!-- Orden -->
                <td class="table-td text-center text-gray-500"><?= $nota['orden'] ?></td>

                <!-- Estado -->
                <td class="table-td text-center"><?= badgeStatus((int)$nota['activo']) ?></td>

                <!-- Acciones -->
                <td class="table-td">
                    <div class="flex items-center gap-2 justify-end">
                        <a href="?action=edit&id=<?= $nota['id'] ?>"
                           class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-pk transition-colors" title="Editar">
                            <i data-lucide="pencil" class="w-4 h-4"></i>
                        </a>
                        <form method="post" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $nota['id'] ?>">
                            <button type="submit"
                                    data-confirm="¿Eliminar la nota de '<?= e($nota['medio']) ?>'?"
                                    class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500 transition-colors" title="Eliminar">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php adminLayoutClose(); ?>
