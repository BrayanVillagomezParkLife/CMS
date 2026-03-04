<?php
declare(strict_types=1);

/**
 * admin/includes/middleware.php
 * Incluir al inicio de CADA página del admin.
 * Carga config, BD, funciones, verifica sesión activa.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Redirigir a login si no está autenticado
requireLogin();

// ═══ PROTECCIÓN AUTOMÁTICA POR PERMISOS ═══
$__currentScript = basename($_SERVER['SCRIPT_NAME'], '.php');

$__moduleMap = [
    'index'              => 'dashboard',
    'propiedades'        => 'propiedades',
    'habitaciones'       => 'habitaciones',
    'precios'            => 'precios',
    'cotizador'          => 'cotizador',
    'imagenes'           => 'imagenes',
    'imagenes-auditoria' => 'imagenes-auditoria',
    'amenidades'         => 'amenidades',
    'faqs'               => 'faqs',
    'hero'               => 'hero',
    'prensa'             => 'prensa',
    'leads'              => 'leads',
    'strings'            => 'strings',
    'config'             => 'config',
    'usuarios'           => 'usuarios',
    'roles'              => 'usuarios',
];

$__openPages = ['mi-perfil', 'login', 'logout', 'activar-cuenta', 'reset-password', '403'];

if (!in_array($__currentScript, $__openPages)) {
    if (isset($__moduleMap[$__currentScript]) && !canAccess($__moduleMap[$__currentScript], 'ver')) {
        $__firstPage = getFirstAccessiblePage();
        if ($__firstPage && $__firstPage !== $__currentScript . '.php') {
            header('Location: ' . $__firstPage);
            exit;
        }
        http_response_code(403);
        if (file_exists(BASE_PATH . '/admin/403.php')) {
            include BASE_PATH . '/admin/403.php';
        } else {
            echo '<h1>403</h1>';
        }
        exit;
    }
}
unset($__currentScript, $__moduleMap, $__openPages);
// ═══ FIN PARCHE ═══

// Helpers exclusivos del admin (el resto sigue igual)

// Helpers exclusivos del admin
function adminUrl(string $page = '', string $params = ''): string
{
    $base = BASE_URL . '/admin/' . $page;
    return $params ? $base . '?' . $params : $base;
}

function flashSet(string $type, string $msg): void
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_msg']  = $msg;
}

function flashGet(): ?array
{
    if (!empty($_SESSION['flash_msg'])) {
        $flash = ['type' => $_SESSION['flash_type'] ?? 'info', 'msg' => $_SESSION['flash_msg']];
        unset($_SESSION['flash_type'], $_SESSION['flash_msg']);
        return $flash;
    }
    return null;
}

function adminCsrf(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

function adminVerifyCsrf(string $token): bool
{
    return hash_equals($_SESSION['admin_csrf'] ?? '', $token);
}

function adminRedirect(string $url, string $flashType = '', string $flashMsg = ''): never
{
    if ($flashMsg) flashSet($flashType, $flashMsg);
    header('Location: ' . $url);
    exit;
}

// Helpers de formato para el admin
function badgeStatus(int $active): string
{
    return $active
        ? '<span class="px-2 py-0.5 text-xs font-semibold bg-green-100 text-green-700 rounded-full">Activo</span>'
        : '<span class="px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-500 rounded-full">Inactivo</span>';
}

function badgeLeadType(string $tipo): string
{
    $map = [
        'dias'     => 'bg-blue-100 text-blue-700',
        'meses'    => 'bg-purple-100 text-purple-700',
        'contacto' => 'bg-yellow-100 text-yellow-700',
        'trabajo'  => 'bg-orange-100 text-orange-700',
    ];
    $cls = $map[$tipo] ?? 'bg-gray-100 text-gray-600';
    return "<span class=\"px-2 py-0.5 text-xs font-semibold {$cls} rounded-full\">" . e($tipo) . "</span>";
}

/**
 * Genera el HTML del modal de explorador de pics/ + el JS global.
 * Usar en formularios que necesiten seleccionar imágenes.
 * 
 * En el HTML del form:
 *   <input type="text" id="mi_campo" ...>
 *   <button onclick="abrirPicsPicker('mi_campo', 'mi_preview')">Explorar</button>
 *   <div id="mi_preview"><img src=""></div>
 * 
 * Al final del form: <?= pickerModal() ?>
 */
