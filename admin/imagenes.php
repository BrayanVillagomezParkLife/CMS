<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf        = adminCsrf();
$propId      = (int)($_GET['propiedad_id'] ?? 0);
$propiedades = dbFetchAll("SELECT id, nombre, slug FROM propiedades WHERE activo = 1 ORDER BY nombre");

$propActual = null;
if ($propId) $propActual = dbFetchOne("SELECT * FROM propiedades WHERE id = ?", [$propId]);
if (!$propActual && !empty($propiedades)) {
    $propActual = $propiedades[0];
    $propId     = (int)$propActual['id'];
}

$tipos = [
    'hero'    => ['label' => 'Hero',     'desc' => 'Banner principal de la propiedad',      'icon' => 'image',       'max' => 5],
    'galeria' => ['label' => 'Galería',  'desc' => 'Interior, espacios y amenidades',       'icon' => 'images',      'max' => 30],
    'card'    => ['label' => 'Card',     'desc' => 'Miniatura para listados',               'icon' => 'layout-grid', 'max' => 1],
    'og'      => ['label' => 'OG / SEO', 'desc' => 'Redes sociales (1200×630)',             'icon' => 'share-2',     'max' => 1],
    'zona'    => ['label' => 'Zona',     'desc' => 'Fotos del barrio y alrededores',        'icon' => 'map-pin',     'max' => 10],
];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // add_from_picker responde siempre JSON
    if ($postAction === 'add_from_picker') {
        header('Content-Type: application/json');
        if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Token inválido']); exit;
        }
        $url     = sanitizeStr($_POST['url'] ?? '');
        $tipo    = sanitizeStr($_POST['tipo'] ?? 'galeria');
        $portada = isset($_POST['es_portada']) ? 1 : 0;
        if ($url && $propId) {
            if ($portada) dbExecute("UPDATE propiedad_imagenes SET es_portada=0 WHERE propiedad_id=? AND tipo=?", [$propId, $tipo]);
            $orden = (int)dbFetchValue("SELECT COALESCE(MAX(orden),0)+1 FROM propiedad_imagenes WHERE propiedad_id=?", [$propId]);
            dbInsert("INSERT INTO propiedad_imagenes (propiedad_id,tipo,url,alt,es_portada,orden) VALUES (?,?,?,?,?,?)",
                [$propId, $tipo, $url, '', $portada, $orden]);
            dbCacheInvalidate();
        }
        echo json_encode(['success' => true]); exit;
    }

    if (!adminVerifyCsrf($_POST['csrf_token'] ?? ''))
        adminRedirect("imagenes.php?propiedad_id=$propId", 'error', 'Token inválido.');

    if ($postAction === 'update') {
        $imgId   = (int)$_POST['img_id'];
        $alt     = sanitizeStr($_POST['alt'] ?? '');
        $orden   = (int)$_POST['orden'];
        $portada = isset($_POST['es_portada']) ? 1 : 0;
        if ($portada) {
            $tipo = dbFetchValue("SELECT tipo FROM propiedad_imagenes WHERE id=?", [$imgId]);
            dbExecute("UPDATE propiedad_imagenes SET es_portada=0 WHERE propiedad_id=? AND tipo=?", [$propId, $tipo]);
        }
        dbExecute("UPDATE propiedad_imagenes SET alt=?,orden=?,es_portada=? WHERE id=? AND propiedad_id=?",
            [$alt, $orden, $portada, $imgId, $propId]);
        dbCacheInvalidate();
        adminRedirect("imagenes.php?propiedad_id=$propId", 'success', 'Imagen actualizada.');
    }

    if ($postAction === 'delete') {
        $imgId = (int)$_POST['img_id'];
        $img   = dbFetchOne("SELECT url FROM propiedad_imagenes WHERE id=? AND propiedad_id=?", [$imgId, $propId]);
        if ($img) {
            $file = __DIR__ . '/../' . $img['url'];
            if (file_exists($file)) @unlink($file);
            dbExecute("DELETE FROM propiedad_imagenes WHERE id=?", [$imgId]);
            dbCacheInvalidate();
        }
        adminRedirect("imagenes.php?propiedad_id=$propId", 'success', 'Imagen eliminada.');
    }

    // Limpiar registros con archivos inexistentes
    if ($postAction === 'limpiar_rotos') {
        $todas = dbFetchAll("SELECT id, url FROM propiedad_imagenes WHERE propiedad_id=?", [$propId]);
        $eliminados = 0;
        foreach ($todas as $img) {
            $file = __DIR__ . '/../' . $img['url'];
            if (!file_exists($file)) {
                dbExecute("DELETE FROM propiedad_imagenes WHERE id=?", [$img['id']]);
                $eliminados++;
            }
        }
        dbCacheInvalidate();
        adminRedirect("imagenes.php?propiedad_id=$propId", 'success', "Se eliminaron $eliminados registro(s) con archivos rotos.");
    }
}

