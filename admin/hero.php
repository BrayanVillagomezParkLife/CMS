<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf        = adminCsrf();
$action      = $_GET['action'] ?? 'list';
$id          = (int)($_GET['id'] ?? 0);
$propiedades = dbFetchAll("SELECT id, nombre FROM propiedades WHERE activo=1 ORDER BY nombre");

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? ''))
        adminRedirect('hero.php', 'error', 'Token inválido.');

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete') {
        $delId = (int)$_POST['id'];
        dbExecute("DELETE FROM hero_slides WHERE id=?", [$delId]);
        dbCacheInvalidate();
        adminRedirect('hero.php', 'success', 'Slide eliminado.');
    }

    if ($postAction === 'reorder') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        foreach ($ids as $orden => $slideId)
            dbExecute("UPDATE hero_slides SET orden=? WHERE id=?", [(int)$orden, (int)$slideId]);
        dbCacheInvalidate();
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($postAction === 'toggle') {
        $togId = (int)$_POST['id'];
        dbExecute("UPDATE hero_slides SET activo = NOT activo WHERE id=?", [$togId]);
        dbCacheInvalidate();
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    // Guardar slide
    $editId      = (int)($_POST['id'] ?? 0);
    $propiedadId = $_POST['propiedad_id'] ? (int)$_POST['propiedad_id'] : null;
    $imagenUrl   = sanitizeStr($_POST['imagen_url'] ?? '');

    if (!$imagenUrl)
        adminRedirect('hero.php?action=' . ($editId ? "edit&id=$editId" : 'new'), 'error', 'Selecciona una imagen.');

    $data = [
        'propiedad_id'  => $propiedadId,
        'imagen_url'    => $imagenUrl,
        'destino_texto' => sanitizeStr($_POST['destino_texto'] ?? ''),
        'texto_es'      => sanitizeStr($_POST['texto_es'] ?? '', 500),
        'texto_en'      => sanitizeStr($_POST['texto_en'] ?? '', 500),
        'link'          => sanitizeStr($_POST['link'] ?? ''),
        'link_text'     => sanitizeStr($_POST['link_text'] ?? ''),
        'activo'        => isset($_POST['activo']) ? 1 : 0,
        'orden'         => (int)($_POST['orden'] ?? 0),
    ];

    if ($editId) {
        $sets = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        dbExecute("UPDATE hero_slides SET $sets WHERE id=?", [...array_values($data), $editId]);
        adminRedirect('hero.php', 'success', 'Slide actualizado.');
    } else {
        $cols = implode(',', array_keys($data));
        $phs  = implode(',', array_fill(0, count($data), '?'));
        dbInsert("INSERT INTO hero_slides ($cols) VALUES ($phs)", array_values($data));
        adminRedirect('hero.php', 'success', 'Slide creado.');
    }
    dbCacheInvalidate();
}

