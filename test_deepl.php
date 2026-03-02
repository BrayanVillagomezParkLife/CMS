<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

echo "<pre>";

$row    = dbFetchOne("SELECT valor FROM config WHERE clave = 'deepl_api_key' LIMIT 1");
$apiKey = trim($row['valor'] ?? '');
echo "API Key: " . ($apiKey ? "OK (len=" . strlen($apiKey) . ")" : "NO ENCONTRADA") . "\n\n";

if (!$apiKey) exit;

$host    = 'https://api-free.deepl.com/v2/translate';
$payload = json_encode([
    'text'        => ['Hola mundo. Esta es una prueba de traducción.'],
    'source_lang' => 'ES',
    'target_lang' => 'EN-US',
    'tag_handling'=> 'html',
]);

$ctx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => "Authorization: DeepL-Auth-Key $apiKey\r\nContent-Type: application/json",
    'content'       => $payload,
    'timeout'       => 15,
    'ignore_errors' => true,
]]);

$body = @file_get_contents($host, false, $ctx);
$data = json_decode($body, true);

echo "Traducción: " . ($data['translations'][0]['text'] ?? 'FALLIDA') . "\n";
echo "Raw: " . $body . "\n";
echo "</pre>";
