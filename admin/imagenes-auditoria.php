<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf = adminCsrf();
requireRole('superadmin');

// ── POST: limpiar registros huérfanos ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Token inválido']); exit;
    }

    $action = $_POST['action'] ?? '';

    // Eliminar un registro huérfano específico
    if ($action === 'delete_orphan') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            // Intentar borrar archivo físico también (por si existe parcialmente)
            $row = dbFetchOne("SELECT url FROM propiedad_imagenes WHERE id = ?", [$id]);
            if ($row) {
                $path = __DIR__ . '/../' . ltrim($row['url'], '/');
                if (file_exists($path)) @unlink($path);
            }
            dbExecute("DELETE FROM propiedad_imagenes WHERE id = ?", [$id]);
        }
        echo json_encode(['success' => true]); exit;
    }

    // Eliminar todos los huérfanos de una propiedad o todos
    if ($action === 'delete_all_orphans') {
        $ids = array_map('intval', json_decode($_POST['ids'] ?? '[]', true));
        if (!empty($ids)) {
            foreach ($ids as $id) {
                $row = dbFetchOne("SELECT url FROM propiedad_imagenes WHERE id = ?", [$id]);
                if ($row) {
                    $path = __DIR__ . '/../' . ltrim($row['url'], '/');
                    if (file_exists($path)) @unlink($path);
                }
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            dbExecute("DELETE FROM propiedad_imagenes WHERE id IN ($placeholders)", $ids);
        }
        echo json_encode(['success' => true, 'deleted' => count($ids)]); exit;
    }

    // Escaneo AJAX — devuelve resultados
    if ($action === 'scan') {
        $propId = (int)($_POST['propiedad_id'] ?? 0);

        $where  = $propId ? "WHERE pi.propiedad_id = $propId" : '';
        $rows   = dbFetchAll("
            SELECT pi.id, pi.propiedad_id, pi.tipo, pi.url, pi.es_portada,
                   p.nombre AS propiedad_nombre, p.slug
            FROM propiedad_imagenes pi
            JOIN propiedades p ON p.id = pi.propiedad_id
            $where
            ORDER BY p.nombre, pi.tipo, pi.id
        ");

        $baseDir  = __DIR__ . '/../';
        $orphans  = [];
        $ok       = 0;

        foreach ($rows as $row) {
            $path = $baseDir . ltrim($row['url'], '/');
            if (!file_exists($path) || filesize($path) < 100) {
                $orphans[] = $row;
            } else {
                $ok++;
            }
        }

        echo json_encode([
            'success'  => true,
            'total'    => count($rows),
            'ok'       => $ok,
            'orphans'  => $orphans,
            'count'    => count($orphans),
        ]);
        exit;
    }
}

// ── GET: página principal ─────────────────────────────────────────────────────
$propiedades = dbFetchAll("SELECT id, nombre FROM propiedades WHERE activo = 1 ORDER BY nombre");

// Stats rápidos
$totalImagenes = dbFetchOne("SELECT COUNT(*) AS c FROM propiedad_imagenes")['c'] ?? 0;

adminLayoutOpen('Auditoría de Imágenes');
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Auditoría de Imágenes</h1>
        <p class="text-sm text-gray-500 mt-1">Detecta y elimina imágenes huérfanas — registros en BD sin archivo físico en el servidor</p>
    </div>
    <a href="imagenes.php" class="btn-secondary flex items-center gap-2">
        <i data-lucide="images" class="w-4 h-4"></i>Gestionar imágenes
    </a>
</div>

<!-- Stats -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="card text-center py-4">
        <div class="text-3xl font-bold text-park-blue" id="stat-total"><?= $totalImagenes ?></div>
        <div class="text-xs text-gray-400 mt-1">Total en BD</div>
    </div>
    <div class="card text-center py-4">
        <div class="text-3xl font-bold text-green-500" id="stat-ok">—</div>
        <div class="text-xs text-gray-400 mt-1">Archivos OK</div>
    </div>
    <div class="card text-center py-4">
        <div class="text-3xl font-bold text-red-500" id="stat-orphans">—</div>
        <div class="text-xs text-gray-400 mt-1">Huérfanas</div>
    </div>
</div>

<!-- Scanner -->
<div class="card mb-5">
    <h2 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
        <i data-lucide="scan-search" class="w-4 h-4 text-pk"></i>Escanear
    </h2>
    <div class="flex gap-3 items-end">
        <div class="flex-1">
            <label class="form-label">Propiedad</label>
            <select id="scan-prop" class="form-input">
                <option value="0">— Todas las propiedades —</option>
                <?php foreach ($propiedades as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button onclick="iniciarScan()" id="btn-scan" class="btn-primary flex items-center gap-2 px-6">
            <i data-lucide="search" class="w-4 h-4"></i>Escanear
        </button>
    </div>

    <!-- Progress -->
    <div id="scan-progress" class="hidden mt-4">
        <div class="flex items-center gap-3 text-sm text-gray-500">
            <div class="w-5 h-5 border-2 border-pk border-t-transparent rounded-full animate-spin"></div>
            Escaneando archivos en el servidor…
        </div>
    </div>
</div>

<!-- Resultados -->
<div id="scan-results" class="hidden">

    <!-- Resumen -->
    <div id="summary-ok" class="hidden card mb-4 border border-green-200 bg-green-50">
        <div class="flex items-center gap-3 text-green-700">
            <i data-lucide="check-circle" class="w-5 h-5"></i>
            <span class="font-semibold">¡Todo limpio! No se encontraron imágenes huérfanas.</span>
        </div>
    </div>

    <!-- Tabla de huérfanas -->
    <div id="summary-orphans" class="hidden card mb-4">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-bold text-gray-800 flex items-center gap-2">
                <i data-lucide="alert-triangle" class="w-4 h-4 text-red-500"></i>
                <span id="orphan-count-title">0 imágenes huérfanas</span>
            </h2>
            <button onclick="eliminarTodos()" id="btn-delete-all"
                class="flex items-center gap-2 px-4 py-2 bg-red-500 text-white rounded-lg text-sm font-semibold hover:bg-red-600 transition-colors">
                <i data-lucide="trash-2" class="w-4 h-4"></i>Eliminar todas
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs text-gray-400 uppercase tracking-wide">
                        <th class="pb-2 pr-4">ID</th>
                        <th class="pb-2 pr-4">Propiedad</th>
                        <th class="pb-2 pr-4">Tipo</th>
                        <th class="pb-2 pr-4">URL en BD</th>
                        <th class="pb-2 pr-4">Portada</th>
                        <th class="pb-2">Acción</th>
                    </tr>
                </thead>
                <tbody id="orphan-tbody">
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= $csrf ?>';
let orphanIds = [];

async function iniciarScan() {
    const propId = document.getElementById('scan-prop').value;
    const btn    = document.getElementById('btn-scan');

    btn.disabled = true;
    btn.innerHTML = '<div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>Escaneando…';
    document.getElementById('scan-progress').classList.remove('hidden');
    document.getElementById('scan-results').classList.add('hidden');

    try {
        const fd = new FormData();
        fd.append('action', 'scan');
        fd.append('csrf_token', CSRF);
        fd.append('propiedad_id', propId);

        const r    = await fetch('imagenes-auditoria.php', { method: 'POST', body: fd });
        const data = await r.json();

        document.getElementById('stat-ok').textContent      = data.ok;
        document.getElementById('stat-orphans').textContent = data.count;

        orphanIds = data.orphans.map(o => o.id);

        document.getElementById('scan-results').classList.remove('hidden');

        if (data.count === 0) {
            document.getElementById('summary-ok').classList.remove('hidden');
            document.getElementById('summary-orphans').classList.add('hidden');
        } else {
            document.getElementById('summary-ok').classList.add('hidden');
            document.getElementById('summary-orphans').classList.remove('hidden');
            document.getElementById('orphan-count-title').textContent =
                data.count + ' imagen' + (data.count !== 1 ? 'es huérfanas' : ' huérfana');
            renderTable(data.orphans);
        }
    } catch(e) {
        alert('Error al escanear: ' + e.message);
    } finally {
        document.getElementById('scan-progress').classList.add('hidden');
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="search" class="w-4 h-4"></i>Escanear';
        lucide.createIcons();
    }
}

function renderTable(orphans) {
    const tbody = document.getElementById('orphan-tbody');
    const tipos = { hero:'Hero', galeria:'Galería', card:'Card', og:'OG/SEO', zona:'Zona' };
    const badges = {
        hero:    'bg-blue-100 text-blue-700',
        galeria: 'bg-purple-100 text-purple-700',
        card:    'bg-yellow-100 text-yellow-700',
        og:      'bg-green-100 text-green-700',
        zona:    'bg-orange-100 text-orange-700',
    };

    tbody.innerHTML = orphans.map(o => `
        <tr class="border-b border-gray-50 hover:bg-gray-50 transition-colors" id="row-${o.id}">
            <td class="py-2 pr-4 text-gray-400 font-mono text-xs">#${o.id}</td>
            <td class="py-2 pr-4 font-medium text-gray-700">${o.propiedad_nombre}</td>
            <td class="py-2 pr-4">
                <span class="text-xs px-2 py-0.5 rounded-full font-semibold ${badges[o.tipo] || 'bg-gray-100 text-gray-600'}">
                    ${tipos[o.tipo] || o.tipo}
                </span>
            </td>
            <td class="py-2 pr-4 text-gray-400 font-mono text-xs max-w-xs truncate" title="${o.url}">${o.url}</td>
            <td class="py-2 pr-4 text-center">
                ${o.es_portada == 1
                    ? '<span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-semibold">⚠ Portada</span>'
                    : '<span class="text-gray-300 text-xs">—</span>'}
            </td>
            <td class="py-2">
                <button onclick="eliminarUno(${o.id})"
                    class="text-xs text-red-500 hover:text-red-700 flex items-center gap-1 transition-colors">
                    <i data-lucide="trash-2" class="w-3 h-3"></i>Eliminar
                </button>
            </td>
        </tr>
    `).join('');

    lucide.createIcons();
}

async function eliminarUno(id) {
    const fd = new FormData();
    fd.append('action', 'delete_orphan');
    fd.append('csrf_token', CSRF);
    fd.append('id', id);

    const r = await fetch('imagenes-auditoria.php', { method: 'POST', body: fd });
    const d = await r.json();

    if (d.success) {
        document.getElementById('row-' + id)?.remove();
        orphanIds = orphanIds.filter(i => i !== id);
        const count = document.querySelectorAll('#orphan-tbody tr').length;
        document.getElementById('orphan-count-title').textContent =
            count + ' imagen' + (count !== 1 ? 'es huérfanas' : ' huérfana');
        document.getElementById('stat-orphans').textContent = count;
        if (count === 0) {
            document.getElementById('summary-orphans').classList.add('hidden');
            document.getElementById('summary-ok').classList.remove('hidden');
        }
    }
}

async function eliminarTodos() {
    if (!confirm(`¿Eliminar ${orphanIds.length} registros huérfanos de la BD? Esta acción no se puede deshacer.`)) return;

    const btn = document.getElementById('btn-delete-all');
    btn.disabled = true;
    btn.textContent = 'Eliminando…';

    const fd = new FormData();
    fd.append('action', 'delete_all_orphans');
    fd.append('csrf_token', CSRF);
    fd.append('ids', JSON.stringify(orphanIds));

    const r = await fetch('imagenes-auditoria.php', { method: 'POST', body: fd });
    const d = await r.json();

    if (d.success) {
        document.getElementById('stat-orphans').textContent = 0;
        document.getElementById('stat-ok').textContent =
            parseInt(document.getElementById('stat-ok').textContent) || 0;
        document.getElementById('summary-orphans').classList.add('hidden');
        document.getElementById('summary-ok').classList.remove('hidden');
        orphanIds = [];
    }
}
</script>

<?php adminLayoutClose(); ?>
