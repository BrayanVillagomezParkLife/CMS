<?php
declare(strict_types=1);
/**
 * admin/api/buscar-clientes.php
 * Busca leads en Zoho CRM para autocomplete del cotizador.
 *
 * GET ?q=texto (mínimo 3 caracteres)
 * Responde JSON con array de leads encontrados.
 *
 * Filtro por agente:
 *   - Superadmin → ve todos los leads
 *   - Agente con user_zoho en tabla reps → solo sus leads (Owner.id = user_zoho)
 *   - Agente sin user_zoho → ve todos (fallback)
 *
 * v1.2 — 2026-03-04 — Filtro por owner según agente logueado
 */

require_once __DIR__ . '/../includes/middleware.php';

header('Content-Type: application/json; charset=utf-8');

// Solo usuarios logueados
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// DEBUG — BORRAR DESPUÉS
if (($_GET['q'] ?? '') === 'debug_role') {
    echo json_encode([
        'admin_role'  => $_SESSION['admin_role'] ?? 'NO EXISTE',
        'admin_email' => $_SESSION['admin_email'] ?? 'NO EXISTE',
        'admin_id'    => $_SESSION['admin_id'] ?? 'NO EXISTE',
    ]);
    exit;
}

$query = trim($_GET['q'] ?? '');

if (mb_strlen($query) < 3) {
    echo json_encode(['results' => [], 'message' => 'Mínimo 3 caracteres']);
    exit;
}

// Detectar entorno local
$isLocal = str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost');

// ═══════════════════════════════════════════════════════════════════════════
// Determinar filtro de owner según rol del agente
// ═══════════════════════════════════════════════════════════════════════════
$esSuperAdmin = function_exists('isSuperAdmin') ? isSuperAdmin() : false;
$adminEmail = $_SESSION['admin_email'] ?? '';
$filterOwnerZohoId = null; // null = sin filtro (superadmin)

if (!$esSuperAdmin) {
    if (!$adminEmail) {
        echo json_encode(['results' => [], 'error' => 'Sin sesión de usuario válida.']);
        exit;
    }
    $rep = dbFetchOne(
        "SELECT user_zoho FROM reps WHERE email = ? AND activo = 1 LIMIT 1",
        [$adminEmail]
    );
    if (!$rep || empty($rep['user_zoho'])) {
        echo json_encode(['results' => [], 'error' => 'Tu cuenta no tiene credenciales de Zoho CRM vinculadas. Contacta al administrador.']);
        exit;
    }
    $filterOwnerZohoId = $rep['user_zoho'];
}

// ═══════════════════════════════════════════════════════════════════════════
// PASO 1: Obtener token de Zoho
// ═══════════════════════════════════════════════════════════════════════════

$cacheFile = __DIR__ . '/../../logs/zoho_token_admin.cache';
$accessToken = null;

// Intentar cache primero
if (file_exists($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached && ($cached['expires_at'] ?? 0) > time()) {
        $accessToken = $cached['access_token'];
    }
}

// Si no hay cache válido, solicitar token nuevo
if (!$accessToken) {
    $tokenUrl = 'https://accounts.zoho.com/oauth/v2/token';
    $postData = http_build_query([
        'refresh_token' => ZOHO_REFRESH_TOKEN,
        'client_id'     => ZOHO_CLIENT_ID,
        'client_secret' => ZOHO_CLIENT_SECRET,
        'grant_type'    => 'refresh_token',
    ]);

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    if ($isLocal) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($resp, true);
        $accessToken = $data['access_token'] ?? null;
        if ($accessToken && !empty($data['expires_in'])) {
            @file_put_contents($cacheFile, json_encode([
                'access_token' => $accessToken,
                'expires_at'   => time() + ($data['expires_in'] - 60),
            ]));
        }
    }
}

if (!$accessToken) {
    http_response_code(503);
    echo json_encode(['error' => 'No se pudo conectar con Zoho CRM', 'results' => []]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// PASO 2: Buscar en Zoho CRM Leads
// ═══════════════════════════════════════════════════════════════════════════

// Pedimos más resultados para compensar el filtro por owner
$perPage = $filterOwnerZohoId ? 50 : 15;

$searchUrl = 'https://www.zohoapis.com/crm/v2/Leads/search?word='
           . urlencode($query)
           . '&per_page=' . $perPage;

$ch = curl_init($searchUrl);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ["Authorization: Zoho-oauthtoken {$accessToken}"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
]);
if ($isLocal) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
}
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$results = [];

if ($httpCode === 200) {
    $data = json_decode($resp, true);
    $leads = $data['data'] ?? [];

    foreach ($leads as $lead) {
        // ── Filtro por owner: si el agente tiene user_zoho, solo sus leads ──
        if ($filterOwnerZohoId) {
            $leadOwnerId = $lead['Owner']['id'] ?? '';
            if ((string)$leadOwnerId !== (string)$filterOwnerZohoId) {
                continue; // No es su lead, saltar
            }
        }

        $firstName = trim($lead['First_Name'] ?? '');
        $lastName  = trim($lead['Last_Name'] ?? '');
        $fullName  = trim("{$firstName} {$lastName}");

        // Teléfono: preferir Mobile, fallback a Phone
        $phone = $lead['Mobile'] ?? $lead['Phone'] ?? '';

        $results[] = [
            'zoho_id'  => $lead['id'] ?? '',
            'nombre'   => $fullName ?: '(Sin nombre)',
            'email'    => $lead['Email'] ?? '',
            'telefono' => $phone,
            'empresa'  => $lead['Company'] ?? '',
            'propiedad_interes' => $lead['Propiedad_de_Interes'] ?? '',
            'lead_source'       => $lead['Lead_Source'] ?? '',
            'owner'    => $lead['Owner']['name'] ?? '',
        ];

        // Máximo 15 resultados para el autocomplete
        if (count($results) >= 15) break;
    }
} elseif ($httpCode === 204) {
    // 204 = No content (sin resultados) — normal
} else {
    error_log("buscar-clientes.php: Zoho HTTP {$httpCode} - " . substr($resp ?? '', 0, 200));
}

echo json_encode([
    'results' => $results,
    'count'   => count($results),
    'query'   => $query,
    'filtered' => $filterOwnerZohoId ? true : false,
]);