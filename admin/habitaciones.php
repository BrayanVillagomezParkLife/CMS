<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf        = adminCsrf();
$action      = $_GET['action'] ?? 'list';
$id          = (int)($_GET['id'] ?? 0);
$filtroProp  = (int)($_GET['propiedad_id'] ?? 0);
$propiedades = dbFetchAll("SELECT id, nombre FROM propiedades WHERE activo=1 ORDER BY nombre");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) adminRedirect('habitaciones.php', 'error', 'Token inválido.');
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete') {
        dbExecute("DELETE FROM habitaciones WHERE id=?", [(int)$_POST['id']]);
        dbCacheInvalidate();
        adminRedirect('habitaciones.php', 'success', 'Habitación eliminada.');
    }

    $propId = (int)$_POST['propiedad_id'];
    $editId = (int)($_POST['id'] ?? 0);

    // Datos base (siempre se guardan)
    $data = [
        'propiedad_id'     => $propId,
        'nombre'           => sanitizeStr($_POST['nombre'] ?? ''),
        'codigo'           => sanitizeStr($_POST['codigo'] ?? ''),
        'num_camas'        => $_POST['num_camas'] !== '' ? (int)$_POST['num_camas'] : null,
        'num_banos'        => $_POST['num_banos'] !== '' ? (float)$_POST['num_banos'] : null,
        'metros_cuadrados' => $_POST['metros_cuadrados'] !== '' ? (float)$_POST['metros_cuadrados'] : null,
        'tiene_parking'    => isset($_POST['tiene_parking']) ? 1 : 0,
        'imagen_url'       => sanitizeStr($_POST['imagen_url'] ?? ''),
        'destacada'        => isset($_POST['destacada']) ? 1 : 0,
        'activa'           => isset($_POST['activa']) ? 1 : 0,
        'orden'            => (int)($_POST['orden'] ?? 0),
    ];

    // Precios: solo al CREAR. Al editar se gestionan desde el módulo Precios.
    if (!$editId) {
        $data['precio_mes_12']        = $_POST['precio_mes_12'] !== '' ? round((float)$_POST['precio_mes_12'], 2) : null;
        $data['precio_mes_6']         = $_POST['precio_mes_6'] !== '' ? round((float)$_POST['precio_mes_6'], 2) : null;
        $data['precio_mes_1']         = $_POST['precio_mes_1'] !== '' ? round((float)$_POST['precio_mes_1'], 2) : null;
        $data['precio_mantenimiento'] = $_POST['precio_mantenimiento'] !== '' ? round((float)$_POST['precio_mantenimiento'], 2) : null;
        $data['precio_servicios']     = $_POST['precio_servicios'] !== '' ? round((float)$_POST['precio_servicios'], 2) : null;
        $data['precio_amueblado']     = $_POST['precio_amueblado'] !== '' ? round((float)$_POST['precio_amueblado'], 2) : null;
        $data['precio_parking_extra'] = $_POST['precio_parking_extra'] !== '' ? round((float)$_POST['precio_parking_extra'], 2) : null;
        $data['precio_mascota']       = $_POST['precio_mascota'] !== '' ? round((float)$_POST['precio_mascota'], 2) : null;
    }

    if ($editId) {
        $sets = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        dbExecute("UPDATE habitaciones SET $sets WHERE id=?", [...array_values($data), $editId]);
    } else {
        $cols   = implode(',', array_keys($data));
        $phs    = implode(',', array_fill(0, count($data), '?'));
        $editId = dbInsert("INSERT INTO habitaciones ($cols) VALUES ($phs)", array_values($data));
    }

    // Guardar galería
    if ($editId) {
        $idsExistentes = array_filter(array_map('intval', $_POST['galeria_ids'] ?? []));
        if ($idsExistentes) {
            $in = implode(',', $idsExistentes);
            dbExecute("DELETE FROM habitacion_imagenes WHERE habitacion_id=? AND id NOT IN ($in)", [$editId]);
        } else {
            dbExecute("DELETE FROM habitacion_imagenes WHERE habitacion_id=?", [$editId]);
        }
        $urls = $_POST['galeria_urls'] ?? [];
        $ids  = $_POST['galeria_ids']  ?? [];
        foreach ($urls as $i => $url) {
            $url = sanitizeStr($url);
            if (!$url) continue;
            if (!empty($ids[$i])) continue;
            dbExecute("INSERT INTO habitacion_imagenes (habitacion_id, url, orden) VALUES (?,?,?)", [$editId, $url, $i]);
        }
    }

    dbCacheInvalidate();
    adminRedirect('habitaciones.php?propiedad_id=' . $propId, 'success', $editId ? 'Habitación actualizada.' : 'Habitación creada.');
}

