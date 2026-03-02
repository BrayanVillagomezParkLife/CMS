<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Si ya está logueado → dashboard
if (isLoggedIn()) {
    header('Location: index.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitizeEmail($_POST['email']    ?? '');
    $password = $_POST['password'] ?? '';

    $result = attemptLogin($email, $password);
    if ($result['success']) {
        header('Location: index.php'); exit;
    }
    $error = $result['message'] ?? 'Credenciales incorrectas.';
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Park Life Properties</title>
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
            <p class="text-white/50 text-sm mt-1">Panel de Administración</p>
        </div>

        <!-- Card -->
        <div class="bg-white rounded-3xl shadow-2xl p-8">
            <h2 class="text-xl font-bold text-pk mb-6">Iniciar Sesión</h2>

            <?php if ($error): ?>
            <div class="flex items-center gap-2 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">
                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                <?= e($error) ?>
            </div>
            <?php endif; ?>

            <form method="post" class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
                    <input type="email" name="email" required autofocus
                           value="<?= e($_POST['email'] ?? '') ?>"
                           placeholder="admin@parklife.mx"
                           class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-pk/30 focus:border-pk transition-all">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Contraseña</label>
                    <div class="relative">
                        <input type="password" name="password" id="pwd" required
                               placeholder="••••••••"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-pk/30 focus:border-pk transition-all pr-12">
                        <button type="button" onclick="togglePwd()" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-pk">
                            <svg id="eye-icon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit"
                        class="w-full bg-pk text-white py-3 rounded-xl font-semibold hover:bg-pk-light transition-all">
                    Entrar al panel
                </button>
            </form>
        </div>

        <p class="text-center text-white/30 text-xs mt-6">Park Life Properties © <?= date('Y') ?></p>
    </div>
    <script>
    function togglePwd() {
        const pwd = document.getElementById('pwd');
        pwd.type = pwd.type === 'password' ? 'text' : 'password';
    }
    </script>
</body>
</html>