// ── FORMULARIO NUEVO / EDITAR ─────────────────────────────────────────────────
if (in_array($action, ['new', 'edit'])) {
    $slide = $id ? dbFetchOne("SELECT * FROM hero_slides WHERE id=?", [$id]) : null;
    if ($action === 'edit' && !$slide) adminRedirect('hero.php', 'error', 'Slide no encontrado.');
    $v = $slide ?? [];

    adminLayoutOpen($slide ? 'Editar Slide' : 'Nuevo Slide');
    ?>

    <div class="mb-6 flex items-center gap-3">
        <a href="hero.php" class="text-sm text-gray-500 hover:text-pk flex items-center gap-1">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver
        </a>
        <span class="text-gray-200">/</span>
        <span class="text-sm font-medium text-gray-700"><?= $slide ? 'Editar slide' : 'Nuevo slide' ?></span>
    </div>

    <div class="grid lg:grid-cols-5 gap-6 max-w-5xl">

        <!-- Col izquierda: imagen -->
        <div class="lg:col-span-2 space-y-4">
            <div class="card">
                <h2 class="font-bold text-gray-800 mb-4 flex items-center gap-2 text-sm">
                    <i data-lucide="image" class="w-4 h-4 text-pk"></i>Imagen del slide
                </h2>

                <!-- Preview -->
                <div id="img_preview_hero"
                     class="rounded-xl overflow-hidden mb-3 bg-slate-50 border-2 border-dashed border-gray-200"
                     style="aspect-ratio:16/9;position:relative">
                    <img id="preview-img"
                         src="<?= !empty($v['imagen_url']) ? '/' . e($v['imagen_url']) : '' ?>"
                         class="w-full h-full object-cover"
                         style="<?= empty($v['imagen_url']) ? 'display:none' : '' ?>">
                    <div id="preview-placeholder"
                         class="absolute inset-0 flex flex-col items-center justify-center text-gray-300 gap-2"
                         style="<?= !empty($v['imagen_url']) ? 'display:none' : '' ?>">
                        <i data-lucide="image" class="w-10 h-10"></i>
                        <p class="text-xs">Sin imagen seleccionada</p>
                    </div>
                </div>

                <!-- Drop zone -->
                <div id="hero-dropzone"
                     ondragover="event.preventDefault();this.style.borderColor='#202944';this.style.background='#eef2ff'"
                     ondragleave="this.style.borderColor='#e5e7eb';this.style.background='#f8fafc'"
                     ondrop="handleHeroDrop(event)"
                     onclick="document.getElementById('hero-file-input').click()"
                     style="border:2px dashed #e5e7eb;border-radius:.75rem;padding:1rem;text-align:center;
                            background:#f8fafc;cursor:pointer;transition:all .2s;margin-bottom:.75rem">
                    <i data-lucide="upload-cloud" style="width:1.5rem;height:1.5rem;margin:0 auto .4rem;color:#BAC4B9;display:block"></i>
                    <p style="font-size:.75rem;color:#6b7280;margin:0">Arrastra una imagen o <span style="color:#202944;font-weight:600">haz clic</span></p>
                    <p style="font-size:.68rem;color:#9ca3af;margin:.2rem 0 0">JPG, PNG, WebP · máx 12MB</p>
                    <input type="file" id="hero-file-input" accept="image/*" style="display:none" onchange="handleHeroFile(this)">
                </div>

                <!-- Progreso upload -->
                <div id="hero-progress" style="display:none;margin-bottom:.75rem">
                    <div style="background:#f3f4f6;border-radius:999px;height:5px;overflow:hidden">
                        <div id="hero-progress-bar" style="height:100%;background:#202944;border-radius:999px;transition:width .3s;width:0%"></div>
                    </div>
                    <p id="hero-progress-text" style="font-size:.7rem;color:#6b7280;margin:.3rem 0 0;text-align:center"></p>
                </div>

                <!-- O explorar picker -->
                <button type="button" onclick="abrirPicsPicker('imagen_url', 'img_preview_hero')"
                        class="btn-secondary w-full text-sm justify-center">
                    <i data-lucide="folder-open" class="w-4 h-4"></i>Explorar imágenes subidas
                </button>

                <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                    Resolución ideal: <strong>1920×1080px</strong> o mayor. El slide se muestra con overlay oscuro, así que imágenes con buena iluminación funcionan mejor.
                </p>
            </div>
        </div>

        <!-- Col derecha: texto y configuración -->
        <div class="lg:col-span-3 space-y-4">
            <form method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action"     value="save">
                <input type="hidden" name="id"         value="<?= (int)($v['id'] ?? 0) ?>">
                <input type="hidden" name="imagen_url" id="imagen_url" value="<?= e($v['imagen_url'] ?? '') ?>">

                <!-- Textos -->
                <div class="card space-y-4">
                    <h2 class="font-bold text-gray-800 flex items-center gap-2 text-sm">
                        <i data-lucide="type" class="w-4 h-4 text-pk"></i>Texto superpuesto
                    </h2>

                    <div>
                        <label class="form-label text-xs">Etiqueta <span class="text-gray-400 font-normal">— aparece arriba del título en letra pequeña</span></label>
                        <input type="text" name="destino_texto" class="form-input"
                               value="<?= e($v['destino_texto'] ?? '') ?>"
                               placeholder="Condesa, CDMX">
                    </div>

                    <div>
                        <label class="form-label text-xs">Título principal (ES)</label>
                        <textarea name="texto_es" class="form-textarea" rows="3"
                                  placeholder="Vive en el corazón&#10;de la ciudad"><?= e($v['texto_es'] ?? '') ?></textarea>
                        <p class="text-xs text-gray-400 mt-1">Cada salto de línea crea una nueva línea en el slide</p>
                    </div>

                    <div>
                        <label class="form-label text-xs">Título principal (EN) <span class="text-gray-400 font-normal">— opcional</span></label>
                        <textarea name="texto_en" class="form-textarea" rows="2"><?= e($v['texto_en'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Link -->
                <div class="card space-y-4">
                    <h2 class="font-bold text-gray-800 flex items-center gap-2 text-sm">
                        <i data-lucide="link" class="w-4 h-4 text-pk"></i>Botón / Link <span class="font-normal text-gray-400 text-xs">— opcional</span>
                    </h2>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label text-xs">Texto del botón</label>
                            <input type="text" name="link_text" class="form-input"
                                   value="<?= e($v['link_text'] ?? '') ?>"
                                   placeholder="Ver departamentos">
                        </div>
                        <div>
                            <label class="form-label text-xs">URL destino</label>
                            <input type="text" name="link" class="form-input"
                                   value="<?= e($v['link'] ?? '') ?>"
                                   placeholder="/condesa">
                        </div>
                    </div>
                </div>

                <!-- Config -->
                <div class="card space-y-4">
                    <h2 class="font-bold text-gray-800 flex items-center gap-2 text-sm">
                        <i data-lucide="settings" class="w-4 h-4 text-pk"></i>Configuración
                    </h2>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label text-xs">Propiedad vinculada <span class="text-gray-400 font-normal">— opcional</span></label>
                            <select name="propiedad_id" class="form-select text-sm">
                                <option value="">— General —</option>
                                <?php foreach ($propiedades as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($v['propiedad_id'] ?? null) == $p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label text-xs">Orden</label>
                            <input type="number" name="orden" class="form-input" min="0"
                                   value="<?= (int)($v['orden'] ?? 0) ?>" placeholder="0">
                        </div>
                    </div>
                    <div class="flex items-center gap-3 pt-1">
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="activo" value="1" class="sr-only peer"
                                   <?= ($v['activo'] ?? 1) ? 'checked' : '' ?>>
                            <div class="w-10 h-6 bg-gray-200 rounded-full peer peer-checked:bg-pk transition-colors
                                        after:content-[''] after:absolute after:top-[2px] after:left-[2px]
                                        after:bg-white after:rounded-full after:h-5 after:w-5
                                        after:transition-all peer-checked:after:translate-x-4"></div>
                        </label>
                        <span class="text-sm text-gray-700">Slide activo — visible en el homepage</span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="btn-primary flex-1 justify-center">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        <?= $slide ? 'Guardar cambios' : 'Crear slide' ?>
                    </button>
                    <a href="hero.php" class="btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

    <?php echo pickerModal('general'); ?>

    <script>
    function setHeroPreview(src) {
        const img  = document.getElementById('preview-img');
        const ph   = document.getElementById('preview-placeholder');
        const cont = document.getElementById('img_preview_hero');
        if (img) { img.src = src; img.style.display = 'block'; }
        if (ph)  { ph.style.display = 'none'; }
        if (cont) { cont.style.border = 'none'; }
    }

    // Cuando el picker confirma, también actualizar preview
    const _heroOrigConfirmar = window.confirmarPicker;
    window.confirmarPicker = function() {
        // Llamar al original que setea el input
        _heroOrigConfirmar();
        // Leer el valor que acaba de setear
        const url = document.getElementById('imagen_url')?.value;
        if (url) setHeroPreview('/' + url);
    };

    function handleHeroDrop(e) {
        e.preventDefault();
        const dz = document.getElementById('hero-dropzone');
        dz.style.borderColor = '#e5e7eb';
        dz.style.background  = '#f8fafc';
        const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
        if (files[0]) subirHeroImg(files[0]);
    }

    function handleHeroFile(input) {
        if (input.files[0]) subirHeroImg(input.files[0]);
        input.value = '';
    }

    function subirHeroImg(file) {
        const prog = document.getElementById('hero-progress');
        const bar  = document.getElementById('hero-progress-bar');
        const txt  = document.getElementById('hero-progress-text');
        prog.style.display   = 'block';
        bar.style.width      = '10%';
        bar.style.background = '#202944';
        txt.textContent      = 'Subiendo…';

        const fd = new FormData();
        fd.append('file',    file);
        fd.append('context', 'general');

        fetch('api/upload-pic.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.error || 'Error al subir');
                bar.style.width      = '100%';
                bar.style.background = '#22c55e';
                txt.textContent      = '✓ Imagen lista';
                document.getElementById('imagen_url').value = data.path;
                setHeroPreview('/' + data.path);
                setTimeout(() => { prog.style.display = 'none'; bar.style.background = '#202944'; }, 2500);
            })
            .catch(err => {
                bar.style.background = '#ef4444';
                bar.style.width      = '100%';
                txt.textContent      = '✗ ' + err.message;
            });
    }
    </script>

    <?php
    adminLayoutClose();
    exit;
}

// ── LISTADO ───────────────────────────────────────────────────────────────────
$slides = dbFetchAll(
    "SELECT s.*, p.nombre AS prop_nombre
     FROM hero_slides s
     LEFT JOIN propiedades p ON s.propiedad_id = p.id
     ORDER BY s.orden, s.id"
);

adminLayoutOpen('Hero Slides');
?>

<!-- Header -->
<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
    <div>
        <p class="text-sm text-gray-500 mt-0.5">
            Imágenes del carrusel en el <strong>homepage</strong>.
            <?= count($slides) ?> slide<?= count($slides) != 1 ? 's' : '' ?> — arrastra para reordenar.
        </p>
    </div>
    <a href="?action=new" class="btn-primary">
        <i data-lucide="plus" class="w-4 h-4"></i>Nuevo slide
    </a>
</div>

<!-- Explicación rápida -->
<div class="bg-pk/5 border border-pk/15 rounded-xl px-4 py-3 mb-6 flex items-start gap-3">
    <i data-lucide="info" class="w-4 h-4 text-pk flex-shrink-0 mt-0.5"></i>
    <div class="text-xs text-gray-600 space-y-0.5">
        <p><strong>¿Cómo funciona?</strong> Cada slide es una foto de fondo que rota en el banner del homepage cada 5 segundos.</p>
        <p>Puedes agregar texto encima, un botón de acción y vincularlo a una propiedad específica.</p>
    </div>
</div>

<?php if (empty($slides)): ?>
<div class="card text-center py-20">
    <i data-lucide="gallery-thumbnails" class="w-14 h-14 mx-auto mb-4 text-gray-200"></i>
    <h3 class="font-bold text-gray-500 mb-2">Sin slides todavía</h3>
    <p class="text-gray-400 text-sm mb-6 max-w-sm mx-auto">
        Crea el primer slide subiendo una foto de alguna propiedad o del estilo de vida Park Life.
    </p>
    <a href="?action=new" class="btn-primary">
        <i data-lucide="plus" class="w-4 h-4"></i>Crear primer slide
    </a>
</div>

<?php else: ?>

<div id="slides-list" class="space-y-3">
    <?php foreach ($slides as $idx => $slide): ?>
    <div class="card p-0 overflow-hidden flex group relative" data-id="<?= $slide['id'] ?>">

        <!-- Handle drag -->
        <div class="drag-handle flex-shrink-0 w-10 flex items-center justify-center cursor-grab
                    bg-gray-50 border-r border-gray-100 text-gray-300 hover:text-gray-500 transition-colors"
             title="Arrastrar para reordenar">
            <i data-lucide="grip-vertical" class="w-4 h-4"></i>
        </div>

        <!-- Número de orden -->
        <div class="flex-shrink-0 w-8 flex items-center justify-center">
            <span class="text-xs font-bold text-gray-300"><?= $idx + 1 ?></span>
        </div>

        <!-- Thumbnail -->
        <div class="flex-shrink-0 w-36 h-24 sm:w-48 sm:h-28 bg-gray-100 overflow-hidden relative">
            <?php if ($slide['imagen_url']): ?>
            <img src="/<?= e($slide['imagen_url']) ?>" alt=""
                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
            <!-- Overlay preview del texto -->
            <?php if ($slide['texto_es']): ?>
            <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent flex items-end p-2">
                <p class="text-white text-[10px] font-semibold leading-tight line-clamp-2"><?= e($slide['texto_es']) ?></p>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="w-full h-full flex items-center justify-center">
                <i data-lucide="image" class="w-8 h-8 text-gray-300"></i>
            </div>
            <?php endif; ?>
        </div>

        <!-- Info -->
        <div class="flex-1 px-4 py-3 min-w-0 flex flex-col justify-center">
            <div class="flex items-center gap-2 mb-1 flex-wrap">
                <!-- Estado toggle -->
                <button onclick="toggleSlide(<?= $slide['id'] ?>, this)"
                        class="text-[10px] font-bold px-2 py-0.5 rounded-full transition-all
                               <?= $slide['activo'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400' ?>"
                        data-activo="<?= $slide['activo'] ?>">
                    <?= $slide['activo'] ? '● ACTIVO' : '○ INACTIVO' ?>
                </button>
                <?php if ($slide['prop_nombre']): ?>
                <span class="text-[10px] text-gray-400 flex items-center gap-0.5">
                    <i data-lucide="building-2" class="w-3 h-3"></i><?= e($slide['prop_nombre']) ?>
                </span>
                <?php else: ?>
                <span class="text-[10px] text-gray-400 flex items-center gap-0.5">
                    <i data-lucide="globe" class="w-3 h-3"></i>General
                </span>
                <?php endif; ?>
            </div>

            <?php if ($slide['destino_texto']): ?>
            <p class="text-xs text-pk font-semibold uppercase tracking-wide mb-0.5"><?= e($slide['destino_texto']) ?></p>
            <?php endif; ?>

            <p class="text-sm font-semibold text-gray-800 leading-snug line-clamp-2">
                <?= $slide['texto_es'] ? e($slide['texto_es']) : '<span class="text-gray-300 font-normal italic">Sin texto</span>' ?>
            </p>

            <?php if ($slide['link'] && $slide['link_text']): ?>
            <p class="text-xs text-gray-400 mt-1 flex items-center gap-1">
                <i data-lucide="link" class="w-3 h-3"></i><?= e($slide['link_text']) ?> → <?= e($slide['link']) ?>
            </p>
            <?php endif; ?>
        </div>

        <!-- Acciones -->
        <div class="flex-shrink-0 flex flex-col gap-2 justify-center px-3 py-3 border-l border-gray-100">
            <a href="?action=edit&id=<?= $slide['id'] ?>"
               class="btn-secondary text-xs py-1.5 px-3 whitespace-nowrap">
                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>Editar
            </a>
            <form method="post" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $slide['id'] ?>">
                <button type="submit"
                        data-confirm="¿Eliminar este slide?"
                        class="w-full text-xs text-red-400 hover:text-red-600 flex items-center justify-center gap-1 py-1.5 px-3 rounded-lg border border-gray-200 hover:bg-red-50 transition-all">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>Eliminar
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<p class="text-xs text-gray-400 text-center mt-4">
    <i data-lucide="grip-vertical" class="w-3 h-3 inline"></i>
    Arrastra por el ícono de la izquierda para cambiar el orden de los slides
</p>

<?php endif; ?>

<script>
const CSRF_HERO = '<?= e($csrf) ?>';

// Toggle activo/inactivo sin recargar
function toggleSlide(id, btn) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_HERO);
    fd.append('action', 'toggle');
    fd.append('id', id);
    fetch('hero.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(() => {
            const activo = btn.dataset.activo === '1' ? 0 : 1;
            btn.dataset.activo = activo;
            btn.textContent    = activo ? '● ACTIVO' : '○ INACTIVO';
            btn.className = btn.className.replace(
                activo ? /bg-gray-100 text-gray-400/ : /bg-green-100 text-green-700/,
                activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400'
            );
        });
}

