<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$csrf   = adminCsrf();

// ── GUARDAR ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('propiedades.php', 'error', 'Token inválido.');
    }
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        dbExecute("DELETE FROM propiedades WHERE id = ?", [$delId]);
        dbCacheInvalidate();
        adminRedirect('propiedades.php', 'success', 'Propiedad eliminada.');
    }

    $data = [
        'slug'                    => sanitizeStr($_POST['slug'] ?? ''),
        'nombre'                  => sanitizeStr($_POST['nombre'] ?? ''),
        'nombre_en'               => sanitizeStr($_POST['nombre_en'] ?? ''),
        'subtitulo'               => sanitizeStr($_POST['subtitulo'] ?? ''),
        'descripcion_corta'       => sanitizeStr($_POST['descripcion_corta'] ?? '', 500),
        'descripcion_corta_en'    => sanitizeStr($_POST['descripcion_corta_en'] ?? '', 500),
        'descripcion_larga'       => sanitizeStr($_POST['descripcion_larga'] ?? '', 2000),
        'descripcion_larga_en'    => sanitizeStr($_POST['descripcion_larga_en'] ?? '', 2000),
        'hero_slogan'             => sanitizeStr($_POST['hero_slogan'] ?? ''),
        'hero_slogan_en'          => sanitizeStr($_POST['hero_slogan_en'] ?? ''),
        'colonia'                 => sanitizeStr($_POST['colonia'] ?? ''),
        'ciudad'                  => sanitizeStr($_POST['ciudad'] ?? ''),
        'estado'                  => sanitizeStr($_POST['estado'] ?? ''),
        'lat'                     => $_POST['lat'] ? (float)$_POST['lat'] : null,
        'lng'                     => $_POST['lng'] ? (float)$_POST['lng'] : null,
        'google_maps_embed'       => $_POST['google_maps_embed'] ?? '',
        'telefono'                => sanitizeStr($_POST['telefono'] ?? ''),
        'whatsapp'                => sanitizeStr($_POST['whatsapp'] ?? ''),
        'email'                   => sanitizeEmail($_POST['email'] ?? ''),
        'precio_desde_dia'        => $_POST['precio_desde_dia'] ? (float)$_POST['precio_desde_dia'] : null,
        'precio_desde_mes'        => $_POST['precio_desde_mes'] ? (float)$_POST['precio_desde_mes'] : null,
        'cloudbeds_code'          => sanitizeStr($_POST['cloudbeds_code'] ?? ''),
        'cloudbeds_property_id'   => sanitizeStr($_POST['cloudbeds_property_id'] ?? ''),
        'commercial_highlight'    => sanitizeStr($_POST['commercial_highlight'] ?? ''),
        'commercial_text'         => sanitizeStr($_POST['commercial_text'] ?? '', 1000),
        'commercial_highlight_en' => sanitizeStr($_POST['commercial_highlight_en'] ?? ''),
        'commercial_text_en'      => sanitizeStr($_POST['commercial_text_en'] ?? '', 1000),
        'zoho_property_name'      => sanitizeStr($_POST['zoho_property_name'] ?? ''),
        'seo_title'               => sanitizeStr($_POST['seo_title'] ?? '', 160),
        'seo_description'         => sanitizeStr($_POST['seo_description'] ?? '', 320),
        'seo_description_en'      => sanitizeStr($_POST['seo_description_en'] ?? '', 320),
        'seo_keywords'            => sanitizeStr($_POST['seo_keywords'] ?? ''),
        'acepta_estancias_largas' => isset($_POST['acepta_estancias_largas']) ? 1 : 0,
        'destacada'               => isset($_POST['destacada']) ? 1 : 0,
        'activo'                  => isset($_POST['activo']) ? 1 : 0,
        'orden'                   => (int)($_POST['orden'] ?? 0),
        'destino_id'              => $_POST['destino_id'] ? (int)$_POST['destino_id'] : null,
    ];

    $editId = (int)($_POST['id'] ?? 0);
    if ($editId) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
        $vals = array_values($data);
        $vals[] = $editId;
        dbExecute("UPDATE propiedades SET $sets WHERE id = ?", $vals);
        dbCacheInvalidate();
        adminRedirect('propiedades.php', 'success', 'Propiedad actualizada.');
    } else {
        $cols = implode(', ', array_keys($data));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        dbInsert("INSERT INTO propiedades ($cols) VALUES ($phs)", array_values($data));
        dbCacheInvalidate();
        adminRedirect('propiedades.php', 'success', 'Propiedad creada.');
    }
}

