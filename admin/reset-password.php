<?php
declare(strict_types=1);
/**
 * admin/reset-password.php
 * Página pública (sin login) para:
 *   1. Solicitar un reset de contraseña (envía email con link)
 *   2. Resetear la contraseña con token válido
 *
 * URL solicitar: /admin/reset-password.php
 * URL resetear:  /admin/reset-password.php?t={token}
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$token   = $_GET['t'] ?? '';
$message = '';
$msgType = '';
$step    = $token ? 'reset' : 'request';

// ═══ POST: Solicitar reset ═══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_reset') {
    $email = sanitizeEmail($_POST['email'] ?? '');

    if ($email) {
        $result = generatePasswordResetToken($email);

        if (!empty($result['token'])) {
            $resetUrl = BASE_URL . '/admin/reset-password.php?t=' . $result['token'];

            // Enviar email
            $sent = false;
            try {
                $mailServiceFile = __DIR__ . '/../services/MailService.php';
                if (file_exists($mailServiceFile)) {
                    require_once $mailServiceFile;
                    $ms = new MailService();
                    $body = "
                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>
                        <div style='background:#202944;padding:30px;text-align:center;border-radius:12px 12px 0 0'>
                            <h1 style='color:#fff;margin:0;font-size:24px'>Park Life Properties</h1>
                        </div>
                        <div style='background:#fff;padding:30px;border:1px solid #e5e7eb;border-top:none'>
                            <h2 style='color:#202944;margin:0 0 16px'>Restablecer contraseña</h2>
                            <p style='color:#374151;line-height:1.6'>Hola " . e($result['nombre'] ?? '') . ", recibimos una solicitud para restablecer tu contraseña.</p>
                            <div style='text-align:center;margin:24px 0'>
                                <a href='{$resetUrl}'
                                   style='display:inline-block;background:#202944;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:600'>
                                    Restablecer contraseña
                                </a>
                            </div>
                            <p style='color:#9ca3af;font-size:12px'>Este enlace expira en 1 hora. Si no solicitaste esto, ignora este email.</p>
                        </div>
                    </div>";
                    $sent = $ms->send(
                        to:      $email,
                        toName:  $result['nombre'] ?? '',
                        subject: 'Restablecer contraseña - Park Life Admin',
                        body:    $body
                    );
                } else {
                    $headers  = "From: Park Life Admin <noreply@parklife.mx>\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $sent = mail($email, 'Restablecer contraseña - Park Life',
                        "<p>Haz clic aquí para restablecer tu contraseña: <a href='{$resetUrl}'>{$resetUrl}</a></p><p>Expira en 1 hora.</p>", $headers);
                }
            } catch (\Throwable $e) {}
        }
    }

    // Siempre mostrar mensaje genérico (no revelar si el email existe)
    $message = 'Si tu email está registrado, recibirás instrucciones para restablecer tu contraseña.';
    $msgType = 'info';
}

// ═══ POST: Resetear con token ════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'do_reset') {
    $tok     = $_POST['token'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($newPass !== $confirm) {
        $message = 'Las contraseñas no coinciden.';
        $msgType = 'error';
        $step = 'reset';
        $token = $tok;
    } else {
        $result = resetPasswordWithToken($tok, $newPass);
        if ($result['success']) {
            $message = '¡Contraseña actualizada! Ya puedes iniciar sesión.';
            $msgType = 'success';
            $step = 'done';
        } else {
            $message = $result['error'] ?? 'Error al restablecer.';
            $msgType = 'error';
            $step = 'reset';
            $token = $tok;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña — Park Life Admin</title>
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
            <p class="text-white/50 text-sm mt-1">Restablecer contraseña</p>
        </div>

        <!-- Card -->
        <div class="bg-white rounded-3xl shadow-2xl p-8">

            <?php if ($message): ?>
            <div class="flex items-start gap-2 mb-5 px-4 py-3 rounded-xl border text-sm
                <?php if ($msgType === 'success'): ?>bg-green-50 border-green-200 text-green-700
                <?php elseif ($msgType === 'error'): ?>bg-red-50 border-red-200 text-red-700
                <?php else: ?>bg-blue-50 border-blue-200 text-blue-700<?php endif; ?>">
                <?= e($message) ?>
            </div>
            <?php endif; ?>

            <?php if ($step === 'request'): ?>
            <!-- Paso 1: Solicitar reset -->
            <h2 class="text-xl font-bold text-pk mb-2">¿Olvidaste tu contraseña?</h2>
            <p class="text-sm text-gray-500 mb-6">Ingresa tu email y te enviaremos un enlace para restablecerla.</p>

            <form method="post" class="space-y-5">
                <input type="hidden" name="action" value="request_reset">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
                    <input type="email" name="email" required autofocus placeholder="tu@parklife.mx"
                           class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-pk/30 focus:border-pk">
                </div>
                <button type="submit" class="w-full bg-pk text-white py-3 rounded-xl font-semibold hover:bg-[#2C3A5E] transition-all">
                    Enviar enlace
                </button>
            </form>

            <?php elseif ($step === 'reset'): ?>
            <!-- Paso 2: Nueva contraseña -->
            <h2 class="text-xl font-bold text-pk mb-2">Nueva contraseña</h2>
            <p class="text-sm text-gray-500 mb-6">Ingresa tu nueva contraseña (mínimo 10 caracteres).</p>

            <form method="post" class="space-y-5">
                <input type="hidden" name="action" value="do_reset">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Nueva contraseña</label>
                    <input type="password" name="new_password" required minlength="10" placeholder="Mínimo 10 caracteres"
                           class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-pk/30 focus:border-pk">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Confirmar contraseña</label>
                    <input type="password" name="confirm_password" required minlength="10" placeholder="Repite la contraseña"
                           class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-pk/30 focus:border-pk">
                </div>
                <button type="submit" class="w-full bg-pk text-white py-3 rounded-xl font-semibold hover:bg-[#2C3A5E] transition-all">
                    Guardar nueva contraseña
                </button>
            </form>

            <?php else: ?>
            <!-- Paso 3: Éxito -->
            <div class="text-center">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-5">
                    <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <a href="login.php" class="inline-block w-full bg-pk text-white py-3 rounded-xl font-semibold hover:bg-[#2C3A5E] transition-all text-center">
                    Iniciar sesión
                </a>
            </div>
            <?php endif; ?>

            <div class="text-center mt-5">
                <a href="login.php" class="text-sm text-gray-400 hover:text-pk transition-colors">← Volver al login</a>
            </div>
        </div>

        <p class="text-center text-white/30 text-xs mt-6">Park Life Properties © <?= date('Y') ?></p>
    </div>
</body>
</html>
