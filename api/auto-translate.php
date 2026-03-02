<?php
/**
 * api/auto-translate.php
 * Traduce un texto corto ES→EN via DeepL. Solo para uso del admin.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }

$texto = trim($_POST['texto'] ?? '');
if (!$texto) { echo json_encode(['error'=>'Texto vacío']); exit; }

$row    = dbFetchOne("SELECT valor FROM config WHERE clave='deepl_api_key' LIMIT 1");
$apiKey = trim($row['valor'] ?? '');
if (!$apiKey) { echo json_encode(['error'=>'Sin API key de DeepL']); exit; }

$host = substr($apiKey,-3) === ':fx'
    ? 'https://api-free.deepl.com/v2/translate'
    : 'https://api.deepl.com/v2/translate';

$ctx = stream_context_create(['http'=>[
    'method'       => 'POST',
    'header'       => "Authorization: DeepL-Auth-Key $apiKey\r\nContent-Type: application/json",
    'content'      => json_encode(['text'=>[$texto],'source_lang'=>'ES','target_lang'=>'EN-US']),
    'timeout'      => 15,
    'ignore_errors'=> true,
]]);

$body = @file_get_contents($host, false, $ctx);
$data = json_decode($body, true);
$translated = $data['translations'][0]['text'] ?? '';

if (!$translated) { echo json_encode(['error'=>'DeepL falló: '.$body]); exit; }
echo json_encode(['traduccion' => $translated]);