// Formulario
if (in_array($action, ['new','edit'])) {
    $hab = $id ? dbFetchOne("SELECT * FROM habitaciones WHERE id=?", [$id]) : null;
    if ($action === 'edit' && !$hab) adminRedirect('habitaciones.php', 'error', 'Habitación no encontrada.');
    $v    = $hab ?? [];
    $pId  = (int)($v['propiedad_id'] ?? $filtroProp);

    // Cargar imágenes de galería si es edición
    $galeria = [];
    if (!empty($v['id'])) {
        $galeria = dbFetchAll(
            "SELECT * FROM habitacion_imagenes WHERE habitacion_id=? ORDER BY orden, id",
            [$v['id']]
        );
    }

    adminLayoutOpen($hab ? 'Editar habitación' : 'Nueva habitación');
    ?>
    <div class="mb-6"><a href="habitaciones.php?propiedad_id=<?= $pId ?>" class="text-sm text-gray-500 hover:text-pk flex items-center gap-1"><i data-lucide="arrow-left" class="w-4 h-4"></i>Volver</a></div>
    <form method="post" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($v['id'] ?? 0) ?>">

        <div class="card">
            <h2 class="font-bold text-gray-800 mb-5">Información básica</h2>
            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label class="form-label">Propiedad *</label>
                    <select name="propiedad_id" required class="form-select">
                        <?php foreach ($propiedades as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $pId == $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label class="form-label">Nombre *</label><input type="text" name="nombre" required class="form-input" value="<?= e($v['nombre'] ?? '') ?>" placeholder="Studio Premium"></div>
                <div><label class="form-label">Código interno</label><input type="text" name="codigo" class="form-input font-mono" value="<?= e($v['codigo'] ?? '') ?>" placeholder="COND-ST-01"></div>
                <div>
                    <label class="form-label">Imagen principal</label>
                    <div class="flex gap-2">
                        <input type="text" name="imagen_url" id="imagen_url" class="form-input font-mono text-sm"
                               value="<?= e($v['imagen_url'] ?? '') ?>" placeholder="pics/studio_condesa.webp" readonly>
                        <button type="button" onclick="abrirPicsPicker('imagen_url', 'img_preview_hab')"
                                class="btn-secondary px-3 flex-shrink-0" title="Explorar pics/">
                            <i data-lucide="folder-open" class="w-4 h-4"></i>
                        </button>
                    </div>
                    <!-- Preview -->
                    <div id="img_preview_hab" class="mt-2 hidden">
                        <img src="" alt="Preview" class="h-24 rounded-xl object-cover border border-gray-100">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 class="font-bold text-gray-800 mb-5">Características físicas</h2>
            <div class="grid sm:grid-cols-4 gap-5">
                <div><label class="form-label">Camas</label><input type="number" name="num_camas" class="form-input" value="<?= e($v['num_camas'] ?? '') ?>" min="0"></div>
                <div><label class="form-label">Baños</label><input type="number" step="0.5" name="num_banos" class="form-input" value="<?= e($v['num_banos'] ?? '') ?>" min="0"></div>
                <div><label class="form-label">m²</label><input type="number" step="0.01" name="metros_cuadrados" class="form-input" value="<?= e($v['metros_cuadrados'] ?? '') ?>"></div>
                <div class="flex items-end pb-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="tiene_parking" class="w-4 h-4 accent-pk" <?= !empty($v['tiene_parking']) ? 'checked' : '' ?>>
                        <span class="text-sm font-medium text-gray-700">Incluye parking</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 class="font-bold text-gray-800 mb-5">Precios mensuales (MXN)</h2>
            <?php if ($hab): // EDITANDO — precios informativos, se gestionan desde Precios ?>
                <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl mb-4">
                    <p class="text-sm text-blue-800 flex items-center gap-2">
                        <i data-lucide="info" class="w-4 h-4"></i>
                        Los precios se gestionan desde el módulo
                        <a href="precios.php?propiedad_id=<?= $pId ?>" class="font-bold underline hover:text-pk">Precios</a>.
                    </p>
                </div>
                <div class="grid sm:grid-cols-4 gap-3 text-sm">
                    <div class="p-3 bg-gray-50 rounded-lg"><span class="text-gray-500 block text-xs mb-1">12 meses</span><strong class="text-gray-800">$<?= number_format((float)($v['precio_mes_12'] ?? 0), 2) ?></strong></div>
                    <div class="p-3 bg-gray-50 rounded-lg"><span class="text-gray-500 block text-xs mb-1">6 meses</span><strong class="text-gray-800">$<?= number_format((float)($v['precio_mes_6'] ?? 0), 2) ?></strong></div>
                    <div class="p-3 bg-gray-50 rounded-lg"><span class="text-gray-500 block text-xs mb-1">1 mes</span><strong class="text-gray-800">$<?= number_format((float)($v['precio_mes_1'] ?? 0), 2) ?></strong></div>
                    <div class="p-3 bg-gray-50 rounded-lg"><span class="text-gray-500 block text-xs mb-1">Mantenimiento</span>$<?= number_format((float)($v['precio_mantenimiento'] ?? 0), 2) ?></div>
                    <div class="p-3 bg-gray-50 rounded-lg"><span class="text-gray-500 block text-xs mb-1">Servicios</span>$<?= number_format((float)($v['precio_servicios'] ?? 0), 2) ?></div>
                    <div class="p-3 bg-gray-50 rounded-lg"><span class="text-gray-500 block text-xs mb-1">Amueblado</span>$<?= number_format((float)($v['precio_amueblado'] ?? 0), 2) ?></div>
                    <div class="p-3 bg-gray-50 rounded-lg"><span class="text-gray-500 block text-xs mb-1">Parking extra</span>$<?= number_format((float)($v['precio_parking_extra'] ?? 0), 2) ?></div>
                    <div class="p-3 bg-gray-50 rounded-lg"><span class="text-gray-500 block text-xs mb-1">Mascota</span>$<?= number_format((float)($v['precio_mascota'] ?? 0), 2) ?></div>
                </div>
            <?php else: // CREANDO — campos editables con auto-cálculo ?>
                <p class="text-xs text-gray-400 bg-blue-50 rounded-lg px-3 py-2 mb-4">
                    <i data-lucide="info" class="w-3.5 h-3.5 inline"></i>
                    Ingresa el precio de 12 meses — los de 6 y 1 mes se calculan (×1.10, ×1.20). Ya creada, los precios se administran desde <strong>Precios</strong>.
                </p>
                <div class="grid sm:grid-cols-4 gap-5">
                    <div><label class="form-label">Renta 12 meses *</label><input type="number" step="0.01" name="precio_mes_12" id="new-mes12" class="form-input font-semibold" placeholder="18000" oninput="autoCalcNew()"></div>
                    <div><label class="form-label">Renta 6 meses <span class="text-gray-300">(×1.10)</span></label><input type="number" step="0.01" name="precio_mes_6" id="new-mes6" class="form-input"></div>
                    <div><label class="form-label">Renta 1 mes <span class="text-gray-300">(×1.20)</span></label><input type="number" step="0.01" name="precio_mes_1" id="new-mes1" class="form-input"></div>
                    <div><label class="form-label">Mantenimiento</label><input type="number" step="0.01" name="precio_mantenimiento" class="form-input"></div>
                    <div><label class="form-label">Servicios</label><input type="number" step="0.01" name="precio_servicios" class="form-input"></div>
                    <div><label class="form-label">Amueblado</label><input type="number" step="0.01" name="precio_amueblado" class="form-input"></div>
                    <div><label class="form-label">Parking extra</label><input type="number" step="0.01" name="precio_parking_extra" class="form-input"></div>
                    <div><label class="form-label">Mascota</label><input type="number" step="0.01" name="precio_mascota" class="form-input"></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="font-bold text-gray-800 mb-1">Galería de imágenes</h2>
            <p class="text-sm text-gray-400 mb-5">Al hacer clic en la tarjeta de esta habitación se abrirá un carrusel con estas fotos.</p>

            <!-- Grid de fotos actuales -->
            <div id="galeria-grid" class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-3 mb-4">
                <?php foreach ($galeria as $img): ?>
                <div class="galeria-item relative group rounded-xl overflow-hidden border border-gray-100"
                     data-id="<?= $img['id'] ?>" data-url="<?= e($img['url']) ?>">
                    <div class="aspect-square bg-gray-100">
                        <img src="/<?= e($img['url']) ?>" alt="" class="w-full h-full object-cover">
                    </div>
                    <button type="button" onclick="eliminarFotoGaleria(this)"
                            class="absolute top-1 right-1 w-6 h-6 bg-red-500 text-white rounded-full
                                   text-xs opacity-0 group-hover:opacity-100 transition-all flex items-center justify-center">
                        <i data-lucide="x" class="w-3 h-3"></i>
                    </button>
                    <input type="hidden" name="galeria_ids[]" value="<?= $img['id'] ?>">
                    <input type="hidden" name="galeria_urls[]" value="<?= e($img['url']) ?>">
                </div>
                <?php endforeach; ?>

                <!-- Botón agregar -->
                <button type="button" id="btn-add-galeria"
                        onclick="abrirPickerGaleria()"
                        class="aspect-square rounded-xl border-2 border-dashed border-gray-200
                               flex flex-col items-center justify-center gap-1 text-gray-400
                               hover:border-pk hover:text-pk transition-all cursor-pointer bg-white">
                    <i data-lucide="plus" class="w-6 h-6"></i>
                    <span class="text-xs font-medium">Agregar</span>
                </button>
            </div>
            <p class="text-xs text-gray-400">Arrastra también imágenes directamente aquí o usa el explorador.</p>
        </div>

        <div class="card">
            <h2 class="font-bold text-gray-800 mb-5">Opciones</h2>
            <div class="flex flex-wrap gap-6">
                <div><label class="form-label">Orden</label><input type="number" name="orden" class="form-input w-24" value="<?= $v['orden'] ?? 0 ?>"></div>
                <div class="flex items-end gap-6 pb-1">
                    <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="activa" class="w-4 h-4 accent-pk" <?= !isset($v['activa']) || $v['activa'] ? 'checked' : '' ?>><span class="text-sm font-medium text-gray-700">Activa</span></label>
                    <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="destacada" class="w-4 h-4 accent-pk" <?= !empty($v['destacada']) ? 'checked' : '' ?>><span class="text-sm font-medium text-gray-700">Destacada (Más Popular)</span></label>
                </div>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary px-8"><i data-lucide="save" class="w-4 h-4"></i>Guardar</button>
            <a href="habitaciones.php?propiedad_id=<?= $pId ?>" class="btn-secondary px-6">Cancelar</a>
        </div>
    </form>

    <?php
    $propSlug = dbFetchOne("SELECT slug FROM propiedades WHERE id=?", [$pId])['slug'] ?? 'general';
    echo pickerModal($propSlug);
    ?>

    <script>
    // Preview imagen principal al cargar
    (function(){
        const url = document.getElementById('imagen_url')?.value;
        if (url) mostrarPreview(url, 'img_preview_hab');
    })();

    // Auto-cálculo al crear habitación nueva
    function autoCalcNew() {
        const v = parseFloat(document.getElementById('new-mes12')?.value);
        if (!isNaN(v) && v > 0) {
            document.getElementById('new-mes6').value = (v * 1.10).toFixed(2);
            document.getElementById('new-mes1').value = (v * 1.20).toFixed(2);
        }
    }

    // Galería: picker en modo múltiple
    function abrirPickerGaleria() {
        abrirPickerMulti(function(paths) {
            paths.forEach(path => {
                const name = path.split('/').pop();
                agregarFotoAlGrid(path);
            });
        });
    }

    function agregarFotoAlGrid(url) {
        const btn  = document.getElementById('btn-add-galeria');
        const grid = document.getElementById('galeria-grid');
        const div  = document.createElement('div');
        div.className = 'galeria-item relative group rounded-xl overflow-hidden border border-gray-100';
        div.dataset.url = url;
        div.innerHTML = `
            <div class="aspect-square bg-gray-100">
                <img src="/${url}" class="w-full h-full object-cover">
            </div>
            <button type="button" onclick="eliminarFotoGaleria(this)"
                    class="absolute top-1 right-1 w-6 h-6 bg-red-500 text-white rounded-full
                           text-xs opacity-0 group-hover:opacity-100 transition-all flex items-center justify-center">
                <i data-lucide="x" class="w-3 h-3"></i>
            </button>
            <input type="hidden" name="galeria_urls[]" value="${url}">
        `;
        grid.insertBefore(div, btn);
        lucide.createIcons();
    }

    function eliminarFotoGaleria(btn) {
        if (confirm('¿Quitar esta foto de la galería?')) {
            btn.closest('.galeria-item').remove();
        }
    }

    // Drag & drop directo en el grid de galería
    const galeriaGrid = document.getElementById('galeria-grid');
    galeriaGrid.addEventListener('dragover', e => { e.preventDefault(); galeriaGrid.style.outline = '2px dashed #202944'; });
    galeriaGrid.addEventListener('dragleave', () => { galeriaGrid.style.outline = ''; });
    galeriaGrid.addEventListener('drop', e => {
        e.preventDefault();
        galeriaGrid.style.outline = '';
        // Si vienen archivos del sistema operativo, subirlos
        if (e.dataTransfer.files.length) {
            // Abrir picker con drop para subir
            abrirPickerGaleria();
            // Simular drop en la dropzone del picker
            setTimeout(() => {
                const dz = document.getElementById('picker-dropzone');
                if (dz) handleDrop(e);
            }, 300);
        }
    });
    </script>

    <?php adminLayoutClose(); exit;
}

// Listado
$habitaciones = dbFetchAll(
    "SELECT h.*, p.nombre AS prop_nombre FROM habitaciones h
     LEFT JOIN propiedades p ON h.propiedad_id = p.id
     WHERE (? = 0 OR h.propiedad_id = ?)
     ORDER BY p.nombre, h.orden, h.id",
    [$filtroProp, $filtroProp]
);

adminLayoutOpen('Habitaciones / Tipos');
?>
<div class="flex items-center gap-4 mb-6">
    <form method="get" class="flex items-center gap-3">
        <label class="form-label mb-0 text-sm whitespace-nowrap">Filtrar por propiedad:</label>
        <select name="propiedad_id" class="form-select text-sm" onchange="this.form.submit()">
            <option value="0">Todas</option>
            <?php foreach ($propiedades as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $filtroProp == $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <a href="?action=new&propiedad_id=<?= $filtroProp ?>" class="btn-primary ml-auto">
        <i data-lucide="plus" class="w-4 h-4"></i>Nueva habitación
    </a>
</div>

<div class="card p-0 overflow-hidden">
    <table class="w-full">
        <thead class="bg-slate-50 border-b border-gray-100">
            <tr>
                <th class="table-th">Habitación</th>
                <th class="table-th">Propiedad</th>
                <th class="table-th text-center">Camas / Baños</th>
                <th class="table-th text-right">Precio / mes</th>
                <th class="table-th text-center">Estado</th>
                <th class="table-th"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            <?php foreach ($habitaciones as $h): ?>
            <tr class="hover:bg-slate-50">
                <td class="table-td">
                    <div class="font-medium text-gray-800"><?= e($h['nombre']) ?></div>
                    <?php if ($h['metros_cuadrados']): ?><div class="text-xs text-gray-400"><?= (int)$h['metros_cuadrados'] ?>m²</div><?php endif; ?>
                </td>
                <td class="table-td text-gray-500 text-sm"><?= e($h['prop_nombre']) ?></td>
                <td class="table-td text-center text-sm text-gray-600">
                    <?= $h['num_camas'] ? $h['num_camas'].'🛏' : '—' ?> / <?= $h['num_banos'] ? $h['num_banos'].'🚿' : '—' ?>
                </td>
                <td class="table-td text-right font-semibold text-gray-800">
                    <?= $h['precio_mes_1'] ? '$' . number_format((float)$h['precio_mes_1'], 2) : '—' ?>
                </td>
                <td class="table-td text-center"><?= badgeStatus((int)$h['activa']) ?></td>
                <td class="table-td">
                    <div class="flex items-center gap-2 justify-end">
                        <a href="?action=edit&id=<?= $h['id'] ?>" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-pk"><i data-lucide="pencil" class="w-4 h-4"></i></a>
                        <form method="post" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $h['id'] ?>">
                            <button type="submit" data-confirm="¿Eliminar '<?= e($h['nombre']) ?>'?" class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($habitaciones)): ?>
            <tr><td colspan="6" class="table-td text-center text-gray-400 py-10">No hay habitaciones. <a href="?action=new" class="text-pk hover:underline">Crear la primera →</a></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php adminLayoutClose(); ?>