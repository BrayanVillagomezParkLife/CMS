<?php
declare(strict_types=1);

/**
 * ============================================================
 * ZohoService.php
 * Encapsula toda la integración con Zoho CRM
 *
 * Lógica heredada de _php.php:
 * - Token via refresh_token
 * - Buscar lead por email (no duplicar)
 * - Crear lead nuevo (con owner según propiedad)
 * - Actualizar lead existente (agregar nuevo interés)
 * - Obtener owner asignado por round-robin
 * - Resolver asesor desde tabla `reps`
 *
 * Reglas de asignación:
 *   - Querétaro   → Karla (ID fijo)
 *   - CDMX / GDL  → round-robin automático de Zoho
 * ============================================================
 */

class ZohoService
{
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    private string $orgId;

    private const API_BASE    = 'https://www.zohoapis.com/crm/v2';
    private const TOKEN_URL   = 'https://accounts.zoho.com/oauth/v2/token';
    private const LOG_FILE    = __DIR__ . '/../logs/zoho.log';

    // ID fijo de Karla para Querétaro (sincronizado con _php.php)
    private const KARLA_ZOHO_ID = '5993209000013421001';

    public function __construct()
    {
        $this->clientId     = ZOHO_CLIENT_ID;
        $this->clientSecret = ZOHO_CLIENT_SECRET;
        $this->refreshToken = ZOHO_REFRESH_TOKEN;
        $this->orgId        = cfg('zoho_org_id', '832957970');
    }

    // ─────────────────────────────────────────────────────────────
    // API PÚBLICA
    // ─────────────────────────────────────────────────────────────

