<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * ============================================================
 * MailService.php
 * Wrapper sobre PHPMailer para Park Life
 * Soporta: email de notificación interna + email al cliente
 * ============================================================
 */
class MailService
{
    private const LOG_FILE = __DIR__ . '/../logs/mail.log';

    // ─────────────────────────────────────────────────────────────
    // API PÚBLICA
    // ─────────────────────────────────────────────────────────────

    /**
     * Notificación interna al equipo cuando entra un nuevo lead.
     */
    public function sendLeadNotification(array $lead, string $ownerEmail, string $ownerName): bool
    {
        $subject = '[Park Life] Nuevo lead: ' . ($lead['nombre'] ?? '') . ' — ' . ($lead['propiedad_nombre'] ?? 'General');

        $body = $this->buildLeadHtml($lead, $ownerName);

        return $this->send(
            to:        EMAIL_ADMIN,
            toName:    'Equipo Park Life',
            subject:   $subject,
            body:      $body,
            cc:        $ownerEmail ?: null,
            ccName:    $ownerName,
            replyTo:   $lead['email']  ?? null,
            replyName: ($lead['nombre'] ?? '') . ' ' . ($lead['apellido'] ?? ''),
        );
    }

    /**
     * Confirmación al cliente de que recibimos su solicitud.
     */
    public function sendClientConfirmation(array $lead): bool
    {
        $clientName = trim(($lead['nombre'] ?? '') . ' ' . ($lead['apellido'] ?? ''));
        $subject    = 'Park Life Properties — Recibimos tu solicitud';
        $body       = $this->buildClientConfirmHtml($lead, $clientName);

        return $this->send(
            to:      $lead['email'],
            toName:  $clientName,
            subject: $subject,
            body:    $body,
        );
    }

    /**
     * Notificación de contacto general (sección contacto home).
     */
    public function sendContactNotification(array $data): bool
    {
        $subject = '[Park Life] Contacto web: ' . ($data['nombre'] ?? '') . ' ' . ($data['apellido'] ?? '');
        $body    = $this->buildContactHtml($data);

        return $this->send(
            to:        EMAIL_ADMIN,
            toName:    'Equipo Park Life',
            subject:   $subject,
            body:      $body,
            replyTo:   $data['email']  ?? null,
            replyName: ($data['nombre'] ?? '') . ' ' . ($data['apellido'] ?? ''),
        );
    }