// ── DATOS ─────────────────────────────────────────────────────────────────────
$destinos = dbFetchAll("SELECT id, nombre FROM destinos ORDER BY nombre");

$prop = null;
if ($action === 'edit' && $id) {
    $prop = dbFetchOne("SELECT * FROM propiedades WHERE id = ?", [$id]);
    if (!$prop) adminRedirect('propiedades.php', 'error', 'Propiedad no encontrada.');
}

// ── LISTADO ───────────────────────────────────────────────────────────────────
$propiedades = dbFetchAll(
    "SELECT p.*, d.nombre AS destino_nombre,
            (SELECT COUNT(*) FROM habitaciones WHERE propiedad_id = p.id AND activa = 1) AS num_habs,
            (SELECT COUNT(*) FROM propiedad_imagenes WHERE propiedad_id = p.id) AS num_imgs
     FROM propiedades p
     LEFT JOIN destinos d ON p.destino_id = d.id
     ORDER BY p.orden, p.nombre"
);

if ($action === 'list') {
    adminLayoutOpen('Propiedades');
    ?>
    <div class="flex items-center justify-between mb-6">
        <p class="text-sm text-gray-500"><?= count($propiedades) ?> propiedades en total</p>
        <a href="?action=new" class="btn-primary">
            <i data-lucide="plus" class="w-4 h-4"></i>Nueva propiedad
        </a>
    </div>
    <div class="card p-0 overflow-hidden">
        <table class="w-full">
            <thead class="bg-slate-50 border-b border-gray-100">
                <tr>
                    <th class="table-th">Propiedad</th>
                    <th class="table-th">Destino</th>
                    <th class="table-th">Slug / URL</th>
                    <th class="table-th text-center">Habs</th>
                    <th class="table-th text-center">Imgs</th>
                    <th class="table-th text-center">Estado</th>
                    <th class="table-th text-center">EN</th>
                    <th class="table-th"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($propiedades as $p): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="table-td">
                        <div class="font-semibold text-gray-800"><?= e($p['nombre']) ?></div>
                        <?php if ($p['nombre_en'] ?? ''): ?>
                        <div class="text-xs text-blue-400">EN: <?= e($p['nombre_en']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="table-td text-gray-500"><?= e($p['destino_nombre'] ?? '—') ?></td>
                    <td class="table-td font-mono text-xs text-gray-400">/<?= e($p['slug']) ?></td>
                    <td class="table-td text-center text-sm"><?= $p['num_habs'] ?></td>
                    <td class="table-td text-center text-sm"><?= $p['num_imgs'] ?></td>
                    <td class="table-td text-center">
                        <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-medium <?= $p['activo'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400' ?>">
                            <?= $p['activo'] ? 'Activa' : 'Inactiva' ?>
                        </span>
                    </td>
                    <td class="table-td text-center">
                        <?php $tieneEn = !empty($p['descripcion_corta_en']) || !empty($p['hero_slogan_en']); ?>
                        <span class="text-xs <?= $tieneEn ? 'text-green-600' : 'text-gray-300' ?>">
                            <?= $tieneEn ? '✓ EN' : '— EN' ?>
                        </span>
                    </td>
                    <td class="table-td text-right">
                        <div class="flex items-center gap-2 justify-end">
                            <a href="?action=edit&id=<?= $p['id'] ?>" class="btn-sm btn-secondary">
                                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>Editar
                            </a>
                            <form method="post" onsubmit="return confirm('¿Eliminar esta propiedad?')">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn-sm btn-danger">
                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    adminLayoutClose();
    exit;
}

// ── FORMULARIO ────────────────────────────────────────────────────────────────
$title = $prop ? 'Editar: ' . $prop['nombre'] : 'Nueva Propiedad';
adminLayoutOpen($title);
$v = $prop ?? [];
?>

<div class="mb-6">
    <a href="propiedades.php" class="text-sm text-gray-500 hover:text-pk flex items-center gap-1">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver a propiedades
    </a>
</div>

<!-- Leyenda de campos EN -->
<div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-xl flex items-center gap-3 text-sm text-blue-700">
    <i data-lucide="globe" class="w-4 h-4 flex-shrink-0"></i>
    Los campos marcados con <span class="font-bold mx-1">🇺🇸 EN</span> se muestran cuando el visitante navega en inglés.
    Usa el botón <strong>Auto-traducir</strong> para sugerir una traducción vía DeepL — puedes editarla antes de guardar.
</div>

<form method="post" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($v['id'] ?? 0) ?>">

    <!-- Información básica -->
    <div class="card">
        <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
            <i data-lucide="info" class="w-4 h-4 text-pk"></i>Información básica
        </h2>
        <div class="grid sm:grid-cols-2 gap-5">
            <div>
                <label class="form-label">Nombre *</label>
                <input type="text" name="nombre" id="nombre" required class="form-input"
                       value="<?= e($v['nombre'] ?? '') ?>" placeholder="Condesa">
            </div>
            <div>
                <label class="form-label">Slug (URL) *</label>
                <div class="flex items-center">
                    <span class="px-3 py-2.5 bg-gray-50 border border-r-0 border-gray-200 rounded-l-xl text-sm text-gray-400">/</span>
                    <input type="text" name="slug" required class="form-input rounded-l-none"
                           value="<?= e($v['slug'] ?? '') ?>" placeholder="condesa"
                           pattern="[a-z0-9\-]+" title="Solo letras minúsculas, números y guiones">
                </div>
            </div>
            <div>
                <label class="form-label">Subtítulo</label>
                <input type="text" name="subtitulo" class="form-input"
                       value="<?= e($v['subtitulo'] ?? '') ?>" placeholder="El corazón de la Condesa">
            </div>
            <div>
                <label class="form-label">Destino</label>
                <select name="destino_id" class="form-select">
                    <option value="">Sin destino</option>
                    <?php foreach ($destinos as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= ($v['destino_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= e($d['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Slogan ES + EN -->
            <div class="sm:col-span-2">
                <label class="form-label">Slogan del hero</label>
                <input type="text" name="hero_slogan" id="hero_slogan" class="form-input"
                       value="<?= e($v['hero_slogan'] ?? '') ?>"
                       placeholder="Vive la Condesa&lt;br&gt;como nunca antes">
                <p class="text-xs text-gray-400 mt-1">Puedes usar &lt;br&gt; para saltos de línea</p>
            </div>
            <div class="sm:col-span-2">
                <?php enField('hero_slogan_en', 'hero_slogan', $v) ?>
            </div>

            <!-- Descripción corta ES + EN -->
            <div>
                <label class="form-label">Descripción corta</label>
                <textarea name="descripcion_corta" id="descripcion_corta" class="form-textarea" rows="2"
                          placeholder="Para el hero del sitio..."><?= e($v['descripcion_corta'] ?? '') ?></textarea>
            </div>
            <div>
                <?php enField('descripcion_corta_en', 'descripcion_corta', $v) ?>
            </div>

            <!-- Descripción larga ES + EN -->
            <div>
                <label class="form-label">Descripción larga</label>
                <textarea name="descripcion_larga" id="descripcion_larga" class="form-textarea" rows="2"
                          placeholder="Para la sección de ubicación..."><?= e($v['descripcion_larga'] ?? '') ?></textarea>
            </div>
            <div>
                <?php enField('descripcion_larga_en', 'descripcion_larga', $v) ?>
            </div>
        </div>
    </div>

    <!-- Ubicación -->
    <div class="card">
        <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
            <i data-lucide="map-pin" class="w-4 h-4 text-pk"></i>Ubicación
        </h2>
        <div class="grid sm:grid-cols-3 gap-5">
            <div><label class="form-label">Colonia</label><input type="text" name="colonia" class="form-input" value="<?= e($v['colonia'] ?? '') ?>"></div>
            <div><label class="form-label">Ciudad</label><input type="text" name="ciudad" class="form-input" value="<?= e($v['ciudad'] ?? '') ?>"></div>
            <div><label class="form-label">Estado</label><input type="text" name="estado" class="form-input" value="<?= e($v['estado'] ?? '') ?>"></div>
            <div><label class="form-label">Latitud</label><input type="number" step="any" name="lat" class="form-input" value="<?= e($v['lat'] ?? '') ?>" placeholder="19.4160"></div>
            <div><label class="form-label">Longitud</label><input type="number" step="any" name="lng" class="form-input" value="<?= e($v['lng'] ?? '') ?>" placeholder="-99.1653"></div>
        </div>
        <div class="mt-5">
            <label class="form-label">Embed de Google Maps</label>
            <textarea name="google_maps_embed" class="form-textarea font-mono text-xs" rows="3"
                      placeholder='<iframe src="https://maps.google.com/..." ...></iframe>'><?= e($v['google_maps_embed'] ?? '') ?></textarea>
        </div>
        <div class="mt-5">
            <label class="form-label">Puntos de interés cercanos</label>
            <textarea name="commercial_text" id="commercial_text" class="form-textarea" rows="4"
                      placeholder="Un punto de interés por línea:&#10;Parque México (3 min)&#10;Metro Chilpancingo (5 min)"><?= e($v['commercial_text'] ?? '') ?></textarea>
        </div>
        <div class="mt-4">
            <?php enField('commercial_text_en', 'commercial_text', $v) ?>
        </div>
        <div class="mt-5">
            <label class="form-label">Highlight de espacios</label>
            <input type="text" name="commercial_highlight" id="commercial_highlight" class="form-input"
                   value="<?= e($v['commercial_highlight'] ?? '') ?>"
                   placeholder="Cada departamento cuidadosamente diseñado...">
        </div>
        <div class="mt-4">
            <?php enField('commercial_highlight_en', 'commercial_highlight', $v) ?>
        </div>
    </div>

    <!-- Contacto y Precios -->
    <div class="card">
        <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
            <i data-lucide="phone" class="w-4 h-4 text-pk"></i>Contacto y Precios
        </h2>
        <div class="grid sm:grid-cols-3 gap-5">
            <div><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-input" value="<?= e($v['telefono'] ?? '') ?>"></div>
            <div><label class="form-label">WhatsApp</label><input type="text" name="whatsapp" class="form-input" value="<?= e($v['whatsapp'] ?? '') ?>" placeholder="525512345678"></div>
            <div><label class="form-label">Email</label><input type="email" name="email" class="form-input" value="<?= e($v['email'] ?? '') ?>"></div>
            <div><label class="form-label">Precio desde / noche (MXN)</label><input type="number" step="1" name="precio_desde_dia" class="form-input" value="<?= e($v['precio_desde_dia'] ?? '') ?>" placeholder="1800"></div>
            <div><label class="form-label">Precio desde / mes (MXN)</label><input type="number" step="1" name="precio_desde_mes" class="form-input" value="<?= e($v['precio_desde_mes'] ?? '') ?>" placeholder="25000"></div>
            <div>
                <label class="form-label">Código Cloudbeds</label>
                <input type="text" name="cloudbeds_code" class="form-input font-mono" value="<?= e($v['cloudbeds_code'] ?? '') ?>" placeholder="b5VW89">
            </div>
            <div>
                <label class="form-label">Property ID Cloudbeds</label>
                <input type="text" name="cloudbeds_property_id" class="form-input font-mono" value="<?= e($v['cloudbeds_property_id'] ?? '') ?>" placeholder="319288">
            </div>
        </div>
    </div>

    <!-- Zoho -->
    <div class="card">
        <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
            <i data-lucide="link" class="w-4 h-4 text-pk"></i>Integración Zoho CRM
        </h2>
        <div>
            <label class="form-label">Nombre en Zoho (campo Desarrollo_de_Inter_s)</label>
            <input type="text" name="zoho_property_name" class="form-input"
                   value="<?= e($v['zoho_property_name'] ?? '') ?>" placeholder="PARK LIFE CONDESA">
        </div>
    </div>

    <!-- SEO -->
    <div class="card">
        <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
            <i data-lucide="search" class="w-4 h-4 text-pk"></i>SEO
        </h2>
        <div class="space-y-4">
            <div>
                <label class="form-label">SEO Title</label>
                <input type="text" name="seo_title" maxlength="160" class="form-input"
                       value="<?= e($v['seo_title'] ?? '') ?>"
                       placeholder="Condesa by Park Life Properties | Departamentos Premium CDMX">
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">SEO Description</label>
                    <textarea name="seo_description" id="seo_description" maxlength="320" class="form-textarea" rows="2"><?= e($v['seo_description'] ?? '') ?></textarea>
                </div>
                <div>
                    <?php enField('seo_description_en', 'seo_description', $v) ?>
                </div>
            </div>
            <div>
                <label class="form-label">Keywords</label>
                <input type="text" name="seo_keywords" class="form-input" value="<?= e($v['seo_keywords'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- Opciones -->
    <div class="card">
        <h2 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
            <i data-lucide="sliders" class="w-4 h-4 text-pk"></i>Opciones
        </h2>
        <div class="grid sm:grid-cols-2 gap-5">
            <div>
                <label class="form-label">Orden de aparición</label>
                <input type="number" name="orden" class="form-input" value="<?= e($v['orden'] ?? 0) ?>" min="0">
            </div>
            <div class="flex flex-col gap-3 justify-end">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="activo" class="w-4 h-4 accent-pk rounded" <?= !empty($v['activo']) ? 'checked' : '' ?>>
                    <span class="text-sm font-medium text-gray-700">Propiedad activa</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="destacada" class="w-4 h-4 accent-pk rounded" <?= !empty($v['destacada']) ? 'checked' : '' ?>>
                    <span class="text-sm font-medium text-gray-700">Destacada</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="acepta_estancias_largas" class="w-4 h-4 accent-pk rounded" <?= !empty($v['acepta_estancias_largas']) ? 'checked' : '' ?>>
                    <span class="text-sm font-medium text-gray-700">Acepta estancias largas</span>
                </label>
            </div>
        </div>
    </div>

    <!-- Botones -->
    <div class="flex items-center gap-4 pt-2">
        <button type="submit" class="btn-primary px-8 py-3">
            <i data-lucide="save" class="w-4 h-4"></i>
            <?= $prop ? 'Guardar cambios' : 'Crear propiedad' ?>
        </button>
        <a href="propiedades.php" class="btn-secondary px-6 py-3">Cancelar</a>
        <?php if ($prop): ?>
        <a href="<?= BASE_URL . '/' . e($prop['slug']) ?>" target="_blank" class="btn-secondary px-6 py-3 ml-auto">
            <i data-lucide="external-link" class="w-4 h-4"></i>Ver en sitio
        </a>
        <?php endif; ?>
    </div>
</form>

<script>
// Auto-slug desde nombre
document.querySelector('[name="nombre"]')?.addEventListener('input', function() {
    const slugField = document.querySelector('[name="slug"]');
    if (slugField && !slugField.value) {
        slugField.value = this.value.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
            .replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
    }
});

// Auto-traducir
async function autoTranslate(sourceId, targetName) {
    const source = document.getElementById(sourceId);
    const target = document.querySelector('[name="' + targetName + '"]');
    if (!source || !target) return;

    const texto = source.value.trim();
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
    } catch(e) {
        alert('Error de conexión');
    } finally {
        btn.disabled = false;
        btn.textContent = '🤖 Auto-traducir';
    }
}
</script>

<?php
// ── Helper para pintar campo EN con botón ────────────────────────────────────
function enField(string $nameEn, string $sourceId, array $v): void {
    $label = match(true) {
        str_contains($nameEn, 'slogan')      => 'Slogan EN',
        str_contains($nameEn, 'descripcion_corta') => 'Descripción corta EN',
        str_contains($nameEn, 'descripcion_larga')  => 'Descripción larga EN',
        str_contains($nameEn, 'seo_description')    => 'SEO Description EN',
        default => strtoupper(str_replace('_en','', $nameEn)) . ' EN',
    };
    $isTextarea = str_contains($nameEn, 'descripcion') || str_contains($nameEn, 'seo_desc');
    $val = e($v[$nameEn] ?? '');
    $idEn = 'en_' . $nameEn;
    echo '<div>';
    echo '<label class="form-label flex items-center gap-2">';
    echo '<span class="text-blue-600">🇺🇸 ' . $label . '</span>';
    echo '<button type="button" onclick="autoTranslate(\'' . $sourceId . '\',\'' . $nameEn . '\')" ';
    echo 'class="text-xs px-2 py-0.5 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded-full transition-colors">🤖 Auto-traducir</button>';
    echo '</label>';
    if ($isTextarea) {
        echo '<textarea name="' . $nameEn . '" id="' . $idEn . '" class="form-textarea border-blue-200" rows="2">' . $val . '</textarea>';
    } else {
        echo '<input type="text" name="' . $nameEn . '" id="' . $idEn . '" class="form-input border-blue-200" value="' . $val . '">';
    }
    echo '</div>';
}

adminLayoutClose();
