<?php
declare(strict_types=1);

/**
 * ============================================================
 * WhatsAppService.php
 * Envío de notificaciones WhatsApp via Facebook Business API
 *
 * Template: avisacomerciales3 (9 parámetros en body)
 * Parámetros:
 *   1. Nombre cliente
 *   2. Email cliente
 *   3. Teléfono cliente
 *   4. Nombre propiedad
 *   5. Fuente (ej: "Sitio Web")
 *   6. Fecha/hora formateada
 *   7. Nombre del asesor asignado
 *   8. URL del lead en Zoho
 *   9. Comentarios del cliente
 *
 * Destinatarios:
 *   - Asesor asignado (si es diferente de Brayan/Ricardo)
 *   - SIEMPRE: Brayan, Ricardo, Cayetano (config)
 * ============================================================
 */

class WhatsAppService
{
    private string $token;
    private string $phoneId;
    private string $template;
    private string $orgId;

    private const API_URL  = 'https://graph.facebook.com/v21.0';
    private const LOG_FILE = __DIR__ . '/../logs/whatsapp.log';

    public function __construct()
    {
        $this->token    = WA_TOKEN;
        $this->phoneId  = WA_PHONE_ID;
        $this->template = WA_TEMPLATE;
        $this->orgId    = cfg('zoho_org_id', '832957970');
    }

    // ─────────────────────────────────────────────────────────────
    // API PÚBLICA
    // ─────────────────────────────────────────────────────────────

    /**
     * Notificación principal: nuevo lead recibido.
     */
    public function notifyNewLead(
        string $clientName,
        string $clientEmail,
        string $clientPhone,
        string $propiedadNombre,
        string $ownerName,
        ?string $zohoLeadId,
        string $comentarios  = '',
        string $ownerWhatsapp = '',
        string $fuente        = 'Sitio Web'
    ): bool {
        $this->log("notifyNewLead | cliente: $clientName | propiedad: $propiedadNombre");

        $fecha     = date('d-M-Y H:i:s');
        $zohoUrl   = $zohoLeadId
            ? "https://crm.zoho.com/crm/org{$this->orgId}/tab/Leads/{$zohoLeadId}"
            : 'https://crm.zoho.com';

        $comentariosClean = $this->cleanText($comentarios ?: 'Sin comentarios');

        $params = [
            $clientName,
            $clientEmail,
            $clientPhone,
            $propiedadNombre,
            $fuente,
            $fecha,
            $ownerName,
            $zohoUrl,
            $comentariosClean,
        ];

        // Construir lista de destinatarios sin duplicados
        $numeros = $this->buildRecipientList($ownerWhatsapp);

        $enviados = 0;
        foreach ($numeros as $numero) {
            $ok = $this->sendTemplate($numero, $params);
            if ($ok) $enviados++;
        }

        $this->log("Enviados: $enviados / " . count($numeros));
        return $enviados > 0;
    }

    /**
     * Alerta de error silencioso: fallo en Zoho u otro servicio.
     * Sólo se envía a Brayan (texto libre, no template).
     */
    public function notifyError(
        string $clientName,
        string $clientEmail,
        string $clientPhone,
        string $propiedadNombre,
        string $error,
        int    $localLeadId
    ): void {
        $text = "⚠️ ERROR ZOHO ⚠️\n\n"
              . "👤 Cliente: $clientName\n"
              . "📧 Email: $clientEmail\n"
              . "📞 Tel: $clientPhone\n"
              . "🏠 Propiedad: $propiedadNombre\n"
              . "❌ Error: $error\n\n"
              . "Lead ID local: $localLeadId\n"
              . "Asignado temporalmente a ti.";

        $this->sendText(FALLBACK_WHATSAPP, $text);
        $this->log("Alerta error enviada a Brayan — lead $localLeadId");
    }

    // ─────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────

    private function buildRecipientList(string $ownerWhatsapp): array
    {
        $list = [];

        // 1. Asesor asignado siempre va primero
        if ($ownerWhatsapp) {
            $tel = preg_replace('/[^0-9]/', '', $ownerWhatsapp);
            if ($tel) $list[] = $tel;
        }

        // 2. Jefes activos — siempre en copia
        try {
            $jefes = dbFetchAll("SELECT telefono FROM notif_jefes WHERE activo = 1 AND telefono IS NOT NULL AND telefono != ''");
            foreach ($jefes as $j) {
                $tel = preg_replace('/[^0-9]/', '', $j['telefono']);
                if ($tel) $list[] = $tel;
            }
        } catch (\Throwable $e) {
            // Tabla aún no existe o error — fallback a Brayan
            $list[] = FALLBACK_WHATSAPP;
            $this->log("⚠ Error leyendo notif_jefes: " . $e->getMessage());
        }

        return array_unique(array_filter($list));
    }

    private function sendTemplate(string $numero, array $params): bool
    {
        $components = [[
            'type'       => 'body',
            'parameters' => array_map(
                fn($p) => ['type' => 'text', 'text' => (string)$p],
                $params
            ),
        ]];

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $numero,
            'type'              => 'template',
            'template'          => [
                'name'       => $this->template,
                'language'   => ['code' => 'es_MX'],
                'components' => $components,
            ],
        ];

        $response = $this->curlPost(json_encode($payload));

        if ($response['code'] === 200) {
            $data = json_decode($response['body'], true);
            if (!empty($data['messages'])) {
                $this->log("✅ Template enviado a $numero");
                return true;
            }
        }

        $this->log("❌ Fallo template a $numero — HTTP {$response['code']}: {$response['body']}");
        return false;
    }

    private function sendText(string $numero, string $text): bool
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $numero,
            'type'              => 'text',
            'text'              => ['body' => $text],
        ];

        $response = $this->curlPost(json_encode($payload));
        $ok = $response['code'] === 200;
        $this->log($ok ? "✅ Texto enviado a $numero" : "❌ Fallo texto a $numero HTTP {$response['code']}");
        return $ok;
    }

    private function curlPost(string $jsonBody): array
    {
        $url = self::API_URL . "/{$this->phoneId}/messages";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $jsonBody,
            CURLOPT_HTTPHEADER      => [
                "Authorization: Bearer {$this->token}",
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_CONNECTTIMEOUT  => 10,
            // SSL bypass para desarrollo local
            CURLOPT_SSL_VERIFYPEER  => APP_ENV === 'production',
            CURLOPT_SSL_VERIFYHOST  => APP_ENV === 'production' ? 2 : 0,
        ]);
        $body     = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        $curlErrN = curl_errno($ch);
        curl_close($ch);

        if ($curlErrN) {
            $this->log("cURL error #$curlErrN: $curlErr | URL: $url");
        }

        return ['code' => $code, 'body' => $body ?: ''];
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/[\r\n\t]+/', ' ', $text);
        $text = preg_replace('/\s{4,}/', '   ', $text);
        return trim($text);
    }

    private function log(string $msg): void
    {
        $dir = dirname(self::LOG_FILE);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(self::LOG_FILE, date('Y-m-d H:i:s') . " [WA] $msg\n", FILE_APPEND);
    }
}