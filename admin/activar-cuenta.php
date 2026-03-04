<?php
declare(strict_types=1);
/**
 * admin/activar-cuenta.php
 * Página pública (sin login) para activar cuentas con token.
 * URL: /admin/activar-cuenta.php?t={token}
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$token  = $_GET['t'] ?? '';
$result = null;
$showForm = false;

if ($token) {
    $result = activateAccount($token);
    if ($result['success']) {
        $showForm = false; // Cuenta activada, mostrar éxito
    }
} else {
    $result = ['success' => false, 'error' => 'No se proporcionó un token de activación.'];
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activar Cuenta — Park Life Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Asap+Condensed:wght@700&display=swap" rel="stylesheet">
    <script>tailwind.config={theme:{extend:{colors:{'pk':'#202944','pk-sage':'#BAC4B9'},fontFamily:{sans:['Plus Jakarta Sans','sans-serif'],asap:['Asap Condensed','sans-serif']}}}}</script>
</head>
<body class="h-full bg-pk flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-10">
            <div class="w-16 h-16 bg-white/10 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <span class="font-asap text-white text-3xl font-bold">PL</span>
            </div>
            <h1 class="font-asap text-3xl font-bold text-white tracking-wider">PARK LIFE</h1>
            <p class="text-white/50 text-sm mt-1">Activación de cuenta</p>
        </div>

        <!-- Card -->
        <div class="bg-white rounded-3xl shadow-2xl p-8 text-center">
            <?php if ($result && $result['success']): ?>
                <!-- Éxito -->
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-5">
                    <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-pk mb-2">¡Cuenta activada!</h2>
                <p class="text-gray-500 mb-6">
                    Bienvenido/a <strong><?= e($result['nombre'] ?? '') ?></strong>.<br>
                    Tu cuenta está lista. Ahora puedes iniciar sesión.
                </p>
                <a href="login.php" class="inline-block w-full bg-pk text-white py-3 rounded-xl font-semibold hover:bg-[#2C3A5E] transition-all">
                    Iniciar sesión
                </a>

            <?php else: ?>
                <!-- Error -->
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-5">
                    <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-pk mb-2">Error de activación</h2>
                <p class="text-gray-500 mb-6"><?= e($result['error'] ?? 'Token inválido.') ?></p>
                <a href="login.php" class="inline-block w-full bg-pk text-white py-3 rounded-xl font-semibold hover:bg-[#2C3A5E] transition-all">
                    Ir al login
                </a>
            <?php endif; ?>
        </div>

        <p class="text-center text-white/30 text-xs mt-6">Park Life Properties © <?= date('Y') ?></p>
    </div>
</body>
</html>
