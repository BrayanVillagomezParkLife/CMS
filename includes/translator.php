<?php
/**
 * includes/translator.php
 * Traducción de página completa via DeepL con chunks.
 * Autenticación via header Authorization (método actual desde nov 2025).
 */
declare(strict_types=1);

function traducirPagina(string $html, string $cacheFile): string
{
    $apiKey = _deeplGetKey();
    if (!$apiKey) return $html;

    $maxChunk  = 100000;
    $translated = strlen($html) <= $maxChunk
        ? _deeplTranslate($html, $apiKey)
        : _deeplTranslateChunked($html, $apiKey, $maxChunk);

    if (!$translated) return $html;

    $dir = dirname($cacheFile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($cacheFile, $translated);

    return $translated;
}

function _deeplGetKey(): string
{
    if (!function_exists('dbFetchOne')) return '';
    $row = dbFetchOne("SELECT valor FROM config WHERE clave = 'deepl_api_key' LIMIT 1");
    return trim($row['valor'] ?? '');
}

function _deeplTranslate(string $html, string $apiKey): string
{
    $host = substr($apiKey, -3) === ':fx'
        ? 'https://api-free.deepl.com/v2/translate'
        : 'https://api.deepl.com/v2/translate';

    // ── Autenticación via header (método actual DeepL desde nov 2025) ──
    $body = json_encode([
        'text'            => [$html],
        'source_lang'     => 'ES',
        'target_lang'     => 'EN-US',
        'tag_handling'    => 'html',
        'ignore_tags'     => ['script', 'style', 'code', 'pre'],
        'split_sentences' => 'nonewlines',
    ]);

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", [
            'Authorization: DeepL-Auth-Key ' . $apiKey,
            'Content-Type: application/json',
        ]),
        'content'       => $body,
        'timeout'       => 30,
        'ignore_errors' => true,
    ]]);

    $response = @file_get_contents($host, false, $ctx);
    if (!$response) return '';

    $data = json_decode($response, true);
    return $data['translations'][0]['text'] ?? '';
}

function _deeplTranslateChunked(string $html, string $apiKey, int $maxChunk): string
{
    $parts      = _splitHtmlSafe($html, $maxChunk);
    $translated = '';

    foreach ($parts as $part) {
        if (trim($part) === '') {
            $translated .= $part;
            continue;
        }
        // No traducir chunks que solo tienen JS/CSS
        $textOnly = preg_replace('/<(script|style)[^>]*>.*?<\/(script|style)>/si', '', $part);
        if (trim(strip_tags($textOnly)) === '') {
            $translated .= $part;
            continue;
        }
        $result      = _deeplTranslate($part, $apiKey);
        $translated .= $result ?: $part;
    }

    return $translated;
}

function _splitHtmlSafe(string $html, int $maxChunk): array
{
    if (strlen($html) <= $maxChunk) return [$html];

    $parts  = [];
    $offset = 0;
    $len    = strlen($html);

    while ($offset < $len) {
        if ($len - $offset <= $maxChunk) {
            $parts[] = substr($html, $offset);
            break;
        }

        $slice    = substr($html, $offset, $maxChunk);
        $cutPoint = null;

        foreach (['</section>', '</div>', "\n"] as $delimiter) {
            $pos = strrpos($slice, $delimiter);
            if ($pos !== false) {
                $cutPoint = $offset + $pos + strlen($delimiter);
                break;
            }
        }

        if (!$cutPoint || $cutPoint <= $offset) $cutPoint = $offset + $maxChunk;

        $parts[] = substr($html, $offset, $cutPoint - $offset);
        $offset  = $cutPoint;
    }

    return $parts;
}

function invalidarCacheTraduccion(?string $slug = null): void
{
    $dir = CACHE_PATH . '/pages/en/';
    if (!is_dir($dir)) return;

    if ($slug) {
        foreach ([$dir . $slug . '.html', CACHE_PATH . '/pages/en/index.html'] as $f) {
            if (file_exists($f)) @unlink($f);
        }
    } else {
        foreach (glob($dir . '*.html') ?: [] as $f) @unlink($f);
        foreach (glob($dir . '*/*.html') ?: [] as $f) @unlink($f);
    }
}
