<?php
/**
 * admin/403.php — Acceso denegado
 * Se incluye automáticamente desde requirePermission() en auth.php
 */
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

adminLayoutOpen('Acceso denegado');
?>
<div class="flex items-center justify-center min-h-[60vh]">
    <div class="text-center max-w-sm">
        <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-6">
            <i data-lucide="shield-x" class="w-10 h-10 text-red-400"></i>
        </div>
        <h2 class="text-5xl font-bold text-gray-200 mb-2">403</h2>
        <p class="text-gray-500 mb-2">No tienes permisos para acceder a este módulo.</p>
        <p class="text-sm text-gray-400 mb-6">
            Tu rol actual es <strong><?= e(getRolLabel(currentAdminRole())['label']) ?></strong>.
            Si necesitas acceso, contacta a un administrador.
        </p>
        <a href="index.php" class="btn-primary px-6 py-2.5">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver al Dashboard
        </a>
    </div>
</div>
<?php adminLayoutClose(); ?>
