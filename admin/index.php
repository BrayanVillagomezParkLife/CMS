<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

// ── Métricas ──────────────────────────────────────────────────────────────
$totalProps   = (int) dbFetchValue("SELECT COUNT(*) FROM propiedades WHERE activo = 1");
$totalLeads   = (int) dbFetchValue("SELECT COUNT(*) FROM leads");
$leadsHoy     = (int) dbFetchValue("SELECT COUNT(*) FROM leads WHERE DATE(created_at) = CURDATE()");
$leadsSemana  = (int) dbFetchValue("SELECT COUNT(*) FROM leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$leadsMes     = (int) dbFetchValue("SELECT COUNT(*) FROM leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$leadsZohoOk  = (int) dbFetchValue("SELECT COUNT(*) FROM leads WHERE in_zoho = 1");
$leadsZohoFail= (int) dbFetchValue("SELECT COUNT(*) FROM leads WHERE in_zoho = 3");

// Últimos 8 leads
$recentLeads = dbFetchAll(
    "SELECT l.*, p.nombre AS prop_nombre
     FROM leads l
     LEFT JOIN propiedades p ON l.propiedad_id = p.id
     ORDER BY l.created_at DESC LIMIT 8"
);

// Leads por tipo (últimos 30 días)
$leadsPorTipo = dbFetchAll(
    "SELECT tipo, COUNT(*) as total FROM leads
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY tipo ORDER BY total DESC"
);

// Props sin imágenes
$propsSinImg = dbFetchAll(
    "SELECT p.nombre, p.slug FROM propiedades p
     LEFT JOIN propiedad_imagenes pi ON p.id = pi.propiedad_id AND pi.tipo = 'hero'
     WHERE p.activo = 1 AND pi.id IS NULL"
);

adminLayoutOpen('Dashboard');
?>

<!-- Stats grid -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <?php
    $stats = [
        ['label' => 'Propiedades activas', 'value' => $totalProps,  'icon' => 'building-2',    'color' => 'bg-blue-50 text-blue-600'],
        ['label' => 'Leads totales',        'value' => $totalLeads,  'icon' => 'users',          'color' => 'bg-purple-50 text-purple-600'],
        ['label' => 'Leads hoy',            'value' => $leadsHoy,    'icon' => 'user-plus',      'color' => 'bg-green-50 text-green-600'],
        ['label' => 'Leads esta semana',    'value' => $leadsSemana, 'icon' => 'trending-up',    'color' => 'bg-orange-50 text-orange-600'],
    ];
    foreach ($stats as $s): ?>
    <div class="card flex items-center gap-4">
        <div class="w-12 h-12 <?= $s['color'] ?> rounded-xl flex items-center justify-center flex-shrink-0">
            <i data-lucide="<?= $s['icon'] ?>" class="w-5 h-5"></i>
        </div>
        <div>
            <div class="text-2xl font-bold text-gray-800"><?= $s['value'] ?></div>
            <div class="text-xs text-gray-500 mt-0.5"><?= $s['label'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-3 gap-6">

    <!-- Leads recientes -->
    <div class="lg:col-span-2 card">
        <div class="flex items-center justify-between mb-5">
            <h2 class="font-bold text-gray-800">Leads recientes</h2>
            <a href="leads.php" class="text-xs text-pk hover:underline">Ver todos →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100">
                        <th class="table-th pl-0">Email</th>
                        <th class="table-th">Propiedad</th>
                        <th class="table-th">Tipo</th>
                        <th class="table-th">Zoho</th>
                        <th class="table-th">Fecha</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($recentLeads as $lead): ?>
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="table-td pl-0">
                            <div class="font-medium text-gray-800 truncate max-w-[160px]"><?= e($lead['email']) ?></div>
                            <?php if ($lead['telefono']): ?>
                            <div class="text-xs text-gray-400"><?= e($lead['telefono']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="table-td text-gray-500 truncate max-w-[120px]"><?= e($lead['prop_nombre'] ?? '—') ?></td>
                        <td class="table-td"><?= badgeLeadType($lead['tipo']) ?></td>
                        <td class="table-td">
                            <?php if ($lead['in_zoho'] == 1): ?>
                            <span class="w-2 h-2 bg-green-400 rounded-full inline-block" title="Sincronizado"></span>
                            <?php elseif ($lead['in_zoho'] == 3): ?>
                            <span class="w-2 h-2 bg-red-400 rounded-full inline-block" title="Error Zoho"></span>
                            <?php else: ?>
                            <span class="w-2 h-2 bg-gray-300 rounded-full inline-block"></span>
                            <?php endif; ?>
                        </td>
                        <td class="table-td text-gray-400 whitespace-nowrap">
                            <?= date('d/m H:i', strtotime($lead['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentLeads)): ?>
                    <tr><td colspan="5" class="table-td text-center text-gray-400 py-8">No hay leads aún</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Panel lateral derecho -->
    <div class="space-y-5">

        <!-- Estado Zoho -->
        <div class="card">
            <h3 class="font-bold text-gray-800 mb-4">Estado Zoho CRM</h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <span class="w-2.5 h-2.5 bg-green-400 rounded-full"></span>Sincronizados
                    </div>
                    <span class="font-bold text-green-600"><?= $leadsZohoOk ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <span class="w-2.5 h-2.5 bg-red-400 rounded-full"></span>Con error
                    </div>
                    <span class="font-bold text-red-500"><?= $leadsZohoFail ?></span>
                </div>
                <div class="flex items-center justify-between border-t border-gray-100 pt-3">
                    <span class="text-sm text-gray-500">Total leads (30d)</span>
                    <span class="font-bold text-gray-800"><?= $leadsMes ?></span>
                </div>
            </div>
        </div>

        <!-- Leads por tipo -->
        <?php if (!empty($leadsPorTipo)): ?>
        <div class="card">
            <h3 class="font-bold text-gray-800 mb-4">Por tipo (30 días)</h3>
            <div class="space-y-2">
                <?php foreach ($leadsPorTipo as $lt): ?>
                <div class="flex items-center justify-between">
                    <?= badgeLeadType($lt['tipo']) ?>
                    <span class="font-semibold text-gray-700 text-sm"><?= $lt['total'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alertas -->
        <?php if (!empty($propsSinImg)): ?>
        <div class="card border-orange-200 bg-orange-50">
            <div class="flex items-center gap-2 mb-3">
                <i data-lucide="alert-triangle" class="w-4 h-4 text-orange-500"></i>
                <h3 class="font-bold text-orange-800 text-sm">Sin imagen hero</h3>
            </div>
            <ul class="space-y-1">
                <?php foreach ($propsSinImg as $p): ?>
                <li class="text-xs text-orange-700">
                    <a href="imagenes.php?slug=<?= e($p['slug']) ?>" class="hover:underline"><?= e($p['nombre']) ?></a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Accesos rápidos -->
        <div class="card">
            <h3 class="font-bold text-gray-800 mb-4">Accesos rápidos</h3>
            <div class="space-y-2">
                <a href="propiedades.php?action=new" class="btn-primary w-full justify-center text-xs">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>Nueva propiedad
                </a>
                <a href="faqs.php?action=new" class="btn-secondary w-full justify-center text-xs">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>Nueva FAQ
                </a>
                <a href="config.php" class="btn-secondary w-full justify-center text-xs">
                    <i data-lucide="settings" class="w-3.5 h-3.5"></i>Configuración
                </a>
            </div>
        </div>

    </div>
</div>

<?php adminLayoutClose(); ?>