function pickerModal(string $context = ''): string
{
    $ctx = htmlspecialchars($context, ENT_QUOTES);
    return <<<HTML
<!-- ── Pics Picker Modal ─────────────────────────────────────────────── -->
<div id="pics-picker-modal"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);backdrop-filter:blur(4px)"
     onclick="if(event.target===this)cerrarPicker()">

    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
                width:min(900px,96vw);height:min(88vh,700px);background:#fff;border-radius:1.25rem;
                display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.35)">

        <!-- Header -->
        <div style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;flex-shrink:0;flex-wrap:wrap">
            <div style="flex:1;min-width:120px">
                <h3 style="font-weight:700;color:#111827;font-size:.9375rem;margin:0">Imágenes</h3>
                <p id="picker-count" style="font-size:.72rem;color:#9ca3af;margin:.15rem 0 0">Cargando...</p>
            </div>
            <!-- Filtro carpeta -->
            <select id="picker-folder" onchange="cambiarCarpeta(this.value)"
                    style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:.625rem;font-size:.8rem;
                           outline:none;background:#f9fafb;font-family:inherit;color:#374151;cursor:pointer">
                <option value="">Todas las carpetas</option>
            </select>
            <!-- Búsqueda -->
            <input type="text" id="picker-search" placeholder="Buscar..." oninput="filtrarPics(this.value)"
                   autocomplete="off"
                   style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:.625rem;font-size:.8rem;
                          outline:none;width:160px;font-family:inherit">
            <!-- FIX 2: Botón Seleccionar todas (solo en modo multi) -->
            <button id="picker-select-all-btn" onclick="toggleSelectAll()" 
                    style="display:none;padding:.4rem .75rem;border:1px solid #22c55e;border-radius:.625rem;font-size:.75rem;
                           background:#f0fdf4;color:#16a34a;cursor:pointer;font-family:inherit;font-weight:600;white-space:nowrap">
                ☑ Seleccionar todas
            </button>
            <!-- Botón eliminar seleccionadas (solo en modo multi con selección) -->
            <button id="picker-delete-selected-btn" onclick="eliminarSeleccionadas()"
                    style="display:none;padding:.4rem .75rem;border:1px solid #fca5a5;border-radius:.625rem;font-size:.75rem;
                           background:#fef2f2;color:#dc2626;cursor:pointer;font-family:inherit;font-weight:600;white-space:nowrap">
                🗑 Eliminar seleccionadas
            </button>

            <!-- Tamaño de vista -->
            <div style="display:flex;gap:2px;background:#f3f4f6;border-radius:.5rem;padding:2px">
                <button onclick="setPickerSize('sm')" id="picker-size-sm" title="Pequeño"
                        style="width:1.6rem;height:1.6rem;border:none;border-radius:.375rem;cursor:pointer;font-size:.7rem;
                            background:transparent;color:#6b7280;display:flex;align-items:center;justify-content:center;transition:all .15s">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>
                </button>
                <button onclick="setPickerSize('md')" id="picker-size-md" title="Mediano"
                        style="width:1.6rem;height:1.6rem;border:none;border-radius:.375rem;cursor:pointer;font-size:.7rem;
                            background:#fff;color:#202944;display:flex;align-items:center;justify-content:center;transition:all .15s;box-shadow:0 1px 2px rgba(0,0,0,.1)">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><rect x="0" y="0" width="7" height="7" rx="1.5"/><rect x="9" y="0" width="7" height="7" rx="1.5"/><rect x="0" y="9" width="7" height="7" rx="1.5"/><rect x="9" y="9" width="7" height="7" rx="1.5"/></svg>
                </button>
                <button onclick="setPickerSize('lg')" id="picker-size-lg" title="Grande"
                        style="width:1.6rem;height:1.6rem;border:none;border-radius:.375rem;cursor:pointer;font-size:.7rem;
                            background:transparent;color:#6b7280;display:flex;align-items:center;justify-content:center;transition:all .15s">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><rect x="0" y="0" width="16" height="7" rx="2"/><rect x="0" y="9" width="16" height="7" rx="2"/></svg>
                </button>
            </div>

            <button onclick="cerrarPicker()"
                    style="width:1.875rem;height:1.875rem;border:none;background:#f3f4f6;border-radius:.5rem;
                           cursor:pointer;font-size:1rem;color:#6b7280;flex-shrink:0">✕</button>
        </div>

        <!-- Drop Zone + Grid -->
        <div style="flex:1;display:flex;flex-direction:column;overflow:hidden">

            <!-- Drop Zone -->
            <div id="picker-dropzone"
                 ondragover="event.preventDefault();this.style.background='#eef2ff';this.style.borderColor='#202944'"
                 ondragleave="this.style.background='#f8fafc';this.style.borderColor='#e5e7eb'"
                 ondrop="handleDrop(event)"
                 onclick="document.getElementById('picker-file-input').click()"
                 style="margin:.75rem;border:2px dashed #e5e7eb;border-radius:1rem;padding:1.25rem;
                        display:flex;align-items:center;justify-content:center;gap:1rem;
                        background:#f8fafc;cursor:pointer;transition:all .2s;flex-shrink:0">
                <div style="font-size:1.5rem">📤</div>
                <div>
                    <p style="margin:0;font-weight:600;font-size:.8125rem;color:#374151">Arrastra imágenes aquí o haz clic para subir</p>
                    <p style="margin:.2rem 0 0;font-size:.72rem;color:#9ca3af">JPG, PNG, WebP, GIF · máx 12MB por archivo · múltiples a la vez</p>
                </div>
                <input type="file" id="picker-file-input" multiple accept="image/*" style="display:none" onchange="handleFileInput(this)">
            </div>

            <!-- Barra de progreso (oculta por defecto) -->
            <div id="picker-upload-progress" style="display:none;margin:0 .75rem .5rem;flex-shrink:0">
                <div style="background:#f3f4f6;border-radius:999px;height:6px;overflow:hidden">
                    <div id="picker-progress-bar" style="height:100%;background:#202944;border-radius:999px;transition:width .3s;width:0%"></div>
                </div>
                <p id="picker-progress-text" style="font-size:.72rem;color:#6b7280;margin:.3rem 0 0;text-align:center"></p>
            </div>

            <!-- Grid de imágenes -->
            <div id="picker-grid"
                 style="flex:1;overflow-y:auto;padding:.25rem .75rem .75rem;
                        display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.625rem">
                <div style="grid-column:1/-1;text-align:center;padding:2rem;color:#9ca3af">
                    <div style="font-size:2rem;margin-bottom:.5rem">⏳</div>Cargando...
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div style="padding:.875rem 1.25rem;border-top:1px solid #f3f4f6;display:flex;align-items:center;
                    justify-content:space-between;flex-shrink:0;background:#fafafa;flex-wrap:wrap;gap:.5rem">
            <div id="picker-selected-preview" style="display:flex;align-items:center;gap:.625rem">
                <span style="font-size:.8rem;color:#9ca3af">Ninguna seleccionada</span>
            </div>
            <div style="display:flex;gap:.5rem">
                <button onclick="cerrarPicker()"
                        style="padding:.5rem 1.125rem;border:1px solid #e5e7eb;border-radius:.75rem;
                               background:#fff;cursor:pointer;font-size:.8125rem;font-family:inherit">
                    Cancelar
                </button>
                <!-- Botón multi-selección (solo visible en modo galería) -->
                <button id="picker-multi-btn" onclick="confirmarPickerMulti()" disabled
                        style="display:none;padding:.5rem 1.25rem;border:none;border-radius:.75rem;background:#22c55e;
                               color:#fff;cursor:pointer;font-size:.8125rem;font-family:inherit;font-weight:600;
                               opacity:.4;align-items:center;gap:.375rem">
                    ✓ Agregar 0 fotos
                </button>
                <button id="picker-confirm-btn" onclick="confirmarPicker()" disabled
                        style="padding:.5rem 1.25rem;border:none;border-radius:.75rem;background:#202944;
                               color:#fff;cursor:pointer;font-size:.8125rem;font-family:inherit;font-weight:600;opacity:.4">
                    ✓ Usar esta imagen
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
const PICKER_CTX = '{$ctx}';
let _input    = null;
let _preview  = null;
let _selected = null;
let _allPics  = [];
let _loaded   = false;
let _multiMode     = false;
let _multiSelected = [];
let _multiCallback = null;
let _visiblePics   = []; // FIX 2: track currently visible pics for select-all

