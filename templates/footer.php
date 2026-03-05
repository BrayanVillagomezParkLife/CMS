<?php
$year = date('Y');
?>

<!-- FOOTER -->
<footer class="bg-park-blue text-white pt-16 pb-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-12 mb-12">

            <!-- Columna 1: Marca -->
            <div>
                <img src="<?= e(cfg('logo_blanco', 'pics/Logo_ParkLife_Blanco.png')) ?>"
                     alt="Park Life Properties" class="h-10 w-auto mb-6"
                     onerror="this.outerHTML='<p class=\'font-asap text-2xl font-bold text-white mb-6\'><?= e(cfg('marca_nombre', 'PARK LIFE')) ?></p>'">
                <p class="text-white/60 text-sm leading-relaxed mb-6">
                    <?= s('footer.slogan') ?>
                </p>
                <div class="flex gap-3">
                    <?php if ($fb = cfg('facebook_url', 'https://www.facebook.com/parklifeproperties/')): ?>
                    <a href="<?= e($fb) ?>" target="_blank" rel="noopener"
                       class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center hover:bg-park-sage hover:text-park-blue transition-all">
                        <i data-lucide="facebook" class="w-5 h-5"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($ig = cfg('instagram_url', 'https://www.instagram.com/parklifeproperties')): ?>
                    <a href="<?= e($ig) ?>" target="_blank" rel="noopener"
                       class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center hover:bg-park-sage hover:text-park-blue transition-all">
                        <i data-lucide="instagram" class="w-5 h-5"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($li = cfg('linkedin_url', 'https://www.linkedin.com/company/parklifeproperties')): ?>
                    <a href="<?= e($li) ?>" target="_blank" rel="noopener"
                       class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center hover:bg-park-sage hover:text-park-blue transition-all">
                        <i data-lucide="linkedin" class="w-5 h-5"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Columnas 2 & 3: Propiedades por destino -->
            <?php
            $destinos      = array_values($propiedadesPorDestino ?? []);
            $col1_destinos = array_slice($destinos, 0, 2);
            $col2_destinos = array_slice($destinos, 2);
            ?>
            <div>
                <?php foreach ($col1_destinos as $destino): ?>
                <h4 class="font-asap font-semibold text-lg mb-4"><?= e($destino['nombre']) ?></h4>
                <ul class="space-y-2 text-sm text-white/60 mb-6">
                    <?php foreach ($destino['propiedades'] as $prop): ?>
                    <li><a href="<?= LANG_PREFIX ?>/<?= e($prop['slug']) ?>" class="hover:text-park-sage transition-colors"><?= e($prop['nombre']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php endforeach; ?>
            </div>
            <div>
                <?php foreach ($col2_destinos as $destino): ?>
                <h4 class="font-asap font-semibold text-lg mb-4"><?= e($destino['nombre']) ?></h4>
                <ul class="space-y-2 text-sm text-white/60 mb-6">
                    <?php foreach ($destino['propiedades'] as $prop): ?>
                    <li><a href="<?= LANG_PREFIX ?>/<?= e($prop['slug']) ?>" class="hover:text-park-sage transition-colors"><?= e($prop['nombre']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php endforeach; ?>
                <!-- Links Park Life -->
                <h4 class="font-asap font-semibold text-lg mb-4"><?= e(cfg('marca_nombre_corto', 'Park Life')) ?></h4>
                <ul class="space-y-2 text-sm text-white/60">
                    <li><a href="<?= LANG_PREFIX ?>/#prensa"        class="hover:text-park-sage transition-colors"><?= s('footer.prensa') ?></a></li>
                    <li><a href="<?= LANG_PREFIX ?>/#nosotros"       class="hover:text-park-sage transition-colors"><?= s('footer.nosotros') ?></a></li>
                    <li><a href="<?= LANG_PREFIX ?>/#bolsa-trabajo"  class="hover:text-park-sage transition-colors"><?= s('footer.bolsa') ?></a></li>
                    <li><a href="<?= LANG_PREFIX ?>/#contacto"       class="hover:text-park-sage transition-colors"><?= s('footer.contacto') ?></a></li>
                    <li><a href="/legal"           class="hover:text-park-sage transition-colors"><?= s('footer.privacidad') ?></a></li>
                    <li><a href="/terminos"        class="hover:text-park-sage transition-colors"><?= s('footer.terminos') ?></a></li>
                </ul>
            </div>
        </div>

        <!-- Bottom bar -->
        <div class="pt-8 border-t border-white/10 flex flex-col sm:flex-row justify-between items-center gap-4">
            <p class="text-white/40 text-sm">&copy; <?= $year ?> Park Life Properties. <?= s('footer.derechos') ?></p>
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                <span class="text-white/40 text-sm"><?= (int)cfg('total_propiedades', '14') ?> <?= s('footer.props_disp') ?></span>
            </div>
        </div>
    </div>
</footer>

<!-- ── Strings traducibles para JavaScript ─────────────────────────────── -->
<?php
$jsStrings = [
    'cuando_donde','cotizar_mensual','fechas_titulo','fechas_texto',
    'propiedad_titulo','propiedad_texto','enviando','exito','error_envio',
    'error_titulo','sin_conexion','enviar','live','actualizado',
    'success_contacto','success_bolsa'
];
?>
<div id="js-i18n" aria-hidden="true" style="display:none;position:absolute;overflow:hidden;height:0">
    <?php foreach ($jsStrings as $k): ?>
    <span data-k="<?= $k ?>"><?= e(s('js.' . $k)) ?></span>
    <?php endforeach; ?>
</div>

<!-- ── WhatsApp data (ANTES del script para que el JS lo encuentre) ────── -->
<?php
    $__waNum = $propiedad['whatsapp'] ?? cfg('whatsapp_ventas', '525543481711');
    $__waCtx = isset($propiedad['nombre']) ? $propiedad['nombre'] : 'Park Life Properties';
?>
<div id="pk-wa-data" data-wa="<?= e(preg_replace('/[^0-9]/', '', $__waNum)) ?>" data-ctx="<?= e($__waCtx) ?>" style="display:none"></div>

<!-- ── JS Global ──────────────────────────────────────────────────────────── -->
<script>
/** Lee un string traducido del DOM */
function str(k) {
    return document.querySelector('#js-i18n [data-k="' + k + '"]')?.textContent?.trim() || k;
}
window.addEventListener("load", () => {
    setTimeout(() => {
        document.getElementById("loader")?.classList.add("hidden");
        lucide.createIcons();
        initReveal();
    }, 800);
});

// ── Navbar ──
const navbar        = document.getElementById("navbar");
const logoWhite     = document.getElementById("logo-white");
const logoBlue      = document.getElementById("logo-blue");
const navLinks      = document.querySelectorAll(".nav-link");
const mobileMenuBtn = document.getElementById("mobile-menu-btn");
const mobileMenu    = document.getElementById("mobile-menu");

window.addEventListener("scroll", () => {
    const scrolled = window.scrollY > 80;
    navbar.classList.toggle("bg-white",    scrolled);
    navbar.classList.toggle("shadow-lg",   scrolled);
    navbar.classList.toggle("navbar-blur", scrolled);
    logoWhite?.classList.toggle("hidden",  scrolled);
    logoBlue?.classList.toggle("hidden",   !scrolled);
    navLinks.forEach(l => l.style.color = scrolled ? "#202944" : "");
    if (mobileMenuBtn) mobileMenuBtn.style.color = scrolled ? "#202944" : "white";
    handleStickyBooking();
});

mobileMenuBtn?.addEventListener("click", () => mobileMenu?.classList.toggle("hidden"));
mobileMenu?.querySelectorAll("a").forEach(a => a.addEventListener("click", () => mobileMenu.classList.add("hidden")));

// ── Sticky Booking ──
const bookingHeroWrapper = document.getElementById("bookingHeroWrapper");
let bookingOrigTop = null;

function handleStickyBooking() {
    if (!bookingHeroWrapper) return;
    if (!bookingOrigTop) bookingOrigTop = bookingHeroWrapper.getBoundingClientRect().top + window.scrollY;
    if (window.scrollY > bookingOrigTop + bookingHeroWrapper.offsetHeight) {
        bookingHeroWrapper.classList.add("is-sticky");
        document.body.classList.add("booking-sticky");
    } else {
        bookingHeroWrapper.classList.remove("is-sticky", "is-expanded");
        document.body.classList.remove("booking-sticky");
    }
}

function toggleMobileBooking() {
    document.getElementById("bookingHeroWrapper")?.classList.toggle("is-expanded");
    lucide.createIcons();
}

function expandOnMobile() {
    const w = document.getElementById("bookingHeroWrapper");
    if (w?.classList.contains("is-sticky") && window.innerWidth < 1024) {
        w.classList.toggle("is-expanded");
    }
}

// ── Booking Engine ──
let bkPlayzo = "days";

function setPlayzo(mode) {
    const w = document.getElementById("bookingHeroWrapper");
    if (w?.classList.contains("is-sticky") && window.innerWidth < 1024) {
        const alreadyExpanded = w.classList.contains("is-expanded");
        if (mode === bkPlayzo && alreadyExpanded) { w.classList.remove("is-expanded"); return; }
        w.classList.add("is-expanded");
    }
    bkPlayzo = mode;
    const btnD  = document.getElementById("toggleDays");
    const btnM  = document.getElementById("toggleMonths");
    const wD    = document.getElementById("widgetDays");
    const wM    = document.getElementById("widgetMonths");
    const isDays = mode === "days";
    if (isDays) {
        btnD.style.cssText = "background:rgba(255,255,255,0.95);color:#202944;box-shadow:0 2px 8px rgba(0,0,0,.15)";
        btnM.style.cssText = "color:rgba(255,255,255,0.65)";
    } else {
        btnM.style.cssText = "background:rgba(255,255,255,0.95);color:#202944;box-shadow:0 2px 8px rgba(0,0,0,.15)";
        btnD.style.cssText = "color:rgba(255,255,255,0.65)";
    }
    wD?.classList.toggle("hidden", !isDays);
    wM?.classList.toggle("hidden", isDays);
    document.getElementById("mobileCollapsedLabel").textContent = isDays ? str("cuando_donde") : str("cotizar_mensual");
    lucide.createIcons();
}

function bkInc() { const i = document.getElementById("bk-guests"); if (+i.value < 10) i.value = +i.value + 1; }
function bkDec() { const i = document.getElementById("bk-guests"); if (+i.value > 1)  i.value = +i.value - 1; }

function bkSearch() {
    const prop   = document.getElementById("bk-property")?.value;
    const guests = document.getElementById("bk-guests")?.value || 2;
    const cloudbedsMap = <?= json_encode($cloudbedsMap ?? ['default' => CLOUDBEDS_DEFAULT_CODE]) ?>;
    const code = cloudbedsMap[prop] || cloudbedsMap['default'] || '<?= e(CLOUDBEDS_DEFAULT_CODE) ?>';
    if (bkPlayzo === "days") {
        const ci = document.getElementById("bk-checkin")?.value;
        const co = document.getElementById("bk-checkout")?.value;
        if (!ci || !co) {
            Swal.fire({ icon:"info", title:str("fechas_titulo"), text:str("fechas_texto"), confirmButtonColor:"#202944" });
            document.getElementById("bk-dates")?._flatpickr?.open();
            return;
        }
        window.open(`https://hotels.cloudbeds.com/reservation/${code}?checkin=${ci}&checkout=${co}&adults=${guests}`, "_blank");
    } else {
        if (!prop) {
            Swal.fire({ icon:"info", title:str("propiedad_titulo"), text:str("propiedad_texto"), confirmButtonColor:"#202944" });
            return;
        }
        const mascota   = document.getElementById("bk-mascota")?.checked  ? "sí" : "no";
        const amueblado = document.getElementById("bk-amueblado")?.checked ? "sí" : "no";
        const params    = new URLSearchParams({ mascota, amueblado, tipo:"mensual" });
        window.location.href = `/${prop}?${params.toString()}#cotizar`;
    }
}

function launchCloudbeds() {
    window.open('https://hotels.cloudbeds.com/reservation/<?= e(CLOUDBEDS_DEFAULT_CODE) ?>', '_blank');
}

function filterProperties(dest, btn) {
    document.querySelectorAll(".filter-tab").forEach(t => t.classList.remove("active"));
    btn.classList.add("active");
    document.querySelectorAll(".property-card").forEach(c => {
        const show = dest === "all" || c.dataset.dest === dest;
        c.style.display = show ? "" : "none";
        if (show) setTimeout(() => c.classList.add("active"), 10);
    });
}

function initReveal() {
    const obs = new IntersectionObserver(
        entries => entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add("active"); obs.unobserve(e.target); } }),
        { threshold:.1, rootMargin:"0px 0px -50px 0px" }
    );
    document.querySelectorAll(".reveal").forEach(el => obs.observe(el));
}

