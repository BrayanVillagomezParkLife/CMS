<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');

$propiedades = dbFetchAll(
    "SELECT slug, updated_at FROM propiedades WHERE activo = 1 ORDER BY orden, nombre"
);

$staticPages = [
    ['loc' => BASE_URL . '/',         'priority' => '1.0',  'changefreq' => 'weekly'],
    ['loc' => BASE_URL . '/legal',    'priority' => '0.2',  'changefreq' => 'yearly'],
    ['loc' => BASE_URL . '/terminos', 'priority' => '0.2',  'changefreq' => 'yearly'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

<?php foreach ($staticPages as $page): ?>
    <url>
        <loc><?= htmlspecialchars($page['loc']) ?></loc>
        <changefreq><?= $page['changefreq'] ?></changefreq>
        <priority><?= $page['priority'] ?></priority>
        <lastmod><?= date('Y-m-d') ?></lastmod>
    </url>
<?php endforeach; ?>

<?php foreach ($propiedades as $prop): ?>
    <url>
        <loc><?= htmlspecialchars(BASE_URL . '/' . $prop['slug']) ?></loc>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
        <lastmod><?= date('Y-m-d', strtotime($prop['updated_at'] ?? 'now')) ?></lastmod>
    </url>
<?php endforeach; ?>

</urlset>
