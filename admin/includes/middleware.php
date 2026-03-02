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
        <div style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;flex-shrink:0">
            <div style="flex:1">
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
            <button onclick="cerrarPicker()"
                    style="width:1.875rem;height:1.875rem;border:none;background:#f3f4f6;border-radius:.5rem;
                           cursor:pointer;font-size:1rem;color:#6b7280;flex-shrink:0">✕</button>
        </div>

        <!-- Drop Zone + Grid -->
        <div style="flex:1;display:flex;flex-direction:column;overflow:hidden">

            <!-- Drop Zone (visible solo cuando no hay archivos o al arrastrar) -->
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
                    justify-content:space-between;flex-shrink:0;background:#fafafa">
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
    // Mostrar botón multi
    const btn = document.getElementById('picker-multi-btn');
    btn.style.display = 'flex';
    btn.textContent   = '✓ Agregar 0 fotos';
    _resetConfirm();

    if (!_loaded) cargarPics();
    else renderPics(_allPics);
};

window.cerrarPicker = function() {
    document.getElementById('pics-picker-modal').style.display = 'none';
};

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
            renderPics(_allPics);
        })
        .catch(() => {
            document.getElementById('picker-grid').innerHTML =
                '<div style="grid-column:1/-1;text-align:center;padding:2rem;color:#ef4444">Error al cargar imágenes</div>';
        });
}

window.cambiarCarpeta = function(folder) {
    const filtered = folder
        ? _allPics.filter(f => f.folder === folder || f.path.startsWith('pics/' + folder + '/'))
        : _allPics;
    document.getElementById('picker-search').value = '';
    renderPics(filtered);
};

window.filtrarPics = function(q) {
    const folder = document.getElementById('picker-folder').value;
    let filtered = folder ? _allPics.filter(f => f.path.includes(folder)) : _allPics;
    if (q) filtered = filtered.filter(f => f.name.toLowerCase().includes(q.toLowerCase()));
    renderPics(filtered);
};

function renderPics(files) {
    const grid    = document.getElementById('picker-grid');
    const current = _input?.value || '';
    document.getElementById('picker-count').textContent =
        files.length + ' imagen' + (files.length !== 1 ? 'es' : '');

    if (files.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:2.5rem;color:#9ca3af">No hay imágenes. ¡Arrastra archivos arriba para subir!</div>';
        return;
    }

    grid.innerHTML = files.map(f => {
        const sel    = _multiMode ? _multiSelected.includes(f.path) : f.path === current;
        const sizeKb = (f.size / 1024).toFixed(0);
        const folder = f.folder ? '<span style="font-size:.58rem;color:#bac4b9;display:block;margin-top:.1rem">' + f.folder + '</span>' : '';
        const check  = sel
            ? '<div style="position:absolute;top:4px;right:4px;background:' + (_multiMode ? '#22c55e' : '#202944') + ';color:#fff;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:11px;z-index:1;font-weight:bold">' + (_multiMode ? '✓' : '✓') + '</div>'
            : (_multiMode ? '<div style="position:absolute;top:4px;right:4px;width:20px;height:20px;border:2px solid rgba(255,255,255,.7);border-radius:50%;z-index:1;background:rgba(0,0,0,.2)"></div>' : '');
        return '<div onclick="seleccionarPic(\'' + f.path.replace(/'/g,"\\'") + '\',\'' + f.name.replace(/'/g,"\\'") + '\')" data-path="' + f.path + '" style="cursor:pointer;border-radius:.625rem;overflow:hidden;border:2px solid ' + (sel ? (_multiMode ? '#22c55e' : '#202944') : '#f3f4f6') + ';background:' + (sel ? (_multiMode ? '#f0fdf4' : '#eef2ff') : '#f9fafb') + ';transition:all .15s">'
            + '<div style="aspect-ratio:4/3;overflow:hidden;background:#e5e7eb;position:relative">'
            + check
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

        // Actualizar botón
        const btn = document.getElementById('picker-multi-btn');
        btn.textContent = '✓ Agregar ' + _multiSelected.length + ' foto' + (_multiSelected.length !== 1 ? 's' : '');
        btn.disabled = _multiSelected.length === 0;
        btn.style.opacity = _multiSelected.length === 0 ? '.4' : '1';

        // Re-render para checkmarks
        const folder = document.getElementById('picker-folder').value;
        const q      = document.getElementById('picker-search').value;
        let filtered = folder ? _allPics.filter(f => f.path.includes(folder)) : _allPics;
        if (q) filtered = filtered.filter(f => f.name.toLowerCase().includes(q.toLowerCase()));
        renderPics(filtered);
        return;
    }

    _selected = path;
    const folder = document.getElementById('picker-folder').value;
    const q      = document.getElementById('picker-search').value;
    let filtered = folder ? _allPics.filter(f => f.path.includes(folder)) : _allPics;
    if (q) filtered = filtered.filter(f => f.name.toLowerCase().includes(q.toLowerCase()));
    renderPics(filtered);

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
                cargarPics(); // recargar grid
            }, 2000);
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
                    // Auto-seleccionar si solo subimos 1
                    if (total === 1 && data.path) {
                        _allPics.unshift({ name: data.name, path: data.path, size: data.size, ext: data.ext, folder: data.subfolder });
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
})();
</script>
HTML;
}