function handleFormSubmit(fid, bid, msg, endpoint) {
    const form = document.getElementById(fid);
    if (!form) return;
    form.addEventListener("submit", function(e) {
        e.preventDefault();
        const btn = document.getElementById(bid);
        const bt  = btn?.querySelector(".btn-text");
        const bl  = btn?.querySelector(".btn-loading");
        const bi  = btn?.querySelector(".btn-icon");
        if (bt) bt.textContent = str("enviando");
        bl?.classList.remove("hidden"); bi?.classList.add("hidden");
        if (btn) btn.disabled = true;
        fetch(endpoint || "/api/contact.php", { method:"POST", body:new FormData(this) })
            .then(r => r.json())
            .then(d => {
                if (d.success) { Swal.fire({ icon:"success", title:str("exito"), text:msg, confirmButtonColor:"#202944", timer:5000, timerProgressBar:true }); this.reset(); }
                else            { Swal.fire({ icon:"error", title:"Error", text:d.message||str("error_envio"), confirmButtonColor:"#202944" }); }
            })
            .catch(() => Swal.fire({ icon:"error", title:str("error_titulo"), text:str("sin_conexion"), confirmButtonColor:"#202944" }))
            .finally(() => { if (bt) bt.textContent=str("enviar"); bl?.classList.add("hidden"); bi?.classList.remove("hidden"); if (btn) btn.disabled=false; });
    });
}

