<?php
declare(strict_types=1);

/**
 * ============================================================
 * pages/propiedad.php
 * Página dinámica de cada propiedad
 * Basada fielmente en condesa.html → 100% datos desde BD
 *
 * URL: /{slug}  →  .htaccess → ?slug={slug}
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seo.php';

// ─── Resolver slug ────────────────────────────────────────────────────────────
$slug = sanitizeStr($_GET['slug'] ?? '');
if (!$slug || !preg_match('/^[a-z0-9][a-z0-9\-]{1,98}$/', $slug)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// ─── Cargar propiedad ─────────────────────────────────────────────────────────
$propiedad = dbFetchOne(
    "SELECT p.*, d.nombre AS destino_nombre, d.slug AS destino_slug
     FROM propiedades p
     LEFT JOIN destinos d ON p.destino_id = d.id
     WHERE p.slug = ? AND p.activo = 1
     LIMIT 1",
    [$slug]
);

if (!$propiedad) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$propId = (int)$propiedad['id'];

// ─── Cargar datos relacionados ────────────────────────────────────────────────
$habitaciones = dbFetchAll(
    "SELECT * FROM habitaciones WHERE propiedad_id = ? AND activa = 1 ORDER BY orden, destacada DESC, id",
    [$propId]
);

// Cargar galería de cada habitación
foreach ($habitaciones as &$hab) {
    $hab['galeria'] = dbFetchAll(
        "SELECT url, alt FROM habitacion_imagenes WHERE habitacion_id = ? ORDER BY orden, id",
        [$hab['id']]
    );
    // Si hay imagen_url principal y no está en la galería, agregarla al inicio
    if ($hab['imagen_url'] && empty(array_filter($hab['galeria'], fn($g) => $g['url'] === $hab['imagen_url']))) {
        array_unshift($hab['galeria'], ['url' => $hab['imagen_url'], 'alt' => $hab['nombre']]);
    }
}
unset($hab);

$imagenes = dbFetchAll(
    "SELECT * FROM propiedad_imagenes WHERE propiedad_id = ? AND activa = 1 ORDER BY tipo, orden, id",
    [$propId]
);

$amenidades = dbFetchAll(
    "SELECT ac.*, pa.descripcion_custom
     FROM amenidades_catalogo ac
     INNER JOIN propiedad_amenidades pa ON ac.id = pa.amenidad_id
     WHERE pa.propiedad_id = ? AND ac.activa = 1
     ORDER BY ac.id",
    [$propId]
);

$faqsPropiedad = dbFetchAll(
    "SELECT * FROM faqs WHERE (propiedad_id = ? OR propiedad_id IS NULL) AND activa = 1 ORDER BY propiedad_id DESC, orden, id LIMIT 8",
    [$propId]
);

// Imágenes separadas por tipo
$imgHero    = array_values(array_filter($imagenes, fn($i) => $i['tipo'] === 'hero'));
$imgGaleria = array_values(array_filter($imagenes, fn($i) => $i['tipo'] === 'galeria'));
$imgZona    = array_values(array_filter($imagenes, fn($i) => $i['tipo'] === 'zona'));
$imgCard    = array_values(array_filter($imagenes, fn($i) => $i['tipo'] === 'card'));
$imgOg      = array_values(array_filter($imagenes, fn($i) => $i['tipo'] === 'og'));

// Función helper: obtener portada o primera imagen de un conjunto
function imgPortadaO(array $imgs): ?string {
    foreach ($imgs as $i) { if ($i['es_portada']) return $i['url']; }
    return $imgs[0]['url'] ?? null;
}

$heroImg = imgPortadaO($imgHero) ?? $propiedad['hero_pic'] ?? 'pics/hero_default.webp';
$cardImg = imgPortadaO($imgCard) ?? imgPortadaO($imgHero) ?? $heroImg;
$ogImg   = imgPortadaO($imgOg)  ?? $propiedad['og_image'] ?? $heroImg;

// Carrusel hero: todas las fotos tipo hero ordenadas, portada primero
usort($imgHero, fn($a, $b) => $b['es_portada'] - $a['es_portada'] ?: $a['orden'] - $b['orden']);

// ─── Datos para el booking engine ────────────────────────────────────────────
$cloudbedsCode = $propiedad['cloudbeds_code'] ?? CLOUDBEDS_DEFAULT_CODE;

// Mapa slug → código (para el JS del widget meses)
$cloudbedsMap = ['default' => cfg('cloudbeds_default', CLOUDBEDS_DEFAULT_CODE), $slug => $cloudbedsCode];

// ─── SEO ──────────────────────────────────────────────────────────────────────
$seoTitle = $propiedad['seo_title']
    ?: ($propiedad['nombre'] . ' by Park Life Properties | ' . ($propiedad['ciudad'] ?? 'México'));
$seoDesc  = $propiedad['seo_description']
    ?: (dbVal($propiedad, 'descripcion_corta') ?? 'Departamento amueblado premium en ' . ($propiedad['ciudad'] ?? 'México') . '. Estancias cortas y largas.');

$seo = [
    'titulo'      => $seoTitle,
    'descripcion' => $seoDesc,
    'keywords'    => $propiedad['seo_keywords'] ?? '',
    'og_image'    => url($ogImg),
    'canonical'   => BASE_URL . '/' . $slug,
    'tipo'        => 'propiedad',
];

// ─── CSRF ─────────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$csrfToken = generateCsrfToken();

// ─── Puntos de interés (zona) ─────────────────────────────────────────────────
// Se pueden cargar desde BD en el futuro; por ahora fallback a datos de la propiedad
$puntosInteres = [];
if (!empty($propiedad['commercial_text'])) {
    $commercialText = dbVal($propiedad, 'commercial_text');
    $puntosInteres = explode("\n", trim($commercialText));
    $puntosInteres = array_filter(array_map('trim', $puntosInteres));
}

// ─── Props para header ────────────────────────────────────────────────────────
$config              = getConfig();
$propiedadesPorDestino = getPropiedadesPorDestino();

// ── Header ───────────────────────────────────────────────────────────────────
// No usamos el header.php genérico aquí porque la página de propiedad
// tiene su propio loader y navbar personalizado con links de la propiedad.
// Generamos el <head> con SEO y compartimos el CSS del header.
?>
<!DOCTYPE html>
<html lang="<?= htmlLang() ?>" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= SEO::renderHead($seo) ?>
    <?= SEO::renderColorVars($config) ?>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: { extend: {
            colors: {
                'park-blue':       'var(--color-primary, #202944)',
                'park-blue-light': 'var(--color-primary-light, #2C3A5E)',
                'park-blue-dark':  'var(--color-primary-dark,  #161d30)',
                'park-sage':       'var(--color-secondary, #BAC4B9)',
                'park-sage-light': 'var(--color-secondary-light, #D4DCD3)',
                'park-cream':      'var(--color-cream, #f9f9f9)',
            },
            fontFamily: {
                'asap':    ['Asap Condensed', 'sans-serif'],
                'jakarta': ['Plus Jakarta Sans', 'sans-serif'],
            }
        }}
    }
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Asap+Condensed:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Icons, Flatpickr, SweetAlert2 -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/airbnb.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- reCAPTCHA -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>

    <style>
        ::-webkit-scrollbar{width:8px}::-webkit-scrollbar-track{background:#f1f1f1}::-webkit-scrollbar-thumb{background:#BAC4B9;border-radius:4px}::-webkit-scrollbar-thumb:hover{background:#202944}
        .loader{transition:opacity .6s ease,visibility .6s ease}.loader.hidden{opacity:0;visibility:hidden}
        .navbar-blur{backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}
        .gallery-item:hover .gallery-overlay{opacity:1}.gallery-item:hover img{transform:scale(1.1)}
        .reveal{opacity:0;transform:translateY(30px);transition:all .8s cubic-bezier(.4,0,.2,1)}.reveal.active{opacity:1;transform:translateY(0)}
        .card-lift{transition:all .4s cubic-bezier(.4,0,.2,1)}.card-lift:hover{transform:translateY(-8px);box-shadow:0 25px 50px -12px rgba(32,41,68,.25)}
        .flatpickr-calendar{font-family:'Plus Jakarta Sans',sans-serif!important;border-radius:16px!important;box-shadow:0 25px 50px -12px rgba(0,0,0,.25)!important}
        .flatpickr-day.selected,.flatpickr-day.startRange,.flatpickr-day.endRange{background:#202944!important;border-color:#202944!important}
        .flatpickr-day.inRange{background:#BAC4B9!important;border-color:#BAC4B9!important}
        .number-input::-webkit-inner-spin-button,.number-input::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}.number-input{-moz-appearance:textfield}
        ::selection{background:#BAC4B9;color:#202944}

        /* Booking sticky */
        .booking-hero-wrapper{transition:all .4s cubic-bezier(.4,0,.2,1)}
        @media(max-width:1023px){.booking-container{padding-left:5%!important;padding-right:5%!important}}
        .booking-hero-wrapper.is-sticky{position:fixed;top:80px;left:0;right:0;z-index:2147483647;padding:12px 5%;background:linear-gradient(to bottom,rgba(32,41,68,.98),rgba(32,41,68,.95));backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);box-shadow:0 4px 30px rgba(0,0,0,.3);animation:slideDown .4s ease}
        @keyframes slideDown{from{transform:translateY(-100%);opacity:0}to{transform:translateY(0);opacity:1}}
        .booking-hero-wrapper.is-sticky .booking-container{max-width:1200px;margin:0 auto}
        .booking-hero-wrapper.is-sticky .booking-engine{border-radius:12px}
        .booking-hero-wrapper.is-sticky .trust-badges{display:none}
        body.booking-sticky{padding-top:80px}
        @media(max-width:1023px){
            #widgetDays>div>div,#widgetMonths form>div>div{width:100%!important;border-right:none!important;border-bottom:1px solid #f3f4f6;flex-shrink:0}
            #widgetDays>div,#widgetMonths form>div{flex-direction:column!important;min-height:unset!important}
            .mascota-row{display:flex!important;flex-direction:row!important;border-bottom:1px solid #f3f4f6}
            .mascota-row>div{flex:1!important;width:auto!important;border-right:1px solid #f3f4f6!important;border-bottom:none!important}
            .mascota-row>div:last-child{border-right:none!important}
            #widgetDays>div>div:last-child button,#widgetMonths form>div>div:last-child button{width:100%!important}
        }
        .booking-widgets{transition:all .35s cubic-bezier(.4,0,.2,1)}
        @media(max-width:1023px){.booking-hero-wrapper.is-sticky{padding:8px 5%;top:72px}.booking-hero-wrapper.is-sticky .booking-widgets{display:none!important}.booking-hero-wrapper.is-sticky.is-expanded .booking-widgets{display:block!important}}
        @media(min-width:1024px){.booking-hero-wrapper.is-sticky .booking-widgets{display:block!important}}
        .booking-mobile-collapsed{display:none}
        /* Galería */
        .gallery-item{overflow:hidden;cursor:pointer;border-radius:1rem}
        .gallery-item img{transition:transform .7s ease}
        /* Habitaciones */
        .hab-card.active{border-color:#202944;background:#f0f2f8}
    </style>
</head>
<body class="font-jakarta bg-park-cream text-gray-800 antialiased">

<!-- Loader -->
<div id="loader" class="loader fixed inset-0 z-[9999] bg-park-blue flex items-center justify-center">
    <div class="text-center">
        <div class="relative w-20 h-20 mx-auto mb-6">
            <div class="absolute inset-0 border-4 border-park-sage/30 rounded-full"></div>
            <div class="absolute inset-0 border-4 border-transparent border-t-park-sage rounded-full animate-spin"></div>
        </div>
        <h2 class="text-white font-asap text-2xl font-bold tracking-[0.3em]"><?= strtoupper(e($propiedad['nombre'])) ?></h2>
        <p class="text-park-sage text-sm mt-2 font-light"><?= s('prop.disponible') ?></p>
    </div>
</div>

<!-- ── NAVBAR ─────────────────────────────────────────────────────────────── -->
<nav id="navbar" class="fixed top-0 left-0 right-0 z-50 transition-all duration-500">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-20">
            <a href="/" class="flex items-center group">
                <img src="<?= e(cfg('logo_blanco', 'pics/Logo_ParkLife_Blanco.png')) ?>" alt="Park Life Properties" class="h-10 sm:h-12 w-auto transition-all duration-300" id="logo-white">
                <img src="<?= e(cfg('logo_color',  'pics/Logo_Parklife.png')) ?>"        alt="Park Life Properties" class="h-10 sm:h-12 w-auto transition-all duration-300 hidden" id="logo-blue">
            </a>
            <div class="hidden lg:flex items-center gap-8">
                <a href="#inicio"     class="nav-link text-white/90 hover:text-white text-sm font-medium transition-colors"><?= s('nav.inicio') ?></a>
                <?php if (!empty($habitaciones)): ?>
                <a href="#espacios"   class="nav-link text-white/90 hover:text-white text-sm font-medium transition-colors"><?= s('prop.nav.espacios') ?></a>
                <?php endif; ?>
                <?php if (!empty($imgGaleria)): ?>
                <a href="#galeria"    class="nav-link text-white/90 hover:text-white text-sm font-medium transition-colors"><?= s('prop.nav.galeria') ?></a>
                <?php endif; ?>
                <?php if (!empty($amenidades)): ?>
                <a href="#amenidades" class="nav-link text-white/90 hover:text-white text-sm font-medium transition-colors"><?= s('prop.nav.amenidades') ?></a>
                <?php endif; ?>
                <?php if ($propiedad['lat'] && $propiedad['lng']): ?>
                <a href="#ubicacion"  class="nav-link text-white/90 hover:text-white text-sm font-medium transition-colors"><?= s('prop.nav.ubicacion') ?></a>
                <?php endif; ?>
                <a href="#reservar"   class="bg-white text-park-blue px-6 py-2.5 rounded-full font-semibold text-sm hover:bg-park-sage transition-all duration-300 hover:shadow-lg"><?= s('nav.contacto') ?></a>
                <!-- Selector idioma -->
                <a href="<?= langSwitch() ?>"
                   class="flex items-center gap-1.5 text-white/70 hover:text-white text-sm transition-colors border border-white/20 hover:border-white/50 rounded-full px-3 py-1.5">
                    <span><?= APP_LANG === 'en' ? '🇲🇽' : '🇺🇸' ?></span>
                    <span class="font-medium"><?= APP_LANG === 'en' ? 'Español' : 'English' ?></span>
                </a>
            </div>
            <button id="mobile-menu-btn" class="lg:hidden p-2 rounded-lg text-white">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
        </div>
    </div>
    <div id="mobile-menu" class="lg:hidden hidden bg-white shadow-2xl rounded-b-3xl mx-4">
        <div class="px-6 py-8 space-y-4">
            <a href="<?= '/' ?>"  class="block text-park-blue font-medium py-2 hover:text-park-sage transition-colors"><?= s('prop.volver') ?></a>
            <a href="#inicio"     class="block text-park-blue font-medium py-2 hover:text-park-sage transition-colors"><?= s('nav.inicio') ?></a>
            <?php if (!empty($habitaciones)): ?>
            <a href="#espacios"   class="block text-park-blue font-medium py-2 hover:text-park-sage transition-colors"><?= s('prop.nav.espacios') ?></a>
            <?php endif; ?>
            <?php if (!empty($imgGaleria)): ?>
            <a href="#galeria"    class="block text-park-blue font-medium py-2 hover:text-park-sage transition-colors"><?= s('prop.nav.galeria') ?></a>
            <?php endif; ?>
            <?php if (!empty($amenidades)): ?>
            <a href="#amenidades" class="block text-park-blue font-medium py-2 hover:text-park-sage transition-colors"><?= s('prop.nav.amenidades') ?></a>
            <?php endif; ?>
            <?php if ($propiedad['lat'] && $propiedad['lng']): ?>
            <a href="#ubicacion"  class="block text-park-blue font-medium py-2 hover:text-park-sage transition-colors"><?= s('prop.nav.ubicacion') ?></a>
            <?php endif; ?>
            <a href="#reservar"   class="block bg-park-blue text-white text-center px-6 py-3 rounded-xl font-semibold hover:bg-park-blue-light transition-colors"><?= s('nav.contacto') ?></a>
            <!-- Selector idioma mobile -->
            <a href="<?= langSwitch() ?>"
               class="flex items-center gap-2 text-park-blue/60 hover:text-park-blue py-2 border-t border-gray-100 pt-4 text-sm font-medium">
                <span><?= APP_LANG === 'en' ? '🇲🇽' : '🇺🇸' ?></span>
                <span><?= APP_LANG === 'en' ? 'Español' : 'English' ?></span>
            </a>
        </div>
    </div>
</nav>

<!-- ═══════════════════════════════════════════════════════════
     HERO
═══════════════════════════════════════════════════════════════ -->
<section id="inicio" class="relative min-h-screen flex items-center overflow-hidden">
    <div class="absolute inset-0" id="hero-bg">
        <?php if (count($imgHero) > 1): ?>
        <!-- Carrusel hero con múltiples fotos -->
        <?php foreach ($imgHero as $hi => $hImg): ?>
        <img src="/<?= e($hImg['url']) ?>" alt="<?= e($propiedad['nombre']) ?>"
             class="w-full h-full object-cover absolute inset-0 transition-opacity duration-1000"
             style="opacity:<?= $hi === 0 ? '1' : '0' ?>">
        <?php endforeach; ?>
        <?php else: ?>
        <img src="/<?= e($heroImg) ?>" alt="<?= e($propiedad['nombre']) ?>" class="w-full h-full object-cover">
        <?php endif; ?>
        <div class="absolute inset-0 bg-gradient-to-b from-park-blue/90 via-park-blue/70 to-park-blue/90"></div>
    </div>
    <?php if (count($imgHero) > 1): ?>
    <script>
    (function(){
        const imgs = document.querySelectorAll('#hero-bg img');
        let cur = 0;
        setInterval(() => {
            imgs[cur].style.opacity = '0';
            cur = (cur + 1) % imgs.length;
            imgs[cur].style.opacity = '1';
        }, 5000);
    })();
    </script>
    <?php endif; ?>
    <div class="absolute top-20 right-10 w-72 h-72 bg-park-sage/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-20 left-10 w-96 h-96 bg-park-sage/10 rounded-full blur-3xl pointer-events-none"></div>

    <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-32 pb-8 w-full">
        <div class="text-center text-white mb-12">
            <div class="inline-flex items-center gap-2 bg-white/10 backdrop-blur-sm border border-white/20 rounded-full px-4 py-2 mb-6">
                <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                <span class="text-sm font-medium"><?= e(dbVal($propiedad, 'destino_nombre') ?: 'México') ?><?= s('prop.disponible') ?></span>
            </div>
            <h1 class="font-asap text-5xl sm:text-6xl lg:text-7xl font-bold leading-tight mb-6">
                <?= nl2br(e(dbVal($propiedad, 'hero_slogan') ?: 'Bienvenido a ' . e($propiedad['nombre']))) ?>
            </h1>
            <p class="text-lg sm:text-xl text-white/80 mb-8 max-w-2xl mx-auto leading-relaxed">
                <?= e(dbVal($propiedad, 'descripcion_corta')) ?>
            </p>
        </div>

        <!-- ── BOOKING ENGINE ── -->
        <div class="booking-hero-wrapper" id="bookingHeroWrapper">
            <div class="booking-container max-w-5xl mx-auto w-full">
                <!-- Pills -->
                <div class="flex justify-center mb-3">
                    <div class="inline-flex items-center rounded-full p-1 gap-0.5"
                         style="background:rgba(255,255,255,0.18);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border:1px solid rgba(255,255,255,0.35);box-shadow:0 8px 32px rgba(0,0,0,.15)">
                        <button id="toggleDays" onclick="setPlayzo('days')"
                                class="px-7 py-2.5 rounded-full text-sm font-bold transition-all duration-200"
                                style="background:rgba(255,255,255,0.95);color:#202944;box-shadow:0 2px 8px rgba(0,0,0,.15)">
                            <?= s('booking.por_dias') ?>
                        </button>
                        <button id="toggleMonths" onclick="setPlayzo('months')"
                                class="px-7 py-2.5 rounded-full text-sm font-semibold transition-all duration-200"
                                style="color:rgba(255,255,255,0.65)">
                            <?= s('booking.por_meses') ?>
                        </button>
                    </div>
                </div>

                <div class="booking-widgets">
                    <!-- DÍAS: va directo a Cloudbeds preseleccionando esta propiedad -->
                    <div id="widgetDays" class="booking-engine bg-white rounded-2xl shadow-2xl overflow-hidden">
                        <div class="flex flex-col lg:flex-row lg:items-stretch lg:divide-x divide-gray-100" style="min-height:90px">
                            <div class="flex flex-col justify-center px-6 py-5 lg:w-44 flex-shrink-0">
                                <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.tipo_estancia') ?></p>
                                <p class="text-sm font-semibold text-park-blue flex items-center gap-1.5"><?= s('booking.por_dias') ?> <i data-lucide="clock" class="w-3.5 h-3.5 text-gray-300"></i></p>
                            </div>
                            <div class="flex-[2] flex flex-col justify-center px-6 py-5 border-t lg:border-t-0">
                                <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.fechas') ?></p>
                                <div class="relative">
                                    <input type="text" id="bk-dates" placeholder="<?= s('booking.sel_fechas') ?>" readonly
                                           class="w-full bg-transparent border-none p-0 text-sm font-semibold text-park-blue cursor-pointer outline-none placeholder-gray-300 pr-5">
                                    <i data-lucide="calendar" class="absolute right-0 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-300 pointer-events-none"></i>
                                </div>
                                <input type="hidden" id="bk-checkin">
                                <input type="hidden" id="bk-checkout">
                            </div>
                            <div class="flex flex-col justify-center px-6 py-5 border-t lg:border-t-0 lg:w-40 flex-shrink-0">
                                <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.huespedes') ?></p>
                                <div class="flex items-center gap-2.5">
                                    <button onclick="bkDec()" type="button" class="w-6 h-6 flex items-center justify-center rounded-full border border-gray-300 text-gray-400 hover:border-park-blue hover:text-park-blue text-sm font-bold transition-all">−</button>
                                    <input type="number" id="bk-guests" value="2" min="1" max="10" class="number-input w-5 text-center text-sm font-semibold text-park-blue bg-transparent outline-none">
                                    <button onclick="bkInc()" type="button" class="w-6 h-6 flex items-center justify-center rounded-full border border-gray-300 text-gray-400 hover:border-park-blue hover:text-park-blue text-sm font-bold transition-all">+</button>
                                </div>
                            </div>
                            <div class="flex-shrink-0 p-3 flex items-center">
                                <button onclick="bkSearchProp()" type="button"
                                        class="w-full lg:w-auto bg-park-blue text-white px-7 rounded-xl font-bold text-sm hover:bg-park-blue-light transition-all flex items-center justify-center gap-2 whitespace-nowrap" style="min-height:56px">
                                    <i data-lucide="search" class="w-4 h-4"></i><?= s('booking.ver_disp') ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- MESES -->
                    <form id="widgetMonths" class="hidden booking-engine bg-white rounded-2xl shadow-2xl overflow-hidden" onsubmit="submitLeadProp(event)">
                        <input type="hidden" name="csrf_token"      value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="form_type"       value="lead_mensual">
                        <input type="hidden" name="propiedad_slug"  value="<?= e($slug) ?>">
                        <input type="hidden" name="propiedad_id"    value="<?= $propId ?>">
                        <input type="hidden" name="form_timestamp"  id="leadFormTs">
                        <!-- Honeypot -->
                        <input type="text" name="website"       style="display:none" tabindex="-1" autocomplete="off">
                        <input type="text" name="confirm_email" style="display:none" tabindex="-1" autocomplete="off">
                        <!-- Privacidad implícita -->
                        <input type="hidden" name="privacidad_ok" value="1">

                        <div class="flex flex-col lg:flex-row lg:items-stretch" style="min-height:90px">
                            <div class="flex-shrink-0 flex flex-col justify-center px-4 py-5 border-r border-gray-100" style="width:150px">
                                <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.duracion') ?></p>
                                <select name="duracion" id="bk-duracion" required
                                        class="bg-transparent border-none p-0 text-sm font-semibold text-park-blue outline-none cursor-pointer appearance-none w-full">
                                    <option value="1">1 mes</option>
                                    <option value="2">2 meses</option>
                                    <option value="3">3 meses</option>
                                    <option value="4">4 meses</option>
                                    <option value="5">5 meses</option>
                                    <option value="6">6 meses</option>
                                    <option value="7">7 meses</option>
                                    <option value="8">8 meses</option>
                                    <option value="9">9 meses</option>
                                    <option value="10">10 meses</option>
                                    <option value="11">11 meses</option>
                                    <option value="12">12 meses</option>
                                    <option value="13">+12 meses</option>
                                </select>
                            </div>
                            <div class="flex-1 flex flex-col justify-center px-5 py-5 border-r border-gray-100">
                                <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.nombre') ?></p>
                                <input type="text" name="nombre" placeholder="<?= s('ph.nombre') ?>" required
                                       class="bg-transparent border-none p-0 text-sm font-semibold text-park-blue outline-none placeholder-gray-300 w-full">
                            </div>
                            <div class="flex-1 flex flex-col justify-center px-5 py-5 border-r border-gray-100">
                                <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.email') ?></p>
                                <input type="email" name="email" placeholder="<?= s('ph.email') ?>" required
                                       class="bg-transparent border-none p-0 text-sm font-semibold text-park-blue outline-none placeholder-gray-300 w-full">
                            </div>
                            <div class="flex-1 flex flex-col justify-center px-5 py-5 border-r border-gray-100">
                                <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.whatsapp') ?></p>
                                <input type="tel" name="telefono" placeholder="<?= s('ph.tel_widget') ?>" required
                                       class="bg-transparent border-none p-0 text-sm font-semibold text-park-blue outline-none placeholder-gray-300 w-full">
                            </div>
                            <div class="mascota-row flex-shrink-0" style="display:contents">
                                <div class="flex flex-col justify-center items-center py-5 border-r border-gray-100 flex-shrink-0" style="width:90px">
                                    <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-2"><?= s('booking.mascota') ?></p>
                                    <label class="cursor-pointer"><input type="checkbox" id="bk-mascota" name="mascota" class="sr-only peer">
                                        <div class="w-8 h-8 rounded-lg border-2 border-gray-200 peer-checked:border-park-blue peer-checked:bg-park-blue/5 flex items-center justify-center transition-all">
                                            <i data-lucide="paw-print" class="w-4 h-4 text-gray-300"></i></div></label>
                                </div>
                                <div class="flex flex-col justify-center items-center py-5 border-r border-gray-100 flex-shrink-0" style="width:90px">
                                    <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-2"><?= s('booking.muebles') ?></p>
                                    <label class="cursor-pointer"><input type="checkbox" id="bk-amueblado" name="amueblado" class="sr-only peer">
                                        <div class="w-8 h-8 rounded-lg border-2 border-gray-200 peer-checked:border-park-blue peer-checked:bg-park-blue/5 flex items-center justify-center transition-all">
                                            <i data-lucide="sofa" class="w-4 h-4 text-gray-300 peer-checked:text-park-blue"></i></div></label>
                                </div>
                            </div>
                            <div class="flex-shrink-0 p-3 flex items-center">
                                <button type="submit" id="leadSubmitBtn"
                                        class="w-full lg:w-auto bg-park-blue text-white px-7 rounded-xl font-bold text-sm hover:bg-park-blue-light transition-all flex items-center justify-center gap-2 whitespace-nowrap" style="min-height:56px">
                                    <span class="btn-text"><?= s('booking.cotizar') ?></span>
                                    <span class="btn-loading hidden"><i data-lucide="loader" class="w-4 h-4 animate-spin"></i></span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 btn-icon"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                    <p class="text-center text-white/40 text-xs mt-2 px-4"><?= s('booking.privacidad_implicita') ?> <a href="/legal" class="underline hover:text-white/70 transition-colors"><?= s('booking.privacidad_link') ?></a></p>
                </div><!-- /booking-widgets -->

                <div class="booking-mobile-collapsed bg-white/10 rounded-xl px-4 py-2 items-center justify-center gap-2 mt-1" id="bookingMobileCollapsed">
                    <i data-lucide="search" class="w-4 h-4 text-white/70"></i>
                    <span class="text-white/70 text-xs font-medium" id="mobileCollapsedLabel"><?= s('booking.buscar_disp') ?></span>
                </div>

                <!-- Trust badges -->
                <div class="trust-badges flex flex-wrap items-center justify-center gap-6 mt-6 text-white/70 text-sm">
                    <div class="flex items-center gap-2"><i data-lucide="shield-check" class="w-4 h-4"></i><span><?= s('booking.reserva_seg') ?></span></div>
                    <div class="flex items-center gap-2"><i data-lucide="credit-card"  class="w-4 h-4"></i><span><?= s('booking.pago_prot') ?></span></div>
                    <div class="flex items-center gap-2"><i data-lucide="rotate-ccw"   class="w-4 h-4"></i><span><?= s('booking.cancelacion') ?></span></div>
                </div>
            </div>
        </div>

        <!-- Stats rápidos de la propiedad -->
        <div class="grid grid-cols-3 gap-6 max-w-2xl mx-auto mt-16 pt-8 border-t border-white/20 text-white text-center">
            <!-- Precio por noche — se actualiza dinámicamente -->
            <div>
                <div id="precio-noche-val" class="text-3xl sm:text-4xl font-bold text-park-sage">
                    <?php if ($propiedad['precio_desde_dia']): ?>
                    <?= formatPrice((float)$propiedad['precio_desde_dia']) ?>
                    <?php else: ?>
                    <span class="opacity-30 text-2xl">—</span>
                    <?php endif; ?>
                </div>
                <div class="text-sm text-white/60 mt-1" id="precio-noche-label"><?= s('booking.por_noche') ?></div>
            </div>
            <!-- Precio por mes -->
            <div>
                <div id="precio-mes-val" class="text-3xl sm:text-4xl font-bold text-park-sage">
                    <?php if ($propiedad['precio_desde_mes']): ?>
                    <?= formatPrice((float)$propiedad['precio_desde_mes']) ?>
                    <?php else: ?>
                    <span class="opacity-30 text-2xl">—</span>
                    <?php endif; ?>
                </div>
                <div class="text-sm text-white/60 mt-1"><?= s('booking.por_mes') ?></div>
            </div>
            <div>
                <div class="text-3xl sm:text-4xl font-bold text-park-sage">24/7</div>
                <div class="text-sm text-white/60 mt-1"><?= s('booking.soporte') ?></div>
            </div>
        </div>
        <p id="precio-actualizado" class="text-center text-white/30 text-xs mt-3" style="display:none"></p>
        <p class="text-center text-white/25 text-xs mt-1"><?= s('booking.precios_ref') ?></p>
    </div>
    <div class="absolute bottom-8 left-1/2 -translate-x-1/2 text-white/60 animate-bounce">
        <i data-lucide="chevrons-down" class="w-6 h-6"></i>
    </div>
</section>

<!-- ═══ FEATURES STRIP ══════════════════════════════════════════════════════ -->
<?php if (!empty($amenidades)): ?>
<section class="bg-white py-6 border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-wrap justify-center items-center gap-8 sm:gap-16 text-gray-600">
            <?php foreach (array_slice($amenidades, 0, 5) as $am): ?>
            <div class="flex items-center gap-2">
                <i data-lucide="<?= e($am['icono_lucide'] ?: 'check') ?>" class="w-5 h-5 text-park-blue"></i>
                <span class="text-sm font-medium"><?= e($am['descripcion_custom'] ?: dbVal($am, 'nombre')) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══ ESPACIOS / HABITACIONES ═════════════════════════════════════════════ -->
<?php if (!empty($habitaciones)): ?>
<section id="espacios" class="py-20 sm:py-28 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-3xl mx-auto mb-16 reveal">
            <span class="inline-block bg-park-sage/20 text-park-blue px-4 py-1.5 rounded-full text-sm font-semibold mb-4"><?= s('prop.espacios') ?></span>
            <h2 class="font-asap text-4xl sm:text-5xl font-bold text-park-blue mb-6"><?= s('prop.espacios_sub') ?></h2>
            <p class="text-gray-600 text-lg leading-relaxed">
                <?= e(dbVal($propiedad, 'commercial_highlight') ?: dbVal($propiedad, 'descripcion_corta') ?: s('prop.espacios_desc')) ?>
            </p>
        </div>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php foreach ($habitaciones as $i => $hab):
                $delay   = number_format($i * 0.1, 1);
                $imgHab  = $hab['imagen_url'] ?? '';
                $galeria = $hab['galeria'] ?? [];
                $precioNoche = $propiedad['precio_desde_dia']
                    ? formatPrice((float)$propiedad['precio_desde_dia']) . '/' . s('prop.noche_suf')
                    : s('prop.consultar_precio');
                $galeriaJson = json_encode(array_map(fn($g) => ['url' => $g['url'], 'alt' => $g['alt'] ?: $hab['nombre']], $galeria), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
                $tieneGaleria = !empty($galeria);
            ?>
            <div class="group bg-park-cream rounded-2xl overflow-hidden card-lift reveal <?= $tieneGaleria ? 'cursor-pointer' : '' ?>"
                 style="transition-delay:<?= $delay ?>s"
                 <?= $tieneGaleria ? "onclick=\"abrirLightbox({$i})\"" : '' ?>>
                <div class="relative h-64 overflow-hidden">
                    <?php if ($imgHab): ?>
                    <img src="/<?= e($imgHab) ?>" alt="<?= e($hab['nombre']) ?>"
                         class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110">
                    <?php else: ?>
                    <div class="w-full h-full bg-park-blue/10 flex items-center justify-center">
                        <i data-lucide="home" class="w-16 h-16 text-park-blue/30"></i>
                    </div>
                    <?php endif; ?>
                    <div class="absolute top-4 left-4 bg-white/90 backdrop-blur-sm px-3 py-1 rounded-full text-sm font-medium text-park-blue">
                        <?php if ($hab['precio_mes_1']): ?>
                            <?= s('prop.desde') ?> <?= formatPrice((float)$hab['precio_mes_1']) ?>/<?= s('prop.mes_suf') ?>
                        <?php else: ?>
                            <?= e($precioNoche) ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($hab['destacada']): ?>
                    <div class="absolute top-4 right-4 bg-park-blue text-white px-3 py-1 rounded-full text-xs font-bold"><?= s('prop.mas_popular') ?></div>
                    <?php endif; ?>
                    <?php if ($tieneGaleria && count($galeria) > 1): ?>
                    <div class="absolute bottom-3 right-3 bg-black/50 backdrop-blur-sm text-white px-2 py-1 rounded-lg text-xs flex items-center gap-1">
                        <i data-lucide="images" class="w-3 h-3"></i><?= count($galeria) ?> <?= s('prop.fotos') ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($tieneGaleria): ?>
                    <div class="absolute inset-0 bg-park-blue/0 group-hover:bg-park-blue/20 transition-all duration-300 flex items-center justify-center">
                        <div class="w-12 h-12 rounded-full bg-white/90 flex items-center justify-center opacity-0 group-hover:opacity-100 scale-75 group-hover:scale-100 transition-all duration-300">
                            <i data-lucide="expand" class="w-5 h-5 text-park-blue"></i>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="p-6">
                    <h3 class="font-asap text-xl font-bold text-park-blue mb-2"><?= e(dbVal($hab, 'nombre')) ?></h3>
                    <div class="flex items-center gap-4 text-sm text-gray-500 mt-3">
                        <?php if ($hab['num_camas']): ?>
                        <span class="flex items-center gap-1"><i data-lucide="bed"   class="w-4 h-4"></i> <?= $hab['num_camas'] ?> <?= $hab['num_camas'] == 1 ? 'cama' : 'camas' ?></span>
                        <?php endif; ?>
                        <?php if ($hab['num_banos']): ?>
                        <span class="flex items-center gap-1"><i data-lucide="bath"  class="w-4 h-4"></i> <?= (float)$hab['num_banos'] ?> <?= s('prop.banos') ?></span>
                        <?php endif; ?>
                        <?php if ($hab['metros_cuadrados']): ?>
                        <span class="flex items-center gap-1"><i data-lucide="ruler" class="w-4 h-4"></i> <?= (int)$hab['metros_cuadrados'] ?>m²</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($tieneGaleria): ?>
                    <p class="text-xs text-park-sage mt-3 font-medium flex items-center gap-1">
                        <i data-lucide="image" class="w-3 h-3"></i> <?= s('prop.toca_fotos') ?>
                    </p>
                    <?php endif; ?>
                </div>
                <!-- Data para el lightbox -->
                <script>window._hab_<?= $i ?> = <?= $galeriaJson ?>;</script>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="text-center mt-12 reveal">
            <a href="#reservar" class="inline-flex items-center gap-2 bg-park-blue text-white px-8 py-4 rounded-xl font-semibold hover:bg-park-blue-light transition-all duration-300 hover:shadow-xl">
                <?= s('prop.ver_disp') ?> <i data-lucide="arrow-right" class="w-5 h-5"></i>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══ GALERÍA ══════════════════════════════════════════════════════════════ -->
<?php if (!empty($imgGaleria)): ?>
<section id="galeria" class="py-20 sm:py-28 bg-park-cream">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-3xl mx-auto mb-16 reveal">
            <span class="inline-block bg-park-sage/20 text-park-blue px-4 py-1.5 rounded-full text-sm font-semibold mb-4"><?= s('prop.galeria') ?></span>
            <h2 class="font-asap text-4xl sm:text-5xl font-bold text-park-blue mb-6"><?= s('prop.galeria_sub') ?></h2>
            <p class="text-gray-600 text-lg leading-relaxed"><?= s('prop.galeria_desc') ?></p>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 reveal">
            <?php foreach ($imgGaleria as $i => $img):
                $isFirst = $i === 0;
                $altText = $img['alt'] ?: $propiedad['nombre'];
            ?>
            <div class="<?= $isFirst ? 'col-span-2 row-span-2' : '' ?> gallery-item relative <?= $isFirst ? 'min-h-[400px]' : 'h-48 md:h-auto' ?>">
                <img src="<?= e($img['url']) ?>" alt="<?= e($altText) ?>" class="w-full h-full object-cover">
                <div class="gallery-overlay absolute inset-0 bg-gradient-to-t from-park-blue/80 to-transparent opacity-0 transition-opacity duration-300 flex items-end <?= $isFirst ? 'p-6' : 'p-4' ?>">
                    <div class="text-white">
                        <?php if ($isFirst): ?>
                        <h4 class="font-asap text-xl font-bold"><?= e($altText) ?></h4>
                        <p class="text-white/80 text-sm"><?= e($propiedad['colonia'] ?? '') ?></p>
                        <?php else: ?>
                        <h4 class="font-semibold text-sm"><?= e($altText) ?></h4>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══ AMENIDADES ═══════════════════════════════════════════════════════════ -->
<?php if (!empty($amenidades)): ?>
<section id="amenidades" class="py-20 sm:py-28 bg-park-blue relative overflow-hidden">
    <div class="absolute inset-0 opacity-5" style="background-image:url('data:image/svg+xml,%3Csvg width=60 height=60 viewBox=%270 0 60 60%27 xmlns=%27http://www.w3.org/2000/svg%27%3E%3Cg fill=%27none%27 fill-rule=%27evenodd%27%3E%3Cg fill=%27%23ffffff%27 fill-opacity=%270.4%27%3E%3Cpath d=%27M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z%27/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')"></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div class="text-center max-w-3xl mx-auto mb-16 reveal">
            <span class="inline-block bg-park-sage/20 text-park-sage px-4 py-1.5 rounded-full text-sm font-semibold mb-4"><?= s('prop.amenidades_etq') ?></span>
            <h2 class="font-asap text-4xl sm:text-5xl font-bold text-white mb-6"><?= s('prop.amenidades') ?></h2>
            <p class="text-white/70 text-lg leading-relaxed"><?= s('prop.amenidades_desc') ?></p>
        </div>
        <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6 reveal">
            <?php foreach ($amenidades as $am): ?>
            <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 border border-white/10 hover:bg-white/15 transition-all duration-300">
                <div class="w-14 h-14 bg-park-sage/20 rounded-xl flex items-center justify-center mb-4">
                    <i data-lucide="<?= e($am['icono_lucide'] ?: 'check-circle') ?>" class="w-7 h-7 text-park-sage"></i>
                </div>
                <h4 class="font-asap text-lg font-bold text-white mb-2"><?= e(dbVal($am, 'nombre')) ?></h4>
                <p class="text-white/60 text-sm"><?= e($am['descripcion_custom'] ?: dbVal($am, 'descripcion') ?: '') ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══ UBICACIÓN ════════════════════════════════════════════════════════════ -->
<?php if ($propiedad['lat'] && $propiedad['lng']): ?>
<section id="ubicacion" class="py-20 sm:py-28 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid lg:grid-cols-2 gap-12 lg:gap-20 items-center">
            <div class="reveal">
                <span class="inline-block bg-park-sage/20 text-park-blue px-4 py-1.5 rounded-full text-sm font-semibold mb-4"><?= s('prop.ubicacion') ?></span>
                <h2 class="font-asap text-4xl sm:text-5xl font-bold text-park-blue mb-6">
                    <?= e($propiedad['colonia'] ?? $propiedad['ciudad'] ?? 'Ubicación Premium') ?>
                </h2>
                <p class="text-gray-600 text-lg leading-relaxed mb-8">
                    <?= e(dbVal($propiedad, 'descripcion_larga') ?: s('prop.ubicacion_fb') . ($propiedad['ciudad'] ?? '')) ?>
                </p>

                <!-- Puntos de interés desde BD o zona imgs -->
                <div class="space-y-3">
                    <?php if (!empty($puntosInteres)): ?>
                        <?php foreach (array_slice($puntosInteres, 0, 4) as $punto): ?>
                        <div class="flex items-center gap-4 p-4 bg-park-cream rounded-xl hover:shadow-md transition-shadow">
                            <div class="w-10 h-10 bg-park-blue/10 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i data-lucide="map-pin" class="w-5 h-5 text-park-blue"></i>
                            </div>
                            <p class="text-gray-700 text-sm font-medium"><?= e($punto) ?></p>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="flex items-center gap-4 p-4 bg-park-cream rounded-xl">
                            <div class="w-10 h-10 bg-park-blue/10 rounded-lg flex items-center justify-center"><i data-lucide="map-pin" class="w-5 h-5 text-park-blue"></i></div>
                            <div><p class="font-semibold text-park-blue"><?= e($propiedad['colonia'] ?? '') ?></p><p class="text-gray-500 text-sm"><?= e($propiedad['ciudad'] ?? '') ?><?= $propiedad['estado'] ? ', ' . e($propiedad['estado']) : '' ?></p></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Fotos de zona si existen -->
                <?php if (!empty($imgZona)): ?>
                <div class="grid grid-cols-<?= min(4, count($imgZona)) ?> gap-3 mt-6">
                    <?php foreach (array_slice($imgZona, 0, 4) as $z): ?>
                    <div class="rounded-xl overflow-hidden h-24"><img src="<?= e($z['url']) ?>" alt="<?= e($z['alt'] ?? 'Zona') ?>" class="w-full h-full object-cover hover:scale-105 transition-transform duration-500"></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="reveal">
                <?php if (!empty($propiedad['google_maps_embed'])): ?>
                <div class="rounded-2xl overflow-hidden shadow-2xl h-[500px]">
                    <?= $propiedad['google_maps_embed'] ?>
                </div>
                <?php else: ?>
                <div class="rounded-2xl overflow-hidden shadow-2xl h-[500px]">
                    <iframe
                        src="https://maps.google.com/maps?q=<?= urlencode($propiedad['lat'] . ',' . $propiedad['lng']) ?>&z=15&output=embed"
                        width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy"
                        class="grayscale hover:grayscale-0 transition-all duration-500"></iframe>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══ FAQ (si hay FAQs específicas de esta propiedad) ═════════════════════ -->
<?php if (!empty($faqsPropiedad)): ?>
<script>
function toggleFaq(i) {
    const ans  = document.getElementById('faq-ans-' + i);
    const icon = document.getElementById('faq-icon-' + i);
    if (!ans) return;
    const open = ans.style.display === 'block';
    document.querySelectorAll('[id^="faq-ans-"]').forEach(el => el.style.display = 'none');
    document.querySelectorAll('[id^="faq-icon-"]').forEach(el => el.style.transform = '');
    if (!open) {
        ans.style.display = 'block';
        if (icon) icon.style.transform = 'rotate(180deg)';
    }
}
</script>
<section id="faq" class="py-16 bg-park-cream">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12 reveal">
            <h2 class="font-asap text-4xl font-bold text-park-blue mb-3"><?= s('prop.faq') ?></h2>
            <p class="text-gray-500"><?= s('prop.faq_sub') ?> <?= e($propiedad['nombre']) ?></p>
        </div>
        <div class="space-y-3 reveal">
            <?php foreach ($faqsPropiedad as $i => $faq): ?>
            <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <button type="button"
                        onclick="toggleFaq(<?= $i ?>)"
                        class="w-full text-left px-6 py-5 flex items-center justify-between gap-4 hover:bg-gray-50 transition-colors">
                    <span class="font-semibold text-park-blue"><?= e(dbVal($faq, 'pregunta')) ?></span>
                    <svg id="faq-icon-<?= $i ?>" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                         viewBox="0 0 24 24" fill="none" stroke="#BAC4B9" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round"
                         style="flex-shrink:0;transition:transform .3s">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
                <div id="faq-ans-<?= $i ?>" style="display:none;padding:0 1.5rem 1.25rem">
                    <p class="text-gray-600 leading-relaxed"><?= nl2br(e(dbVal($faq, 'respuesta'))) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══ CONTACTO / RESERVAR ══════════════════════════════════════════════════ -->
<section id="reservar" class="py-20 sm:py-28 bg-park-cream">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid lg:grid-cols-5 gap-12 lg:gap-16">
            <!-- Info -->
            <div class="lg:col-span-2 reveal">
                <span class="inline-block bg-park-sage/20 text-park-blue px-4 py-1.5 rounded-full text-sm font-semibold mb-4"><?= s('prop.contactanos') ?></span>
                <h2 class="font-asap text-4xl sm:text-5xl font-bold text-park-blue mb-6"><?= s('prop.reservar') ?></h2>
                <p class="text-gray-600 text-lg leading-relaxed mb-8"><?= s('prop.contacto_sub') ?></p>
                <div class="space-y-4">
                    <?php if ($tel = $propiedad['telefono'] ?: cfg('telefono_ventas', '')): ?>
                    <a href="tel:<?= e($tel) ?>" class="flex items-center gap-4 p-5 bg-white rounded-xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-12 h-12 bg-park-blue rounded-xl flex items-center justify-center group-hover:bg-park-sage transition-colors"><i data-lucide="phone" class="w-5 h-5 text-white"></i></div>
                        <div><div class="text-sm text-gray-500"><?= s('prop.llamanos') ?></div><div class="font-semibold text-park-blue"><?= e($tel) ?></div></div>
                    </a>
                    <?php endif; ?>
                    <a href="mailto:<?= e($propiedad['email'] ?: EMAIL_INFO) ?>" class="flex items-center gap-4 p-5 bg-white rounded-xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-12 h-12 bg-park-blue rounded-xl flex items-center justify-center group-hover:bg-park-sage transition-colors"><i data-lucide="mail" class="w-5 h-5 text-white"></i></div>
                        <div><div class="text-sm text-gray-500"><?= s('ctc.email_lbl') ?></div><div class="font-semibold text-park-blue"><?= e($propiedad['email'] ?: EMAIL_INFO) ?></div></div>
                    </a>
                    <?php if ($wa = $propiedad['whatsapp'] ?: cfg('whatsapp_ventas', '')): ?>
                    <a href="https://wa.me/<?= e($wa) ?>?text=<?= urlencode('Hola, me interesa información sobre ' . $propiedad['nombre']) ?>" target="_blank"
                       class="flex items-center gap-4 p-5 bg-white rounded-xl shadow-sm hover:shadow-md transition-all group">
                        <div class="w-12 h-12 bg-green-500 rounded-xl flex items-center justify-center group-hover:bg-green-600 transition-colors"><i data-lucide="message-circle" class="w-5 h-5 text-white"></i></div>
                        <div><div class="text-sm text-gray-500"><?= s('ctc.wa_lbl') ?></div><div class="font-semibold text-park-blue"><?= s('prop.escribe_ahora') ?></div></div>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Formulario -->
            <div class="lg:col-span-3 reveal">
                <div class="bg-white rounded-3xl shadow-xl p-8 sm:p-10">
                    <h3 class="font-asap text-2xl font-bold text-park-blue mb-2"><?= s('prop.solicita_info') ?></h3>
                    <p class="text-gray-500 mb-8"><?= s('form.subtitulo') ?></p>
                    <form id="contactForm" class="space-y-6">
                        <input type="hidden" name="csrf_token"      value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="form_type"       value="contacto">
                        <input type="hidden" name="propiedad_id"    value="<?= $propId ?>">
                        <input type="hidden" name="propiedad_interes" value="<?= e($slug) ?>">
                        <input type="text" name="website"       style="display:none" tabindex="-1" autocomplete="off">
                        <input type="text" name="confirm_email" style="display:none" tabindex="-1" autocomplete="off">

                        <div class="grid sm:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-park-blue mb-2"><?= s('form.nombre') ?></label>
                                <input type="text" name="nombre" required placeholder="<?= s('ph.tu_nombre') ?>"
                                       class="w-full px-4 py-3 bg-park-cream border-2 border-transparent rounded-xl focus:border-park-blue focus:bg-white outline-none transition-all">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-park-blue mb-2"><?= s('form.apellido') ?></label>
                                <input type="text" name="apellido" required placeholder="<?= s('ph.tu_apellido') ?>"
                                       class="w-full px-4 py-3 bg-park-cream border-2 border-transparent rounded-xl focus:border-park-blue focus:bg-white outline-none transition-all">
                            </div>
                        </div>
                        <div class="grid sm:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-park-blue mb-2"><?= s('form.email') ?></label>
                                <input type="email" name="email" required placeholder="tu@email.com"
                                       class="w-full px-4 py-3 bg-park-cream border-2 border-transparent rounded-xl focus:border-park-blue focus:bg-white outline-none transition-all">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-park-blue mb-2"><?= s('form.telefono') ?></label>
                                <input type="tel" name="telefono" required placeholder="+52 55 1234 5678"
                                       class="w-full px-4 py-3 bg-park-cream border-2 border-transparent rounded-xl focus:border-park-blue focus:bg-white outline-none transition-all">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-park-blue mb-2"><?= s('form.tipo_label') ?></label>
                            <select name="tipo_estancia" required
                                    class="w-full px-4 py-3 bg-park-cream border-2 border-transparent rounded-xl focus:border-park-blue focus:bg-white outline-none transition-all appearance-none cursor-pointer">
                                <option value=""><?= s('form.selecciona') ?></option>
                                <option value="corta"><?= s('form.corta') ?></option>
                                <option value="media"><?= s('form.media') ?></option>
                                <option value="larga"><?= s('form.larga') ?></option>
                                <option value="permanente"><?= s('form.permanente') ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-park-blue mb-2"><?= s('form.mensaje_label') ?></label>
                            <textarea name="mensaje" rows="4" placeholder="<?= s('ph.cuantanos') ?>"
                                      class="w-full px-4 py-3 bg-park-cream border-2 border-transparent rounded-xl focus:border-park-blue focus:bg-white outline-none transition-all resize-none"></textarea>
                        </div>
                        <div class="flex items-start gap-3">
                            <input type="checkbox" id="privacy-prop" name="privacidad_ok" required class="w-5 h-5 mt-0.5 accent-park-blue rounded">
                            <label for="privacy-prop" class="text-sm text-gray-600">
                                <?= s('form.privacidad') ?>
                                <a href="<?= '/legal' ?>" target="_blank" class="text-park-blue font-medium hover:underline"><?= s('form.politica') ?></a>.
                            </label>
                        </div>
                        <button type="submit" id="submitBtn"
                                class="w-full bg-park-blue text-white py-4 rounded-xl font-semibold text-lg hover:bg-park-blue-light transition-all duration-300 hover:shadow-xl flex items-center justify-center gap-2">
                            <span class="btn-text"><?= s('form.enviar_sol') ?></span>
                            <span class="btn-loading hidden">
                                <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </span>
                            <i data-lucide="send" class="w-5 h-5 btn-icon"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Schema.org -->
<?= SEO::schemaOrganization($config) ?>
<?php if (!empty($faqsPropiedad)): ?>
<?= SEO::schemaFAQ($faqsPropiedad) ?>
<?php endif; ?>

<!-- ── Footer dinámico ─────────────────────────────────────────────────────── -->

<!-- ═══ MODAL COTIZACIÓN ═══ -->
<div id="modalCotizacion" class="hidden fixed inset-0 z-[9999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4"
     onclick="if(event.target===this)closeCotizModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[92vh] overflow-y-auto"
         style="animation:popIn .35s cubic-bezier(.34,1.56,.64,1)">
        <div class="px-7 pt-7 pb-0 relative">
            <button onclick="closeCotizModal()" class="absolute top-5 right-5 w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center text-gray-400 hover:text-gray-700 transition-all">
                <i data-lucide="x" class="w-3.5 h-3.5"></i>
            </button>
            <p class="text-[9px] font-black uppercase tracking-[3px] text-park-sage mb-1">Cotización de Renta</p>
            <h3 class="font-asap font-extrabold text-2xl text-park-blue leading-tight mb-1" id="cotiz-saludo">¡Hola!</h3>
            <p class="text-sm text-gray-400 mb-5" id="cotiz-sub">Aquí está tu pre-cotización</p>
        </div>
        <div class="h-px bg-gray-100 mx-7"></div>
        <div class="mx-7 mt-5 bg-park-blue rounded-2xl px-5 py-4 flex items-center justify-between gap-3">
            <div>
                <p class="text-[9px] font-black uppercase tracking-[3px] text-park-sage mb-1">Precio desde</p>
                <p class="font-asap font-extrabold text-4xl text-white leading-none" id="cotiz-precio">$—</p>
            </div>
            <div class="border border-white/20 rounded-xl px-4 py-2 text-center flex-shrink-0">
                <p class="font-asap font-extrabold text-xl text-white leading-none" id="cotiz-dur-num">—</p>
                <p class="text-[10px] text-white/50 mt-0.5" id="cotiz-dur-label">meses</p>
            </div>
        </div>
        <div class="px-7 py-5">
            <div class="flex flex-wrap gap-1.5 mb-5" id="cotiz-tags"></div>
            <p class="text-[9px] font-black uppercase tracking-[2.5px] text-gray-300 mb-2">Opciones disponibles</p>
            <div class="space-y-2 mb-4" id="cotiz-habs"></div>
            <div class="flex items-center gap-3 bg-gray-50 rounded-xl p-3 mt-2">
                <div class="w-9 h-9 rounded-full bg-park-blue flex items-center justify-center text-white text-xs font-bold flex-shrink-0" id="cotiz-av">—</div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-park-blue" id="cotiz-asesor">—</p>
                    <p class="text-xs text-gray-400">Leasing Agent</p>
                </div>
                <div class="text-right flex-shrink-0">
                    <p class="text-[10px] text-gray-400">Te contacta en</p>
                    <p class="font-asap font-bold text-park-blue text-sm">&lt; 2 hrs</p>
                </div>
            </div>
            <p class="text-[10px] text-gray-300 text-center mt-4 leading-relaxed">* Pre-cotización de referencia, sujeta a disponibilidad.</p>
            <button onclick="closeCotizModal()" class="w-full mt-4 bg-park-blue text-white rounded-xl py-3 font-asap font-bold text-sm tracking-wider uppercase hover:bg-park-blue-light transition-all">
                Entendido
            </button>
        </div>
    </div>
</div>
<style>@keyframes popIn{from{opacity:0;transform:scale(.88) translateY(20px)}to{opacity:1;transform:scale(1) translateY(0)}}</style>

<?php require __DIR__ . '/../templates/footer.php'; ?>

<!-- ── WhatsApp flotante ──────────────────────────────────────────────────── -->
<?php if ($wa = $propiedad['whatsapp'] ?: cfg('whatsapp_ventas', '')): ?>
<a href="https://wa.me/<?= e($wa) ?>?text=<?= urlencode('Hola, me interesa información sobre ' . $propiedad['nombre']) ?>"
   target="_blank"
   class="fixed bottom-6 right-6 z-50 w-14 h-14 bg-green-500 rounded-full shadow-lg flex items-center justify-center hover:bg-green-600 hover:scale-110 transition-all duration-300">
    <i data-lucide="message-circle" class="w-6 h-6 text-white"></i>
</a>
<?php endif; ?>

<!-- ── JavaScript de la página ───────────────────────────────────────────── -->
<script>
const CLOUDBEDS_CODE = '<?= e($cloudbedsCode) ?>';
const PROP_SLUG      = '<?= e($propiedad['slug']) ?>';

// ── Precios dinámicos Cloudbeds ──────────────────────────────────────────────
(function fetchPrecios() {
    fetch('/api/cloudbeds-rates.php?property_id=' + PROP_SLUG)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;

            const fmtPeso = n => '$' + Number(n).toLocaleString('es-MX', {
                minimumFractionDigits: 0, maximumFractionDigits: 0
            }) + ' MXN';

            // Precio noche
            if (data.precio_noche) {
                const el = document.getElementById('precio-noche-val');
                if (el) {
                    el.innerHTML = fmtPeso(data.precio_noche);
                    // Indicador si viene de Cloudbeds (en tiempo real)
                    if (data.source === 'cloudbeds') {
                        const lbl = document.getElementById('precio-noche-label');
                        if (lbl) lbl.innerHTML = 'Por noche desde <span style="font-size:.6rem;opacity:.5">●\u00A0' + str('live') + '</span>';
                    }
                }
            }

            // Precio mes
            if (data.precio_mes) {
                const el = document.getElementById('precio-mes-val');
                if (el) el.innerHTML = fmtPeso(data.precio_mes);
            }

            // Timestamp actualización
            if (data.source === 'cloudbeds' && data.actualizado) {
                const ts = document.getElementById('precio-actualizado');
                if (ts) {
                    ts.textContent = str('actualizado') + ' ' + data.actualizado;
                    ts.style.display = 'block';
                }
            }
        })
        .catch(() => {}); // Silencioso si falla — los precios manuales siguen mostrándose
})();

window.addEventListener('load', () => {
    setTimeout(() => { document.getElementById('loader')?.classList.add('hidden'); lucide.createIcons(); initReveal(); }, 1200);
    setTimeout(() => { document.getElementById('loader')?.classList.add('hidden'); }, 3000);
});

// Navbar scroll y sticky booking — manejados por footer.php

// Booking — bkPlayzo, bkInc, bkDec manejados por footer.php

function bkSearchProp() {
    const ci = document.getElementById('bk-checkin')?.value;
    const co = document.getElementById('bk-checkout')?.value;
    const g  = document.getElementById('bk-guests')?.value || 2;
    if (!ci || !co) {
        Swal.fire({ icon:'info', title:str('fechas_titulo'), text:str('fechas_texto'), confirmButtonColor:'#202944' });
        document.getElementById('bk-dates')?._flatpickr?.open();
        return;
    }
    window.open(`https://hotels.cloudbeds.com/reservation/${CLOUDBEDS_CODE}?checkin=${ci}&checkout=${co}&adults=${g}`, '_blank');
}

// Datos de habitaciones para cotización
    const HABITACIONES = <?= json_encode(array_map(fn($h) => [
        'id'          => $h['id'],
        'nombre'      => $h['nombre'],
        'descripcion' => $h['descripcion'] ?? '',
        'capacidad'   => $h['capacidad'] ?? '',
        'm2'          => $h['m2'] ?? '',
        'precio_mes_1'  => (float)($h['precio_mes_1']  ?? 0),
        'precio_mes_6'  => (float)($h['precio_mes_6']  ?? 0),
        'precio_mes_12' => (float)($h['precio_mes_12'] ?? 0),
        'imagen'      => $h['imagen_url'] ?? ($h['galeria'][0]['url'] ?? ''),
    ], $habitaciones)) ?>;

// Lead mensual desde propiedad
function submitLeadProp(e) {
    e.preventDefault();
    const form = document.getElementById('widgetMonths');
    const btn  = document.getElementById('leadSubmitBtn');
    const bt   = btn?.querySelector('.btn-text');
    const bl   = btn?.querySelector('.btn-loading');
    const bi   = btn?.querySelector('.btn-icon');
    if (bt) bt.textContent = str('enviando');
    bl?.classList.remove('hidden'); bi?.classList.add('hidden');
    if (btn) btn.disabled = true;
    fetch('/api/send-lead.php', { method:'POST', body: new FormData(form) })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                    const nombre = form.querySelector('[name="nombre"]')?.value?.split(' ')[0] || '';
                    const dur = parseInt(document.getElementById('bk-duracion')?.value || 1);
                    const key = dur >= 12 ? 'precio_mes_12' : dur >= 6 ? 'precio_mes_6' : 'precio_mes_1';
                    const durLabel = dur >= 12 ? '12 meses' : dur >= 6 ? '6 meses' : '1 mes';
                    const fmtP = n => n > 0 ? '$' + n.toLocaleString('es-MX', {minimumFractionDigits:0}) : null;
                    form.reset();

                    // Habitaciones con precio para esa duración
                    const habsConPrecio = HABITACIONES.filter(h => h[key] > 0);

                    // Precio desde = el más barato
                    const precios = habsConPrecio.map(h => h[key]).filter(p => p > 0);
                    const precioDesde = precios.length > 0 ? Math.min(...precios) : null;

                    let html = '';

                    // Saludo personalizado
                    if (nombre) html += `<p class="text-park-blue font-semibold text-base mb-1">¡Hola ${nombre}!</p>`;
                    html += `<p class="text-gray-500 text-sm mb-4">Recibimos tu solicitud. Te contactamos en menos de 2 hrs.</p>`;

                    if (precioDesde && habsConPrecio.length > 0) {
                        // Precio desde destacado
                        html += `<div class="bg-park-blue rounded-xl px-5 py-4 mb-4 text-center">
                            <p class="text-white/70 text-xs uppercase tracking-widest font-bold mb-1">Precio desde · ${durLabel}</p>
                            <p class="text-white text-3xl font-bold">${fmtP(precioDesde)}<span class="text-lg font-normal text-white/70">/mes</span></p>
                        </div>`;

                        // Todas las habitaciones
                        html += '<div class="space-y-2 text-left">';
                        habsConPrecio.forEach(h => {
                            const p = fmtP(h[key]);
                            const isBarata = h[key] === precioDesde;
                            html += `<div class="flex justify-between items-center rounded-xl px-4 py-3 ${isBarata ? 'bg-park-blue/5 border border-park-blue/20' : 'bg-gray-50'}">
                                <div>
                                    <p class="font-semibold text-park-blue text-sm">${h.nombre}${isBarata ? ' <span class="text-[10px] bg-park-blue text-white px-1.5 py-0.5 rounded-full ml-1">Mejor precio</span>' : ''}</p>
                                    ${h.capacidad ? `<p class="text-xs text-gray-400">${h.capacidad} personas${h.m2 ? ' · ' + h.m2 + ' m²' : ''}</p>` : ''}
                                </div>
                                <p class="font-bold text-park-blue text-sm">${p}<span class="text-xs font-normal text-gray-400">/mes</span></p>
                            </div>`;
                        });
                        html += '</div>';
                        html += '<p class="text-xs text-gray-400 mt-3 text-center">* Precios de referencia, sujetos a disponibilidad.</p>';
                    }

                    Swal.fire({
                        title: '¡Todo listo! 🎉',
                        html,
                        confirmButtonColor: '#202944',
                        confirmButtonText: 'Entendido',
                        width: 500
                    });
                }
            else            { Swal.fire({ icon:'error',   title:'Error',  text: d.message || str('error_envio'), confirmButtonColor:'#202944' }); }
        })
        .catch(err => Swal.fire({ icon:'error', title:'Error', text: err.message || str('sin_conexion'), confirmButtonColor:'#202944' }))
        .finally(() => { if (bt) bt.textContent=str('cotizar_mensual'); bl?.classList.add('hidden'); bi?.classList.remove('hidden'); if (btn) btn.disabled=false; });
}

