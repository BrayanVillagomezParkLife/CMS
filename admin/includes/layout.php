<?php
/**
 * admin/includes/layout.php
 * Renderiza <head>, sidebar y navbar del admin.
 * Uso: adminLayoutOpen($pageTitle) al inicio, adminLayoutClose() al final.
 *
 * v2.0 — Responsive: mobile-first, tablas con scroll, grids adaptativos,
 *         picker modal responsive, sidebar swipe, bottom nav mobile.
 */

function adminLayoutOpen(string $pageTitle = 'Admin'): void
{
    $flash   = flashGet();
    $csrf    = adminCsrf();
    $user    = currentAdminName();
    $role    = currentAdminRole();
    $current = basename($_SERVER['SCRIPT_NAME'], '.php');

    // Todos los items del sidebar
    $allNav = [
        ['id' => 'index',             'label' => 'Dashboard',          'icon' => 'layout-dashboard',  'href' => 'index.php',              'modulo' => 'dashboard'],
        ['id' => 'propiedades',       'label' => 'Propiedades',        'icon' => 'building-2',        'href' => 'propiedades.php',        'modulo' => 'propiedades'],
        ['id' => 'habitaciones',      'label' => 'Habitaciones',       'icon' => 'bed-double',        'href' => 'habitaciones.php',       'modulo' => 'habitaciones'],
        ['id' => 'precios',           'label' => 'Precios',            'icon' => 'badge-dollar-sign', 'href' => 'precios.php',            'modulo' => 'precios'],
        ['id' => 'cotizador',         'label' => 'Cotizador',          'icon' => 'calculator',        'href' => 'cotizador.php',          'modulo' => 'cotizador'],
        ['id' => 'imagenes',          'label' => 'Imágenes',           'icon' => 'image',             'href' => 'imagenes.php',           'modulo' => 'imagenes'],
        ['id' => 'imagenes-auditoria','label' => 'Auditoría img',      'icon' => 'scan-search',       'href' => 'imagenes-auditoria.php', 'modulo' => 'imagenes-auditoria'],
        ['id' => 'amenidades',        'label' => 'Amenidades',         'icon' => 'sparkles',          'href' => 'amenidades.php',         'modulo' => 'amenidades'],
        ['id' => 'faqs',              'label' => 'FAQs',               'icon' => 'help-circle',       'href' => 'faqs.php',               'modulo' => 'faqs'],
        ['id' => 'hero',              'label' => 'Hero Slides',        'icon' => 'gallery-thumbnails','href' => 'hero.php',               'modulo' => 'hero'],
        ['id' => 'prensa',            'label' => 'Prensa',             'icon' => 'newspaper',         'href' => 'prensa.php',             'modulo' => 'prensa'],
        ['id' => 'leads',             'label' => 'Leads',              'icon' => 'users',             'href' => 'leads.php',              'modulo' => 'leads'],
        ['id' => 'strings',           'label' => 'Textos del Sitio',   'icon' => 'languages',         'href' => 'strings.php',            'modulo' => 'strings'],
        ['id' => 'usuarios',          'label' => 'Usuarios',           'icon' => 'shield-check',      'href' => 'usuarios.php',           'modulo' => 'usuarios'],
        ['id' => 'roles',              'label' => 'Roles y Permisos',   'icon' => 'grid-3x3',          'href' => 'roles.php',              'modulo' => 'usuarios'],
        ['id' => 'config',            'label' => 'Configuración',      'icon' => 'settings',          'href' => 'config.php',             'modulo' => 'config'],
    ];

    // Filtrar sidebar por permisos del usuario actual
    $nav = array_filter($allNav, fn($item) => canAccess($item['modulo'], 'ver'));
    ?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
        /* ═══ BASE ═══ */
        .sidebar-link { display:flex; align-items:center; gap:.75rem; padding:.625rem 1rem; border-radius:.75rem; font-size:.875rem; font-weight:500; transition:all .2s; color:#475569; text-decoration:none; }
        .sidebar-link:hover { background:#f1f5f9; color:#202944; }
        .sidebar-link.active { background:#202944; color:#fff; }
        .sidebar-link.active:hover { background:#2C3A5E; color:#fff; }

        .card { background:#fff; border-radius:1rem; padding:1.5rem; box-shadow:0 1px 3px rgba(0,0,0,.04); border:1px solid #f3f4f6; }

        .btn-primary { display:inline-flex; align-items:center; gap:.5rem; background:#202944; color:#fff; padding:.5rem 1rem; border-radius:.75rem; font-size:.875rem; font-weight:600; border:none; cursor:pointer; transition:all .2s; white-space:nowrap; }
        .btn-primary:hover { background:#2C3A5E; }
        .btn-secondary { display:inline-flex; align-items:center; gap:.5rem; background:#fff; color:#374151; padding:.5rem 1rem; border-radius:.75rem; font-size:.875rem; font-weight:500; border:1px solid #e5e7eb; cursor:pointer; transition:all .2s; white-space:nowrap; }
        .btn-secondary:hover { background:#f9fafb; }
        .btn-danger { display:inline-flex; align-items:center; gap:.5rem; background:#ef4444; color:#fff; padding:.5rem 1rem; border-radius:.75rem; font-size:.875rem; font-weight:600; border:none; cursor:pointer; transition:all .2s; white-space:nowrap; }
        .btn-danger:hover { background:#dc2626; }

        .form-label { display:block; font-size:.875rem; font-weight:500; color:#374151; margin-bottom:.375rem; }
        .form-input, .form-textarea, .form-select { width:100%; padding:.625rem .75rem; border:1px solid #e5e7eb; border-radius:.75rem; font-size:.875rem; background:#fff; color:#111827; outline:none; transition:all .2s; box-sizing:border-box; font-family:inherit; }
        .form-input:focus, .form-textarea:focus, .form-select:focus { border-color:#202944; box-shadow:0 0 0 3px rgba(32,41,68,.1); }
        .form-textarea { resize:vertical; }

        .table-th { padding:.75rem 1rem; text-align:left; font-size:.7rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
        .table-td { padding:.75rem 1rem; font-size:.875rem; color:#374151; }

        /* ═══ SIDEBAR ═══ */
        #sidebar { transition:transform .3s cubic-bezier(.4,0,.2,1); }
        @media (max-width: 1023px) {
            #sidebar { transform: translateX(-100%); }
            #sidebar.open { transform: translateX(0); }
        }

        /* ═══ RESPONSIVE TABLE ═══ */
        .table-wrap { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:.75rem; }
        .table-wrap::-webkit-scrollbar { height:5px; }
        .table-wrap::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:3px; }
        .table-wrap table { min-width:100%; }

        /* ═══ MOBILE < 640px ═══ */
        @media (max-width: 639px) {
            /* Tighter padding */
            main { padding-left:.75rem !important; padding-right:.75rem !important; padding-top:.75rem !important; }
            .card { padding:1rem !important; border-radius:.75rem !important; }
            .card h2 { font-size:.9rem !important; }

            /* Force single-column forms */
            .grid.sm\:grid-cols-2,
            .grid.sm\:grid-cols-3,
            .grid.md\:grid-cols-2,
            .grid.md\:grid-cols-3,
            .grid.lg\:grid-cols-3,
            .grid.lg\:grid-cols-4 { grid-template-columns:1fr !important; }

            /* Dashboard stat grid → 2 cols */
            .grid.sm\:grid-cols-2.lg\:grid-cols-4 { grid-template-columns:repeat(2,1fr) !important; gap:.5rem !important; }

            /* Smaller inputs */
            .form-input, .form-textarea, .form-select { padding:.5rem .625rem; font-size:.8125rem; }
            .form-label { font-size:.8rem; }

            /* Smaller buttons */
            .btn-primary, .btn-secondary, .btn-danger { font-size:.75rem; padding:.4375rem .75rem; }

            /* Tighter table cells */
            .table-th { padding:.5rem .5rem; font-size:.6rem; }
            .table-td { padding:.5rem .5rem; font-size:.75rem; }

            /* Header title */
            header h1 { font-size:1rem !important; }

            /* Action groups wrap */
            .flex.items-center.gap-2:not(header .flex),
            .flex.items-center.gap-3:not(header .flex),
            .flex.items-center.gap-4:not(header .flex) { flex-wrap:wrap; }

            /* Strings.php sub-sidebar → horizontal scroll */
            .admin-sub-sidebar { flex-direction:column !important; gap:.75rem !important; }
            .admin-sub-sidebar > nav,
            .admin-sub-sidebar > .sub-nav { display:flex; flex-wrap:wrap; gap:.25rem; width:100% !important; }
            .admin-sub-sidebar > nav a,
            .admin-sub-sidebar > .sub-nav a { font-size:.7rem; padding:.35rem .6rem; white-space:nowrap; }

            /* Picker modal → full screen */
            #pics-picker-modal > div {
                width:100vw !important; height:100vh !important;
                border-radius:0 !important; top:0 !important; left:0 !important;
                transform:none !important;
            }
            #picker-grid { grid-template-columns:repeat(auto-fill,minmax(80px,1fr)) !important; gap:.35rem !important; }

            /* Flash msg */
            #flash-msg { margin-left:.5rem !important; margin-right:.5rem !important; font-size:.8rem; }

            /* Hide non-essential table columns */
            .hide-mobile { display:none !important; }

            /* Badge smaller */
            .badge, [class*="rounded-full"][class*="text-xs"] { font-size:.65rem; padding:.125rem .375rem; }

            /* Bottom safe area (notch phones) */
            main { padding-bottom:calc(.75rem + env(safe-area-inset-bottom, 0px)) !important; }
        }

        /* ═══ TABLET 640-1023px ═══ */
        @media (min-width:640px) and (max-width:1023px) {
            main { padding-left:1.25rem !important; padding-right:1.25rem !important; }
            .grid.lg\:grid-cols-4 { grid-template-columns:repeat(2,1fr) !important; }
            .grid.lg\:grid-cols-3 { grid-template-columns:repeat(2,1fr) !important; }

            /* Picker modal */
            #pics-picker-modal > div { width:94vw !important; height:88vh !important; }
        }

        /* ═══ STRINGS.PHP SUB-SIDEBAR ═══ */
        @media (max-width:639px) {
            /* Stack sidebar + content vertically */
            main > .flex.gap-6,
            main > div > .flex.gap-6 { flex-direction:column !important; gap:.75rem !important; }
            /* Make fixed sidebar full width, horizontal nav */
            main .w-52 { width:100% !important; }
            main .w-52 > nav { display:flex !important; flex-wrap:wrap; gap:.25rem; }
            main .w-52 > nav a { font-size:.7rem; padding:.35rem .6rem; white-space:nowrap; }
            main .w-52 > .border-t { padding-top:.5rem !important; margin-top:.25rem !important; }
            main .w-52 > .border-t a { font-size:.7rem; padding:.35rem .6rem; }
        }
        @media (min-width:640px) and (max-width:1023px) {
            main .w-52 { width:9rem !important; }
            main .w-52 > nav a { font-size:.8rem; }
        }

        /* ═══ COTIZADOR CALCULATOR ═══ */
        @media (max-width:639px) {
            /* Force cotizador grids to stack */
            .grid.grid-cols-2 { grid-template-columns:1fr !important; }
            .grid.grid-cols-3 { grid-template-columns:1fr !important; }
            .grid.grid-cols-4 { grid-template-columns:repeat(2,1fr) !important; }
        }

        /* ═══ HELPER CLASSES ═══ */
        .cell-truncate { max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        @media (max-width:639px) { .cell-truncate { max-width:100px; } }

        /* Action dropdown menu */
        .action-menu { position:relative; }
        .action-dropdown { position:absolute; right:0; top:100%; z-index:30; background:#fff; border:1px solid #e5e7eb; border-radius:.75rem; box-shadow:0 8px 24px rgba(0,0,0,.12); min-width:160px; padding:.25rem; display:none; }
        .action-dropdown.show { display:block; }
        .action-dropdown a,
        .action-dropdown button { display:flex; width:100%; align-items:center; gap:.5rem; padding:.5rem .75rem; font-size:.75rem; border-radius:.5rem; border:none; background:none; cursor:pointer; color:#374151; text-decoration:none; text-align:left; }
        .action-dropdown a:hover,
        .action-dropdown button:hover { background:#f3f4f6; }

        /* Page header flex → wraps on mobile */
        .page-header { display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin-bottom:1.5rem; flex-wrap:wrap; }
        .page-header h2 { font-size:1rem; }
        @media (max-width:639px) {
            .page-header { margin-bottom:1rem; }
            .page-header > * { width:100%; }
            .page-header > .flex { justify-content:flex-start; }
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
                <?php $rl = getRolLabel($role); ?>
                <span class="inline-block px-1.5 py-0 text-[10px] font-semibold rounded-full <?= $rl['color'] ?>"><?= $rl['label'] ?></span>
            </div>
        </div>
        <a href="mi-perfil.php" class="sidebar-link text-xs mb-1 <?= $current === 'mi-perfil' ? 'active' : '' ?>">
            <i data-lucide="circle-user" class="w-4 h-4"></i>Mi perfil
        </a>
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
<div id="main-wrapper" class="lg:pl-64 min-h-screen flex flex-col">

    <!-- Topbar -->
    <header class="sticky top-0 z-30 bg-white border-b border-gray-100 px-3 sm:px-8 h-14 sm:h-16 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
            <button onclick="toggleSidebar()" class="lg:hidden p-2 -ml-1 rounded-lg hover:bg-gray-100 transition-colors flex-shrink-0">
                <i data-lucide="menu" class="w-5 h-5 text-gray-600"></i>
            </button>
            <h1 class="text-base sm:text-lg font-bold text-gray-800 truncate"><?= e($pageTitle) ?></h1>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
            <a href="<?= BASE_URL ?>/" target="_blank"
               class="hidden sm:flex items-center gap-2 text-xs text-gray-500 hover:text-pk transition-colors px-3 py-2 rounded-lg hover:bg-gray-50">
                <i data-lucide="external-link" class="w-3.5 h-3.5"></i><span class="hidden md:inline">Ver sitio</span>
            </a>
            <form method="post" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button type="submit" class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-red-500 transition-colors px-2 sm:px-3 py-2 rounded-lg hover:bg-red-50">
                    <i data-lucide="log-out" class="w-3.5 h-3.5"></i><span class="hidden sm:inline">Salir</span>
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
    <div class="mx-3 sm:mx-8 mt-3 flex items-center gap-3 px-3 sm:px-4 py-3 rounded-xl border <?= $cls ?>" id="flash-msg">
        <i data-lucide="<?= $icon ?>" class="w-4 h-4 flex-shrink-0"></i>
        <span class="text-sm font-medium flex-1"><?= e($flash['msg']) ?></span>
        <button onclick="document.getElementById('flash-msg').remove()" class="ml-auto opacity-50 hover:opacity-100 flex-shrink-0">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Page content -->
    <main class="flex-1 px-3 sm:px-8 py-4 sm:py-6">
    <?php
}

function adminLayoutClose(): void
{
    ?>
    </main>
</div><!-- /main wrapper -->

<script>
lucide.createIcons();

/* ── Sidebar toggle ──────────────────────────────────────────────────── */
function toggleSidebar() {
    const s = document.getElementById('sidebar');
    const o = document.getElementById('overlay');
    const isOpen = s.classList.contains('open');
    s.classList.toggle('open', !isOpen);
    o.classList.toggle('hidden', isOpen);
    document.body.style.overflow = isOpen ? '' : 'hidden';
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.add('hidden');
    document.body.style.overflow = '';
}

// Close sidebar on nav link click (mobile)
document.querySelectorAll('#sidebar .sidebar-link').forEach(link => {
    link.addEventListener('click', () => { if (window.innerWidth < 1024) closeSidebar(); });
});

/* ── Swipe to close sidebar ──────────────────────────────────────────── */
(function(){
    let startX = 0, startY = 0, tracking = false;
    const sidebar = document.getElementById('sidebar');
    sidebar.addEventListener('touchstart', e => {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        tracking = true;
    }, {passive:true});
    sidebar.addEventListener('touchmove', e => {
        if (!tracking) return;
        const dx = e.touches[0].clientX - startX;
        const dy = Math.abs(e.touches[0].clientY - startY);
        if (dx < -60 && dy < 40) { closeSidebar(); tracking = false; }
    }, {passive:true});
    sidebar.addEventListener('touchend', () => { tracking = false; }, {passive:true});
})();

/* ── Swipe from left edge to open sidebar ────────────────────────────── */
(function(){
    let startX = 0, tracking = false;
    document.addEventListener('touchstart', e => {
        if (e.touches[0].clientX < 20 && window.innerWidth < 1024) {
            startX = e.touches[0].clientX;
            tracking = true;
        }
    }, {passive:true});
    document.addEventListener('touchmove', e => {
        if (!tracking) return;
        if (e.touches[0].clientX - startX > 60) {
            toggleSidebar();
            tracking = false;
        }
    }, {passive:true});
    document.addEventListener('touchend', () => { tracking = false; }, {passive:true});
})();

/* ── Auto-wrap tables in .table-wrap ─────────────────────────────────── */
document.querySelectorAll('table').forEach(table => {
    if (table.closest('.table-wrap')) return;
    const wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);
});

/* ── Confirm before delete ───────────────────────────────────────────── */
document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', e => {
        if (!confirm(btn.dataset.confirm || '¿Confirmas esta acción?')) e.preventDefault();
    });
});

/* ── Close action dropdowns on outside click ─────────────────────────── */
document.addEventListener('click', e => {
    if (!e.target.closest('.action-menu')) {
        document.querySelectorAll('.action-dropdown.show').forEach(d => d.classList.remove('show'));
    }
});

/* ── Auto-hide flash (no auto-hide si contiene contraseña) ──────────── */
const _fm = document.getElementById('flash-msg');
if (_fm && !_fm.textContent.includes('Contraseña') && !_fm.textContent.includes('contraseña')) {
    setTimeout(() => { _fm?.remove(); }, 5000);
}

/* ── Handle orientation changes ──────────────────────────────────────── */
window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('overlay').classList.add('hidden');
        document.body.style.overflow = '';
    }
});
</script>
</body>
</html>
    <?php
}