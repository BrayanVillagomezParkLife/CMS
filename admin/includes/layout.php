<?php
/**
 * admin/includes/layout.php
 * Renderiza <head>, sidebar y navbar del admin.
 * Uso: adminLayoutOpen($pageTitle) al inicio, adminLayoutClose() al final.
 */

function adminLayoutOpen(string $pageTitle = 'Admin'): void
{
    $flash   = flashGet();
    $csrf    = adminCsrf();
    $user    = currentAdminName();
    $role    = currentAdminRole();
    $current = basename($_SERVER['SCRIPT_NAME'], '.php');

    $nav = [
        ['id' => 'index',        'label' => 'Dashboard',    'icon' => 'layout-dashboard', 'href' => 'index.php'],
        ['id' => 'propiedades',  'label' => 'Propiedades',  'icon' => 'building-2',       'href' => 'propiedades.php'],
        ['id' => 'habitaciones', 'label' => 'Habitaciones', 'icon' => 'bed-double',        'href' => 'habitaciones.php'],
        ['id' => 'precios', 'label' => 'Precios', 'icon' => 'badge-dollar-sign', 'href' => 'precios.php'],
        ['id' => 'cotizador', 'label' => 'Cotizador', 'icon' => 'calculator', 'href' => 'cotizador.php'],
        ['id' => 'imagenes',          'label' => 'Imágenes',          'icon' => 'image',      'href' => 'imagenes.php'],
        ['id' => 'imagenes-auditoria','label' => 'Auditoría imágenes','icon' => 'scan-search','href' => 'imagenes-auditoria.php'],
        ['id' => 'amenidades',   'label' => 'Amenidades',   'icon' => 'sparkles',          'href' => 'amenidades.php'],
        ['id' => 'faqs',         'label' => 'FAQs',         'icon' => 'help-circle',       'href' => 'faqs.php'],
        ['id' => 'hero',         'label' => 'Hero Slides',  'icon' => 'gallery-thumbnails','href' => 'hero.php'],
        ['id' => 'prensa',       'label' => 'Prensa',       'icon' => 'newspaper',         'href' => 'prensa.php'],
        ['id' => 'leads',        'label' => 'Leads',        'icon' => 'users',             'href' => 'leads.php'],
        ['id' => 'strings',      'label' => 'Textos del Sitio', 'icon' => 'languages',     'href' => 'strings.php'],
        ['id' => 'config',       'label' => 'Configuración','icon' => 'settings',          'href' => 'config.php'],
    ];
    ?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — Park Life Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
    tailwind.config = { theme: { extend: {
        colors: { 'pk': '#202944', 'pk-light': '#2C3A5E', 'pk-sage': '#BAC4B9' },
        fontFamily: { sans: ['Plus Jakarta Sans', 'system-ui', 'sans-serif'] }
    }}}
    </script>
    <style>
        /* Sidebar links */
        .sidebar-link { display:flex; align-items:center; gap:.75rem; padding:.625rem 1rem; border-radius:.75rem; font-size:.875rem; font-weight:500; transition:all .2s; color:#475569; text-decoration:none; }
        .sidebar-link:hover { background:#f1f5f9; color:#202944; }
        .sidebar-link.active { background:#202944; color:#fff; }
        .sidebar-link.active:hover { background:#2C3A5E; color:#fff; }

        /* Cards */
        .card { background:#fff; border-radius:1rem; box-shadow:0 1px 3px rgba(0,0,0,.06); border:1px solid #f3f4f6; padding:1.5rem; }

        /* Buttons */
        .btn-primary { display:inline-flex; align-items:center; gap:.5rem; background:#202944; color:#fff; padding:.5rem 1rem; border-radius:.75rem; font-size:.875rem; font-weight:600; text-decoration:none; border:none; cursor:pointer; transition:all .2s; }
        .btn-primary:hover { background:#2C3A5E; }
        .btn-secondary { display:inline-flex; align-items:center; gap:.5rem; background:#fff; border:1px solid #e5e7eb; color:#374151; padding:.5rem 1rem; border-radius:.75rem; font-size:.875rem; font-weight:500; text-decoration:none; cursor:pointer; transition:all .2s; }
        .btn-secondary:hover { background:#f9fafb; }
        .btn-danger { display:inline-flex; align-items:center; gap:.5rem; background:#ef4444; color:#fff; padding:.5rem 1rem; border-radius:.75rem; font-size:.875rem; font-weight:600; border:none; cursor:pointer; transition:all .2s; }
        .btn-danger:hover { background:#dc2626; }

        /* Forms */
        .form-label { display:block; font-size:.875rem; font-weight:500; color:#374151; margin-bottom:.375rem; }
        .form-input, .form-textarea, .form-select { width:100%; padding:.625rem .75rem; border:1px solid #e5e7eb; border-radius:.75rem; font-size:.875rem; background:#fff; color:#111827; outline:none; transition:all .2s; box-sizing:border-box; font-family:inherit; }
        .form-input:focus, .form-textarea:focus, .form-select:focus { border-color:#202944; box-shadow:0 0 0 3px rgba(32,41,68,.1); }
        .form-textarea { resize:vertical; }

        /* Table */
        .table-th { padding:.75rem 1rem; text-align:left; font-size:.7rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
        .table-td { padding:.75rem 1rem; font-size:.875rem; color:#374151; }

        /* Sidebar transition */
        #sidebar { transition:transform .3s ease; }
        @media (max-width: 1023px) {
            #sidebar { transform: translateX(-100%); }
            #sidebar.open { transform: translateX(0); }
        }
    </style>
</head>
<body class="h-full bg-slate-50 font-sans antialiased">

<!-- ── Sidebar ──────────────────────────────────────────────────────────── -->
<aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-white border-r border-gray-100 flex flex-col shadow-sm">
    <!-- Logo -->
    <div class="flex items-center gap-3 px-6 py-5 border-b border-gray-100">
        <div class="w-9 h-9 bg-pk rounded-xl flex items-center justify-center flex-shrink-0">
            <i data-lucide="home" class="w-5 h-5 text-white"></i>
        </div>
        <div>
            <div class="font-bold text-pk text-sm leading-tight">Park Life</div>
            <div class="text-xs text-gray-400">Admin Panel</div>
        </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
        <?php foreach ($nav as $item): ?>
        <a href="<?= e($item['href']) ?>"
           class="sidebar-link <?= $current === $item['id'] ? 'active' : '' ?>">
            <i data-lucide="<?= e($item['icon']) ?>" class="w-4 h-4 flex-shrink-0"></i>
            <?= e($item['label']) ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <!-- Footer del sidebar -->
    <div class="px-3 py-4 border-t border-gray-100">
        <div class="flex items-center gap-3 px-3 py-2 mb-2">
            <div class="w-8 h-8 bg-pk-sage/30 rounded-full flex items-center justify-center flex-shrink-0">
                <span class="text-pk font-bold text-xs"><?= strtoupper(substr($user, 0, 1)) ?></span>
            </div>
            <div class="min-w-0">
                <div class="text-sm font-semibold text-gray-800 truncate"><?= e($user) ?></div>
                <div class="text-xs text-gray-400 capitalize"><?= e($role) ?></div>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/" target="_blank"
           class="sidebar-link text-xs mb-1">
            <i data-lucide="external-link" class="w-4 h-4"></i>Ver sitio web
        </a>
        <a href="logout.php" class="sidebar-link text-xs text-red-500 hover:bg-red-50 hover:text-red-600">
            <i data-lucide="log-out" class="w-4 h-4"></i>Cerrar sesión
        </a>
    </div>
</aside>

<!-- ── Overlay mobile ─────────────────────────────────────────────────── -->
<div id="overlay" class="fixed inset-0 bg-black/40 z-40 hidden lg:hidden" onclick="closeSidebar()"></div>

<!-- ── Main wrapper ──────────────────────────────────────────────────────── -->
<div class="lg:pl-64 min-h-screen flex flex-col">

    <!-- Topbar -->
    <header class="sticky top-0 z-30 bg-white border-b border-gray-100 px-4 sm:px-8 h-16 flex items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 transition-colors">
                <i data-lucide="menu" class="w-5 h-5 text-gray-600"></i>
            </button>
            <h1 class="text-lg font-bold text-gray-800"><?= e($pageTitle) ?></h1>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?= BASE_URL ?>/" target="_blank"
               class="hidden sm:flex items-center gap-2 text-xs text-gray-500 hover:text-pk transition-colors px-3 py-2 rounded-lg hover:bg-gray-50">
                <i data-lucide="external-link" class="w-3.5 h-3.5"></i>Ver sitio
            </a>
            <form method="post" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button type="submit" class="flex items-center gap-2 text-xs text-gray-500 hover:text-red-500 transition-colors px-3 py-2 rounded-lg hover:bg-red-50">
                    <i data-lucide="log-out" class="w-3.5 h-3.5"></i>Salir
                </button>
            </form>
        </div>
    </header>

    <!-- Flash message -->
    <?php if ($flash): ?>
    <?php
        $colors = [
            'success' => 'bg-green-50 border-green-200 text-green-800',
            'error'   => 'bg-red-50 border-red-200 text-red-800',
            'warning' => 'bg-yellow-50 border-yellow-200 text-yellow-800',
            'info'    => 'bg-blue-50 border-blue-200 text-blue-800',
        ];
        $icons = ['success' => 'check-circle', 'error' => 'alert-circle', 'warning' => 'alert-triangle', 'info' => 'info'];
        $cls  = $colors[$flash['type']] ?? $colors['info'];
        $icon = $icons[$flash['type']] ?? 'info';
    ?>
    <div class="mx-4 sm:mx-8 mt-4 flex items-center gap-3 px-4 py-3 rounded-xl border <?= $cls ?>" id="flash-msg">
        <i data-lucide="<?= $icon ?>" class="w-4 h-4 flex-shrink-0"></i>
        <span class="text-sm font-medium"><?= e($flash['msg']) ?></span>
        <button onclick="document.getElementById('flash-msg').remove()" class="ml-auto opacity-50 hover:opacity-100">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Page content -->
    <main class="flex-1 px-4 sm:px-8 py-6">
    <?php
}

function adminLayoutClose(): void
{
    ?>
    </main>
</div><!-- /main wrapper -->

<script>
lucide.createIcons();
function toggleSidebar() {
    const s = document.getElementById('sidebar');
    const o = document.getElementById('overlay');
    const isOpen = s.classList.contains('open');
    s.classList.toggle('open', !isOpen);
    o.classList.toggle('hidden', isOpen);
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.add('hidden');
}
// Close sidebar when clicking a nav link on mobile
document.querySelectorAll('#sidebar .sidebar-link').forEach(link => {
    link.addEventListener('click', () => { if (window.innerWidth < 1024) closeSidebar(); });
});

// Confirmar antes de eliminar
document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', e => {
        if (!confirm(btn.dataset.confirm || '¿Confirmas esta acción?')) e.preventDefault();
    });
});

// Auto-hide flash
setTimeout(() => { document.getElementById('flash-msg')?.remove(); }, 5000);
</script>
</body>
</html>
    <?php
}