// Drag & drop reordenar
(function() {
    const list = document.getElementById('slides-list');
    if (!list) return;
    let dragging = null;

    list.querySelectorAll('.drag-handle').forEach(handle => {
        const card = handle.closest('[data-id]');
        handle.addEventListener('mousedown', () => { card.draggable = true; });
        handle.addEventListener('mouseup',   () => { card.draggable = false; });
    });

    list.addEventListener('dragstart', e => {
        dragging = e.target.closest('[data-id]');
        if (!dragging) return;
        dragging.style.opacity = '.4';
    });
    list.addEventListener('dragend', e => {
        const card = e.target.closest('[data-id]');
        if (card) card.style.opacity = '1';
        dragging = null;
        guardarOrden();
    });
    list.addEventListener('dragover', e => {
        e.preventDefault();
        const after = getDragAfter(list, e.clientY);
        if (after) list.insertBefore(dragging, after);
        else list.appendChild(dragging);
    });

    function getDragAfter(container, y) {
        return [...container.querySelectorAll('[data-id]:not([style*="opacity: 0.4"])')].reduce((closest, el) => {
            const box = el.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            return offset < 0 && offset > closest.offset ? { offset, element: el } : closest;
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    function guardarOrden() {
        const ids = [...list.querySelectorAll('[data-id]')].map(el => el.dataset.id);
        const fd  = new FormData();
        fd.append('csrf_token', CSRF_HERO);
        fd.append('action', 'reorder');
        fd.append('ids', JSON.stringify(ids));
        fetch('hero.php', { method: 'POST', body: fd });
    }
})();
</script>

<?php adminLayoutClose(); ?>