$imagenes = $propId ? dbFetchAll(
    "SELECT * FROM propiedad_imagenes WHERE propiedad_id=? ORDER BY tipo, orden, id", [$propId]
) : [];

$propSlug = $propActual['slug'] ?? 'general';
adminLayoutOpen('Imágenes' . ($propActual ? ' — ' . $propActual['nombre'] : ''));
?>

<!-- Selector propiedad -->
<div class="flex items-center gap-4 mb-6">
    <form method="get" class="flex items-center gap-3">
        <label class="form-label mb-0 text-sm whitespace-nowrap">Propiedad:</label>
        <select name="propiedad_id" class="form-select text-sm" onchange="this.form.submit()">
            <?php foreach ($propiedades as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $propId == $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($propActual): ?>
    <div class="ml-auto flex items-center gap-2">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="limpiar_rotos">
            <button type="submit"
                    data-confirm="¿Eliminar todos los registros con archivos de imagen inexistentes?"
                    class="btn-secondary text-xs py-1.5 px-3 text-amber-600 border-amber-200 hover:bg-amber-50">
                <i data-lucide="trash" class="w-3.5 h-3.5"></i>Limpiar rotos
            </button>
        </form>
        <a href="<?= '/' . e($propSlug) ?>" target="_blank" class="btn-secondary text-xs">
            <i data-lucide="external-link" class="w-3.5 h-3.5"></i>Ver propiedad
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$propActual): ?>
<div class="card text-center py-16 text-gray-400">
    <i data-lucide="building-2" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
    <p>Selecciona una propiedad para gestionar sus imágenes.</p>
</div>
<?php else: ?>

<!-- ── Zona principal de subida ───────────────────────────────────────────── -->
<div class="card mb-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h2 class="font-bold text-gray-800 flex items-center gap-2">
            <i data-lucide="upload-cloud" class="w-4 h-4 text-pk"></i>Agregar imágenes
        </h2>
        <div class="flex items-center gap-2">
            <label class="text-sm text-gray-500">Tipo:</label>
            <select id="add-tipo" class="form-select text-sm">
                <?php foreach ($tipos as $key => $t): ?>
                <option value="<?= $key ?>"><?= $t['label'] ?> — <?= $t['desc'] ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="abrirPickerParaTipo()" class="btn-primary">
                <i data-lucide="images" class="w-4 h-4"></i>Explorar pics/
            </button>
        </div>
    </div>

    <!-- Drop zone -->
    <div id="main-dropzone"
         ondragover="event.preventDefault();this.style.background='#eef2ff';this.style.borderColor='#202944'"
         ondragleave="this.style.background='#f8fafc';this.style.borderColor='#e5e7eb'"
         ondrop="handleMainDrop(event)"
         onclick="document.getElementById('main-file-input').click()"
         style="border:2px dashed #e5e7eb;border-radius:.875rem;padding:2rem 1rem;
                text-align:center;background:#f8fafc;cursor:pointer;transition:all .2s">
        <i data-lucide="upload-cloud" style="width:2.25rem;height:2.25rem;margin:0 auto .75rem;color:#BAC4B9;display:block"></i>
        <p style="font-weight:600;color:#374151;font-size:.875rem;margin:0">Arrastra imágenes aquí o haz clic para subir</p>
        <p style="color:#9ca3af;font-size:.75rem;margin:.35rem 0 0">JPG, PNG, WebP · máx 12MB · múltiples a la vez</p>
        <input type="file" id="main-file-input" multiple accept="image/*" style="display:none" onchange="handleMainFileInput(this)">
    </div>

    <!-- Progreso -->
    <div id="main-progress" style="display:none;margin-top:1rem">
        <div style="background:#f3f4f6;border-radius:999px;height:6px;overflow:hidden">
            <div id="main-progress-bar" style="height:100%;background:#202944;border-radius:999px;transition:width .3s;width:0%"></div>
        </div>
        <p id="main-progress-text" style="font-size:.75rem;color:#6b7280;margin:.375rem 0 0;text-align:center"></p>
    </div>
