<!DOCTYPE html>
<html lang="<?= htmlLang() ?>" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?= SEO::renderHead($seo) ?>
    <?= SEO::renderPreloads($seo['hero_image'] ?? null) ?>

    <!-- Hreflang multiidioma -->
    <?php
    $_url     = defined('APP_URL') ? APP_URL : '';
    $_curPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $_esPath  = APP_LANG === 'en' ? (preg_replace('#^/en#', '', $_curPath) ?: '/') : $_curPath;
    $_enPath  = '/en' . $_esPath;
    ?>
    <link rel="alternate" hreflang="es"        href="<?= $_url . $_esPath ?>">
    <link rel="alternate" hreflang="en"        href="<?= $_url . $_enPath ?>">
    <link rel="alternate" hreflang="x-default" href="<?= $_url . $_esPath ?>">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fuentes -->
    <link href="https://fonts.googleapis.com/css2?family=Asap+Condensed:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Cloudbeds Widget — async para no bloquear el render -->
    <script src="https://hotels.cloudbeds.com/widget/load/<?= e(cfg('cloudbeds_default', CLOUDBEDS_DEFAULT_CODE)) ?>/immersive" async defer></script>

    <!-- reCAPTCHA -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>

    <!-- Tailwind Config -->
    <script>
    tailwind.config = {
        theme: { extend: {
            colors: {
                'park-blue':       '#202944',
                'park-blue-light': '#2C3A5E',
                'park-blue-dark':  '#161d30',
                'park-sage':       '#BAC4B9',
                'park-sage-light': '#D4DCD3',
                'park-cream':      '#f9f9f9'
            },
            fontFamily: {
                'asap':    ['Asap Condensed', 'sans-serif'],
                'jakarta': ['Plus Jakarta Sans', 'sans-serif']
            }
        }}
    }
    </script>

    <!-- CSS Variables desde BD + Estilos globales -->
    <?= SEO::renderColorVars($config) ?>

    <style>
        ::-webkit-scrollbar{width:8px}::-webkit-scrollbar-track{background:#f1f1f1}
        ::-webkit-scrollbar-thumb{background:#BAC4B9;border-radius:4px}
        ::-webkit-scrollbar-thumb:hover{background:#202944}
        ::selection{background:#BAC4B9;color:#202944}
        .loader{transition:opacity .6s ease,visibility .6s ease}
        .loader.hidden{opacity:0;visibility:hidden}
        .navbar-blur{backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}
        .reveal{opacity:0;transform:translateY(30px);transition:all .8s cubic-bezier(.4,0,.2,1)}
        .reveal.active{opacity:1;transform:translateY(0)}
        .card-lift{transition:all .4s cubic-bezier(.4,0,.2,1)}
        .card-lift:hover{transform:translateY(-8px);box-shadow:0 25px 50px -12px rgba(32,41,68,.25)}
        .property-card img{transition:transform .7s cubic-bezier(.4,0,.2,1)}
        .property-card:hover img{transform:scale(1.08)}
        .property-card{transition:all .4s cubic-bezier(.4,0,.2,1)}
        .property-card:hover{transform:translateY(-8px);box-shadow:0 25px 50px -12px rgba(32,41,68,.25)}
        .flatpickr-calendar{font-family:"Plus Jakarta Sans",sans-serif!important;border-radius:16px!important;box-shadow:0 25px 50px -12px rgba(0,0,0,.25)!important}
        .flatpickr-day.selected,.flatpickr-day.startRange,.flatpickr-day.endRange{background:#202944!important;border-color:#202944!important}
        .flatpickr-day.inRange{background:#BAC4B9!important;border-color:#BAC4B9!important}
        .number-input::-webkit-inner-spin-button,.number-input::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}
        .number-input{-moz-appearance:textfield}
        .filter-tab{transition:all .3s ease;cursor:pointer}
        .filter-tab.active{background:#202944;color:white}
        .filter-tab:not(.active):hover{background:#BAC4B9;color:#202944}
        .mode-tab{transition:all .3s ease}.mode-tab.active{background:#202944;color:white}
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
            #widgetDays>div>div:last-child,#widgetMonths form>div>div:last-child{border-bottom:none;padding:12px}
            #widgetDays>div>div:last-child button,#widgetMonths form>div>div:last-child button{width:100%!important}
        }
        .booking-widgets{transition:all .35s cubic-bezier(.4,0,.2,1)}
        @media(max-width:1023px){
            .booking-hero-wrapper.is-sticky{padding:8px 5%;top:72px}
            .booking-hero-wrapper.is-sticky .booking-widgets{display:none!important}
            .booking-hero-wrapper.is-sticky.is-expanded .booking-widgets{display:block!important}
        }
        @media(min-width:1024px){.booking-hero-wrapper.is-sticky .booking-widgets{display:block!important}}
        .booking-mobile-collapsed{display:none}
        .flip-card{perspective:1200px;cursor:pointer}
        .flip-inner{position:relative;width:100%;height:100%;transition:transform .65s cubic-bezier(.4,0,.2,1);transform-style:preserve-3d}
        .flip-card:hover .flip-inner{transform:rotateY(180deg)}
        .flip-front,.flip-back{position:absolute;top:0;left:0;width:100%;height:100%;backface-visibility:hidden;-webkit-backface-visibility:hidden;border-radius:1rem;overflow:hidden}
        .flip-back{transform:rotateY(180deg);background:rgba(32,41,68,0.65);backdrop-filter:blur(28px);-webkit-backdrop-filter:blur(28px);border:1px solid rgba(255,255,255,0.18)}
        .line-clamp-2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .job-card{transition:all .35s ease;border-left:3px solid transparent}
        .job-card:hover{border-left-color:#BAC4B9;transform:translateX(4px);box-shadow:0 10px 30px rgba(32,41,68,.12)}
        .slide{opacity:0;transition:opacity 1.4s ease;z-index:0}
        .slide.active{opacity:1;z-index:1}
        .slide.active img{animation:kenburns 8s ease forwards}
        @keyframes kenburns{from{transform:scale(1.08) translateX(0)}to{transform:scale(1) translateX(0)}}
        .scroll-indicator{animation:bounce 2s ease-in-out infinite}
        @keyframes bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(8px)}}
        .animate-pulse-slow{animation:pulseSlow 4s ease-in-out infinite}
        @keyframes pulseSlow{0%,100%{opacity:.5}50%{opacity:1}}
        .slide-dot{cursor:pointer}
        .hero-badge{animation:fadeUp .6s ease .3s both}
        .hero-h1{animation:fadeUp .6s ease .5s both}
        .hero-p{animation:fadeUp .6s ease .7s both}
        @keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
    </style>
</head>
<body class="font-jakarta bg-park-cream text-gray-800 antialiased">

<!-- LOADER -->
<div id="loader" class="loader fixed inset-0 z-[9999] bg-park-blue flex items-center justify-center">
    <div class="text-center">
        <div class="relative w-20 h-20 mx-auto mb-6">
            <div class="absolute inset-0 border-4 border-park-sage/30 rounded-full"></div>
            <div class="absolute inset-0 border-4 border-transparent border-t-park-sage rounded-full animate-spin"></div>
        </div>
        <p class="text-white font-asap text-3xl font-bold tracking-[.3em]"><?= e(cfg('marca_nombre', 'PARK LIFE')) ?></p>
        <p class="text-park-sage/70 text-xs tracking-[.3em] uppercase mt-2"><?= e(cfg('marca_sufijo', 'Properties')) ?></p>
    </div>
</div>

<!-- NAVBAR -->
<nav id="navbar" class="fixed top-0 left-0 right-0 z-50 transition-all duration-500">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-20">

            <!-- Logo -->
            <a href="<?= LANG_PREFIX ?>/" class="flex items-center">
                <img src="/<?= ltrim(e(cfg('logo_blanco', 'pics/Logo_ParkLife_Blanco.png')), '/') ?>"
                     id="logo-white" alt="Park Life Properties"
                     class="h-10 sm:h-12 w-auto transition-all duration-300"
                     onerror="this.style.display='none'">
                <img src="/<?= ltrim(e(cfg('logo_color', 'pics/Logo_Parklife.png')), '/') ?>"
                     id="logo-blue" alt="Park Life Properties"
                     class="h-10 sm:h-12 w-auto transition-all duration-300 hidden"
                     onerror="this.style.display='none'">
            </a>

            <!-- Links desktop -->
            <div class="hidden lg:flex items-center gap-8">
                <a href="<?= LANG_PREFIX ?>/#inicio"        class="nav-link text-white/90 hover:text-white text-sm font-medium transition-colors"><?= s('nav.inicio') ?></a>
                <a href="<?= LANG_PREFIX ?>/#propiedades"   class="nav-link text-white/90 hover:text-white text-sm font-medium transition-colors"><?= s('nav.propiedades') ?></a>
                <a href="<?= LANG_PREFIX ?>/#prensa"        class="nav-link text-white/90 hover:text-white text-sm font-medium transition-colors"><?= s('nav.prensa') ?></a>
                <a href="<?= LANG_PREFIX ?>/#nosotros"      class="nav-link text-white/90 hover:text-white text-sm font-medium transition-colors"><?= s('nav.nosotros') ?></a>
                <a href="<?= LANG_PREFIX ?>/#bolsa-trabajo" class="nav-link text-white/90 hover:text-white text-sm font-medium transition-colors"><?= s('nav.unete') ?></a>
                <a href="<?= LANG_PREFIX ?>/#contacto"      class="bg-white text-park-blue px-6 py-2.5 rounded-full font-semibold text-sm hover:bg-park-sage transition-all duration-300"><?= s('nav.contacto') ?></a>
                <!-- Switcher de idioma -->
                <a href="<?= langSwitch() ?>"
                   class="flex items-center gap-1.5 text-white/70 hover:text-white text-sm transition-colors border border-white/20 hover:border-white/50 rounded-full px-3 py-1.5"
                   translate="no"
                   title="<?= APP_LANG === 'en' ? 'Ver en Español' : 'View in English' ?>">
                    <span><?= APP_LANG === 'en' ? '🇲🇽' : '🇺🇸' ?></span>
                    <span class="font-medium"><?= APP_LANG === 'en' ? 'Español' : 'English' ?></span>
                </a>
            </div>

            <!-- Menú mobile -->
            <button id="mobile-menu-btn" class="lg:hidden p-2 text-white">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
        </div>
    </div>

    <!-- Mobile menu dropdown -->
    <div id="mobile-menu" class="lg:hidden hidden bg-white shadow-2xl rounded-b-3xl mx-4">
        <div class="px-6 py-8 space-y-4">
            <a href="<?= LANG_PREFIX ?>/#inicio"        class="block text-park-blue font-medium py-2"><?= s('nav.inicio') ?></a>
            <a href="<?= LANG_PREFIX ?>/#propiedades"   class="block text-park-blue font-medium py-2"><?= s('nav.propiedades') ?></a>
            <a href="<?= LANG_PREFIX ?>/#prensa"        class="block text-park-blue font-medium py-2"><?= s('nav.prensa') ?></a>
            <a href="<?= LANG_PREFIX ?>/#nosotros"      class="block text-park-blue font-medium py-2"><?= s('nav.nosotros') ?></a>
            <a href="<?= LANG_PREFIX ?>/#bolsa-trabajo" class="block text-park-blue font-medium py-2"><?= s('nav.unete_largo') ?></a>
            <a href="<?= LANG_PREFIX ?>/#contacto"      class="block bg-park-blue text-white text-center px-6 py-3 rounded-xl font-semibold"><?= s('nav.contacto') ?></a>
            <a href="<?= langSwitch() ?>"
               class="flex items-center gap-2 text-park-blue/60 hover:text-park-blue py-2 border-t border-gray-100 pt-4 text-sm font-medium"
               translate="no">
                <span><?= APP_LANG === 'en' ? '🇲🇽' : '🇺🇸' ?></span>
                <span><?= APP_LANG === 'en' ? 'Español' : 'English' ?></span>
            </a>
        </div>
    </div>
</nav>
