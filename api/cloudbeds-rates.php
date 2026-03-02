<?php
/**
 * Proxy para Cloudbeds getAvailableRoomTypes
 * Devuelve la tarifa mínima por noche de una propiedad
 * Cache: 30 minutos
 * 
 * GET /api/cloudbeds-rates.php?property_id=condesa
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['property_id'] ?? ''));

if (!$slug) {
    echo json_encode(['error' => 'property_id requerido']); exit;
}

$propiedad = dbFetchOne(
    "SELECT id, nombre, cloudbeds_property_id, precio_desde_dia, precio_desde_mes
     FROM propiedades WHERE slug = ? AND activo = 1",
    [$slug]
);

if (!$propiedad) {
    echo json_encode(['error' => 'Propiedad no encontrada']); exit;
}

$cacheKey = 'cloudbeds_rates_' . $slug;
$result   = dbCache($cacheKey, fn() => fetchCloudbedsRates($propiedad), 900); // 15 min

echo json_encode($result);

// ─────────────────────────────────────────────────────────────────────────────

function fetchCloudbedsRates(array $propiedad): array
{
    $cloudbedsId = $propiedad['cloudbeds_property_id'] ?? null;
    // Leer API key directo de la BD (no de constante para evitar orden de carga)
    $apiKeyRow = dbFetchOne("SELECT valor FROM config WHERE clave = 'cloudbeds_api_key' LIMIT 1");
    $apiKey    = $apiKeyRow['valor'] ?? '';

    if (!$cloudbedsId || !$apiKey) {
        return buildFallback($propiedad, 'no_config');
    }

    $startDate = date('Y-m-d');                          // hoy
    $endDate   = date('Y-m-d', strtotime('+1 day'));     // mañana — tarifa de 1 noche

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "Authorization: Bearer $apiKey\r\nContent-Type: application/json\r\n",
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);

    // Paso 1: obtener tipos de habitación de la propiedad
    $urlTypes = 'https://api.cloudbeds.com/api/v1.3/getRoomTypes?' . http_build_query([
        'propertyID' => $cloudbedsId,
    ]);

    $bodyTypes = @file_get_contents($urlTypes, false, $ctx);
    if ($bodyTypes === false) return buildFallback($propiedad, 'timeout');

    $dataTypes = json_decode($bodyTypes, true);
    if (!$dataTypes || !($dataTypes['success'] ?? false)) {
        return buildFallback($propiedad, $dataTypes['message'] ?? 'api_error');
    }

    $roomTypes = $dataTypes['data'] ?? [];
    if (empty($roomTypes)) return buildFallback($propiedad, 'sin_tipos');

    // Paso 2: obtener tarifa base de cada tipo (getRate no requiere disponibilidad)
    $precios = [];
    foreach ($roomTypes as $room) {
        $roomTypeId = $room['roomTypeID'] ?? null;
        if (!$roomTypeId) continue;

        $urlRate = 'https://api.cloudbeds.com/api/v1.3/getRate?' . http_build_query([
            'propertyID' => $cloudbedsId,
            'roomTypeID' => $roomTypeId,
            'startDate'  => $startDate,
            'endDate'    => $endDate,
        ]);

        $bodyRate = @file_get_contents($urlRate, false, $ctx);
        if (!$bodyRate) continue;

        $dataRate = json_decode($bodyRate, true);

        if (!($dataRate['success'] ?? false)) continue;

        // getRate devuelve data como objeto directo (no array de días)
        $rate = (float)($dataRate['data']['roomRate'] ?? 0);
        if ($rate > 0) $precios[] = $rate;
    }

    if (empty($precios)) {
        return buildFallback($propiedad, 'sin_tarifas');
    }

    $min = min($precios);

    return [
        'success'      => true,
        'source'       => 'cloudbeds',
        'precio_noche' => $min,
        'precio_mes'   => $propiedad['precio_desde_mes'] ?? null,
        'currency'     => 'MXN',
        'formato'      => '$' . number_format($min, 0, '.', ',') . ' MXN',
        'actualizado'  => date('Y-m-d H:i'),
    ];
}

function buildFallback(array $propiedad, string $reason = ''): array
{
    $noche = (float)($propiedad['precio_desde_dia'] ?? 0);
    $mes   = (float)($propiedad['precio_desde_mes'] ?? 0);

    return [
        'success'      => true,
        'source'       => 'manual',
        'reason'       => $reason,
        'precio_noche' => $noche ?: null,
        'precio_mes'   => $mes   ?: null,
        'currency'     => 'MXN',
        'formato'      => $noche > 0 ? '$' . number_format($noche, 0, '.', ',') . ' MXN' : null,
        'actualizado'  => date('Y-m-d H:i'),
    ];
}