window.abrirPicsPicker = function(inputId, previewId) {
    _input    = document.getElementById(inputId);
    _preview  = previewId ? document.getElementById(previewId) : null;
    _selected = null;
    _multiMode = false;
    _multiSelected = [];

    const modal = document.getElementById('pics-picker-modal');
    modal.style.display = 'block';
    document.getElementById('picker-search').value = '';
    document.getElementById('picker-multi-btn').style.display = 'none';
    document.getElementById('picker-select-all-btn').style.display = 'none';
    document.getElementById('picker-delete-selected-btn').style.display = 'none';
    _resetConfirm();

    if (!_loaded) cargarPics();
    else renderPics(_allPics);
};

window.abrirPickerMulti = function(onConfirmCb) {
    _input    = null;
    _preview  = null;
    _selected = null;
    _multiMode = true;
    _multiSelected = [];
    _multiCallback = onConfirmCb;

    const modal = document.getElementById('pics-picker-modal');
    modal.style.display = 'block';
    document.getElementById('picker-search').value = '';
    // Mostrar botones multi
    const btn = document.getElementById('picker-multi-btn');
    btn.style.display = 'flex';
    btn.textContent   = '✓ Agregar 0 fotos';
    btn.disabled      = true;
    btn.style.opacity = '.4';
    // FIX 2: Mostrar botón seleccionar todas
    document.getElementById('picker-select-all-btn').style.display = 'inline-block';
    _resetConfirm();

    if (!_loaded) cargarPics();
    else {
        // FIX 3: Auto-filtrar por carpeta de la propiedad actual
        _autoFilterFolder();
        renderPics(_getFilteredPics());
    }
};