// Reveal — manejado por footer.php


// Formulario contacto
document.getElementById('contactForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const bt  = btn?.querySelector('.btn-text');
    const bl  = btn?.querySelector('.btn-loading');
    const bi  = btn?.querySelector('.btn-icon');
    if (bt) bt.textContent = str('enviando');
    bl?.classList.remove('hidden'); bi?.classList.add('hidden');
    if (btn) btn.disabled = true;
    fetch('/api/contact.php', { method:'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(d => {
            if (d.success) { this.reset(); openCotizModal(d); }
            else            { Swal.fire({ icon:'error',   title:'Error', text: d.message || str('error_envio'), confirmButtonColor:'#202944' }); }
        })
        .catch(err => Swal.fire({ icon:'error', title:'Error', text: err.message || str('sin_conexion'), confirmButtonColor:'#202944' }))
        .finally(() => { if (bt) bt.textContent=str('enviar'); bl?.classList.add('hidden'); bi?.classList.remove('hidden'); if (btn) btn.disabled=false; });
});

// Flatpickr
document.addEventListener('DOMContentLoaded', () => {
    const fp = flatpickr('#bk-dates', {
        mode:'range', minDate:'today', dateFormat:'d M Y',
        locale:'<?= 'es' ?>', showMonths: window.innerWidth > 640 ? 2 : 1, disableMobile:true,
        onChange: (dates) => {
            if (dates.length === 2) {
                document.getElementById('bk-checkin').value  = dates[0].toISOString().split('T')[0];
                document.getElementById('bk-checkout').value = dates[1].toISOString().split('T')[0];
            }
        }
    });
    if (document.getElementById('bk-dates')) document.getElementById('bk-dates')._flatpickr = fp;

    // Timestamp anti-bot
    const ts = document.getElementById('leadFormTs');
    if (ts) ts.value = Math.floor(Date.now() / 1000);
});
</script>

