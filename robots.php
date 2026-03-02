<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/plain; charset=UTF-8');
?>
User-agent: *
Allow: /
Disallow: /admin/
Disallow: /api/
Disallow: /includes/
Disallow: /services/
Disallow: /logs/
Disallow: /cache/

Sitemap: <?= BASE_URL ?>/sitemap.xml