window.cerrarPicker = function() {
    document.getElementById('pics-picker-modal').style.display = 'none';
};

// FIX 3: Auto-filtrar a la carpeta de la propiedad
function _autoFilterFolder() {
    if (!PICKER_CTX || PICKER_CTX === 'general') return;
    const sel = document.getElementById('picker-folder');
    const targetFolder = 'propiedades/' + PICKER_CTX;
    for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === targetFolder) {
            sel.value = targetFolder;
            return;
        }
    }
}

function _getFilteredPics() {
    const folder = document.getElementById('picker-folder').value;
    const q = document.getElementById('picker-search').value.toLowerCase();
    let filtered = folder
        ? _allPics.filter(f => f.folder === folder || f.path.startsWith('pics/' + folder + '/'))
        : _allPics;
    if (q) filtered = filtered.filter(f => f.name.toLowerCase().includes(q));
    return filtered;
}

function cargarPics() {
    const url = 'api/list-pics.php' + (PICKER_CTX ? '?context=' + encodeURIComponent(PICKER_CTX) : '');
    fetch(url)
        .then(r => r.json())
        .then(data => {
            _allPics = data.files || [];
            _loaded  = true;
            // Poblar selector de carpetas
            const sel = document.getElementById('picker-folder');
            sel.innerHTML = '<option value="">Todas las carpetas (' + _allPics.length + ')</option>';
            (data.folders || []).forEach(f => {
                const opt = document.createElement('option');
                opt.value = f;
                const count = _allPics.filter(p => p.folder === f || ('pics/'+f === p.path.substring(0, ('pics/'+f).length))).length;
                opt.textContent = f + ' (' + count + ')';
                sel.appendChild(opt);
            });
            // FIX 3: Auto-filtrar por propiedad en modo multi
            if (_multiMode) _autoFilterFolder();
            renderPics(_getFilteredPics());
        })
        .catch(() => {
            document.getElementById('picker-grid').innerHTML =
                '<div style="grid-column:1/-1;text-align:center;padding:2rem;color:#ef4444">Error al cargar imágenes</div>';
        });
}

window.cambiarCarpeta = function(folder) {
    document.getElementById('picker-search').value = '';
    renderPics(_getFilteredPics());
};

window.filtrarPics = function(q) {
    renderPics(_getFilteredPics());
};

