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
            <p class="text-white/40 text-sm">© <?= $year ?> Park Life Properties. <?= s('footer.derechos') ?></p>
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                <span class="text-white/40 text-sm"><?= (int)cfg('total_propiedades', '14') ?> <?= s('footer.props_disp') ?></span>
            </div>
        </div>
    </div>
</footer>

<!-- ── Strings traducibles para JavaScript — servidos desde BD vía s() ─── -->
<?php
// ── Strings JS leídos de BD — multiidioma gestionado desde CMS ─────────
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

<!-- ── JS Global ──────────────────────────────────────────────────────────── -->
<script>
/** Lee un string traducido del DOM (DeepL ya lo procesó junto con el HTML) */
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
</script>
</body>
</html>
