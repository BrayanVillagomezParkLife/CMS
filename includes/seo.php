<?php
declare(strict_types=1);

/**
 * ============================================================
 * PARK LIFE PROPERTIES — seo.php
 * Clase SEO: meta tags, Schema.org JSON-LD, Open Graph,
 * Twitter Cards, sitemap dinámico
 * ============================================================
 */

require_once __DIR__ . '/functions.php';

class SEO
{
    // ─── HEAD COMPLETO ────────────────────────────────────────────────────────

    /**
     * Genera el bloque completo de SEO para el <head>.
     *
     * @param array $data {
     *   titulo:      string  Título de la página (sin sufijo de marca)
     *   descripcion: string  Meta description
     *   keywords:    string  Meta keywords (separadas por coma)
     *   og_image:    string  URL absoluta imagen OG (1200×630)
     *   canonical:   string  URL canónica (vacío = currentUrl)
     *   tipo:        string  'home' | 'propiedad' | 'general'
     *   noindex:     bool    Si se debe bloquear indexación
     * }
     */
    public static function renderHead(array $data): string
    {
        $titulo      = self::sanitize($data['titulo'] ?? '');
        $descripcion = self::sanitize($data['descripcion'] ?? '');
        $keywords    = self::sanitize($data['keywords'] ?? '');
        $ogImage     = self::sanitize($data['og_image'] ?? '');
        $canonical   = self::sanitize($data['canonical'] ?? currentUrl());
        $noindex     = $data['noindex'] ?? false;

        // Limpiar canonical de query strings innecesarios
        $canonical = strtok($canonical, '?') ?: $canonical;

        // Título final con sufijo de marca
        $fullTitle = $titulo
            ? "{$titulo} | Park Life Properties"
            : 'Park Life Properties | Departamentos Amueblados Premium en México';

        $out = '';

        // ── Básicos ────────────────────────────────────────────────────────
        $out .= "<title>" . e($fullTitle) . "</title>\n";
        $out .= "    <meta name=\"description\" content=\"" . e($descripcion) . "\">\n";

        if ($keywords) {
            $out .= "    <meta name=\"keywords\" content=\"" . e($keywords) . "\">\n";
        }

        if ($noindex) {
            $out .= "    <meta name=\"robots\" content=\"noindex, nofollow\">\n";
        } else {
            $out .= "    <meta name=\"robots\" content=\"index, follow, max-image-preview:large\">\n";
        }

        $out .= "    <link rel=\"canonical\" href=\"" . e($canonical) . "\">\n";

        // ── Open Graph ─────────────────────────────────────────────────────
        $out .= self::renderOpenGraph([
            'title'       => $fullTitle,
            'description' => $descripcion,
            'url'         => $canonical,
            'image'       => $ogImage,
            'tipo'        => $data['tipo'] ?? 'website',
        ]);

        // ── Twitter Cards ──────────────────────────────────────────────────
        $out .= self::renderTwitterCard([
            'title'       => $fullTitle,
            'description' => $descripcion,
            'image'       => $ogImage,
        ]);

        return $out;
    }

    // ─── OPEN GRAPH ───────────────────────────────────────────────────────────

    public static function renderOpenGraph(array $data): string
    {
        $ogType = ($data['tipo'] ?? 'website') === 'propiedad' ? 'website' : 'website';

        $out  = "    <!-- Open Graph -->\n";
        $out .= "    <meta property=\"og:type\" content=\"{$ogType}\">\n";
        $out .= "    <meta property=\"og:site_name\" content=\"Park Life Properties\">\n";
        $out .= "    <meta property=\"og:title\" content=\"" . e($data['title'] ?? '') . "\">\n";
        $out .= "    <meta property=\"og:description\" content=\"" . e($data['description'] ?? '') . "\">\n";
        $out .= "    <meta property=\"og:url\" content=\"" . e($data['url'] ?? '') . "\">\n";
        $out .= "    <meta property=\"og:locale\" content=\"es_MX\">\n";

        if (!empty($data['image'])) {
            $out .= "    <meta property=\"og:image\" content=\"" . e($data['image']) . "\">\n";
            $out .= "    <meta property=\"og:image:width\" content=\"1200\">\n";
            $out .= "    <meta property=\"og:image:height\" content=\"630\">\n";
            $out .= "    <meta property=\"og:image:alt\" content=\"" . e($data['title'] ?? 'Park Life Properties') . "\">\n";
        }

        return $out;
    }

    // ─── TWITTER CARDS ────────────────────────────────────────────────────────

    public static function renderTwitterCard(array $data): string
    {
        $out  = "    <!-- Twitter Cards -->\n";
        $out .= "    <meta name=\"twitter:card\" content=\"summary_large_image\">\n";
        $out .= "    <meta name=\"twitter:site\" content=\"@parklifeproperties\">\n";
        $out .= "    <meta name=\"twitter:title\" content=\"" . e($data['title'] ?? '') . "\">\n";
        $out .= "    <meta name=\"twitter:description\" content=\"" . e($data['description'] ?? '') . "\">\n";

        if (!empty($data['image'])) {
            $out .= "    <meta name=\"twitter:image\" content=\"" . e($data['image']) . "\">\n";
        }

        return $out;
    }

