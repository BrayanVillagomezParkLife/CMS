<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seo.php';

// ─── Datos desde BD ──────────────────────────────────────────────────────────
$config                = getConfig();
$propiedades           = getPropiedadesActivas();
$propiedadesPorDestino = getPropiedadesPorDestino();
$heroSlides            = getHeroSlides();
$faqs                  = getFaqs();
$pilares               = getPilares();
$prensa                = getPrensa(3);
$totalPropiedades      = count($propiedades);

// Mapa Cloudbeds slug → code
$cloudbedsMap = ['default' => cfg('cloudbeds_default', CLOUDBEDS_DEFAULT_CODE)];
foreach ($propiedades as $p) {
    if (!empty($p['cloudbeds_code'])) $cloudbedsMap[$p['slug']] = $p['cloudbeds_code'];
}

// Textos cíclicos del hero
$destinoHeroTexts = [];
foreach ($heroSlides as $slide) {
    $destinoHeroTexts[] = $slide['propiedad_nombre'] ?? $slide['texto_es'] ?? 'cualquier destino';
}
if (empty($destinoHeroTexts)) {
    $destinoHeroTexts = ['Ciudad de México','Guadalajara','Polanco','Santa Fe','Querétaro','Riviera Nayarit'];
}

// Colores de badge por destino
$destinoBadgeColor = [
    'cdmx'        => '#202944',
    'guadalajara' => '#7c4d14',
    'queretaro'   => '#4a5568',
    'nayarit'     => '#0e5c7a',
];

// CSRF
if (session_status() === PHP_SESSION_NONE) session_start();
$csrfToken = generateCsrfToken();

// SEO
$seo = [
    'titulo'      => 'Departamentos y Propiedades Amuebladas Premium en México',
    'descripcion' => 'Renta departamentos amueblados premium en CDMX, Guadalajara, Querétaro y Riviera Nayarit. Estancias por días o meses con todo incluido. Factura disponible.',
    'keywords'    => 'departamentos amueblados CDMX, renta mensual Polanco, corporate housing México, departamento amueblado Condesa, renta vacacional Riviera Nayarit',
    'og_image'    => url('pics/og_home.jpg'),
    'canonical'   => BASE_URL . '/',
    'tipo'        => 'home',
    'hero_image'  => !empty($heroSlides[0]['imagen_url']) ? url($heroSlides[0]['imagen_url']) : null,
];

require __DIR__ . '/../templates/header.php';
?>