    /**
     * Sincronizar lead: crea si no existe, actualiza si ya existe.
     * Retorna datos del owner asignado para WhatsApp + email.
     */
    public function syncLead(
            string $firstName,
            string $lastName,
            string $email,
            string $phone,
            string $propiedadNombre,
            string $propiedadSlug = '',
            string $tipoLead      = 'meses',
            string $duracionLabel = '',
            array  $utmData       = []
        ): array {
        $this->log("syncLead INICIADO para: $email | propiedad: $propiedadNombre");

        // 1. Obtener token
        $token = $this->getAccessToken();
        if (!$token) {
            return $this->fallback('No se pudo obtener token de Zoho');
        }

        $zohoNombre = $this->mapPropertyName($propiedadNombre, $propiedadSlug);
        $this->log("Propiedad mapeada: $propiedadNombre → $zohoNombre");

        // 2. Buscar si el lead ya existe
        [$exists, $existingLead, $zohoLeadId] = $this->searchLeadByEmail($token, $email);

        // 3. Determinar owner (solo Querétaro se fuerza)
        $ownerId = $this->resolveOwnerId($propiedadNombre, $propiedadSlug);

        // 4. Crear o actualizar
        if (!$exists) {
            $zohoLeadId = $this->createLead(
                $token, $firstName, $lastName, $email, $phone,
                $propiedadNombre, $zohoNombre, $ownerId, $tipoLead, $duracionLabel, $utmData
            );
            if (!$zohoLeadId) {
                return $this->fallback('No se pudo crear lead en Zoho');
            }
            $this->log("Lead CREADO: $zohoLeadId");
            // Esperar a que Zoho procese el round-robin
            sleep(3);
        } else {
            $this->updateLeadInterest($token, $zohoLeadId, $existingLead, $propiedadNombre, $zohoNombre, $tipoLead, $duracionLabel, $utmData);
            $this->log("Lead ACTUALIZADO: $zohoLeadId");
        }

        // 5. Obtener datos frescos del lead (con owner ya asignado)
        $lead = $exists ? $existingLead : $this->fetchLeadByEmail($token, $email);
        if (!$lead) {
            return $this->fallback('Lead no encontrado tras sync');
        }

        // 6. Resolver datos del asesor desde BD local
        $ownerZohoId = $lead['Owner']['id']    ?? null;
        $ownerName   = $lead['Owner']['name']  ?? 'Equipo Park Life';
        $ownerEmail  = $lead['Owner']['email'] ?? '';
        $ownerData   = $this->resolveRepFromDB($ownerZohoId, $ownerName);

        $this->log("COMPLETADO — owner: {$ownerData['name']} | wa: {$ownerData['whatsapp']}");

        return [
            'success'      => true,
            'zoho_lead_id' => $zohoLeadId,
            'owner_id'     => $ownerZohoId,
            'owner_name'   => $ownerData['name'],
            'owner_email'  => $ownerData['email'] ?: $ownerEmail,
            'owner_phone'  => $ownerData['phone'],
            'owner_whatsapp' => $ownerData['whatsapp'],
            'was_existing' => $exists,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────

    private function getAccessToken(): ?string
    {
        $response = $this->curlPost(self::TOKEN_URL, http_build_query([
            'refresh_token' => $this->refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'refresh_token',
        ]), [], false);

        if (!$response['ok']) {
            $this->log("Error token HTTP {$response['code']}: {$response['body']}");
            return null;
        }

        $data = json_decode($response['body'], true);
        return $data['access_token'] ?? null;
    }

    private function searchLeadByEmail(string $token, string $email): array
    {
        $response = $this->curlGet(
            self::API_BASE . '/Leads/search?email=' . urlencode($email),
            $token
        );

        if ($response['code'] === 200) {
            $data = json_decode($response['body'], true);
            if (!empty($data['data'])) {
                $lead = $data['data'][0];
                return [true, $lead, $lead['id']];
            }
        }

        return [false, null, null];
    }

    private function fetchLeadByEmail(string $token, string $email): ?array
    {
        $response = $this->curlGet(
            self::API_BASE . '/Leads/search?email=' . urlencode($email),
            $token
        );

        if ($response['code'] === 200) {
            $data = json_decode($response['body'], true);
            return $data['data'][0] ?? null;
        }
        return null;
    }

    private function createLead(
        string $token,
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        string $propNombre,
        string $zohoNombre,
        ?string $ownerId,
        string $tipoLead,
        string $duracionLabel = '',
        array  $utmData       = []
    ): ?string {
        $payload = [
            'First_Name'               => $firstName,
            'Last_Name'                => $lastName ?: '-',
            'Email'                    => $email,
            'Phone'                    => $phone,
            'Company'                  => 'Park Life',
            'Description'              => 'Lead del sitio web - Tipo: ' . $tipoLead . ($duracionLabel ? ' - Duración: ' . $duracionLabel : '') . ' - Propiedad: ' . $propNombre . (!empty($utmData['amueblado']) ? ' - Amueblado: Sí' : '') . (!empty($utmData['mascota']) ? ' - Mascota: Sí' : ''),
            'Lead_Source'              => 'Sitio Web',
            'Desarrollo_de_Inter_s'    => $zohoNombre,
        ];
        
        // UTM → Social Lead ID en Zoho
        $campaignId = $utmData['utm_id'] ?? $utmData['utm_campaign'] ?? '';
        if ($campaignId) {
            $payload['leadchain0__Social_Lead_ID'] = $campaignId;
            $this->log("UTM Campaign ID → Social_Lead_ID: {$campaignId}");
        }

        // Enriquecer Description con UTMs completos
        $utmParts = [];
        if (!empty($utmData['utm_source']))   $utmParts[] = 'Source: ' . $utmData['utm_source'];
        if (!empty($utmData['utm_medium']))   $utmParts[] = 'Medium: ' . $utmData['utm_medium'];
        if (!empty($utmData['utm_campaign'])) $utmParts[] = 'Campaign: ' . $utmData['utm_campaign'];
        if (!empty($utmData['utm_content']))  $utmParts[] = 'Content: ' . $utmData['utm_content'];
        if ($utmParts) {
            $payload['Description'] .= "\n\n--- UTM ---\n" . implode(' | ', $utmParts);
        }

        // Solo forzar owner para Querétaro; CDMX/GDL usan round-robin
        if ($ownerId) {
            $payload['Owner'] = ['id' => $ownerId];
            $this->log("Owner forzado: $ownerId (Querétaro)");
        } else {
            $this->log("Sin owner forzado → round-robin Zoho");
        }

        $response = $this->curlPost(
            self::API_BASE . '/Leads',
            json_encode(['data' => [$payload]]),
            ["Authorization: Zoho-oauthtoken $token", 'Content-Type: application/json']
        );

        if (!$response['ok'] && $response['code'] !== 201) {
            $this->log("Error creando lead HTTP {$response['code']}: {$response['body']}");
            return null;
        }

        $data = json_decode($response['body'], true);
        return $data['data'][0]['details']['id'] ?? null;
    }

    private function updateLeadInterest(
        string $token,
        string $leadId,
        array  $existingLead,
        string $propNombre,
        string $zohoNombre,
        string $tipoLead      = '',
        string $duracionLabel = '',
        array  $utmData       = []
    ): void {
        $lines = [];
        $lines[] = "\n\n--- NUEVO INTERÉS (" . date('Y-m-d H:i:s') . ") ---";
        $lines[] = "Propiedad: $propNombre";
        if ($tipoLead)      $lines[] = "Tipo: $tipoLead";
        if (!empty($utmData['amueblado'])) $lines[] = "Amueblado: Sí";
        if (!empty($utmData['mascota']))   $lines[] = "Mascota: Sí";

        // UTMs
        $utmParts = [];
        if (!empty($utmData['utm_source']))   $utmParts[] = 'Source: ' . $utmData['utm_source'];
        if (!empty($utmData['utm_medium']))   $utmParts[] = 'Medium: ' . $utmData['utm_medium'];
        if (!empty($utmData['utm_campaign'])) $utmParts[] = 'Campaign: ' . $utmData['utm_campaign'];
        if ($utmParts) $lines[] = "UTM: " . implode(' | ', $utmParts);

        $newInterest = implode("\n", $lines);
        $currentDesc = $existingLead['Description'] ?? '';
        $updatedDesc = $currentDesc . $newInterest;

        $response = $this->curlPut(
            self::API_BASE . '/Leads/' . $leadId,
            json_encode(['data' => [[
                'Description'           => $updatedDesc,
                'Desarrollo_de_Inter_s' => $zohoNombre,
            ]]]),
            ["Authorization: Zoho-oauthtoken $token", 'Content-Type: application/json']
        );

        if ($response['ok'] || $response['code'] === 202) {
            $this->log("Lead $leadId actualizado correctamente");
        } else {
            $this->log("Advertencia: no se pudo actualizar lead $leadId (HTTP {$response['code']})");
        }
    }

    private function resolveOwnerId(string $propNombre, string $propSlug): ?string
    {
        $isQueretaro = stripos($propNombre, 'queretaro') !== false
                    || stripos($propNombre, 'querétaro') !== false
                    || stripos($propSlug,   'queretaro') !== false;

        return $isQueretaro ? self::KARLA_ZOHO_ID : null;
    }

    private function resolveRepFromDB(?string $ownerZohoId, string $fallbackName): array
    {
        $defaults = [
            'name'     => $fallbackName,
            'email'    => EMAIL_ADMIN,
            'phone'    => FALLBACK_WHATSAPP,
            'whatsapp' => FALLBACK_WHATSAPP,
        ];

        if (!$ownerZohoId) return $defaults;

        try {
            $row = dbFetchOne(
                "SELECT nombre, email, telefono_whatsapp FROM reps WHERE user_zoho = ? LIMIT 1",
                [$ownerZohoId]
            );
            if ($row) {
                return [
                    'name'     => $row['nombre']            ?? $fallbackName,
                    'email'    => $row['email']              ?? EMAIL_ADMIN,
                    'phone'    => $row['telefono_whatsapp']  ?? FALLBACK_WHATSAPP,
                    'whatsapp' => $row['telefono_whatsapp']  ?? FALLBACK_WHATSAPP,
                ];
            }
        } catch (Throwable $e) {
            $this->log("Error resolviendo rep: " . $e->getMessage());
        }

        return $defaults;
    }

    /**
     * Mapear nombre de propiedad al valor exacto del campo Zoho
     * Driven by BD: si la propiedad tiene un campo zoho_nombre lo usa.
     * Fallback al mapping heredado del legacy.
     */
    private function mapPropertyName(string $propNombre, string $propSlug): string
    {
        // Intentar desde BD primero
        try {
            $row = dbFetchOne(
                "SELECT zoho_nombre FROM propiedades WHERE slug = ? OR nombre = ? LIMIT 1",
                [$propSlug, $propNombre]
            );
            if (!empty($row['zoho_nombre'])) return $row['zoho_nombre'];
        } catch (Throwable) {}

        // Fallback al mapping heredado del legacy (conservado exactamente)
        $mapping = [
            'Guadalajara'       => 'PARK LIFE GUADALAJARA',
            'Condesa'           => 'PARK LIFE CONDESA',
            'Centro Sur'        => 'PARK LIFE QUERÉTARO',
            'Polanco'           => 'PARK LIFE PARADOX',
            'Santa Fe Paradox'  => 'PARK LIFE PARADOX',
            'santafe-paradox'   => 'PARK LIFE PARADOX',
            'Santa Fe Siroco'   => 'PARK LIFE SANTA FE',
            'santafe-sirocco'   => 'PARK LIFE SANTA FE',
            'Querétaro'         => 'PARK LIFE QUERÉTARO',
            'queretaro'         => 'PARK LIFE QUERÉTARO',
            'Masaryk'           => 'PARK LIFE MASARYK',
            'Lamartine'         => 'PARK LIFE LAMARTINE',
        ];

        $clean = trim($propNombre);
        if (isset($mapping[$clean])) return $mapping[$clean];
        foreach ($mapping as $key => $val) {
            if (stripos($clean, $key) !== false || stripos($key, $clean) !== false) return $val;
        }
        // Probar con slug
        if (isset($mapping[$propSlug])) return $mapping[$propSlug];
        foreach ($mapping as $key => $val) {
            if (stripos($propSlug, $key) !== false) return $val;
        }

        return 'PARK LIFE ' . strtoupper($clean);
    }

    // ─────────────────────────────────────────────────────────────
    // FALLBACK CUANDO ZOHO FALLA
    // ─────────────────────────────────────────────────────────────

    private function fallback(string $reason): array
    {
        $this->log("FALLBACK activado: $reason");
        return [
            'success'        => false,
            'error'          => $reason,
            'zoho_lead_id'   => null,
            'owner_id'       => null,
            'owner_name'     => 'Equipo Park Life (Fallback)',
            'owner_email'    => EMAIL_ADMIN,
            'owner_phone'    => FALLBACK_WHATSAPP,
            'owner_whatsapp' => FALLBACK_WHATSAPP,
            'was_existing'   => false,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // HTTP HELPERS
    // ─────────────────────────────────────────────────────────────

    private function curlGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Authorization: Zoho-oauthtoken $token"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER  => APP_ENV !== 'development' ? true : false,
            CURLOPT_SSL_VERIFYHOST  => APP_ENV !== 'development' ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'body' => $body];
    }

    private function curlPost(string $url, string $body, array $headers = [], bool $json = true): array
    {
        if ($json && empty($headers)) {
            $headers = ['Content-Type: application/json'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER  => APP_ENV !== 'development' ? true : false,
            CURLOPT_SSL_VERIFYHOST  => APP_ENV !== 'development' ? 2 : 0,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'body' => $resp];
    }

    private function curlPut(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER  => APP_ENV !== 'development' ? true : false,
            CURLOPT_SSL_VERIFYHOST  => APP_ENV !== 'development' ? 2 : 0,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'body' => $resp];
    }

    private function log(string $msg): void
    {
        $dir = dirname(self::LOG_FILE);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(self::LOG_FILE, date('Y-m-d H:i:s') . " [Zoho] $msg\n", FILE_APPEND);
    }
}