    // ─── SCHEMA: ORGANIZACIÓN (Homepage) ──────────────────────────────────────

    /**
     * Schema.org Organization para el homepage.
     *
     * @param array $config Resultado de getConfig()
     */
    public static function schemaOrganization(array $config): string
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'RealEstateAgent',
            'name'     => 'Park Life Properties',
            'url'      => BASE_URL,
            'logo'     => [
                '@type' => 'ImageObject',
                'url'   => url($config['logo_color'] ?? 'pics/Logo_Parklife.png'),
            ],
            'contactPoint' => [
                '@type'             => 'ContactPoint',
                'telephone'         => $config['telefono_principal'] ?? '+52 55 3566 8199',
                'contactType'       => 'customer service',
                'availableLanguage' => ['Spanish', 'English'],
                'areaServed'        => 'MX',
            ],
            'address' => [
                '@type'           => 'PostalAddress',
                'addressLocality' => 'Ciudad de México',
                'addressCountry'  => 'MX',
            ],
            'sameAs' => array_filter([
                $config['facebook_url']  ?? '',
                $config['instagram_url'] ?? '',
                $config['linkedin_url']  ?? '',
            ]),
            'description' => 'Departamentos y propiedades amuebladas premium en México. Estancias cortas y mensuales en CDMX, Guadalajara, Querétaro y Riviera Nayarit.',
            'areaServed'  => [
                ['@type' => 'City', 'name' => 'Ciudad de México'],
                ['@type' => 'City', 'name' => 'Guadalajara'],
                ['@type' => 'City', 'name' => 'Querétaro'],
                ['@type' => 'State', 'name' => 'Nayarit'],
            ],
        ];

        if (!empty($config['rating_google'])) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $config['rating_google'],
                'bestRating'  => '5',
                'ratingCount' => '150',
            ];
        }

        return self::toScriptTag($schema);
    }

    // ─── SCHEMA: PROPIEDAD (Landing de propiedad) ─────────────────────────────

    /**
     * Schema.org LodgingBusiness / ApartmentComplex para cada propiedad.
     *
     * @param array $prop       Datos de la propiedad (de tabla propiedades)
     * @param array $amenidades Array de amenidades (de getAmenidadesByPropiedad)
     * @param array $config     Resultado de getConfig()
     * @param array $faqs       Array de FAQs de la propiedad
     */
    public static function schemaPropiedad(array $prop, array $amenidades = [], array $config = [], array $faqs = []): string
    {
        $url     = url($prop['slug']);
        $imgUrl  = !empty($prop['og_image']) ? url($prop['og_image']) : url($prop['hero_pic'] ?? '');
        $telefono = $prop['telefono'] ?? $config['telefono_principal'] ?? '+52 55 3566 8199';

        // Precio mínimo
        $precioDesde = null;
        if (!empty($prop['precio_desde_mes'])) {
            $precioDesde = [
                '@type'    => 'MonetaryAmount',
                'currency' => $prop['precio_moneda'] ?? 'MXN',
                'value'    => $prop['precio_desde_mes'],
            ];
        }

        // Amenidades → amenityFeature Schema
        $amenityFeature = [];
        foreach ($amenidades as $am) {
            $nombre = $am['schema_name'] ?: $am['nombre'];
            $amenityFeature[] = [
                '@type' => 'LocationFeatureSpecification',
                'name'  => $nombre,
                'value' => true,
            ];
        }

        // Siempre agregar estancia mensual como feature
        $amenityFeature[] = [
            '@type' => 'LocationFeatureSpecification',
            'name'  => 'Estancia mensual (30+ noches)',
            'value' => true,
        ];

        $schema = [
            '@context'       => 'https://schema.org',
            '@type'          => 'LodgingBusiness',
            '@id'            => $url,
            'name'           => $prop['nombre'],
            'url'            => $url,
            'telephone'      => $telefono,
            'priceRange'     => '$$$$',
            'description'    => $prop['descripcion_corta'] ?? $prop['descripcion_larga'] ?? '',
            'address'        => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => trim(($prop['calle'] ?? '') . ' ' . ($prop['numero'] ?? '')),
                'addressLocality' => $prop['ciudad'] ?? 'Ciudad de México',
                'addressRegion'   => $prop['estado'] ?? 'CDMX',
                'postalCode'      => $prop['codigo_postal'] ?? '',
                'addressCountry'  => $prop['pais'] ?? 'MX',
            ],
            'amenityFeature' => $amenityFeature,
        ];

        if (!empty($prop['lat']) && !empty($prop['lng'])) {
            $schema['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => $prop['lat'],
                'longitude' => $prop['lng'],
            ];
        }

        if ($imgUrl) {
            $schema['image'] = $imgUrl;
        }

        if ($precioDesde) {
            $schema['priceRange'] = '$$$$';
        }

        if (!empty($config['rating_google'])) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $config['rating_google'],
                'bestRating'  => '5',
                'ratingCount' => '80',
            ];
        }

        $output = self::toScriptTag($schema);

        // Agregar FAQPage si hay FAQs
        if (!empty($faqs)) {
            $output .= "\n" . self::schemaFAQ($faqs);
        }

        return $output;
    }

    // ─── SCHEMA: FAQ ──────────────────────────────────────────────────────────

    /**
     * Schema.org FAQPage.
     *
     * @param array $faqs Array de FAQs con keys 'pregunta' y 'respuesta'
     */
    public static function schemaFAQ(array $faqs): string
    {
        if (empty($faqs)) {
            return '';
        }

        $mainEntity = [];
        foreach ($faqs as $faq) {
            $mainEntity[] = [
                '@type'          => 'Question',
                'name'           => $faq['pregunta'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => strip_tags($faq['respuesta']),
                ],
            ];
        }

        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $mainEntity,
        ];

        return self::toScriptTag($schema);
    }

    // ─── SITEMAP XML ──────────────────────────────────────────────────────────

    /**
     * Genera el sitemap.xml dinámico.
     *
     * @param array $propiedades Array de propiedades activas
     */
    public static function generateSitemap(array $propiedades): string
    {
        $now = date('Y-m-d');

        $urls = [
            // Homepage
            [
                'loc'        => BASE_URL . '/',
                'lastmod'    => $now,
                'changefreq' => 'weekly',
                'priority'   => '1.0',
            ],
        ];

        // Landing por propiedad
        foreach ($propiedades as $prop) {
            $urls[] = [
                'loc'        => url($prop['slug']),
                'lastmod'    => date('Y-m-d', strtotime($prop['updated_at'] ?? 'now')),
                'changefreq' => 'monthly',
                'priority'   => '0.9',
            ];
        }

        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"\n";
        $xml .= "        xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
            $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
            $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$url['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>";
        return $xml;
    }

    // ─── ROBOTS.TXT ───────────────────────────────────────────────────────────

    public static function generateRobots(): string
    {
        return "User-agent: *\n"
             . "Disallow: /admin/\n"
             . "Disallow: /includes/\n"
             . "Disallow: /api/\n"
             . "Disallow: /logs/\n"
             . "Disallow: /cache/\n"
             . "Allow: /\n\n"
             . "Sitemap: " . BASE_URL . "/sitemap.xml\n";
    }

    // ─── HELPERS PRIVADOS ─────────────────────────────────────────────────────

    /**
     * Genera un bloque <script type="application/ld+json"> para Schema.
     */
    private static function toScriptTag(array $schema): string
    {
        $json = json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        return "    <script type=\"application/ld+json\">\n{$json}\n    </script>";
    }

    /**
     * Sanitiza un string para uso en atributos HTML.
     */
    private static function sanitize(string $value): string
    {
        return htmlspecialchars(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ─── PRELOAD HINTS ────────────────────────────────────────────────────────

    /**
     * Genera los <link rel="preload"> para la imagen LCP y fuentes.
     *
     * @param string|null $heroImageUrl URL de la imagen hero de la página
     */
    public static function renderPreloads(?string $heroImageUrl = null): string
    {
        $out  = "    <!-- Preloads -->\n";

        if ($heroImageUrl) {
            $out .= "    <link rel=\"preload\" as=\"image\" href=\"" . e($heroImageUrl) . "\">\n";
        }

        // Fuentes críticas
        $out .= "    <link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n";
        $out .= "    <link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n";
        $out .= "    <link rel=\"dns-prefetch\" href=\"https://hotels.cloudbeds.com\">\n";

        return $out;
    }

    // ─── CSS VARIABLES DINÁMICAS ──────────────────────────────────────────────

    /**
     * Genera el bloque <style> con las CSS variables de colores desde BD.
     *
     * @param array $config Resultado de getConfig()
     */
    public static function renderColorVars(array $config): string
    {
        $primary   = self::sanitizeColor($config['color_primario']   ?? '#202944');
        $secondary = self::sanitizeColor($config['color_secundario'] ?? '#BAC4B9');
        $accent    = self::sanitizeColor($config['color_acento']     ?? '#2C3A5E');

        return "    <style>
        :root {
            --park-blue:       {$primary};
            --park-blue-light: {$accent};
            --park-sage:       {$secondary};
        }
        /* Sobreescribir clases Tailwind con variables dinámicas */
        .bg-park-blue       { background-color: var(--park-blue) !important; }
        .text-park-blue     { color: var(--park-blue) !important; }
        .border-park-blue   { border-color: var(--park-blue) !important; }
        .bg-park-blue-light { background-color: var(--park-blue-light) !important; }
        .bg-park-sage       { background-color: var(--park-sage) !important; }
        .text-park-sage     { color: var(--park-sage) !important; }
    </style>\n";
    }

    /**
     * Valida que un valor sea un color hex válido.
     */
    private static function sanitizeColor(string $color): string
    {
        if (preg_match('/^#[0-9A-Fa-f]{3,6}$/', $color)) {
            return $color;
        }
        return '#202944'; // Fallback al azul principal
    }
}
