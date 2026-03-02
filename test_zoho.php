<?php
require_once __DIR__ . '/includes/config.php';

$url = 'https://accounts.zoho.com/oauth/v2/token';
$body = http_build_query([
    'refresh_token' => ZOHO_REFRESH_TOKEN,
    'client_id'     => ZOHO_CLIENT_ID,
    'client_secret' => ZOHO_CLIENT_SECRET,
    'grant_type'    => 'refresh_token',
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => $body,
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_TIMEOUT         => 10,
    CURLOPT_HTTPHEADER      => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_SSL_VERIFYPEER  => false,
    CURLOPT_SSL_VERIFYHOST  => 0,
]);
$response = curl_exec($ch);
$error    = curl_error($ch);
$code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<pre>";
echo "HTTP: $code\n";
echo "cURL error: $error\n";
echo "Response: " . $response;
echo "</pre>";