<!-- ═══ HERO ═══════════════════════════════════════════════════════════════ -->
<section id="inicio" class="relative min-h-screen flex items-center overflow-hidden">

    <!-- Slideshow background -->
    <div class="absolute inset-0" id="heroSlideshow">
        <?php foreach ($heroSlides as $i => $slide): ?>
        <div class="slide <?= $i === 0 ? 'active' : '' ?> absolute inset-0">
            <img src="/<?= e($slide['imagen_url']) ?>" class="w-full h-full object-cover"
                 alt="<?= e($slide['propiedad_nombre'] ?? 'Park Life Properties') ?>">
        </div>
        <?php endforeach; ?>
        <?php if (empty($heroSlides)): ?>
        <div class="slide active absolute inset-0"><img src="pics/hero_condesa.JPG" class="w-full h-full object-cover" alt="Park Life Condesa"></div>
        <div class="slide absolute inset-0"><img src="pics/hero_guadalajara.jpg" class="w-full h-full object-cover" alt="Park Life Guadalajara"></div>
        <div class="slide absolute inset-0"><img src="pics/hero_queretaro.jpg" class="w-full h-full object-cover" alt="Park Life Querétaro"></div>
        <?php endif; ?>
        <div class="absolute inset-0 bg-gradient-to-b from-park-blue/85 via-park-blue/65 to-park-blue/90"></div>
    </div>

    <div class="absolute top-1/4 right-1/4 w-80 h-80 bg-park-sage/15 rounded-full blur-3xl pointer-events-none animate-pulse-slow"></div>
    <div class="absolute bottom-1/3 left-1/5 w-96 h-96 bg-park-sage/10 rounded-full blur-3xl pointer-events-none animate-pulse-slow" style="animation-delay:2s"></div>

    <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-6 w-full">
        <div class="text-center text-white mb-6">

            <div class="hero-badge inline-flex items-center gap-2 bg-white/10 backdrop-blur-sm border border-white/20 rounded-full px-4 py-2 mb-6">
                <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                <span class="text-sm font-medium"><?= $totalPropiedades ?> <?= s('hero.badge') ?></span>
            </div>

            <h1 class="hero-h1 font-asap text-5xl sm:text-6xl lg:text-7xl font-bold leading-tight mb-3">
                <?= s('hero.tu_hogar') ?><br>
                <span class="text-park-sage inline-flex items-center gap-3">
                    <span id="heroDestino"><?= s('hero.destino_default') ?></span>
                </span>
            </h1>

            <p class="hero-p text-lg sm:text-xl text-white/70 max-w-2xl mx-auto leading-relaxed">
                <?= s('hero.subtitulo_1') ?><br class="hidden sm:block">
                <?= s('hero.subtitulo_2') ?>
            </p>

            <!-- Dots del slideshow -->
            <div class="flex justify-center gap-2 mt-6" id="slideDots">
                <?php $totalSlides = max(count($heroSlides), 3); ?>
                <?php for ($i = 0; $i < $totalSlides; $i++): ?>
                <span class="slide-dot <?= $i === 0 ? 'w-6 bg-white' : 'w-2 bg-white/30' ?> h-1 rounded-full transition-all duration-500"></span>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ── BOOKING ENGINE ── -->
        <div class="booking-hero-wrapper" id="bookingHeroWrapper">
            <div class="booking-container max-w-5xl mx-auto w-full">

                <!-- Pill tabs -->
                <div class="flex justify-center mb-3" id="pillContainer" onclick="expandOnMobile()">
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

                <!-- ══ MODO DÍAS ══ -->
                <div id="widgetDays" class="booking-engine bg-white rounded-2xl shadow-2xl overflow-hidden">
                    <div class="flex flex-col lg:flex-row lg:items-stretch lg:divide-x divide-gray-100" style="min-height:90px">
                        <div class="flex flex-col justify-center px-6 py-5 lg:w-44 flex-shrink-0">
                            <?php /* Plazo label */ ?>
                            <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.plazo') ?></p>
                            <p class="text-sm font-semibold text-park-blue flex items-center gap-1.5">
                                <?= s('booking.por_dias') ?> <i data-lucide="clock" class="w-3.5 h-3.5 text-gray-300"></i>
                            </p>
                        </div>
                        <div class="flex-1 min-w-0 flex flex-col justify-center px-6 py-5 border-t lg:border-t-0">
                            <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.propiedad') ?></p>
                            <div class="relative">
                                <select id="bk-property" class="w-full bg-transparent border-none p-0 text-sm font-semibold text-park-blue appearance-none outline-none cursor-pointer pr-4">
                                    <option value=""><?= s('booking.seleccionar') ?></option>
                                    <?php foreach ($propiedadesPorDestino as $destKey => $destGroup): ?>
                                    <optgroup label="<?= e($destGroup['nombre']) ?>">
                                        <?php foreach ($destGroup['propiedades'] as $prop): ?>
                                        <option value="<?= e($prop['slug']) ?>"><?= e($prop['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <i data-lucide="map-pin" class="absolute right-0 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-300 pointer-events-none"></i>
                            </div>
                        </div>
                        <div class="flex flex-col justify-center px-6 py-5 border-t lg:border-t-0 flex-[1.2]">
                            <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.fechas') ?></p>
                            <div class="relative">
                                <input type="text" id="bk-dates" placeholder="<?= s('booking.sel_fechas') ?>" readonly
                                       class="w-full bg-transparent border-none p-0 text-sm font-semibold text-park-blue cursor-pointer outline-none placeholder-gray-300 pr-5">
                                <i data-lucide="calendar" class="absolute right-0 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-300 pointer-events-none"></i>
                            </div>
                            <input type="hidden" id="bk-checkin">
                            <input type="hidden" id="bk-checkout">
                        </div>
                        <div class="flex flex-col justify-center px-6 py-5 border-t lg:border-t-0 lg:w-36 flex-shrink-0">
                            <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.huespedes') ?></p>
                            <div class="flex items-center gap-2">
                                <button onclick="bkDec()" type="button" class="w-6 h-6 rounded-full border border-gray-200 flex items-center justify-center hover:border-park-blue transition-colors text-xs">−</button>
                                <input type="number" id="bk-guests" value="2" min="1" max="10"
                                       class="number-input w-8 text-center text-sm font-semibold text-park-blue border-none outline-none p-0">
                                <button onclick="bkInc()" type="button" class="w-6 h-6 rounded-full border border-gray-200 flex items-center justify-center hover:border-park-blue transition-colors text-xs">+</button>
                            </div>
                        </div>
                        <div class="flex-shrink-0 p-3 flex items-center border-t lg:border-t-0">
                            <button onclick="bkSearch()" type="button"
                                    class="w-full lg:w-auto bg-park-blue text-white px-7 rounded-xl font-bold text-sm hover:bg-park-blue-light transition-all flex items-center justify-center gap-2 whitespace-nowrap"
                                    style="min-height:56px">
                                <?= s('booking.buscar') ?> <i data-lucide="search" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ══ MODO MESES ══ -->
                <form id="widgetMonths" class="hidden booking-engine bg-white rounded-2xl shadow-2xl overflow-hidden" onsubmit="submitLead(event)">
                    <input type="hidden" name="csrf_token"      value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="form_type"       value="lead_mensual">
                    <input type="text"   name="website"         style="display:none" tabindex="-1" autocomplete="off">
                    <input type="text"   name="confirm_email"   style="display:none" tabindex="-1" autocomplete="off">
                    <input type="text"   name="phone_confirm"   style="display:none" tabindex="-1" autocomplete="off">
                    <input type="hidden" name="form_timestamp"  id="leadFormTimestamp">
                    <input type="hidden" name="privacidad_ok" value="1">
                    <div class="flex flex-col lg:flex-row lg:items-stretch lg:divide-x divide-gray-100" style="min-height:90px">
                        <div class="flex-1 flex flex-col justify-center px-5 py-5 border-r border-gray-100">
                            <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.propiedad') ?></p>
                            <div class="relative">
                                <select name="propiedad_slug" id="bk-prop-months" class="w-full bg-transparent border-none p-0 text-sm font-semibold text-park-blue appearance-none outline-none cursor-pointer pr-4">
                                    <option value=""><?= s('booking.seleccionar') ?></option>
                                    <?php foreach ($propiedadesPorDestino as $destKey => $destGroup): ?>
                                    <optgroup label="<?= e($destGroup['nombre']) ?>">
                                        <?php foreach ($destGroup['propiedades'] as $prop): ?>
                                        <option value="<?= e($prop['slug']) ?>" data-id="<?= (int)$prop['id'] ?>"><?= e($prop['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <i data-lucide="map-pin" class="absolute right-0 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-300 pointer-events-none"></i>
                            </div>
                        </div>
                        <!-- Duración -->
                        <div class="flex-shrink-0 flex flex-col justify-center px-4 py-5 border-r border-gray-100" style="width:155px">
                            <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.duracion') ?></p>
                            <div class="relative">
                                <select name="duracion" id="bk-duracion" required
                                        class="w-full bg-transparent border-none p-0 text-sm font-semibold text-park-blue appearance-none outline-none cursor-pointer pr-4">
                                    <option value="1">1 mes</option>
                                    <option value="2">2 meses</option>
                                    <option value="3">3 meses</option>
                                    <option value="4">4 meses</option>
                                    <option value="5">5 meses</option>
                                    <option value="6" selected>6 meses</option>
                                    <option value="7">7 meses</option>
                                    <option value="8">8 meses</option>
                                    <option value="9">9 meses</option>
                                    <option value="10">10 meses</option>
                                    <option value="11">11 meses</option>
                                    <option value="12">12 meses</option>
                                    <option value="13">+12 meses</option>
                                </select>
                                <i data-lucide="chevron-down" class="absolute right-0 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-300 pointer-events-none"></i>
                            </div>
                        </div>
                        <!-- Nombre -->
                        <div class="flex-1 flex flex-col justify-center px-5 py-5 border-r border-gray-100">
                            <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.nombre') ?></p>
                            <input type="text" name="nombre" placeholder="<?= s('ph.nombre') ?>" required
                                   class="bg-transparent border-none p-0 text-sm font-semibold text-park-blue outline-none placeholder-gray-300 w-full">
                        </div>
                        <div class="flex-1 flex flex-col justify-center px-5 py-5 border-r border-gray-100">
                            <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.email') ?></p>
                            <input type="email" name="email" placeholder="tu@email.com" required
                                   class="bg-transparent border-none p-0 text-sm font-semibold text-park-blue outline-none placeholder-gray-300 w-full">
                        </div>
                        <div class="flex-1 flex flex-col justify-center px-5 py-5 border-r border-gray-100">
                            <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-1"><?= s('booking.whatsapp') ?></p>
                            <input type="tel" name="telefono" placeholder="+52 55 0000 0000" required
                                   class="bg-transparent border-none p-0 text-sm font-semibold text-park-blue outline-none placeholder-gray-300 w-full">
                        </div>
                        <div class="mascota-row flex-shrink-0" style="display:contents">
                            <div class="flex flex-col justify-center items-center py-5 border-r border-gray-100 flex-shrink-0" style="width:90px">
                                <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-2"><?= s('booking.mascota') ?></p>
                                <label class="cursor-pointer">
                                    <input type="checkbox" id="bk-mascota" name="mascota" class="sr-only peer">
                                    <div class="w-8 h-8 rounded-lg border-2 border-gray-200 peer-checked:border-park-blue peer-checked:bg-park-blue/5 flex items-center justify-center transition-all">
                                        <i data-lucide="paw-print" class="w-4 h-4 text-gray-300"></i></div></label>
                            </div>
                            <div class="flex flex-col justify-center items-center py-5 border-r border-gray-100 flex-shrink-0" style="width:90px">
                                <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mb-2"><?= s('booking.muebles') ?></p>
                                <label class="cursor-pointer">
                                    <input type="checkbox" id="bk-amueblado" name="amueblado" class="sr-only peer">
                                    <div class="w-8 h-8 rounded-lg border-2 border-gray-200 peer-checked:border-park-blue peer-checked:bg-park-blue/5 flex items-center justify-center transition-all">
                                        <i data-lucide="sofa" class="w-4 h-4 text-gray-300"></i></div></label>
                            </div>
                        </div>
                        <div class="flex-shrink-0 p-3 flex items-center">
                            <button type="submit" id="leadSubmitBtn"
                                    class="w-full lg:w-auto bg-park-blue text-white px-7 rounded-xl font-bold text-sm hover:bg-park-blue-light transition-all flex items-center justify-center gap-2 whitespace-nowrap"
                                    style="min-height:56px">
                                <span class="btn-text"><?= s('booking.cotizar') ?></span>
                                <span class="btn-loading hidden"><i data-lucide="loader" class="w-4 h-4 animate-spin"></i></span>
                                <i data-lucide="arrow-right" class="w-4 h-4 btn-icon"></i>
                            </button>
                        </div>
                    </div>
                </form>
                <p class="text-center text-white/40 text-xs mt-2 px-4"><?= s('booking.privacidad_implicita') ?> <a href="/legal" class="underline hover:text-white/70 transition-colors"><?= s('booking.privacidad_link') ?></a></p>

                </div><!-- /booking-widgets -->

                <!-- Mobile collapsed (sticky) -->
                <div class="booking-mobile-collapsed bg-white rounded-xl px-4 py-3 items-center justify-between gap-3" id="bookingMobileCollapsed">
                    <div class="flex items-center gap-2">
                        <i data-lucide="search" class="w-4 h-4 text-park-blue"></i>
                        <span class="text-park-blue text-sm font-semibold" id="mobileCollapsedLabel"><?= s('booking.cuando_donde') ?></span>
                    </div>
                    <button onclick="toggleMobileBooking()" class="bg-park-blue text-white px-5 py-2 rounded-xl text-sm font-bold flex items-center gap-1.5">
                        <?= s('booking.buscar') ?> <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="text-center mt-10">
            <a href="#stats" class="scroll-indicator inline-flex flex-col items-center gap-2 text-white/40 hover:text-white/70 transition-colors">
                <span class="text-xs tracking-widest uppercase"><?= s('hero.explorar') ?></span>
                <i data-lucide="chevron-down" class="w-5 h-5"></i>
            </a>
        </div>
    </div>
</section>

<!-- ═══ STATS ═══════════════════════════════════════════════════════════════ -->
<section id="stats" class="py-12 bg-white border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-8 text-center">
            <div class="reveal">
                <div class="font-asap text-5xl font-bold text-park-blue"><?= $totalPropiedades ?></div>
                <div class="text-gray-500 text-sm mt-1"><?= s('stats.propiedades') ?></div>
            </div>
            <div class="reveal" style="transition-delay:.1s">
                <div class="font-asap text-5xl font-bold text-park-blue"><?= count($propiedadesPorDestino) ?></div>
                <div class="text-gray-500 text-sm mt-1"><?= s('stats.destinos') ?></div>
            </div>
            <div class="reveal" style="transition-delay:.2s">
                <div class="font-asap text-5xl font-bold text-park-blue"><?= e(cfg('rating_google', '4.9')) ?></div>
                <div class="flex items-center justify-center gap-1 mt-1">
                    <i data-lucide="star" class="w-3.5 h-3.5 text-amber-400 fill-amber-400"></i>
                    <span class="text-gray-500 text-sm"><?= s('stats.calificacion') ?></span>
                </div>
            </div>
            <div class="reveal" style="transition-delay:.3s">
                <div class="font-asap text-5xl font-bold text-park-blue"><?= e(cfg('stat_huespedes', '+5k')) ?></div>
                <div class="text-gray-500 text-sm mt-1"><?= s('stats.huespedes') ?></div>
            </div>
        </div>
    </div>
</section>

<!-- ═══ PROPIEDADES ══════════════════════════════════════════════════════════ -->
<section id="propiedades" class="py-20 sm:py-28 bg-park-cream">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-3xl mx-auto mb-12 reveal">
            <span class="inline-block bg-park-sage/20 text-park-blue px-4 py-1.5 rounded-full text-sm font-semibold mb-4"><?= s('port.etiqueta') ?></span>
            <h2 class="font-asap text-4xl sm:text-5xl font-bold text-park-blue mb-6"><?= $totalPropiedades ?> <?= s('port.titulo') ?></h2>
            <p class="text-gray-600 text-lg leading-relaxed"><?= s('port.subtitulo') ?></p>
        </div>

        <!-- Filtros por destino -->
        <div class="flex flex-wrap justify-center gap-2 mb-10 reveal">
            <button class="filter-tab active px-5 py-2 rounded-full text-sm font-semibold border border-park-blue/20"
                    onclick="filterProperties('all',this)"><?= s('port.todos') ?></button>
            <?php foreach ($propiedadesPorDestino as $destKey => $destGroup): ?>
            <button class="filter-tab px-5 py-2 rounded-full text-sm font-semibold border border-park-blue/20 text-park-blue"
                    onclick="filterProperties('<?= e($destKey) ?>',this)">
                <?= e(!empty($destGroup['nombre_en']) && defined('APP_LANG') && APP_LANG === 'en' ? $destGroup['nombre_en'] : $destGroup['nombre']) ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Grid de propiedades -->
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6" id="propertiesGrid">
            <?php foreach ($propiedades as $i => $prop):
                $delay      = number_format($i * 0.05, 2);
                $destSlug   = $prop['destino_slug'] ?? 'cdmx';
                $destNombre = dbVal($prop, 'destino_nombre') ?: 'México';
                $badgeColor = $destinoBadgeColor[$destSlug] ?? '#202944';
                $cardImg    = !empty($prop['card_image']) ? $prop['card_image'] : 'pics/card_' . $prop['slug'] . '.webp';
                $precioDesde = !empty($prop['precio_desde_mes'])
                    ? formatPrice((float)$prop['precio_desde_mes']) . ' / ' . s('prop.mes_card')
                    : 'Consultar tarifas';
                $disponible = (bool)($prop['activo'] ?? true);
            ?>
            <div class="flip-card h-72 property-card reveal rounded-2xl shadow-md"
                 data-dest="<?= e($destSlug) ?>"
                 style="transition-delay:<?= $delay ?>s">
                <div class="flip-inner h-full">
                    <div class="flip-front overflow-hidden">
                        <img src="<?= e($cardImg) ?>" alt="<?= e($prop['nombre']) ?>" class="w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-park-blue/60 via-transparent to-transparent"></div>
                        <div class="absolute top-3 left-3">
                            <span class="text-white text-xs px-3 py-1 rounded-full font-semibold" style="background:<?= $badgeColor ?>">
                                <?= e($destNombre) ?>
                            </span>
                        </div>
                        <?php if ($disponible): ?>
                        <div class="absolute top-3 right-3">
                            <span class="bg-green-500 text-white text-xs px-2.5 py-1 rounded-full font-semibold flex items-center gap-1">
                                <span class="w-1.5 h-1.5 bg-white rounded-full"></span><?= s('prop.disponible') ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="absolute bottom-0 left-0 right-0 p-4">
                            <h3 class="font-asap text-xl font-bold text-white leading-tight"><?= e($prop['nombre']) ?></h3>
                            <p class="text-white/70 text-xs flex items-center gap-1 mt-0.5">
                                <i data-lucide="map-pin" class="w-3 h-3"></i>
                                <?= e(($prop['colonia'] ?? '') . ', ' . ($prop['ciudad'] ?? $destNombre)) ?>
                            </p>
                        </div>
                    </div>
                    <div class="flip-back flex flex-col justify-between p-5">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-widest text-white/70"><?= e($destNombre) ?></span>
                            <h3 class="font-asap text-xl font-bold text-white mt-0.5 mb-2 leading-tight drop-shadow-sm"><?= e($prop['nombre']) ?></h3>
                            <p class="text-white/80 text-xs leading-relaxed mb-3 line-clamp-2">
                                <?= e(dbVal($prop, 'hero_slogan') ?: dbVal($prop, 'descripcion_larga')) ?>
                            </p>
                            <div class="border-t border-white/25 pt-3">
                                <p class="text-white/70 text-[10px] uppercase tracking-widest font-bold mb-0.5"><?= s('booking.renta') ?></p>
                                <p class="text-white font-black text-base leading-tight drop-shadow-sm"><?= e($precioDesde) ?></p>
                                <p class="text-white/70 text-xs mt-0.5 flex items-center gap-1">
                                    <i data-lucide="map-pin" class="w-3 h-3"></i>
                                    <?= e(($prop['colonia'] ?? '') . ', ' . ($prop['ciudad'] ?? '')) ?>
                                </p>
                            </div>
                        </div>
                        <a href="<?= LANG_PREFIX ?>/<?= e($prop['slug']) ?>"
                           class="mt-3 block w-full text-center bg-park-sage text-park-blue py-2 rounded-xl text-sm font-black hover:bg-park-sage-light transition-all">
                            <?= s('booking.conoce_mas') ?>
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center mt-12 reveal">
            <button onclick="launchCloudbeds()"
                    class="inline-flex items-center gap-2 bg-park-blue text-white px-8 py-4 rounded-xl font-semibold hover:bg-park-blue-light transition-all duration-300 hover:shadow-xl">
                <i data-lucide="calendar-check" class="w-5 h-5"></i>
                <?= s('booking.verificar') ?>
            </button>
        </div>
    </div>
</section>

<!-- ═══ PRENSA ═══════════════════════════════════════════════════════════════ -->
<section id="prensa" class="py-20 sm:py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-2xl mx-auto mb-14 reveal">
            <span class="inline-block bg-park-sage/20 text-park-blue px-4 py-1.5 rounded-full text-sm font-semibold mb-4"><?= s('prensa.etiqueta') ?></span>
            <h2 class="font-asap text-4xl sm:text-5xl font-bold text-park-blue mb-4"><?= s('prensa.titulo') ?></h2>
            <p class="text-gray-500 text-lg"><?= s('prensa.subtitulo') ?></p>
        </div>

        <div class="flex flex-wrap justify-center items-center gap-8 sm:gap-14 mb-16 reveal">
            <?php foreach (['Forbes','Expansión','El Economista','Business Insider','Architectural Digest'] as $medio): ?>
            <span class="font-asap text-2xl font-bold text-gray-300 hover:text-park-blue transition-colors cursor-default tracking-tight">
                <?= e($medio) ?>
            </span>
            <?php endforeach; ?>
        </div>

        <div class="grid md:grid-cols-3 gap-6 reveal">
            <?php if (!empty($prensa)): ?>
                <?php foreach ($prensa as $articulo): ?>
                <article class="group bg-park-cream rounded-2xl overflow-hidden hover:shadow-xl transition-all duration-300 card-lift border border-gray-100">
                    <?php if (!empty($articulo['imagen_url'])): ?>
                    <div class="h-28 bg-white flex items-center justify-center px-6 border-b border-gray-100">
                        <img src="/<?= e($articulo['imagen_url']) ?>" alt="<?= e($articulo['medio']) ?>"
                             class="max-h-16 max-w-full object-contain filter grayscale group-hover:grayscale-0 transition-all duration-500">
                    </div>
                    <?php endif; ?>
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-3">
                            <?php if (empty($articulo['imagen_url'])): ?>
                            <span class="font-asap text-base font-bold text-gray-400 tracking-tight"><?= e($articulo['medio']) ?></span>
                            <?php endif; ?>
                            <span class="text-xs text-gray-400 ml-auto"><?= date('Y', strtotime($articulo['fecha_publicacion'])) ?></span>
                        </div>
                        <h3 class="font-asap text-xl font-bold text-park-blue mb-3 leading-snug">"<?= e($articulo['titulo']) ?>"</h3>
                        <p class="text-gray-500 text-sm leading-relaxed mb-5 line-clamp-3"><?= e($articulo['extracto']) ?></p>
                        <?php if (!empty($articulo['url_articulo'])): ?>
                        <a href="<?= e($articulo['url_articulo']) ?>" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 text-park-blue font-semibold text-sm hover:gap-3 transition-all">
                            <?= s('prensa.leer') ?> <i data-lucide="arrow-right" class="w-4 h-4"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ([
                    ['medio'=>'Forbes México','anio'=>'2025','titulo'=>'Las startups de renta premium que están cambiando cómo vivimos en México','extracto'=>'Park Life Properties figura entre las empresas que están redefiniendo el mercado de renta residencial premium con un modelo que combina tecnología, diseño y servicio.'],
                    ['medio'=>'Expansión','anio'=>'2025','titulo'=>'El auge del living premium: por qué más ejecutivos eligen rentar sobre comprar','extracto'=>'Con presencia en CDMX, Guadalajara, Querétaro y Riviera Nayarit, Park Life Properties se posiciona como referente de la nueva movilidad residencial premium.'],
                    ['medio'=>'Architectural Digest','anio'=>'2025','titulo'=>'Espacios que inspiran: los apartamentos más deseados de la Condesa','extracto'=>'Un recorrido por las propiedades más fotografiadas del barrio que catapultó a Park Life al mapa del diseño residencial contemporáneo.'],
                ] as $p): ?>
                <article class="group bg-park-cream rounded-2xl overflow-hidden hover:shadow-xl transition-all duration-300 card-lift border border-gray-100">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <span class="font-asap text-base font-bold text-gray-300 tracking-tight"><?= e($p['medio']) ?></span>
                            <span class="text-xs text-gray-400"><?= e($p['anio']) ?></span>
                        </div>
                        <h3 class="font-asap text-xl font-bold text-park-blue mb-3 leading-snug">"<?= e($p['titulo']) ?>"</h3>
                        <p class="text-gray-500 text-sm leading-relaxed line-clamp-3"><?= e($p['extracto']) ?></p>
                    </div>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="text-center mt-12 reveal">
            <a href="mailto:<?= e(EMAIL_PRENSA) ?>"
               class="inline-flex items-center gap-2 border-2 border-park-blue text-park-blue px-8 py-3.5 rounded-xl font-semibold hover:bg-park-blue hover:text-white transition-all duration-300">
                <i data-lucide="mail" class="w-4 h-4"></i>
                <?= s('prensa.contacto_prensa') ?> <?= e(EMAIL_PRENSA) ?>
            </a>
        </div>
    </div>
</section>

<!-- ═══ NOSOTROS ═════════════════════════════════════════════════════════════ -->
<section id="nosotros" class="py-20 sm:py-28 bg-park-blue relative overflow-hidden">
    <div class="absolute inset-0 opacity-5"
         style="background-image:url('data:image/svg+xml,%3Csvg width=60 height=60 viewBox=%270 0 60 60%27 xmlns=%27http://www.w3.org/2000/svg%27%3E%3Cg fill=%27none%27 fill-rule=%27evenodd%27%3E%3Cg fill=%27%23ffffff%27 fill-opacity=%270.4%27%3E%3Cpath d=%27M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z%27/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')">
    </div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div class="grid lg:grid-cols-2 gap-16 items-center">
            <div class="reveal">
                <span class="inline-block bg-park-sage/20 text-park-sage px-4 py-1.5 rounded-full text-sm font-semibold mb-6"><?= s('nos.etiqueta') ?></span>
                <h2 class="font-asap text-4xl sm:text-5xl lg:text-6xl font-bold text-white mb-6 leading-tight">
                    <?= s('nos.titulo_1') ?><br><span class="text-park-sage"><?= s('nos.titulo_2') ?></span><br><?= s('nos.titulo_3') ?>
                </h2>
                <p class="text-white/70 text-lg leading-relaxed mb-6">
                    <?= nl2br(e(s('nos.texto_1', cfg('texto_nosotros')))) ?>
                </p>
                <p class="text-white/60 text-base leading-relaxed mb-10">
                    <?= nl2br(e(s('nos.texto_2', cfg('texto_nosotros_2')))) ?>
                </p>
                <a href="#contacto"
                   class="inline-flex items-center gap-2 bg-park-sage text-park-blue px-8 py-4 rounded-xl font-bold hover:bg-park-sage-light transition-all duration-300">
                    <?= s('nos.cta') ?> <i data-lucide="arrow-right" class="w-5 h-5"></i>
                </a>
            </div>
            <div class="grid grid-cols-2 gap-4 reveal">
                <?php
                $pilarClasses = [
                    1 => '',
                    2 => 'mt-6',
                    3 => '-mt-2',
                    4 => 'mt-4',
                ];
                foreach ($pilares as $idx => $pilar):
                    $mtClass = $pilarClasses[$idx + 1] ?? '';
                ?>
                <div class="bg-white/10 rounded-2xl p-6 border border-white/10 hover:bg-white/15 transition-all <?= $mtClass ?>">
                    <div class="w-12 h-12 bg-park-sage/20 rounded-xl flex items-center justify-center mb-4">
                        <i data-lucide="<?= e($pilar['icono']) ?>" class="w-6 h-6 text-park-sage"></i>
                    </div>
                    <h4 class="font-asap text-lg font-bold text-white mb-2"><?= e(dbVal($pilar, 'titulo')) ?></h4>
                    <p class="text-white/60 text-sm"><?= e(dbVal($pilar, 'descripcion')) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- ═══ FAQ ══════════════════════════════════════════════════════════════════ -->
<?php if (!empty($faqs)): ?>
<section id="faq" class="py-20 sm:py-28 bg-park-cream">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-14 reveal">
            <span class="inline-block bg-park-sage/20 text-park-blue px-4 py-1.5 rounded-full text-sm font-semibold mb-4"><?= s('faq.etiqueta') ?></span>
            <h2 class="font-asap text-4xl sm:text-5xl font-bold text-park-blue mb-4"><?= s('faq.titulo') ?></h2>
            <p class="text-gray-500 text-lg"><?= s('faq.subtitulo') ?></p>
        </div>
        <div class="space-y-4 reveal">
            <?php foreach ($faqs as $faq): ?>
            <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <button class="w-full text-left px-6 py-5 flex items-center justify-between gap-4 hover:bg-park-cream/50 transition-colors faq-btn">
                    <span class="font-semibold text-park-blue"><?= e(dbVal($faq, 'pregunta')) ?></span>
                    <i data-lucide="chevron-down" class="w-5 h-5 text-park-sage flex-shrink-0 transition-transform duration-300 faq-icon"></i>
                </button>
                <div class="faq-answer hidden px-6 pb-5">
                    <p class="text-gray-600 leading-relaxed"><?= nl2br(e(dbVal($faq, 'respuesta'))) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══ CONTACTO ══════════════════════════════════════════════════════════════ -->
<section id="contacto" class="py-20 sm:py-28 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid lg:grid-cols-5 gap-16">
            <div class="lg:col-span-2 reveal">
                <span class="inline-block bg-park-sage/20 text-park-blue px-4 py-1.5 rounded-full text-sm font-semibold mb-6"><?= s('ctc.etiqueta') ?></span>
                <h2 class="font-asap text-4xl sm:text-5xl font-bold text-park-blue mb-6"><?= s('ctc.titulo') ?></h2>
                <p class="text-gray-600 text-lg leading-relaxed mb-8"><?= s('ctc.subtitulo') ?></p>
                <div class="space-y-4">
                    <a href="mailto:<?= e(EMAIL_INFO) ?>"
                       class="flex items-center gap-4 p-4 bg-park-cream rounded-xl hover:shadow-md transition-shadow group">
                        <div class="w-12 h-12 bg-park-blue/10 rounded-lg flex items-center justify-center"><i data-lucide="mail" class="w-6 h-6 text-park-blue"></i></div>
                        <div>
                            <div class="text-xs text-gray-400 font-medium uppercase tracking-wide"><?= s('ctc.email_lbl') ?></div>
                            <div class="font-semibold text-park-blue group-hover:underline"><?= e(EMAIL_INFO) ?></div>
                        </div>
                    </a>
                    <?php if ($wa = cfg('whatsapp_ventas', '')): ?>
                    <a href="https://wa.me/<?= e($wa) ?>" target="_blank"
                       class="flex items-center gap-4 p-4 bg-park-cream rounded-xl hover:shadow-md transition-shadow group">
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center"><i data-lucide="message-circle" class="w-6 h-6 text-green-600"></i></div>
                        <div>
                            <div class="text-xs text-gray-400 font-medium uppercase tracking-wide"><?= s('ctc.wa_lbl') ?></div>
                            <div class="font-semibold text-park-blue group-hover:underline"><?= s('ctc.escribenos') ?></div>
                        </div>
                    </a>
                    <?php endif; ?>
                    <div class="flex items-center gap-4 p-4 bg-park-cream rounded-xl">
                        <div class="w-12 h-12 bg-park-blue/10 rounded-lg flex items-center justify-center"><i data-lucide="clock" class="w-6 h-6 text-park-blue"></i></div>
                        <div>
                            <div class="text-xs text-gray-400 font-medium uppercase tracking-wide"><?= s('ctc.horario') ?></div>
                            <div class="font-semibold text-park-blue"><?= e(cfg('horario', 'Lun – Dom · 8:00 a 22:00')) ?></div>
                        </div>
                    </div>
                </div>
                <div class="flex gap-3 mt-8">
                    <?php if ($ig = cfg('instagram_url','')): ?><a href="<?= e($ig) ?>" target="_blank" class="w-11 h-11 bg-park-blue/10 rounded-xl flex items-center justify-center hover:bg-park-blue hover:text-white text-park-blue transition-all"><i data-lucide="instagram" class="w-5 h-5"></i></a><?php endif; ?>
                    <?php if ($fb = cfg('facebook_url','')): ?><a href="<?= e($fb) ?>" target="_blank" class="w-11 h-11 bg-park-blue/10 rounded-xl flex items-center justify-center hover:bg-park-blue hover:text-white text-park-blue transition-all"><i data-lucide="facebook" class="w-5 h-5"></i></a><?php endif; ?>
                    <?php if ($li = cfg('linkedin_url','')): ?><a href="<?= e($li) ?>" target="_blank" class="w-11 h-11 bg-park-blue/10 rounded-xl flex items-center justify-center hover:bg-park-blue hover:text-white text-park-blue transition-all"><i data-lucide="linkedin" class="w-5 h-5"></i></a><?php endif; ?>
                </div>
            </div>

            <div class="lg:col-span-3 reveal">
                <div class="bg-white rounded-3xl shadow-xl border border-gray-100 p-8 sm:p-10">
                    <h3 class="font-asap text-2xl font-bold text-park-blue mb-2"><?= s('form.titulo') ?></h3>
                    <p class="text-gray-500 mb-8"><?= s('form.subtitulo') ?></p>
                    <form id="contactForm" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="form_type"  value="contacto">
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
                        <div class="grid sm:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-park-blue mb-2"><?= s('form.propiedad') ?></label>
                                <select name="propiedad_interes"
                                        class="w-full px-4 py-3 bg-park-cream border-2 border-transparent rounded-xl focus:border-park-blue focus:bg-white outline-none transition-all appearance-none cursor-pointer">
                                    <option value=""><?= s('form.any_prop') ?></option>
                                    <?php foreach ($propiedadesPorDestino as $destGroup): ?>
                                    <optgroup label="<?= e($destGroup['nombre']) ?>">
                                        <?php foreach ($destGroup['propiedades'] as $prop): ?>
                                        <option value="<?= e($prop['slug']) ?>"><?= e($prop['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-park-blue mb-2"><?= s('form.tipo') ?></label>
                                <select name="tipo_estancia" required
                                        class="w-full px-4 py-3 bg-park-cream border-2 border-transparent rounded-xl focus:border-park-blue focus:bg-white outline-none transition-all appearance-none cursor-pointer">
                                    <option value=""><?= s('form.selecciona') ?></option>
                                    <option value="corta"><?= s('form.corta') ?></option>
                                    <option value="media"><?= s('form.media') ?></option>
                                    <option value="larga"><?= s('form.larga') ?></option>
                                    <option value="permanente"><?= s('form.permanente') ?></option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-park-blue mb-2"><?= s('form.mensaje') ?></label>
                            <textarea name="mensaje" rows="4" placeholder="<?= s('ph.mensaje') ?>"
                                      class="w-full px-4 py-3 bg-park-cream border-2 border-transparent rounded-xl focus:border-park-blue focus:bg-white outline-none transition-all resize-none"></textarea>
                        </div>
                        <div class="flex items-start gap-3">
                            <input type="checkbox" id="privacy-contact" name="privacidad_ok" required class="w-5 h-5 mt-0.5 accent-park-blue rounded">
                            <label for="privacy-contact" class="text-sm text-gray-600">
                                <?= s('form.privacidad_txt') ?> <a href="/legal" class="text-park-blue font-medium hover:underline"><?= s('form.politica') ?></a>.
                            </label>
                        </div>
                        <button type="submit" id="submitBtn"
                                class="w-full bg-park-blue text-white py-4 rounded-xl font-semibold text-lg hover:bg-park-blue-light transition-all duration-300 hover:shadow-xl flex items-center justify-center gap-2">
                            <span class="btn-text"><?= s('form.enviar') ?></span>
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

<?= SEO::schemaOrganization($config) ?>
<?php if (!empty($faqs)): ?>
<?= SEO::schemaFAQ($faqs) ?>
<?php endif; ?>

<script>
const destinoTexts = <?= json_encode($destinoHeroTexts, JSON_UNESCAPED_UNICODE) ?>;

// Hero slideshow
(function() {
    const slides = document.querySelectorAll('.slide');
    const dots   = document.querySelectorAll('.slide-dot');
    const destEl = document.getElementById('heroDestino');
    let cur = 0;
    function goTo(n) {
        if (!slides.length) return;
        slides[cur].classList.remove('active');
        if (dots[cur]) { dots[cur].classList.remove('w-6','bg-white'); dots[cur].classList.add('w-2','bg-white/30'); }
        cur = (n + slides.length) % slides.length;
        slides[cur].classList.add('active');
        if (dots[cur]) { dots[cur].classList.add('w-6','bg-white'); dots[cur].classList.remove('w-2','bg-white/30'); }
        if (destEl && destinoTexts[cur]) {
            destEl.style.opacity = 0; destEl.style.transform = 'translateY(8px)'; destEl.style.transition = 'all .4s ease';
            setTimeout(() => { destEl.textContent = destinoTexts[cur]; destEl.style.opacity = 1; destEl.style.transform = 'translateY(0)'; }, 300);
        }
    }
    dots.forEach((d, i) => d.addEventListener('click', () => goTo(i)));
    setInterval(() => goTo(cur + 1), 6000);
})();

document.addEventListener('DOMContentLoaded', () => {
    const ts = document.getElementById('leadFormTimestamp');
    if (ts) ts.value = Math.floor(Date.now() / 1000);

    const fp = flatpickr("#bk-dates", {
        mode:"range", minDate:"today", dateFormat:"d M Y", locale:"es",
        showMonths: window.innerWidth > 640 ? 2 : 1, disableMobile:true,
        onChange: (dates) => {
            if (dates.length === 2) {
                document.getElementById("bk-checkin").value  = dates[0].toISOString().split("T")[0];
                document.getElementById("bk-checkout").value = dates[1].toISOString().split("T")[0];
            }
        }
    });
    if (document.getElementById("bk-dates")) document.getElementById("bk-dates")._flatpickr = fp;

    document.querySelectorAll('.faq-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const answer = btn.nextElementSibling;
            const icon   = btn.querySelector('.faq-icon');
            const isOpen = !answer.classList.contains('hidden');
            document.querySelectorAll('.faq-answer').forEach(a => a.classList.add('hidden'));
            document.querySelectorAll('.faq-icon').forEach(i => i.style.transform = '');
            if (!isOpen) { answer.classList.remove('hidden'); if (icon) icon.style.transform = 'rotate(180deg)'; }
        });
    });
});

function submitLead(e) {
    e.preventDefault();
    const form   = document.getElementById("widgetMonths");
    const btn    = document.getElementById("leadSubmitBtn");
    const bt     = btn?.querySelector(".btn-text");
    const bl     = btn?.querySelector(".btn-loading");
    const bi     = btn?.querySelector(".btn-icon");

    // Capturar ANTES del reset
    const propSel    = document.getElementById("bk-prop-months");
    const propId     = propSel?.selectedOptions[0]?.dataset?.id || "0";
    const propNombre = propSel?.selectedOptions[0]?.text || "";
    const nombre     = form.querySelector("[name='nombre']")?.value?.trim() || "";
    const duracion   = form.querySelector("[name='duracion']")?.value || "1";
    const durLabel   = form.querySelector("[name='duracion'] option:checked")?.text || duracion + " mes";

    if (bt) bt.textContent = str('enviando');
    bl?.classList.remove("hidden"); bi?.classList.add("hidden");
    if (btn) btn.disabled = true;

    const fd = new FormData(form);
    fd.append("propiedad_id", propId);

    fetch("/api/send-lead.php", { method:"POST", body:fd })
        .then(r => {
            if (!r.ok && r.status === 0) throw new Error('Sin conexión');
            return r.text().then(text => {
                try { return JSON.parse(text); }
                catch(e) { console.error('Response no es JSON:', text); throw new Error('Respuesta inválida del servidor'); }
            });
        })
        .then(d => {
            form.reset();
            if (d.success) {
                openCotizModal(d);
            } else {
                Swal.fire({ icon:"error", title:"Error", text: d.message || str('error_envio'), confirmButtonColor:"#202944" });
            }
        })
        .catch(err => Swal.fire({ icon:"error", title:"Error", text: err.message || str('sin_conexion'), confirmButtonColor:"#202944" }))
        .finally(() => { if (bt) bt.textContent=str('cotizar_mensual'); bl?.classList.add("hidden"); bi?.classList.remove("hidden"); if (btn) btn.disabled=false; });
}

function openCotizModal(d) {
    const c   = d.cotizacion || {};
    const fmt = n => n ? "$" + Number(n).toLocaleString("es-MX", {maximumFractionDigits:0}) : "$—";
    const primer = (c.nombre || "Cliente").split(" ")[0];
    document.getElementById("cotiz-saludo").textContent = "¡Hola, " + primer + "! 🎉";
    document.getElementById("cotiz-sub").textContent    = "Pre-cotización para " + (c.propiedad || "");
    document.getElementById("cotiz-precio").textContent = fmt(c.precio_desde);
    const durParts = (c.duracion || "1 mes").split(" ");
    document.getElementById("cotiz-dur-num").textContent   = durParts[0] || "—";
    document.getElementById("cotiz-dur-label").textContent = durParts.slice(1).join(" ") || "mes";
    const tags = document.getElementById("cotiz-tags");
    tags.innerHTML = "";
    const mkTag = (icon, txt) => `<span class="flex items-center gap-1 text-[11px] font-semibold text-park-blue bg-gray-50 border border-gray-200 rounded-lg px-2 py-1"><i data-lucide="${icon}" class="w-3 h-3 text-park-sage"></i>${txt}</span>`;
    tags.innerHTML += mkTag("user", primer);
    if (c.propiedad) tags.innerHTML += mkTag("building-2", c.propiedad);
    if (c.duracion)  tags.innerHTML += mkTag("calendar-range", c.duracion);
    if (c.amueblado) tags.innerHTML += mkTag("sofa", "Amueblado");
    if (c.mascota)   tags.innerHTML += mkTag("paw-print", "Mascota");
    const habs = document.getElementById("cotiz-habs");
    habs.innerHTML = "";
    (c.habitaciones || []).forEach(h => {
        const best = h.mejor_precio;
        habs.innerHTML += `<div class="flex items-center justify-between p-3 rounded-xl border ${best ? 'border-park-blue/20 bg-park-blue/[0.03]' : 'border-gray-100'} gap-3">
            <div>
                <p class="text-sm font-bold text-park-blue flex items-center gap-1.5 flex-wrap">${h.nombre}${best ? ' <span class="text-[9px] font-extrabold uppercase tracking-wide bg-park-blue text-white px-1.5 py-0.5 rounded">Mejor precio</span>' : ''}</p>
                <p class="text-xs text-gray-400">${h.capacidad ? h.capacidad+' personas' : ''}${h.metros ? ' · '+h.metros+' m²' : ''}</p>
            </div>
            <div class="text-right flex-shrink-0">
                <p class="font-asap font-extrabold text-lg text-park-blue leading-none">${fmt(h.precio)}</p>
                <p class="text-xs text-gray-400">/mes</p>
            </div>
        </div>`;
    });
    if (!c.habitaciones || c.habitaciones.length === 0) {
        habs.innerHTML = '<p class="text-xs text-gray-400 text-center py-2">Un asesor te enviará precios detallados.</p>';
    }
    const asesor = c.asesor || "Asesor Park Life";
    document.getElementById("cotiz-asesor").textContent = asesor;
    document.getElementById("cotiz-av").textContent = asesor.split(" ").map(w => w[0]).join("").toUpperCase().substring(0, 2);
    document.getElementById("modalCotizacion").classList.remove("hidden");
    document.body.style.overflow = "hidden";
    lucide.createIcons();
}
function closeCotizModal() {
    document.getElementById("modalCotizacion").classList.add("hidden");
    document.body.style.overflow = "";
}
</script>


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
            <p class="text-[9px] font-black uppercase tracking-[2.5px] text-gray-300 mb-2 flex items-center gap-2 after:flex-1 after:h-px after:bg-gray-100 after:content-[\'\']">Opciones disponibles</p>
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