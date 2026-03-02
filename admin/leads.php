<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf    = adminCsrf();
$action  = $_GET['action'] ?? 'list';
$id      = (int)($_GET['id'] ?? 0);

// ── ACCIONES POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) {
        adminRedirect('leads.php', 'error', 'Token inválido.');
    }
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete') {
        dbExecute("DELETE FROM leads WHERE id = ?", [(int)$_POST['id']]);
        adminRedirect('leads.php', 'success', 'Lead eliminado.');
    }

    if ($postAction === 'follow_up') {
        $lid = (int)$_POST['id'];
        dbExecute("UPDATE leads SET followed_up = 1 WHERE id = ?", [$lid]);
        adminRedirect('leads.php?action=view&id=' . $lid, 'success', 'Marcado como seguido.');
    }
}

// ── FILTROS ──────────────────────────────────────────────────────────────────
$filtroTipo  = sanitizeStr($_GET['tipo'] ?? '');
$filtroZoho  = $_GET['zoho'] ?? '';
$filtroProp  = (int)($_GET['propiedad_id'] ?? 0);
$search      = sanitizeStr($_GET['q'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 25;
$offset      = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filtroTipo) { $where[] = 'l.tipo = ?';            $params[] = $filtroTipo; }
if ($filtroZoho !== '') { $where[] = 'l.in_zoho = ?';  $params[] = (int)$filtroZoho; }
if ($filtroProp) { $where[] = 'l.propiedad_id = ?';    $params[] = $filtroProp; }
if ($search)     { $where[] = '(l.email LIKE ? OR l.nombre LIKE ? OR l.telefono LIKE ?)';
                   $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereStr = implode(' AND ', $where);

// ── DETALLE ───────────────────────────────────────────────────────────────────
if ($action === 'view' && $id) {
    $lead = dbFetchOne(
        "SELECT l.*, p.nombre AS prop_nombre, p.slug AS prop_slug
         FROM leads l LEFT JOIN propiedades p ON l.propiedad_id = p.id
         WHERE l.id = ?", [$id]
    );
    if (!$lead) adminRedirect('leads.php', 'error', 'Lead no encontrado.');

    adminLayoutOpen('Lead #' . $id);
    ?>
    <div class="mb-6">
        <a href="leads.php" class="text-sm text-gray-500 hover:text-pk flex items-center gap-1">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver a leads
        </a>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
        <!-- Info principal -->
        <div class="lg:col-span-2 space-y-5">
            <div class="card">
                <div class="flex items-start justify-between mb-5">
                    <div>
                        <div class="flex items-center gap-3 mb-1">
                            <?= badgeLeadType($lead['tipo']) ?>
                            <?php if ($lead['followed_up']): ?>
                            <span class="px-2 py-0.5 text-xs font-semibold bg-green-100 text-green-700 rounded-full">Seguido ✓</span>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-xl font-bold text-gray-800"><?= e(trim($lead['nombre'] . ' ' . $lead['apellido']) ?: 'Sin nombre') ?></h2>
                    </div>
                    <span class="text-sm text-gray-400"><?= date('d/m/Y H:i', strtotime($lead['created_at'])) ?></span>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <?php
                    $fields = [
                        'Email'       => $lead['email'],
                        'Teléfono'    => $lead['telefono'],
                        'Propiedad'   => $lead['prop_nombre'] ?? '—',
                        'Mes entrada' => $lead['mes_entrada'] ?? '—',
                        'Mascota'     => $lead['mascota'] ? 'Sí 🐾' : 'No',
                        'Amueblado'   => $lead['amueblado'] ? 'Sí' : 'No',
                        'Fuente'      => $lead['lead_source'] ?? '—',
                        'IP'          => $lead['ip_address'] ?? '—',
                    ];
                    foreach ($fields as $label => $val):
                    ?>
                    <div>
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5"><?= $label ?></div>
                        <div class="text-sm text-gray-800"><?= e((string)$val) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($lead['comentarios'] || $lead['mensaje']): ?>
                <div class="mt-5 pt-5 border-t border-gray-100">
                    <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Mensaje / Comentarios</div>
                    <p class="text-sm text-gray-700 whitespace-pre-line"><?= e($lead['comentarios'] ?? $lead['mensaje'] ?? '') ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- UTMs -->
            <?php if ($lead['utm_source']): ?>
            <div class="card">
                <h3 class="font-bold text-gray-800 mb-4">Origen de tráfico</h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <?php foreach (['utm_source' => 'Source', 'utm_medium' => 'Medium', 'utm_campaign' => 'Campaign', 'utm_content' => 'Content'] as $k => $l): ?>
                    <?php if ($lead[$k]): ?>
                    <div class="bg-slate-50 rounded-xl px-3 py-2">
                        <div class="text-xs text-gray-400"><?= $l ?></div>
                        <div class="text-sm font-semibold text-gray-700"><?= e($lead[$k]) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar derecho -->
        <div class="space-y-5">
            <!-- Estado de integraciones -->
            <div class="card">
                <h3 class="font-bold text-gray-800 mb-4">Estado</h3>
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Zoho CRM</span>
                        <?php if ($lead['in_zoho'] == 1): ?>
                        <span class="text-xs font-semibold text-green-600 bg-green-50 px-2 py-0.5 rounded-full">Sincronizado</span>
                        <?php else: ?>
                        <span class="text-xs font-semibold text-red-500 bg-red-50 px-2 py-0.5 rounded-full">Error</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Email admin</span>
                        <?= $lead['email_admin_enviado'] ? '<span class="text-xs text-green-600">✓ Enviado</span>' : '<span class="text-xs text-gray-400">No enviado</span>' ?>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">WhatsApp</span>
                        <?= $lead['whatsapp_enviado'] ? '<span class="text-xs text-green-600">✓ Enviado</span>' : '<span class="text-xs text-gray-400">No enviado</span>' ?>
                    </div>
                </div>
                <?php if ($lead['zoho_lead_id']): ?>
                <a href="https://crm.zoho.com/crm/org832957970/tab/Leads/<?= e($lead['zoho_lead_id']) ?>"
                   target="_blank" class="btn-secondary w-full justify-center mt-4 text-xs">
                    <i data-lucide="external-link" class="w-3.5 h-3.5"></i>Ver en Zoho CRM
                </a>
                <?php endif; ?>
            </div>

            <!-- Asesor asignado -->
            <?php if ($lead['zoho_owner_name']): ?>
            <div class="card">
                <h3 class="font-bold text-gray-800 mb-3">Asesor asignado</h3>
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-pk-sage/30 rounded-full flex items-center justify-center">
                        <span class="text-pk font-bold text-sm"><?= strtoupper(substr($lead['zoho_owner_name'], 0, 1)) ?></span>
                    </div>
                    <div>
                        <div class="font-semibold text-gray-800 text-sm"><?= e($lead['zoho_owner_name']) ?></div>
                        <?php if ($lead['owner_whatsapp']): ?>
                        <a href="https://wa.me/<?= e($lead['owner_whatsapp']) ?>" target="_blank" class="text-xs text-green-600 hover:underline">WhatsApp</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Acciones -->
            <div class="card">
                <h3 class="font-bold text-gray-800 mb-4">Acciones</h3>
                <div class="space-y-3">
                    <?php if (!$lead['followed_up']): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="follow_up">
                        <input type="hidden" name="id" value="<?= $lead['id'] ?>">
                        <button type="submit" class="btn-primary w-full justify-center text-xs">
                            <i data-lucide="check" class="w-3.5 h-3.5"></i>Marcar como seguido
                        </button>
                    </form>
                    <?php endif; ?>
                    <a href="mailto:<?= e($lead['email']) ?>" class="btn-secondary w-full justify-center text-xs">
                        <i data-lucide="mail" class="w-3.5 h-3.5"></i>Enviar email
                    </a>
                    <?php if ($lead['telefono']): ?>
                    <a href="https://wa.me/<?= preg_replace('/\D/', '', $lead['telefono']) ?>" target="_blank"
                       class="btn-secondary w-full justify-center text-xs">
                        <i data-lucide="message-circle" class="w-3.5 h-3.5"></i>WhatsApp cliente
                    </a>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $lead['id'] ?>">
                        <button type="submit" data-confirm="¿Eliminar este lead?" class="btn-danger w-full justify-center text-xs">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>Eliminar lead
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    adminLayoutClose(); exit;
}

// ── LISTADO ───────────────────────────────────────────────────────────────────
$total   = (int) dbFetchValue("SELECT COUNT(*) FROM leads l WHERE $whereStr", $params);
$leads   = dbFetchAll(
    "SELECT l.*, p.nombre AS prop_nombre
     FROM leads l LEFT JOIN propiedades p ON l.propiedad_id = p.id
     WHERE $whereStr ORDER BY l.created_at DESC LIMIT $perPage OFFSET $offset",
    $params
);
$propiedades = dbFetchAll("SELECT id, nombre FROM propiedades ORDER BY nombre");
$pages = (int) ceil($total / $perPage);

adminLayoutOpen('Leads');
?>

<!-- Filtros -->
<div class="card mb-6">
    <form method="get" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="form-label text-xs">Buscar</label>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Email, nombre, tel..." class="form-input text-sm w-48">
        </div>
        <div>
            <label class="form-label text-xs">Tipo</label>
            <select name="tipo" class="form-select text-sm">
                <option value="">Todos</option>
                <?php foreach (['dias','meses','contacto','trabajo'] as $t): ?>
                <option value="<?= $t ?>" <?= $filtroTipo === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label text-xs">Propiedad</label>
            <select name="propiedad_id" class="form-select text-sm">
                <option value="">Todas</option>
                <?php foreach ($propiedades as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $filtroProp == $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label text-xs">Zoho</label>
            <select name="zoho" class="form-select text-sm">
                <option value="">Todos</option>
                <option value="1" <?= $filtroZoho === '1' ? 'selected' : '' ?>>Sincronizados</option>
                <option value="3" <?= $filtroZoho === '3' ? 'selected' : '' ?>>Con error</option>
            </select>
        </div>
        <button type="submit" class="btn-primary text-sm">
            <i data-lucide="search" class="w-4 h-4"></i>Filtrar
        </button>
        <?php if ($search || $filtroTipo || $filtroProp || $filtroZoho): ?>
        <a href="leads.php" class="btn-secondary text-sm">Limpiar</a>
        <?php endif; ?>
        <span class="text-sm text-gray-400 ml-auto self-center"><?= $total ?> resultados</span>
    </form>
</div>

<!-- Tabla -->
<div class="card p-0 overflow-hidden">
    <table class="w-full">
        <thead class="bg-slate-50 border-b border-gray-100">
            <tr>
                <th class="table-th">Contacto</th>
                <th class="table-th">Propiedad</th>
                <th class="table-th">Tipo</th>
                <th class="table-th">Zoho</th>
                <th class="table-th">WA</th>
                <th class="table-th">Seguimiento</th>
                <th class="table-th">Fecha</th>
                <th class="table-th"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            <?php foreach ($leads as $lead): ?>
            <tr class="hover:bg-slate-50 transition-colors <?= !$lead['followed_up'] && strtotime($lead['created_at']) > strtotime('-24 hours') ? 'border-l-4 border-l-orange-300' : '' ?>">
                <td class="table-td">
                    <div class="font-medium text-gray-800"><?= e($lead['email']) ?></div>
                    <div class="text-xs text-gray-400"><?= e($lead['telefono'] ?? '') ?></div>
                </td>
                <td class="table-td text-gray-500 text-xs"><?= e($lead['prop_nombre'] ?? '—') ?></td>
                <td class="table-td"><?= badgeLeadType($lead['tipo']) ?></td>
                <td class="table-td text-center">
                    <?php if ($lead['in_zoho'] == 1): ?>
                    <span class="w-2.5 h-2.5 bg-green-400 rounded-full inline-block" title="OK"></span>
                    <?php else: ?>
                    <span class="w-2.5 h-2.5 bg-red-400 rounded-full inline-block" title="Error"></span>
                    <?php endif; ?>
                </td>
                <td class="table-td text-center">
                    <?= $lead['whatsapp_enviado'] ? '<span class="text-green-500 text-xs">✓</span>' : '<span class="text-gray-300 text-xs">—</span>' ?>
                </td>
                <td class="table-td text-center">
                    <?= $lead['followed_up'] ? '<span class="text-green-500 text-xs font-semibold">✓</span>' : '<span class="text-orange-400 text-xs">Pendiente</span>' ?>
                </td>
                <td class="table-td text-xs text-gray-400 whitespace-nowrap"><?= date('d/m/y H:i', strtotime($lead['created_at'])) ?></td>
                <td class="table-td">
                    <a href="?action=view&id=<?= $lead['id'] ?>" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-pk transition-colors inline-flex" title="Ver detalle">
                        <i data-lucide="eye" class="w-4 h-4"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($leads)): ?>
            <tr><td colspan="8" class="table-td text-center text-gray-400 py-12">No hay leads con estos filtros</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Paginación -->
<?php if ($pages > 1): ?>
<div class="flex items-center justify-center gap-2 mt-6">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
       class="w-9 h-9 flex items-center justify-center rounded-xl text-sm font-medium transition-colors <?= $p == $page ? 'bg-pk text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50' ?>">
        <?= $p ?>
    </a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php adminLayoutClose(); ?>