</div>

<!-- ── Secciones por tipo ──────────────────────────────────────────────────── -->
<?php foreach ($tipos as $tipoKey => $tipoCfg):
    $imgsTipo = array_values(array_filter($imagenes, fn($i) => $i['tipo'] === $tipoKey));
?>
<div class="card mb-5">
    <div class="flex items-center justify-between mb-4">
        <h3 class="font-bold text-gray-800 flex items-center gap-2">
            <i data-lucide="<?= $tipoCfg['icon'] ?>" class="w-4 h-4 text-pk"></i>
            <?= $tipoCfg['label'] ?>
            <span class="text-xs font-normal text-gray-400"><?= $tipoCfg['desc'] ?></span>
        </h3>
        <div class="flex items-center gap-2">
            <span class="text-xs <?= count($imgsTipo) >= $tipoCfg['max'] ? 'text-amber-500 font-semibold' : 'text-gray-400' ?>">
                <?= count($imgsTipo) ?>/<?= $tipoCfg['max'] ?>
            </span>
            <button onclick="abrirPickerParaTipoEspecifico('<?= $tipoKey ?>')"
                    class="btn-secondary text-xs py-1.5 px-3">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i>Agregar
            </button>
        </div>
    </div>

    <?php if (empty($imgsTipo)): ?>
    <div class="py-8 text-center text-gray-300 border-2 border-dashed border-gray-100 rounded-xl">
        <i data-lucide="image-off" class="w-7 h-7 mx-auto mb-2 opacity-40"></i>
        <p class="text-sm">Sin imágenes · haz clic en <strong>Agregar</strong> o arrastra aquí</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
        <?php foreach ($imgsTipo as $img): ?>
        <div class="group relative rounded-xl overflow-hidden border border-gray-100 hover:border-pk/40 hover:shadow-sm transition-all bg-gray-50">
            <!-- Imagen -->
            <div class="aspect-video overflow-hidden relative bg-gray-200">
                <img src="<?= '/' . e($img['url']) ?>" alt="<?= e($img['alt'] ?? '') ?>"
                     class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                <?php if ($img['es_portada']): ?>
                <div class="absolute top-1.5 left-1.5 bg-pk text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full leading-tight">★ PORTADA</div>
                <?php endif; ?>
                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/25 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                    <a href="<?= '/' . e($img['url']) ?>" target="_blank"
                       class="w-8 h-8 bg-white rounded-full flex items-center justify-center shadow"
                       onclick="event.stopPropagation()">
                        <i data-lucide="expand" class="w-3.5 h-3.5 text-gray-700"></i>
                    </a>
                </div>
            </div>
            <!-- Controles -->
            <form method="post" class="p-2 space-y-1.5">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action"     value="update">
                <input type="hidden" name="img_id"     value="<?= $img['id'] ?>">
                <input type="text" name="alt" value="<?= e($img['alt'] ?? '') ?>"
                       placeholder="Alt text (SEO)"
                       class="w-full text-xs px-2 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:border-pk">
                <div class="flex items-center gap-1.5">
                    <input type="number" name="orden" value="<?= $img['orden'] ?>" min="0"
                           class="w-12 text-xs px-2 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:border-pk text-center"
                           title="Orden">
                    <label class="flex items-center gap-1 text-xs text-gray-500 cursor-pointer flex-1">
                        <input type="checkbox" name="es_portada" class="w-3 h-3 accent-pk" <?= $img['es_portada'] ? 'checked' : '' ?>>
                        Portada
                    </label>
                    <button type="submit"
                            class="w-7 h-7 bg-pk/10 text-pk rounded-lg hover:bg-pk hover:text-white transition-all flex items-center justify-center flex-shrink-0">
                        <i data-lucide="check" class="w-3.5 h-3.5"></i>
                    </button>
                </div>
            </form>
            <form method="post" class="px-2 pb-2">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action"     value="delete">
                <input type="hidden" name="img_id"     value="<?= $img['id'] ?>">
                <button type="submit"
                        data-confirm="¿Eliminar esta imagen? Se borrará el archivo."
                        class="w-full text-[11px] text-red-400 hover:text-red-600 py-0.5 transition-colors flex items-center justify-center gap-1">
                    <i data-lucide="trash-2" class="w-3 h-3"></i>Eliminar
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php echo pickerModal($propSlug); ?>