<!-- ── Lightbox carrusel de habitaciones ──────────────────────────────────── -->
<div id="hab-lightbox"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.92);backdrop-filter:blur(8px)"
     onclick="if(event.target===this)cerrarLightbox()">

    <!-- Cerrar -->
    <button onclick="cerrarLightbox()"
            style="position:absolute;top:1rem;right:1rem;z-index:10;width:2.5rem;height:2.5rem;
                   background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
                   border-radius:50%;color:#fff;font-size:1.25rem;cursor:pointer;
                   display:flex;align-items:center;justify-content:center;
                   transition:background .2s" onmouseover="this.style.background='rgba(255,255,255,.2)'"
                   onmouseout="this.style.background='rgba(255,255,255,.1)'">✕</button>

    <!-- Contador -->
    <div id="lb-counter"
         style="position:absolute;top:1.125rem;left:50%;transform:translateX(-50%);
                color:rgba(255,255,255,.6);font-size:.8125rem;font-family:'Plus Jakarta Sans',sans-serif;
                background:rgba(0,0,0,.4);padding:.25rem .875rem;border-radius:999px;z-index:10">
        1 / 1
    </div>

    <!-- Imagen principal -->
    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:4rem 5rem">
        <img id="lb-img" src="" alt=""
             style="max-width:100%;max-height:100%;object-fit:contain;border-radius:.75rem;
                    transition:opacity .25s ease;user-select:none">
    </div>

    <!-- Flecha izquierda -->
    <button id="lb-prev" onclick="lightboxNav(-1)"
            style="position:absolute;left:1rem;top:50%;transform:translateY(-50%);z-index:10;
                   width:3rem;height:3rem;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
                   border-radius:50%;color:#fff;font-size:1.25rem;cursor:pointer;transition:all .2s;
                   display:flex;align-items:center;justify-content:center"
            onmouseover="this.style.background='rgba(255,255,255,.25)'"
            onmouseout="this.style.background='rgba(255,255,255,.1)'">‹</button>

    <!-- Flecha derecha -->
    <button id="lb-next" onclick="lightboxNav(1)"
            style="position:absolute;right:1rem;top:50%;transform:translateY(-50%);z-index:10;
                   width:3rem;height:3rem;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
                   border-radius:50%;color:#fff;font-size:1.25rem;cursor:pointer;transition:all .2s;
                   display:flex;align-items:center;justify-content:center"
            onmouseover="this.style.background='rgba(255,255,255,.25)'"
            onmouseout="this.style.background='rgba(255,255,255,.1)'">›</button>

    <!-- Thumbnails -->
    <div id="lb-thumbs"
         style="position:absolute;bottom:1.5rem;left:50%;transform:translateX(-50%);
                display:flex;gap:.5rem;max-width:90vw;overflow-x:auto;padding:.25rem;z-index:10">
    </div>