    /**
     * Notificación de bolsa de trabajo.
     */
    public function sendJobApplication(array $data): bool
    {
        $subject = '[Park Life] Solicitud de trabajo: ' . ($data['nombre'] ?? '') . ' — ' . ($data['posicion'] ?? 'Espontánea');
        $body    = $this->buildJobHtml($data);

        return $this->send(
            to:        EMAIL_ADMIN,
            toName:    'Equipo Park Life',
            subject:   $subject,
            body:      $body,
            replyTo:   $data['email']  ?? null,
            replyName: $data['nombre'] ?? '',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // CORE SEND
    // ─────────────────────────────────────────────────────────────

    public function send(
        string  $to,
        string  $toName,
        string  $subject,
        string  $body,
        ?string $cc       = null,
        string  $ccName   = '',
        ?string $replyTo  = null,
        string  $replyName = ''
    ): bool {
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet   = 'UTF-8';
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            $mail->SMTPDebug  = 0;

            $mail->setFrom(EMAIL_NOREPLY, cfg('brand_name', 'Park Life Properties'));
            $mail->addAddress($to, $toName);

            if ($cc) $mail->addCC($cc, $ccName);
            if (BCC_EMAIL) $mail->addBCC(BCC_EMAIL);
            if ($replyTo) $mail->addReplyTo($replyTo, $replyName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

            $mail->send();
            $this->log("✅ Email enviado a $to — $subject");
            return true;

        } catch (MailException $e) {
            $this->log("❌ PHPMailer error: " . $e->getMessage() . " | to: $to");
            return false;
        } catch (Throwable $e) {
            $this->log("❌ Exception: " . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // HTML BUILDERS
    // ─────────────────────────────────────────────────────────────

    private function buildLeadHtml(array $lead, string $ownerName): string
    {
        $prop     = htmlspecialchars($lead['propiedad_nombre'] ?? 'General', ENT_QUOTES);
        $nombre   = htmlspecialchars(($lead['nombre'] ?? '') . ' ' . ($lead['apellido'] ?? ''), ENT_QUOTES);
        $email    = htmlspecialchars($lead['email'] ?? '', ENT_QUOTES);
        $tel      = htmlspecialchars($lead['telefono'] ?? '', ENT_QUOTES);
        $tipo     = htmlspecialchars(ucfirst($lead['tipo'] ?? ''), ENT_QUOTES);
        $msg      = nl2br(htmlspecialchars($lead['comentarios'] ?? $lead['mensaje'] ?? '', ENT_QUOTES));
        $mascota  = !empty($lead['mascota'])   ? '🐾 Sí' : 'No';
        $muebles  = !empty($lead['amueblado']) ? '✅ Sí' : 'No';
        $asesor   = htmlspecialchars($ownerName, ENT_QUOTES);
        $fecha    = date('d/m/Y H:i');

        return "
<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><style>
  body{font-family:Arial,sans-serif;background:#f5f5f5;color:#333;margin:0;padding:0}
  .wrap{max-width:600px;margin:20px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1)}
  .hdr{background:#202944;padding:24px;text-align:center}
  .hdr h1{color:#BAC4B9;margin:0;font-size:22px;letter-spacing:3px}
  .hdr p{color:#fff;margin:6px 0 0;font-size:13px}
  .body{padding:32px}
  .row{display:flex;margin-bottom:12px;border-bottom:1px solid #f3f3f3;padding-bottom:12px}
  .label{font-weight:bold;color:#202944;width:140px;flex-shrink:0;font-size:13px}
  .val{color:#555;font-size:13px}
  .tag{display:inline-block;background:#BAC4B9;color:#202944;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:bold}
  .cta{display:block;background:#202944;color:#BAC4B9;text-decoration:none;text-align:center;padding:14px;border-radius:8px;margin-top:20px;font-weight:bold;font-size:14px}
  .ftr{background:#f9f9f9;padding:16px;text-align:center;font-size:11px;color:#aaa}
</style></head>
<body>
<div class='wrap'>
  <div class='hdr'><h1>PARK LIFE</h1><p>Nuevo lead recibido — $fecha</p></div>
  <div class='body'>
    <span class='tag'>$tipo</span>
    <h2 style='color:#202944;margin:16px 0 20px'>$nombre</h2>
    <div class='row'><span class='label'>📧 Email</span><span class='val'>$email</span></div>
    <div class='row'><span class='label'>📞 Teléfono</span><span class='val'>$tel</span></div>
    <div class='row'><span class='label'>🏠 Propiedad</span><span class='val'>$prop</span></div>
    <div class='row'><span class='label'>🐾 Mascota</span><span class='val'>$mascota</span></div>
    <div class='row'><span class='label'>🪑 Amueblado</span><span class='val'>$muebles</span></div>
    <div class='row'><span class='label'>👤 Asesor</span><span class='val'>$asesor</span></div>
    " . ($msg ? "<div class='row'><span class='label'>💬 Mensaje</span><span class='val'>$msg</span></div>" : '') . "
    " . ($lead['zoho_lead_id'] ? "<a href='https://crm.zoho.com/crm/org832957970/tab/Leads/{$lead['zoho_lead_id']}' class='cta'>Ver en Zoho CRM →</a>" : '') . "
  </div>
  <div class='ftr'>Park Life Properties &copy; " . date('Y') . "</div>
</div>
</body></html>";
    }

    private function buildClientConfirmHtml(array $lead, string $clientName): string
    {
        $nombre = htmlspecialchars($clientName, ENT_QUOTES);
        $prop   = htmlspecialchars($lead['propiedad_nombre'] ?? 'nuestras propiedades', ENT_QUOTES);

        return "
<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><style>
  body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:0}
  .wrap{max-width:560px;margin:20px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)}
  .hdr{background:#202944;padding:32px;text-align:center}
  .hdr h1{color:#BAC4B9;margin:0;letter-spacing:4px}
  .body{padding:40px 32px}
  .body h2{color:#202944}
  .body p{color:#555;line-height:1.7}
  .cta{display:block;background:#202944;color:#BAC4B9;text-decoration:none;text-align:center;padding:16px;border-radius:8px;margin:24px 0;font-weight:bold;font-size:15px}
  .ftr{background:#f9f9f9;padding:16px;text-align:center;font-size:11px;color:#aaa}
</style></head>
<body>
<div class='wrap'>
  <div class='hdr'><h1>PARK LIFE</h1></div>
  <div class='body'>
    <h2>¡Hola, $nombre!</h2>
    <p>Recibimos tu solicitud sobre <strong>$prop</strong>. Nuestro equipo la revisará y te contactará a la brevedad, normalmente en menos de 2 horas durante nuestro horario de atención.</p>
    <p><strong>Horario:</strong> Lunes a Domingo · 8:00 a 22:00 hrs</p>
    <a href='https://parklife.mx' class='cta'>Ver todas nuestras propiedades →</a>
    <p style='font-size:12px;color:#999'>Si tienes alguna pregunta urgente puedes escribirnos a <a href='mailto:" . EMAIL_INFO . "' style='color:#202944'>" . EMAIL_INFO . "</a></p>
  </div>
  <div class='ftr'>Park Life Properties &copy; " . date('Y') . " — <a href='https://parklife.mx/legal' style='color:#aaa'>Privacidad</a></div>
</div>
</body></html>";
    }

    private function buildContactHtml(array $data): string
    {
        $nombre   = htmlspecialchars(($data['nombre'] ?? '') . ' ' . ($data['apellido'] ?? ''), ENT_QUOTES);
        $email    = htmlspecialchars($data['email'] ?? '', ENT_QUOTES);
        $tel      = htmlspecialchars($data['telefono'] ?? '', ENT_QUOTES);
        $prop     = htmlspecialchars($data['propiedad_interes'] ?? 'General', ENT_QUOTES);
        $estancia = htmlspecialchars($data['tipo_estancia'] ?? '', ENT_QUOTES);
        $msg      = nl2br(htmlspecialchars($data['mensaje'] ?? '', ENT_QUOTES));
        $fecha    = date('d/m/Y H:i');

        return "
<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><style>
  body{font-family:Arial,sans-serif;background:#f5f5f5;color:#333}
  .wrap{max-width:560px;margin:20px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1)}
  .hdr{background:#202944;padding:24px;text-align:center}
  .hdr h1{color:#BAC4B9;margin:0;font-size:20px;letter-spacing:3px}
  .hdr p{color:#fff;margin:4px 0 0;font-size:12px}
  .body{padding:28px}
  .row{margin-bottom:10px;border-bottom:1px solid #f3f3f3;padding-bottom:10px}
  .label{font-weight:bold;color:#202944;font-size:12px;text-transform:uppercase;letter-spacing:.5px}
  .val{color:#555;font-size:14px;margin-top:2px}
  .ftr{background:#f9f9f9;padding:12px;text-align:center;font-size:11px;color:#aaa}
</style></head>
<body>
<div class='wrap'>
  <div class='hdr'><h1>PARK LIFE</h1><p>Solicitud de contacto — $fecha</p></div>
  <div class='body'>
    <div class='row'><div class='label'>Nombre</div><div class='val'>$nombre</div></div>
    <div class='row'><div class='label'>Email</div><div class='val'>$email</div></div>
    <div class='row'><div class='label'>Teléfono</div><div class='val'>$tel</div></div>
    <div class='row'><div class='label'>Propiedad de interés</div><div class='val'>$prop</div></div>
    <div class='row'><div class='label'>Tipo de estancia</div><div class='val'>$estancia</div></div>
    " . ($msg ? "<div class='row'><div class='label'>Mensaje</div><div class='val'>$msg</div></div>" : '') . "
  </div>
  <div class='ftr'>Park Life Properties &copy; " . date('Y') . "</div>
</div>
</body></html>";
    }

    private function buildJobHtml(array $data): string
    {
        $nombre   = htmlspecialchars($data['nombre'] ?? '', ENT_QUOTES);
        $email    = htmlspecialchars($data['email'] ?? '', ENT_QUOTES);
        $tel      = htmlspecialchars($data['telefono'] ?? '', ENT_QUOTES);
        $posicion = htmlspecialchars($data['posicion'] ?? 'Espontánea', ENT_QUOTES);
        $msg      = nl2br(htmlspecialchars($data['mensaje'] ?? '', ENT_QUOTES));
        $fecha    = date('d/m/Y H:i');

        return "
<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><style>
  body{font-family:Arial,sans-serif;background:#f5f5f5;color:#333}
  .wrap{max-width:560px;margin:20px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1)}
  .hdr{background:#202944;padding:24px;text-align:center}
  .hdr h1{color:#BAC4B9;margin:0;font-size:20px;letter-spacing:3px}
  .hdr p{color:#fff;margin:4px 0 0;font-size:12px}
  .body{padding:28px}
  .row{margin-bottom:10px;border-bottom:1px solid #f3f3f3;padding-bottom:10px}
  .label{font-weight:bold;color:#202944;font-size:12px;text-transform:uppercase}
  .val{color:#555;font-size:14px;margin-top:2px}
  .tag{display:inline-block;background:#BAC4B9;color:#202944;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:bold;margin-bottom:16px}
  .ftr{background:#f9f9f9;padding:12px;text-align:center;font-size:11px;color:#aaa}
</style></head>
<body>
<div class='wrap'>
  <div class='hdr'><h1>PARK LIFE</h1><p>Solicitud de trabajo — $fecha</p></div>
  <div class='body'>
    <span class='tag'>$posicion</span>
    <div class='row'><div class='label'>Nombre</div><div class='val'>$nombre</div></div>
    <div class='row'><div class='label'>Email</div><div class='val'>$email</div></div>
    <div class='row'><div class='label'>Teléfono</div><div class='val'>$tel</div></div>
    " . ($msg ? "<div class='row'><div class='label'>Sobre el candidato</div><div class='val'>$msg</div></div>" : '') . "
  </div>
  <div class='ftr'>Park Life Properties &copy; " . date('Y') . "</div>
</div>
</body></html>";
    }

    private function log(string $msg): void
    {
        $dir = dirname(self::LOG_FILE);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(self::LOG_FILE, date('Y-m-d H:i:s') . " [Mail] $msg\n", FILE_APPEND);
    }
}