// FIX 2: Toggle seleccionar todas / deseleccionar todas
window.toggleSelectAll = function() {
    if (!_multiMode) return;
    const visible = _getFilteredPics();
    const allSelected = visible.length > 0 && visible.every(f => _multiSelected.includes(f.path));

    if (allSelected) {
        // Deseleccionar todas las visibles
        visible.forEach(f => {
            const idx = _multiSelected.indexOf(f.path);
            if (idx !== -1) _multiSelected.splice(idx, 1);
        });
        document.getElementById('picker-select-all-btn').textContent = '☑ Seleccionar todas';
        document.getElementById('picker-select-all-btn').style.background = '#f0fdf4';
    } else {
        // Seleccionar todas las visibles
        visible.forEach(f => {
            if (!_multiSelected.includes(f.path)) _multiSelected.push(f.path);
        });
        document.getElementById('picker-select-all-btn').textContent = '☐ Deseleccionar todas';
        document.getElementById('picker-select-all-btn').style.background = '#dcfce7';
    }

    _updateMultiBtn();
    renderPics(_getFilteredPics());
};

function _updateMultiBtn() {
    const btn = document.getElementById('picker-multi-btn');
    btn.textContent = '✓ Agregar ' + _multiSelected.length + ' foto' + (_multiSelected.length !== 1 ? 's' : '');
    btn.disabled = _multiSelected.length === 0;
    btn.style.opacity = _multiSelected.length === 0 ? '.4' : '1';
    // Show/hide delete selected button
    const delBtn = document.getElementById('picker-delete-selected-btn');
    if (_multiSelected.length > 0) {
        delBtn.style.display = 'inline-block';
        delBtn.textContent = '🗑 Eliminar ' + _multiSelected.length;
    } else {
        delBtn.style.display = 'none';
    }
}