<script>
const PROP_ID   = <?= $propId ?>;
const CSRF_TOK  = '<?= e($csrf) ?>';
const PROP_SLUG = '<?= e($propSlug) ?>';

function abrirPickerParaTipo() {
    abrirPickerParaTipoEspecifico(document.getElementById('add-tipo').value);
}

function abrirPickerParaTipoEspecifico(tipo) {
    abrirPickerMulti(function(paths) {
        registrarImagenes(paths, tipo);
    });
}

function registrarImagenes(paths, tipo) {
    let done = 0;
    paths.forEach(url => {
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOK);
        fd.append('action',     'add_from_picker');
        fd.append('url',        url);
        fd.append('tipo',       tipo);
        fetch('imagenes.php?propiedad_id=' + PROP_ID, { method: 'POST', body: fd })
            .finally(() => { if (++done === paths.length) location.reload(); });
    });
}

function handleMainDrop(e) {
    e.preventDefault();
    const dz = document.getElementById('main-dropzone');
    dz.style.background  = '#f8fafc';
    dz.style.borderColor = '#e5e7eb';
    subirYRegistrar(
        Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/')),
        document.getElementById('add-tipo').value
    );
}

function handleMainFileInput(input) {
    subirYRegistrar(Array.from(input.files), document.getElementById('add-tipo').value);
    input.value = '';
}

function subirYRegistrar(files, tipo) {
    if (!files.length) return;
    const prog = document.getElementById('main-progress');
    const bar  = document.getElementById('main-progress-bar');
    const txt  = document.getElementById('main-progress-text');
    prog.style.display = 'block';
    let uploaded = 0, errors = 0;
    const total = files.length;

    function update() {
        const done = uploaded + errors;
        bar.style.width = Math.round(done / total * 100) + '%';
        txt.textContent = done < total
            ? 'Subiendo ' + done + ' de ' + total + '…'
            : '✓ ' + uploaded + ' imagen' + (uploaded !== 1 ? 'es' : '') + ' agregada' + (uploaded !== 1 ? 's' : '') + (errors ? ' · ' + errors + ' error(s)' : '');
        bar.style.background = done === total ? (errors && !uploaded ? '#ef4444' : '#22c55e') : '#202944';
        if (done === total) setTimeout(() => location.reload(), 1000);
    }

    files.forEach(file => {
        const fd = new FormData();
        fd.append('file',    file);
        fd.append('context', PROP_SLUG);
        fetch('api/upload-pic.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error();
                const fd2 = new FormData();
                fd2.append('csrf_token', CSRF_TOK);
                fd2.append('action',     'add_from_picker');
                fd2.append('url',        data.path);
                fd2.append('tipo',       tipo);
                return fetch('imagenes.php?propiedad_id=' + PROP_ID, { method: 'POST', body: fd2 });
            })
            .then(() => { uploaded++; update(); })
            .catch(()  => { errors++;   update(); });
    });
}
</script>

<?php adminLayoutClose(); ?>