</div>

<script>
(function(){
    let _imgs   = [];
    let _cur    = 0;
    let _habIdx = null;

    window.abrirLightbox = function(habIdx) {
        const key = '_hab_' + habIdx;
        if (!window[key] || !window[key].length) return;
        _imgs   = window[key];
        _cur    = 0;
        _habIdx = habIdx;
        renderLightbox();
        document.getElementById('hab-lightbox').style.display = 'block';
        document.body.style.overflow = 'hidden';
    };

    window.cerrarLightbox = function() {
        document.getElementById('hab-lightbox').style.display = 'none';
        document.body.style.overflow = '';
    };

    window.lightboxNav = function(dir) {
        _cur = (_cur + dir + _imgs.length) % _imgs.length;
        renderLightbox();
    };

    function renderLightbox() {
        const img     = document.getElementById('lb-img');
        const counter = document.getElementById('lb-counter');
        const thumbs  = document.getElementById('lb-thumbs');
        const prev    = document.getElementById('lb-prev');
        const next    = document.getElementById('lb-next');

        // Imagen
        img.style.opacity = '0';
        setTimeout(() => {
            img.src         = '/' + _imgs[_cur].url;
            img.alt         = _imgs[_cur].alt || '';
            img.style.opacity = '1';
        }, 150);

        // Contador
        counter.textContent = (_cur + 1) + ' / ' + _imgs.length;

        // Flechas
        prev.style.display = _imgs.length > 1 ? 'flex' : 'none';
        next.style.display = _imgs.length > 1 ? 'flex' : 'none';

        // Thumbnails
        if (_imgs.length > 1) {
            thumbs.innerHTML = _imgs.map((img, i) =>
                '<div onclick="event.stopPropagation();lightboxGoto(' + i + ')" '
                + 'style="width:56px;height:40px;flex-shrink:0;border-radius:.4rem;overflow:hidden;cursor:pointer;'
                + 'border:2px solid ' + (i === _cur ? '#BAC4B9' : 'transparent') + ';transition:all .2s;opacity:' + (i === _cur ? '1' : '.5') + '">'
                + '<img src="/' + img.url + '" style="width:100%;height:100%;object-fit:cover">'
                + '</div>'
            ).join('');
        } else {
            thumbs.innerHTML = '';
        }
    }

    window.lightboxGoto = function(i) {
        _cur = i;
        renderLightbox();
    };

    // Teclado
    document.addEventListener('keydown', e => {
        if (document.getElementById('hab-lightbox').style.display === 'none') return;
        if (e.key === 'ArrowLeft')  lightboxNav(-1);
        if (e.key === 'ArrowRight') lightboxNav(1);
        if (e.key === 'Escape')     cerrarLightbox();
    });

    // Swipe en móvil
    let _touchX = 0;
    document.getElementById('hab-lightbox').addEventListener('touchstart', e => { _touchX = e.touches[0].clientX; });
    document.getElementById('hab-lightbox').addEventListener('touchend',   e => {
        const diff = _touchX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 50) lightboxNav(diff > 0 ? 1 : -1);
    });
})();
</script>
</body>
</html>