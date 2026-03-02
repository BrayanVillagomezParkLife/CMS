<?php
declare(strict_types=1);
/**
 * admin/precios.php — Módulo de gestión de precios del catálogo.
 * v1.1 — Todos los montos con 2 decimales
 */

require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/audit.php';

$csrf       = adminCsrf();
$tab        = $_GET['tab'] ?? 'catalogo';
$filtroProp = (int)($_GET['propiedad_id'] ?? 0);
$propiedades = dbFetchAll("SELECT id, nombre FROM propiedades WHERE activo=1 ORDER BY nombre");

/** Formatea moneda con 2 decimales. Retorna HTML. */
function fmtMoney(?float $val): string {
    if ($val === null || $val == 0) return '<span class="text-gray-300">—</span>';
    return '$' . number_format($val, 2);
}
/** Formatea moneda sin HTML (para stats). */
function fmtMoneyPlain(?float $val): string {
    if ($val === null || $val == 0) return '$0.00';
    return '$' . number_format($val, 2);
}

// ═════════════════════════════════════════════════════════════════════════════
// POST HANDLERS
// ═════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('precios.php', 'error', 'Token inválido.');
    }
    $postAction = $_POST['action'] ?? '';

    // ── Actualizar precio individual ─────────────────────────────────────
    if ($postAction === 'update_single') {
        $habId = (int)($_POST['hab_id'] ?? 0);
        if (!$habId) adminRedirect('precios.php', 'error', 'ID inválido.');

        $antes = dbFetchOne("SELECT * FROM habitaciones WHERE id = ?", [$habId]);
        if (!$antes) adminRedirect('precios.php', 'error', 'Habitación no encontrada.');

        $data = [
            'precio_mes_12'        => $_POST['precio_mes_12'] !== '' ? round((float)$_POST['precio_mes_12'], 2) : null,
            'precio_mes_6'         => $_POST['precio_mes_6'] !== '' ? round((float)$_POST['precio_mes_6'], 2) : null,
            'precio_mes_1'         => $_POST['precio_mes_1'] !== '' ? round((float)$_POST['precio_mes_1'], 2) : null,
            'precio_mantenimiento' => $_POST['precio_mantenimiento'] !== '' ? round((float)$_POST['precio_mantenimiento'], 2) : null,
            'precio_servicios'     => $_POST['precio_servicios'] !== '' ? round((float)$_POST['precio_servicios'], 2) : null,
            'precio_amueblado'     => $_POST['precio_amueblado'] !== '' ? round((float)$_POST['precio_amueblado'], 2) : null,
            'precio_parking_extra' => $_POST['precio_parking_extra'] !== '' ? round((float)$_POST['precio_parking_extra'], 2) : null,
            'precio_mascota'       => $_POST['precio_mascota'] !== '' ? round((float)$_POST['precio_mascota'], 2) : null,
        ];

        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
        $values = array_values($data);
        $values[] = $habId;
        dbExecute("UPDATE habitaciones SET $sets WHERE id = ?", $values);

        $despues = dbFetchOne("SELECT * FROM habitaciones WHERE id = ?", [$habId]);
        $camposAudit = ['precio_mes_12','precio_mes_6','precio_mes_1','precio_mantenimiento','precio_servicios','precio_amueblado','precio_parking_extra','precio_mascota'];
        $antesA = []; $despuesA = [];
        foreach ($camposAudit as $c) { $antesA[$c] = $antes[$c]; $despuesA[$c] = $despues[$c]; }
        logAudit('UPDATE_PRECIO', 'habitaciones', $habId, $antesA, $despuesA, sanitizeStr($_POST['notas'] ?? ''));

        dbCacheInvalidate();
        adminRedirect("precios.php?propiedad_id={$antes['propiedad_id']}", 'success', "Precios de «{$antes['nombre']}» actualizados.");
    }

    // ── Toggle activa ────────────────────────────────────────────────────
    if ($postAction === 'toggle_active') {
        $habId    = (int)($_POST['hab_id'] ?? 0);
        $nuevoVal = (int)($_POST['nuevo_valor'] ?? 0);
        $hab      = dbFetchOne("SELECT id, nombre, activa, propiedad_id FROM habitaciones WHERE id = ?", [$habId]);
        if ($hab) {
            dbExecute("UPDATE habitaciones SET activa = ? WHERE id = ?", [$nuevoVal, $habId]);
            logAudit('TOGGLE_ACTIVA', 'habitaciones', $habId, ['activa' => $hab['activa']], ['activa' => $nuevoVal],
                $nuevoVal ? 'Unidad reactivada' : 'Unidad desactivada');
            dbCacheInvalidate();
            adminRedirect("precios.php?propiedad_id={$hab['propiedad_id']}", 'success',
                "«{$hab['nombre']}» " . ($nuevoVal ? 'activada' : 'desactivada') . '.');
        }
        adminRedirect('precios.php', 'error', 'Habitación no encontrada.');
    }

    // ── Aplicar actualización masiva ─────────────────────────────────────
    if ($postAction === 'apply_bulk') {
        $porcentaje = (float)($_POST['porcentaje'] ?? 0);
        $propIdBulk = (int)($_POST['propiedad_id_bulk'] ?? 0);
        $campos     = $_POST['campos'] ?? ['precio_mes_12'];
        $notas      = sanitizeStr($_POST['notas_bulk'] ?? '');

        if ($porcentaje == 0) adminRedirect('precios.php?tab=masiva', 'error', 'El porcentaje no puede ser 0.');
        if (abs($porcentaje) > 50) adminRedirect('precios.php?tab=masiva', 'error', 'Máximo permitido es ±50%.');

        $camposPermitidos = ['precio_mes_12','precio_mes_6','precio_mes_1','precio_mantenimiento','precio_servicios','precio_amueblado','precio_parking_extra','precio_mascota'];
        $campos = array_intersect((array)$campos, $camposPermitidos);
        if (empty($campos)) adminRedirect('precios.php?tab=masiva', 'error', 'Selecciona al menos un campo.');

        $autoCalc = isset($_POST['auto_calc']);
        $whereSQL = "WHERE activa = 1"; $params = [];
        if ($propIdBulk > 0) { $whereSQL .= " AND propiedad_id = ?"; $params[] = $propIdBulk; }

        $habitaciones = dbFetchAll("SELECT * FROM habitaciones $whereSQL ORDER BY propiedad_id, nombre", $params);
        $factor = 1 + ($porcentaje / 100);
        $actualizados = 0;

        foreach ($habitaciones as $hab) {
            $antes = []; $despues = []; $sets = []; $vals = [];
            foreach ($campos as $campo) {
                if ($hab[$campo] !== null && (float)$hab[$campo] > 0) {
                    $antes[$campo]   = (float)$hab[$campo];
                    $nuevo           = round((float)$hab[$campo] * $factor, 2);
                    $despues[$campo] = $nuevo;
                    $sets[] = "$campo = ?"; $vals[] = $nuevo;
                }
            }
            if ($autoCalc && in_array('precio_mes_12', $campos)) {
                $baseMes12 = $despues['precio_mes_12'] ?? (float)$hab['precio_mes_12'];
                if ($baseMes12 > 0) {
                    if (!in_array('precio_mes_6', $campos)) {
                        $antes['precio_mes_6'] = (float)$hab['precio_mes_6'];
                        $n6 = round($baseMes12 * 1.10, 2);
                        $despues['precio_mes_6'] = $n6; $sets[] = "precio_mes_6 = ?"; $vals[] = $n6;
                    }
                    if (!in_array('precio_mes_1', $campos)) {
                        $antes['precio_mes_1'] = (float)$hab['precio_mes_1'];
                        $n1 = round($baseMes12 * 1.20, 2);
                        $despues['precio_mes_1'] = $n1; $sets[] = "precio_mes_1 = ?"; $vals[] = $n1;
                    }
                }
            }
            if (!empty($sets)) {
                $vals[] = (int)$hab['id'];
                dbExecute("UPDATE habitaciones SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
                logAudit('BULK_UPDATE', 'habitaciones', (int)$hab['id'], $antes, $despues,
                    "Masivo {$porcentaje}%" . ($propIdBulk ? " (prop $propIdBulk)" : ' (global)') . ($notas ? " — $notas" : ''));
                $actualizados++;
            }
        }
        dbCacheInvalidate();
        $scope = $propIdBulk ? 'de la propiedad' : 'del catálogo completo';
        adminRedirect("precios.php?tab=masiva&propiedad_id=$propIdBulk", 'success', "$actualizados unidades actualizadas ({$porcentaje}%) $scope.");
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// DATA LOADING
// ═════════════════════════════════════════════════════════════════════════════

$habitaciones = [];
if ($tab === 'catalogo') {
    $w = "WHERE 1=1"; $p = [];
    if ($filtroProp > 0) { $w .= " AND h.propiedad_id = ?"; $p[] = $filtroProp; }
    $habitaciones = dbFetchAll("SELECT h.*, p.nombre AS prop_nombre FROM habitaciones h
        JOIN propiedades p ON p.id = h.propiedad_id $w ORDER BY p.nombre, h.orden, h.nombre", $p);
}

$auditData = ['rows' => [], 'total' => 0];
if ($tab === 'historial') {
    $filtros = ['tabla' => 'habitaciones'];
    if (!empty($_GET['admin_id'])) $filtros['admin_id'] = (int)$_GET['admin_id'];
    if (!empty($_GET['accion']))   $filtros['accion']   = $_GET['accion'];
    if (!empty($_GET['desde']))    $filtros['desde']    = $_GET['desde'];
    if (!empty($_GET['hasta']))    $filtros['hasta']    = $_GET['hasta'];
    $page = max(1, (int)($_GET['page'] ?? 1));
    $auditData = getAuditLog($filtros, 30, ($page - 1) * 30);
}

$previewData = []; $pctPreview = 0; $propPreview = 0; $autoCalcPrev = false;
if ($tab === 'masiva' && isset($_GET['preview'], $_GET['pct'])) {
    $pctPreview = (float)$_GET['pct']; $propPreview = (int)($_GET['preview_prop'] ?? 0);
    $factorPrev = 1 + ($pctPreview / 100); $autoCalcPrev = isset($_GET['auto_calc']);
    $wSQL = "WHERE h.activa = 1"; $pars = [];
    if ($propPreview > 0) { $wSQL .= " AND h.propiedad_id = ?"; $pars[] = $propPreview; }
    $previewData = dbFetchAll("SELECT h.*, p.nombre AS prop_nombre FROM habitaciones h
        JOIN propiedades p ON p.id = h.propiedad_id $wSQL ORDER BY p.nombre, h.nombre", $pars);
    foreach ($previewData as &$row) {
        $row['nuevo_mes_12'] = $row['precio_mes_12'] > 0 ? round($row['precio_mes_12'] * $factorPrev, 2) : null;
        if ($autoCalcPrev && $row['nuevo_mes_12']) {
            $row['nuevo_mes_6'] = round($row['nuevo_mes_12'] * 1.10, 2);
            $row['nuevo_mes_1'] = round($row['nuevo_mes_12'] * 1.20, 2);
        } else {
            $row['nuevo_mes_6'] = $row['precio_mes_6'] > 0 ? round($row['precio_mes_6'] * $factorPrev, 2) : null;
            $row['nuevo_mes_1'] = $row['precio_mes_1'] > 0 ? round($row['precio_mes_1'] * $factorPrev, 2) : null;
        }
    }
    unset($row);
}

$stats = dbFetchOne("SELECT COUNT(*) as total, SUM(activa=1) as activas, SUM(activa=0) as inactivas,
    AVG(CASE WHEN activa=1 AND precio_mes_12>0 THEN precio_mes_12 END) as avg_mes12,
    MIN(CASE WHEN activa=1 AND precio_mes_12>0 THEN precio_mes_12 END) as min_mes12,
    MAX(CASE WHEN activa=1 AND precio_mes_12>0 THEN precio_mes_12 END) as max_mes12
    FROM habitaciones");

// ═════════════════════════════════════════════════════════════════════════════
// RENDER
// ═════════════════════════════════════════════════════════════════════════════
adminLayoutOpen('Gestión de Precios');
?>

<!-- Stats -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
    <div class="card p-4 text-center"><div class="text-2xl font-bold text-gray-800"><?= (int)$stats['total'] ?></div><div class="text-xs text-gray-500 mt-1">Total unidades</div></div>
    <div class="card p-4 text-center"><div class="text-2xl font-bold text-green-600"><?= (int)$stats['activas'] ?></div><div class="text-xs text-gray-500 mt-1">Activas</div></div>
    <div class="card p-4 text-center"><div class="text-2xl font-bold text-red-500"><?= (int)$stats['inactivas'] ?></div><div class="text-xs text-gray-500 mt-1">Inactivas</div></div>
    <div class="card p-4 text-center"><div class="text-2xl font-bold text-gray-800"><?= fmtMoneyPlain((float)($stats['min_mes12'] ?? 0)) ?></div><div class="text-xs text-gray-500 mt-1">Mín /mes (12m)</div></div>
    <div class="card p-4 text-center"><div class="text-2xl font-bold text-pk"><?= fmtMoneyPlain((float)($stats['avg_mes12'] ?? 0)) ?></div><div class="text-xs text-gray-500 mt-1">Prom /mes (12m)</div></div>
    <div class="card p-4 text-center"><div class="text-2xl font-bold text-gray-800"><?= fmtMoneyPlain((float)($stats['max_mes12'] ?? 0)) ?></div><div class="text-xs text-gray-500 mt-1">Máx /mes (12m)</div></div>
</div>

<!-- Tabs -->
<div class="flex gap-1 mb-6 border-b border-gray-200">
    <?php $tabsDef = ['catalogo' => ['Catálogo de precios','table-2'], 'masiva' => ['Actualización masiva','percent'], 'historial' => ['Historial de cambios','history']];
    foreach ($tabsDef as $key => [$label, $icon]): $active = $tab === $key; ?>
    <a href="?tab=<?= $key ?>&propiedad_id=<?= $filtroProp ?>"
       class="flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-colors <?= $active ? 'border-pk text-pk' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
        <i data-lucide="<?= $icon ?>" class="w-4 h-4"></i><?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<?php
// ═════════════════════════════════════════════════════════════════════════════
// TAB: CATÁLOGO
// ═════════════════════════════════════════════════════════════════════════════
if ($tab === 'catalogo'):
?>

<div class="flex items-center gap-4 mb-6">
    <form method="get" class="flex items-center gap-3">
        <input type="hidden" name="tab" value="catalogo">
        <label class="form-label mb-0 text-sm whitespace-nowrap">Propiedad:</label>
        <select name="propiedad_id" class="form-select text-sm w-64" onchange="this.form.submit()">
            <option value="0">— Todas las propiedades —</option>
            <?php foreach ($propiedades as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $filtroProp == $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <div class="ml-auto text-sm text-gray-400"><?= count($habitaciones) ?> unidad<?= count($habitaciones) !== 1 ? 'es' : '' ?></div>
</div>

<?php if (empty($habitaciones)): ?>
    <div class="card p-12 text-center text-gray-400">
        <i data-lucide="package-open" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
        <p class="text-lg font-medium mb-1">Sin unidades</p>
        <p class="text-sm"><?= $filtroProp ? 'Esta propiedad no tiene habitaciones.' : 'Selecciona una propiedad para ver su catálogo.' ?></p>
    </div>
<?php else: ?>
    <?php $porPropiedad = []; foreach ($habitaciones as $h) $porPropiedad[$h['prop_nombre']][] = $h; ?>
    <?php foreach ($porPropiedad as $propNombre => $habs): ?>
    <div class="mb-8">
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-3 flex items-center gap-2">
            <i data-lucide="building-2" class="w-4 h-4 text-pk"></i>
            <?= e($propNombre) ?>
            <span class="text-xs font-normal text-gray-400 lowercase">(<?= count($habs) ?> unidades)</span>
        </h2>
        <div class="card p-0 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-gray-100">
                    <tr>
                        <th class="table-th">Unidad</th>
                        <th class="table-th text-right">12 meses</th>
                        <th class="table-th text-right">6 meses</th>
                        <th class="table-th text-right">1 mes</th>
                        <th class="table-th text-right">Mant.</th>
                        <th class="table-th text-right">Servicios</th>
                        <th class="table-th text-right">Amueblado</th>
                        <th class="table-th text-right">Parking</th>
                        <th class="table-th text-right">Mascota</th>
                        <th class="table-th text-center">Estado</th>
                        <th class="table-th text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($habs as $h): ?>
                    <tr class="hover:bg-blue-50/30 <?= !$h['activa'] ? 'opacity-50 bg-gray-50' : '' ?>">
                        <td class="table-td">
                            <div class="font-medium text-gray-800"><?= e($h['nombre']) ?></div>
                            <?php if ($h['codigo']): ?><div class="text-xs text-gray-400"><?= e($h['codigo']) ?></div><?php endif; ?>
                        </td>
                        <td class="table-td text-right font-semibold text-gray-800"><?= fmtMoney($h['precio_mes_12'] ? (float)$h['precio_mes_12'] : null) ?></td>
                        <td class="table-td text-right text-gray-600"><?= fmtMoney($h['precio_mes_6'] ? (float)$h['precio_mes_6'] : null) ?></td>
                        <td class="table-td text-right text-gray-600"><?= fmtMoney($h['precio_mes_1'] ? (float)$h['precio_mes_1'] : null) ?></td>
                        <td class="table-td text-right text-gray-500 text-xs"><?= fmtMoney($h['precio_mantenimiento'] ? (float)$h['precio_mantenimiento'] : null) ?></td>
                        <td class="table-td text-right text-gray-500 text-xs"><?= fmtMoney($h['precio_servicios'] ? (float)$h['precio_servicios'] : null) ?></td>
                        <td class="table-td text-right text-gray-500 text-xs"><?= fmtMoney($h['precio_amueblado'] ? (float)$h['precio_amueblado'] : null) ?></td>
                        <td class="table-td text-right text-gray-500 text-xs"><?= fmtMoney($h['precio_parking_extra'] ? (float)$h['precio_parking_extra'] : null) ?></td>
                        <td class="table-td text-right text-gray-500 text-xs"><?= fmtMoney($h['precio_mascota'] ? (float)$h['precio_mascota'] : null) ?></td>
                        <td class="table-td text-center">
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="hab_id" value="<?= $h['id'] ?>">
                                <input type="hidden" name="nuevo_valor" value="<?= $h['activa'] ? 0 : 1 ?>">
                                <button type="submit" title="<?= $h['activa'] ? 'Desactivar' : 'Activar' ?>"
                                    class="px-2 py-1 text-xs rounded-full font-semibold transition-colors <?= $h['activa'] ? 'bg-green-100 text-green-700 hover:bg-red-100 hover:text-red-700' : 'bg-gray-100 text-gray-500 hover:bg-green-100 hover:text-green-700' ?>">
                                    <?= $h['activa'] ? 'Activa' : 'Inactiva' ?>
                                </button>
                            </form>
                        </td>
                        <td class="table-td text-center">
                            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($h), ENT_QUOTES) ?>)"
                                class="p-1.5 rounded-lg hover:bg-pk/10 text-gray-400 hover:text-pk transition-colors" title="Editar precios">
                                <i data-lucide="pencil" class="w-4 h-4"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal edición individual -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" onclick="closeEditModal()"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto relative">
            <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl flex items-center justify-between">
                <h3 class="font-bold text-gray-800"><i data-lucide="pencil" class="w-4 h-4 inline text-pk"></i> Editar precios: <span id="modal-nombre" class="text-pk"></span></h3>
                <button onclick="closeEditModal()" class="p-1 rounded-lg hover:bg-gray-100"><i data-lucide="x" class="w-5 h-5 text-gray-400"></i></button>
            </div>
            <form method="post" class="p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="update_single">
                <input type="hidden" name="hab_id" id="modal-hab-id">

                <p class="text-xs text-gray-400 bg-blue-50 rounded-lg px-3 py-2">
                    <i data-lucide="info" class="w-3.5 h-3.5 inline"></i>
                    Al cambiar 12 meses, los de 6 y 1 mes se recalculan (×1.10 y ×1.20). Puedes ajustarlos después.
                </p>

                <div class="grid grid-cols-3 gap-3">
                    <div><label class="form-label text-xs">12 meses <span class="text-pk">*</span></label>
                        <input type="number" step="0.01" name="precio_mes_12" id="m-mes12" class="form-input text-sm font-semibold" oninput="autoCalcPrices()"></div>
                    <div><label class="form-label text-xs">6 meses <span class="text-gray-300">(×1.10)</span></label>
                        <input type="number" step="0.01" name="precio_mes_6" id="m-mes6" class="form-input text-sm"></div>
                    <div><label class="form-label text-xs">1 mes <span class="text-gray-300">(×1.20)</span></label>
                        <input type="number" step="0.01" name="precio_mes_1" id="m-mes1" class="form-input text-sm"></div>
                </div>

                <div class="border-t border-gray-100 pt-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Cargos adicionales</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="form-label text-xs">Mantenimiento</label><input type="number" step="0.01" name="precio_mantenimiento" id="m-mant" class="form-input text-sm"></div>
                        <div><label class="form-label text-xs">Servicios</label><input type="number" step="0.01" name="precio_servicios" id="m-serv" class="form-input text-sm"></div>
                        <div><label class="form-label text-xs">Amueblado</label><input type="number" step="0.01" name="precio_amueblado" id="m-amue" class="form-input text-sm"></div>
                        <div><label class="form-label text-xs">Parking extra</label><input type="number" step="0.01" name="precio_parking_extra" id="m-park" class="form-input text-sm"></div>
                        <div><label class="form-label text-xs">Mascota</label><input type="number" step="0.01" name="precio_mascota" id="m-masc" class="form-input text-sm"></div>
                    </div>
                </div>

                <div><label class="form-label text-xs">Notas del cambio <span class="text-gray-300">(opcional)</span></label>
                    <input type="text" name="notas" class="form-input text-sm" placeholder="Ej: Ajuste tarifa 2026, descuento temporada baja..."></div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="btn-primary px-8"><i data-lucide="save" class="w-4 h-4"></i>Guardar cambios</button>
                    <button type="button" onclick="closeEditModal()" class="btn-secondary px-6">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// ═════════════════════════════════════════════════════════════════════════════
// TAB: ACTUALIZACIÓN MASIVA
// ═════════════════════════════════════════════════════════════════════════════
elseif ($tab === 'masiva'):
?>

<div class="grid lg:grid-cols-2 gap-6">
    <div class="card p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i data-lucide="percent" class="w-5 h-5 text-pk"></i> Configurar incremento / decremento</h3>
        <form method="get" class="space-y-4">
            <input type="hidden" name="tab" value="masiva">
            <input type="hidden" name="preview" value="1">
            <div><label class="form-label">Propiedad</label>
                <select name="preview_prop" class="form-select">
                    <option value="0">🌐 Todas las propiedades (global)</option>
                    <?php foreach ($propiedades as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $propPreview == $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="form-label">Porcentaje de ajuste</label>
                <div class="flex items-center gap-3">
                    <input type="number" step="0.1" min="-50" max="50" name="pct" id="pctInput" value="<?= e($_GET['pct'] ?? '5') ?>" class="form-input text-sm w-32 text-center font-bold text-lg" required>
                    <span class="text-gray-500 text-sm font-medium">%</span>
                    <div class="flex gap-1 ml-auto">
                        <?php foreach ([3,5,8,10,15] as $q): ?>
                        <button type="button" onclick="document.getElementById('pctInput').value=<?= $q ?>" class="px-2 py-1 text-xs rounded-lg bg-gray-100 hover:bg-pk hover:text-white transition-colors">+<?= $q ?>%</button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-1">Valores negativos para descuentos. Máximo ±50%.</p>
            </div>
            <div class="flex items-center gap-3 p-3 bg-blue-50 rounded-xl">
                <input type="checkbox" name="auto_calc" id="autoCalcCheck" value="1" <?= isset($_GET['auto_calc']) || !isset($_GET['preview']) ? 'checked' : '' ?> class="w-4 h-4 text-pk border-gray-300 rounded focus:ring-pk">
                <label for="autoCalcCheck" class="text-sm text-gray-700"><span class="font-medium">Auto-calcular</span> 6 meses (×1.10) y 1 mes (×1.20) desde 12 meses</label>
            </div>
            <button type="submit" class="btn-secondary w-full"><i data-lucide="eye" class="w-4 h-4"></i> Ver preview de cambios</button>
        </form>
    </div>
    <div class="card p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i data-lucide="info" class="w-5 h-5 text-blue-500"></i> Cómo funciona</h3>
        <div class="space-y-3 text-sm text-gray-600">
            <div class="flex gap-3"><span class="flex-shrink-0 w-6 h-6 bg-pk/10 text-pk rounded-full flex items-center justify-center text-xs font-bold">1</span><p>Selecciona propiedad o "Todas" para ajuste global.</p></div>
            <div class="flex gap-3"><span class="flex-shrink-0 w-6 h-6 bg-pk/10 text-pk rounded-full flex items-center justify-center text-xs font-bold">2</span><p>Define %. Positivo = incremento, negativo = descuento.</p></div>
            <div class="flex gap-3"><span class="flex-shrink-0 w-6 h-6 bg-pk/10 text-pk rounded-full flex items-center justify-center text-xs font-bold">3</span><p>Revisa el preview antes/después.</p></div>
            <div class="flex gap-3"><span class="flex-shrink-0 w-6 h-6 bg-pk/10 text-pk rounded-full flex items-center justify-center text-xs font-bold">4</span><p>Confirma. Todo queda en el historial.</p></div>
        </div>
        <div class="mt-6 p-4 bg-amber-50 border border-amber-200 rounded-xl">
            <p class="text-sm text-amber-800 flex items-start gap-2"><i data-lucide="alert-triangle" class="w-4 h-4 flex-shrink-0 mt-0.5"></i>
            <span>Los cambios masivos son <strong>irreversibles</strong>. Siempre revisa el preview. El historial guarda valores anteriores.</span></p>
        </div>
    </div>
</div>

<?php if (!empty($previewData)): ?>
<div class="mt-6">
    <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
        <i data-lucide="eye" class="w-5 h-5 text-amber-500"></i>
        Preview: <?= count($previewData) ?> unidades afectadas
        <span class="px-2 py-0.5 text-xs rounded-full font-bold <?= $pctPreview > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>"><?= $pctPreview > 0 ? '+' : '' ?><?= $pctPreview ?>%</span>
    </h3>
    <div class="card p-0 overflow-x-auto mb-4">
        <table class="w-full text-sm">
            <thead class="bg-amber-50 border-b border-amber-100">
                <tr>
                    <th class="table-th">Unidad</th><th class="table-th">Propiedad</th>
                    <th class="table-th text-right">12m actual</th><th class="table-th text-right">12m nuevo</th>
                    <th class="table-th text-right">6m actual</th><th class="table-th text-right">6m nuevo</th>
                    <th class="table-th text-right">1m actual</th><th class="table-th text-right">1m nuevo</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($previewData as $row): $cls = $pctPreview > 0 ? 'text-green-700' : 'text-red-600'; ?>
                <tr class="hover:bg-amber-50/30">
                    <td class="table-td font-medium"><?= e($row['nombre']) ?></td>
                    <td class="table-td text-gray-500"><?= e($row['prop_nombre']) ?></td>
                    <td class="table-td text-right text-gray-400"><?= fmtMoney($row['precio_mes_12'] ? (float)$row['precio_mes_12'] : null) ?></td>
                    <td class="table-td text-right font-semibold <?= $cls ?>"><?= $row['nuevo_mes_12'] ? '$'.number_format($row['nuevo_mes_12'], 2) : '—' ?></td>
                    <td class="table-td text-right text-gray-400"><?= fmtMoney($row['precio_mes_6'] ? (float)$row['precio_mes_6'] : null) ?></td>
                    <td class="table-td text-right font-semibold <?= $cls ?>"><?= $row['nuevo_mes_6'] ? '$'.number_format($row['nuevo_mes_6'], 2) : '—' ?></td>
                    <td class="table-td text-right text-gray-400"><?= fmtMoney($row['precio_mes_1'] ? (float)$row['precio_mes_1'] : null) ?></td>
                    <td class="table-td text-right font-semibold <?= $cls ?>"><?= $row['nuevo_mes_1'] ? '$'.number_format($row['nuevo_mes_1'], 2) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="post" onsubmit="return confirmBulk()" class="card p-5 border-2 border-amber-200 bg-amber-50">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="apply_bulk">
        <input type="hidden" name="porcentaje" value="<?= $pctPreview ?>">
        <input type="hidden" name="propiedad_id_bulk" value="<?= $propPreview ?>">
        <input type="hidden" name="campos[]" value="precio_mes_12">
        <?php if ($autoCalcPrev): ?><input type="hidden" name="auto_calc" value="1">
        <?php else: ?><input type="hidden" name="campos[]" value="precio_mes_6"><input type="hidden" name="campos[]" value="precio_mes_1"><?php endif; ?>
        <div class="flex flex-col sm:flex-row items-start sm:items-end gap-4">
            <div class="flex-1"><label class="form-label text-xs">Justificación del cambio</label>
                <input type="text" name="notas_bulk" class="form-input text-sm" placeholder="Ej: Ajuste inflación 2026..." required></div>
            <button type="submit" class="btn-primary px-8 whitespace-nowrap bg-amber-600 hover:bg-amber-700"><i data-lucide="check-circle" class="w-4 h-4"></i> Confirmar y aplicar</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php
// ═════════════════════════════════════════════════════════════════════════════
// TAB: HISTORIAL
// ═════════════════════════════════════════════════════════════════════════════
elseif ($tab === 'historial'):
    $rows = $auditData['rows']; $total = $auditData['total'];
    $pages = (int)ceil($total / 30); $page = max(1, (int)($_GET['page'] ?? 1));
?>

<div class="flex flex-wrap items-end gap-3 mb-6">
    <form method="get" class="flex flex-wrap items-end gap-3">
        <input type="hidden" name="tab" value="historial">
        <div><label class="form-label text-xs">Acción</label>
            <select name="accion" class="form-select text-sm w-44">
                <option value="">Todas</option>
                <option value="UPDATE_PRECIO" <?= ($_GET['accion'] ?? '') === 'UPDATE_PRECIO' ? 'selected' : '' ?>>Edición individual</option>
                <option value="BULK_UPDATE" <?= ($_GET['accion'] ?? '') === 'BULK_UPDATE' ? 'selected' : '' ?>>Masiva</option>
                <option value="TOGGLE_ACTIVA" <?= ($_GET['accion'] ?? '') === 'TOGGLE_ACTIVA' ? 'selected' : '' ?>>Toggle activa</option>
            </select>
        </div>
        <div><label class="form-label text-xs">Desde</label><input type="date" name="desde" value="<?= e($_GET['desde'] ?? '') ?>" class="form-input text-sm"></div>
        <div><label class="form-label text-xs">Hasta</label><input type="date" name="hasta" value="<?= e($_GET['hasta'] ?? '') ?>" class="form-input text-sm"></div>
        <button type="submit" class="btn-secondary text-sm"><i data-lucide="filter" class="w-4 h-4"></i>Filtrar</button>
        <a href="?tab=historial" class="text-xs text-gray-400 hover:text-pk underline">Limpiar</a>
    </form>
    <div class="ml-auto text-sm text-gray-400"><?= $total ?> registro<?= $total !== 1 ? 's' : '' ?></div>
</div>

<?php if (empty($rows)): ?>
    <div class="card p-12 text-center text-gray-400">
        <i data-lucide="history" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
        <p class="text-lg font-medium">Sin registros de auditoría</p>
        <p class="text-sm mt-1">Los cambios de precios aparecerán aquí.</p>
    </div>
<?php else: ?>
    <div class="card p-0 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-gray-100">
                <tr><th class="table-th">Fecha</th><th class="table-th">Usuario</th><th class="table-th">Acción</th><th class="table-th">Detalle</th><th class="table-th">Notas</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($rows as $r):
                    $antes = $r['datos_antes'] ? json_decode($r['datos_antes'], true) : [];
                    $despues = $r['datos_despues'] ? json_decode($r['datos_despues'], true) : [];
                    $badge = match($r['accion']) { 'UPDATE_PRECIO'=>'bg-blue-100 text-blue-700', 'BULK_UPDATE'=>'bg-purple-100 text-purple-700', 'TOGGLE_ACTIVA'=>'bg-yellow-100 text-yellow-700', default=>'bg-gray-100 text-gray-600' };
                ?>
                <tr class="hover:bg-slate-50">
                    <td class="table-td whitespace-nowrap text-gray-500"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                    <td class="table-td"><span class="text-gray-700 font-medium"><?= e($r['admin_email']) ?></span></td>
                    <td class="table-td">
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $badge ?>"><?= e($r['accion']) ?></span>
                        <?php if ($r['registro_id']): ?><span class="text-xs text-gray-400 ml-1">#<?= $r['registro_id'] ?></span><?php endif; ?>
                    </td>
                    <td class="table-td">
                        <?php if ($antes && $despues): ?>
                        <details class="cursor-pointer"><summary class="text-xs text-pk hover:underline">Ver cambios</summary>
                            <div class="mt-2 text-xs space-y-1 max-w-xs">
                                <?php foreach ($despues as $campo => $valNuevo):
                                    $valAntes = $antes[$campo] ?? '—';
                                    if ($valAntes != $valNuevo): ?>
                                <div class="flex justify-between gap-2">
                                    <span class="text-gray-500"><?= e($campo) ?>:</span>
                                    <span><span class="text-red-500 line-through">$<?= number_format((float)$valAntes, 2) ?></span> → <span class="text-green-600 font-medium">$<?= number_format((float)$valNuevo, 2) ?></span></span>
                                </div>
                                <?php endif; endforeach; ?>
                            </div>
                        </details>
                        <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
                    </td>
                    <td class="table-td text-gray-500 text-xs max-w-[200px] truncate" title="<?= e($r['notas'] ?? '') ?>"><?= e($r['notas'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="flex justify-center gap-1 mt-4">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?tab=historial&page=<?= $i ?>&accion=<?= e($_GET['accion'] ?? '') ?>&desde=<?= e($_GET['desde'] ?? '') ?>&hasta=<?= e($_GET['hasta'] ?? '') ?>"
           class="w-8 h-8 flex items-center justify-center rounded-lg text-sm <?= $i === $page ? 'bg-pk text-white font-bold' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php endif; // tabs ?>

<!-- JS -->
<script>
function openEditModal(hab) {
    document.getElementById('modal-nombre').textContent = hab.nombre;
    document.getElementById('modal-hab-id').value = hab.id;
    document.getElementById('m-mes12').value = hab.precio_mes_12 || '';
    document.getElementById('m-mes6').value  = hab.precio_mes_6 || '';
    document.getElementById('m-mes1').value  = hab.precio_mes_1 || '';
    document.getElementById('m-mant').value  = hab.precio_mantenimiento || '';
    document.getElementById('m-serv').value  = hab.precio_servicios || '';
    document.getElementById('m-amue').value  = hab.precio_amueblado || '';
    document.getElementById('m-park').value  = hab.precio_parking_extra || '';
    document.getElementById('m-masc').value  = hab.precio_mascota || '';
    document.getElementById('editModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('m-mes12').focus(), 100);
}
function closeEditModal() { document.getElementById('editModal').classList.add('hidden'); document.body.style.overflow = ''; }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEditModal(); });
function autoCalcPrices() {
    const v = parseFloat(document.getElementById('m-mes12').value);
    if (!isNaN(v) && v > 0) {
        document.getElementById('m-mes6').value = (v * 1.10).toFixed(2);
        document.getElementById('m-mes1').value = (v * 1.20).toFixed(2);
    }
}
function confirmBulk() {
    return confirm('¿Confirmar la actualización masiva?\n\nEsta acción no se puede deshacer.');
}
</script>
<?php adminLayoutClose(); ?>