handleFormSubmit("contactForm",  "submitBtn",   str("success_contacto"), "/api/contact.php");
handleFormSubmit("jobForm",      "jobSubmitBtn",str("success_bolsa"),    "/api/contact.php");

// ══════════════════════════════════════════════════════════════════════════
// UTM Tracking + WhatsApp flotante con Campaign ID
// ══════════════════════════════════════════════════════════════════════════
(function(){
    // === 1. Capturar UTMs de la URL ===
    var p = new URLSearchParams(location.search);
    var utmKeys = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id'];
    var campaignParams = ['campaignid','campaign_id','gad_campaignid','fbcampaignid'];
    var utms = {};
    utmKeys.forEach(function(k){ if(p.get(k)) utms[k]=p.get(k); });
    campaignParams.forEach(function(k){ if(p.get(k)) utms[k]=p.get(k); });

    // Guardar en sessionStorage (persiste entre páginas)
    if(Object.keys(utms).length) sessionStorage.setItem('pk_utms',JSON.stringify(utms));
    var saved = JSON.parse(sessionStorage.getItem('pk_utms')||'{}');

    // === 2. Inyectar UTMs en todos los forms ===
    if(Object.keys(saved).length){
        document.querySelectorAll('form').forEach(function(f){
            Object.entries(saved).forEach(function(e){
                if(!f.querySelector('input[name="'+e[0]+'"]')){
                    var i=document.createElement('input');
                    i.type='hidden'; i.name=e[0]; i.value=e[1];
                    f.appendChild(i);
                }
            });
        });
    }

    // === 3. Resolver Campaign ID (misma lógica del whatsapp_custom.js original) ===
    function getCampaignId(){
        if(saved.utm_id) return saved.utm_id;
        var cKeys=['campaignid','campaign_id','gad_campaignid','fbcampaignid'];
        for(var j=0;j<cKeys.length;j++){ if(saved[cKeys[j]]) return saved[cKeys[j]]; }
        if(saved.utm_campaign){
            var nums=saved.utm_campaign.match(/\d{6,}/);
            return nums ? nums[0] : saved.utm_campaign;
        }
        return null;
    }

    // === 4. Crear botón flotante WhatsApp ===
    var waData = document.getElementById('pk-wa-data');
    if(!waData) return;
    var waNum = waData.dataset.wa;
    var waCtx = waData.dataset.ctx;
    if(!waNum) return;
    if(document.getElementById('pk-wa-float')) return;

    var btn = document.createElement('a');
    btn.id = 'pk-wa-float';
    btn.href = '#';
    btn.target = '_blank';
    btn.setAttribute('aria-label','Chatea con nosotros por WhatsApp');
    btn.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:2147483647;text-decoration:none;transition:all .3s ease';
    btn.innerHTML = '<div style="background:#25D366;border-radius:50%;width:60px;height:60px;display:flex;justify-content:center;align-items:center;box-shadow:0 4px 12px rgba(0,0,0,.15);transition:transform .3s ease">'
        + '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:35px;height:35px;fill:white">'
        + '<path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/>'
        + '</svg>'
        + '<div style="position:absolute;top:0;right:0;width:12px;height:12px;background:#FF4B4B;border-radius:50%;border:2px solid white"></div>'
        + '</div>';

    btn.addEventListener('mouseover', function(){ this.firstChild.style.transform='scale(1.1)'; });
    btn.addEventListener('mouseout',  function(){ this.firstChild.style.transform='scale(1)'; });

    btn.addEventListener('click', function(e){
        e.preventDefault();
        var cid = getCampaignId();
        var msg;
        if(cid){
            msg = 'ID: ' + cid + '\n\u00a1Hola!, deseo informaci\u00f3n sobre ' + waCtx;
        } else {
            msg = '\u00a1Hola!, deseo informaci\u00f3n sobre ' + waCtx;
        }
        // GTM dataLayer
        if(window.dataLayer){
            window.dataLayer.push({
                'event':'whatsapp_click',
                'campaign_id': cid || 'organic',
                'traffic_type': cid ? 'paid' : 'organic',
                'property': waCtx,
                'timestamp': new Date().toISOString()
            });
        }
        window.open('https://wa.me/' + waNum + '?text=' + encodeURIComponent(msg), '_blank');
    });

    document.body.appendChild(btn);
})();
</script>
</body>
</html>