function renderPics(files) {
    const grid    = document.getElementById('picker-grid');
    const current = _input?.value || '';
    _visiblePics  = files; // track for select-all
    document.getElementById('picker-count').textContent =
        files.length + ' imagen' + (files.length !== 1 ? 'es' : '');

    // Update select-all button state
    if (_multiMode && files.length > 0) {
        const allSel = files.every(f => _multiSelected.includes(f.path));
        const btn = document.getElementById('picker-select-all-btn');
        btn.textContent = allSel ? '☐ Deseleccionar todas' : '☑ Seleccionar todas';
        btn.style.background = allSel ? '#dcfce7' : '#f0fdf4';
    }

    if (files.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:2.5rem;color:#9ca3af">No hay imágenes. ¡Arrastra archivos arriba para subir!</div>';
        return;
    }

    grid.innerHTML = files.map(f => {
        const sel    = _multiMode ? _multiSelected.includes(f.path) : f.path === current;
        const sizeKb = (f.size / 1024).toFixed(0);
        const folder = f.folder ? '<span style="font-size:.58rem;color:#bac4b9;display:block;margin-top:.1rem">' + f.folder + '</span>' : '';
        const check  = sel
            ? '<div style="position:absolute;top:4px;right:4px;background:' + (_multiMode ? '#22c55e' : '#202944') + ';color:#fff;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:11px;z-index:1;font-weight:bold">✓</div>'
            : (_multiMode ? '<div style="position:absolute;top:4px;right:4px;width:20px;height:20px;border:2px solid rgba(255,255,255,.7);border-radius:50%;z-index:1;background:rgba(0,0,0,.2)"></div>' : '');
        // Trash button (appears on hover)
        const trash = '<div onclick="event.stopPropagation();eliminarPicUna(\'' + f.path.replace(/'/g,"\\'") + '\')" style="position:absolute;bottom:4px;right:4px;width:22px;height:22px;background:rgba(220,38,38,.85);color:#fff;border-radius:6px;display:none;align-items:center;justify-content:center;font-size:11px;z-index:2;cursor:pointer" class="pic-trash-btn" title="Eliminar archivo">🗑</div>';
        return '<div onclick="seleccionarPic(\'' + f.path.replace(/'/g,"\\'") + '\',\'' + f.name.replace(/'/g,"\\'") + '\')" data-path="' + f.path + '" style="cursor:pointer;border-radius:.625rem;overflow:hidden;border:2px solid ' + (sel ? (_multiMode ? '#22c55e' : '#202944') : '#f3f4f6') + ';background:' + (sel ? (_multiMode ? '#f0fdf4' : '#eef2ff') : '#f9fafb') + ';transition:all .15s" onmouseenter="this.querySelector(\'.pic-trash-btn\').style.display=\'flex\'" onmouseleave="this.querySelector(\'.pic-trash-btn\').style.display=\'none\'">'
            + '<div style="aspect-ratio:4/3;overflow:hidden;background:#e5e7eb;position:relative">'
            + check + trash
            + '<img src="/' + f.path + '" loading="lazy" style="width:100%;height:100%;object-fit:cover;transition:transform .3s" onmouseover="this.style.transform=\'scale(1.06)\'" onmouseout="this.style.transform=\'scale(1)\'" onerror="this.parentElement.innerHTML=\'<div style=\\\"display:flex;align-items:center;justify-content:center;height:100%;color:#9ca3af;font-size:1.25rem\\\">🖼️</div>\'">'
            + '</div>'
            + '<div style="padding:.3rem .4rem">'
            + '<p style="font-size:.63rem;color:#374151;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:0" title="' + f.name + '">' + f.name + '</p>'
            + '<p style="font-size:.58rem;color:#9ca3af;margin:.1rem 0 0">' + sizeKb + ' KB' + folder + '</p>'
            + '</div></div>';
    }).join('');
}

window.seleccionarPic = function(path, name) {
    if (_multiMode) {
        // Toggle en multi-selección
        const idx = _multiSelected.indexOf(path);
        if (idx === -1) _multiSelected.push(path);
        else            _multiSelected.splice(idx, 1);

        _updateMultiBtn();
        renderPics(_getFilteredPics());
        return;
    }

    _selected = path;
    renderPics(_getFilteredPics());

    document.getElementById('picker-selected-preview').innerHTML =
        '<img src="/' + path + '" style="height:36px;width:50px;object-fit:cover;border-radius:.4rem;border:1px solid #e5e7eb">'
        + '<span style="font-size:.8rem;color:#374151;font-weight:500">' + name + '</span>';
    const btn = document.getElementById('picker-confirm-btn');
    btn.disabled = false;
    btn.style.opacity = '1';
};

window.confirmarPicker = function() {
    if (!_selected || !_input) return;
    _input.value = _selected;
    if (_preview) {
        const img = _preview.querySelector('img');
        if (img) { img.src = '/' + _selected; _preview.style.display = 'block'; _preview.classList.remove('hidden'); }
    }
    cerrarPicker();
};

window.confirmarPickerMulti = function() {
    if (!_multiSelected.length || !_multiCallback) return;
    _multiCallback(_multiSelected.slice());
    cerrarPicker();
};

// ── Drag & Drop ─────────────────────────────────────────────────────────────
window.handleDrop = function(e) {
    e.preventDefault();
    const dz = document.getElementById('picker-dropzone');
    dz.style.background   = '#f8fafc';
    dz.style.borderColor  = '#e5e7eb';
    subirArchivos(Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/')));
};

window.handleFileInput = function(input) {
    subirArchivos(Array.from(input.files));
    input.value = ''; // reset para permitir seleccionar el mismo archivo
};

function subirArchivos(files) {
    if (!files.length) return;
    const ctx      = PICKER_CTX || 'general';
    const total    = files.length;
    let   uploaded = 0;
    let   errors   = 0;
    const uploadedPaths = []; // FIX 1: track all uploaded paths

    const prog     = document.getElementById('picker-upload-progress');
    const bar      = document.getElementById('picker-progress-bar');
    const txt      = document.getElementById('picker-progress-text');
    prog.style.display = 'block';

    function updateProgress() {
        const done = uploaded + errors;
        const pct  = Math.round((done / total) * 100);
        bar.style.width = pct + '%';
        txt.textContent = 'Subiendo ' + done + ' de ' + total + '…' + (errors ? ' (' + errors + ' error' + (errors>1?'s':'') + ')' : '');
        if (done === total) {
            txt.textContent = '✓ ' + uploaded + ' imagen' + (uploaded!==1?'es':'') + ' subida' + (uploaded!==1?'s':'') + (errors ? ' · ' + errors + ' error(s)' : '');
            bar.style.background = errors && !uploaded ? '#ef4444' : '#22c55e';
            setTimeout(() => {
                prog.style.display = 'none';
                bar.style.width    = '0%';
                bar.style.background = '#202944';
                _loaded = false;

                // FIX 1: Auto-seleccionar TODAS las recién subidas en modo multi
                if (_multiMode && uploadedPaths.length > 0) {
                    uploadedPaths.forEach(p => {
                        if (!_multiSelected.includes(p)) _multiSelected.push(p);
                    });
                    _updateMultiBtn();
                }

                cargarPics(); // recargar grid (las mostrará seleccionadas)
            }, 1200);
        }
    }

    files.forEach(file => {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('context', ctx);
        fetch('api/upload-pic.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    uploaded++;
                    // FIX 1: Guardar path para auto-selección
                    if (data.path) {
                        uploadedPaths.push(data.path);
                        _allPics.unshift({ name: data.name, path: data.path, size: data.size, ext: data.ext, folder: data.subfolder });
                    }
                    // Auto-seleccionar en modo single si es 1 archivo
                    if (!_multiMode && total === 1 && data.path) {
                        seleccionarPic(data.path, data.name);
                    }
                } else { errors++; }
                updateProgress();
            })
            .catch(() => { errors++; updateProgress(); });
    });
}

function _resetConfirm() {
    _selected = null;
    const btn = document.getElementById('picker-confirm-btn');
    btn.disabled = true;
    btn.style.opacity = '.4';
    document.getElementById('picker-selected-preview').innerHTML =
        '<span style="font-size:.8rem;color:#9ca3af">Ninguna seleccionada</span>';
}

window.mostrarPreview = function(path, previewId) {
    if (!previewId) return;
    const c = document.getElementById(previewId);
    if (!c) return;
    const img = c.querySelector('img');
    if (img) { img.src = '/' + path; c.classList.remove('hidden'); c.style.display = 'block'; }
};

// ── Eliminar imágenes ───────────────────────────────────────────────────────
window.eliminarPicUna = function(path) {
    if (!confirm('¿Eliminar este archivo permanentemente?\\n' + path)) return;
    _deletePics([path]);
};

window.eliminarSeleccionadas = function() {
    if (!_multiSelected.length) return;
    if (!confirm('¿Eliminar ' + _multiSelected.length + ' archivo(s) permanentemente?\\nEsta acción no se puede deshacer.')) return;
    _deletePics(_multiSelected.slice());
};

function _deletePics(paths) {
    let done = 0, ok = 0, fail = 0;
    const total = paths.length;
    const txt = document.getElementById('picker-progress-text');
    const bar = document.getElementById('picker-progress-bar');
    const prog = document.getElementById('picker-upload-progress');
    prog.style.display = 'block';
    bar.style.background = '#dc2626';
    bar.style.width = '0%';
    txt.textContent = 'Eliminando…';

    paths.forEach(p => {
        const fd = new FormData();
        fd.append('path', p);
        fetch('api/delete-pic.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { if (d.success) ok++; else fail++; })
            .catch(() => fail++)
            .finally(() => {
                done++;
                bar.style.width = Math.round(done / total * 100) + '%';
                txt.textContent = 'Eliminando ' + done + '/' + total + '…';
                if (done === total) {
                    bar.style.background = fail && !ok ? '#ef4444' : '#22c55e';
                    txt.textContent = '✓ ' + ok + ' eliminada' + (ok !== 1 ? 's' : '') + (fail ? ' · ' + fail + ' error(es)' : '');
                    // Limpiar selección y recargar
                    _multiSelected = paths.reduce((sel, p) => sel.filter(s => s !== p), _multiSelected);
                    _allPics = _allPics.filter(f => !paths.includes(f.path));
                    if (_multiMode) _updateMultiBtn();
                    setTimeout(() => {
                        prog.style.display = 'none';
                        bar.style.width = '0%';
                        bar.style.background = '#202944';
                        renderPics(_getFilteredPics());
                    }, 1500);
                }
            });
    });
}

// ── Tamaño de vista ──────────────────────────────────────────────────────
let _pickerSize = 'md';
const _sizeMap = {
    sm: 'repeat(auto-fill,minmax(100px,1fr))',
    md: 'repeat(auto-fill,minmax(150px,1fr))',
    lg: 'repeat(auto-fill,minmax(220px,1fr))'
};

window.setPickerSize = function(size) {
    _pickerSize = size;
    const grid = document.getElementById('picker-grid');
    grid.style.gridTemplateColumns = _sizeMap[size];

    // Actualizar botones activos
    ['sm','md','lg'].forEach(s => {
        const btn = document.getElementById('picker-size-' + s);
        if (s === size) {
            btn.style.background = '#fff';
            btn.style.color = '#202944';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,.1)';
        } else {
            btn.style.background = 'transparent';
            btn.style.color = '#6b7280';
            btn.style.boxShadow = 'none';
        }
    });
};

})();


</script>
HTML;
}