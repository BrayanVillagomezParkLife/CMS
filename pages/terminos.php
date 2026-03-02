<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seo.php';

$config  = getConfig();
$empresa = cfg('nombre_empresa', 'Park Life Properties');
$email   = cfg('email_info', 'info@parklife.mx');
$hoy     = date('d \d\e F \d\e Y');

$seo = [
    'titulo'      => 'Términos y Condiciones — ' . $empresa,
    'descripcion' => 'Conoce los términos y condiciones que rigen el uso del sitio web y los servicios de ' . $empresa . '.',
    'canonical'   => BASE_URL . '/terminos',
    'no_index'    => true,
];

require __DIR__ . '/../templates/header.php';
?>

<main class="min-h-screen bg-white font-jakarta pt-32 pb-24">
    <div class="max-w-4xl mx-auto px-4 sm:px-8">

        <!-- Header de página -->
        <div class="mb-12 pb-10 border-b border-gray-100">
            <a href="/" class="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-park-blue transition-colors mb-8">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver al inicio
            </a>
            <p class="text-park-sage font-semibold text-sm uppercase tracking-widest mb-3">Legal</p>
            <h1 class="font-asap text-5xl sm:text-6xl font-bold text-park-blue leading-tight">
                Términos y Condiciones
            </h1>
            <p class="text-gray-400 text-sm mt-4">Última actualización: <?= $hoy ?></p>
        </div>

        <!-- Intro -->
        <div class="bg-park-cream rounded-2xl p-6 mb-10 text-sm text-gray-600 leading-relaxed">
            Al acceder y utilizar el sitio web de <strong class="text-park-blue"><?= e($empresa) ?></strong> y/o contratar cualquiera de nuestros servicios, usted acepta quedar sujeto a los presentes Términos y Condiciones. Si no está de acuerdo con alguno de ellos, le pedimos abstenerse de usar el sitio o nuestros servicios.
        </div>

        <div class="space-y-10 text-gray-600 leading-relaxed">

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">1. Definiciones</h2>
                <div class="space-y-3">
                    <?php $defs = [
                        ['"La empresa"', e($empresa) . ', propietaria y operadora de este sitio web y los servicios de renta de departamentos amueblados.'],
                        ['"El sitio"', 'El sitio web accesible en ' . BASE_URL . ' y sus subdominios.'],
                        ['"El usuario"', 'Toda persona que acceda, navegue o utilice el sitio o los servicios de la empresa.'],
                        ['"La propiedad"', 'Cualquier departamento o unidad habitacional gestionada y ofertada por la empresa.'],
                        ['"La reservación"', 'El acuerdo formal entre el usuario y la empresa para el arrendamiento de una propiedad por un período determinado.'],
                    ]; foreach ($defs as $d): ?>
                    <div class="flex gap-3">
                        <span class="font-semibold text-park-blue w-36 flex-shrink-0"><?= $d[0] ?>:</span>
                        <span class="text-sm"><?= $d[1] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">2. Uso del sitio web</h2>
                <p>El usuario se compromete a utilizar el sitio de forma lícita, responsable y conforme a estos términos. Queda expresamente prohibido:</p>
                <ul class="mt-4 space-y-2">
                    <?php $prohibidos = [
                        'Usar el sitio para fines fraudulentos, ilegales o que vulneren derechos de terceros.',
                        'Intentar acceder de forma no autorizada a sistemas, servidores o bases de datos de la empresa.',
                        'Reproducir, copiar, distribuir o explotar comercialmente el contenido del sitio sin autorización expresa por escrito.',
                        'Introducir virus, malware o cualquier código malicioso.',
                        'Realizar scraping automatizado o extracción masiva de datos sin consentimiento.',
                    ]; foreach ($prohibidos as $p): ?>
                    <li class="flex gap-3 text-sm"><span class="w-2 h-2 rounded-full bg-red-300 mt-1.5 flex-shrink-0"></span><?= $p ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">3. Servicios y reservaciones</h2>
                <div class="space-y-4 text-sm">
                    <p><strong class="text-park-blue">3.1 Disponibilidad:</strong> La disponibilidad de las propiedades está sujeta a cambios sin previo aviso. La empresa no garantiza que una propiedad esté disponible hasta que la reservación haya sido confirmada por escrito.</p>
                    <p><strong class="text-park-blue">3.2 Precios:</strong> Los precios publicados en el sitio son referenciales y pueden variar según temporada, duración de la estancia, servicios adicionales y disponibilidad. El precio definitivo se confirma al momento de la reservación.</p>
                    <p><strong class="text-park-blue">3.3 Depósito:</strong> La empresa podrá solicitar un depósito de garantía que será reembolsado al término de la estancia, sujeto a inspección de la propiedad.</p>
                    <p><strong class="text-park-blue">3.4 Estancia mínima:</strong> Cada propiedad puede tener un período mínimo de estancia. Dicha información se indica en la ficha de cada propiedad y se confirma al momento de la reservación.</p>
                    <p><strong class="text-park-blue">3.5 Mascotas:</strong> Únicamente se permiten mascotas en propiedades expresamente indicadas como pet-friendly. Puede aplicar un cargo adicional.</p>
                </div>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">4. Cancelaciones y modificaciones</h2>
                <div class="space-y-4 text-sm">
                    <p>Las políticas de cancelación varían por propiedad y tipo de estancia. En todos los casos:</p>
                    <ul class="space-y-2">
                        <li class="flex gap-3"><span class="w-2 h-2 rounded-full bg-park-sage mt-1.5 flex-shrink-0"></span>Las cancelaciones deben realizarse por escrito (correo electrónico).</li>
                        <li class="flex gap-3"><span class="w-2 h-2 rounded-full bg-park-sage mt-1.5 flex-shrink-0"></span>La empresa comunicará las penalidades aplicables al momento de confirmar la reservación.</li>
                        <li class="flex gap-3"><span class="w-2 h-2 rounded-full bg-park-sage mt-1.5 flex-shrink-0"></span>Modificaciones de fecha quedan sujetas a disponibilidad y podrán generar ajuste en el precio.</li>
                    </ul>
                </div>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">5. Responsabilidades del usuario</h2>
                <p>El usuario que ocupe una propiedad se compromete a:</p>
                <ul class="mt-4 space-y-2">
                    <?php $resps = [
                        'Usar la propiedad de manera responsable y conforme a las normas del inmueble.',
                        'No subarrendar, ceder o permitir el uso de la propiedad a terceros no autorizados.',
                        'Respetar el reglamento interno del edificio o desarrollo.',
                        'Notificar cualquier daño o desperfecto a la brevedad.',
                        'Entregar la propiedad en las mismas condiciones en que la recibió.',
                    ]; foreach ($resps as $r): ?>
                    <li class="flex gap-3 text-sm"><span class="w-2 h-2 rounded-full bg-park-sage mt-1.5 flex-shrink-0"></span><?= $r ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">6. Propiedad intelectual</h2>
                <p>Todo el contenido del sitio — incluyendo textos, fotografías, logotipos, diseño gráfico, código fuente e identidad de marca — es propiedad de <?= e($empresa) ?> o de sus respectivos titulares, y está protegido por las leyes de propiedad intelectual aplicables en México. Su reproducción o uso sin autorización expresa y por escrito está estrictamente prohibida.</p>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">7. Limitación de responsabilidad</h2>
                <p>La empresa no será responsable por daños indirectos, incidentales o consecuentes derivados del uso del sitio o de los servicios, incluyendo pero no limitado a:</p>
                <ul class="mt-4 space-y-2">
                    <?php $limits = [
                        'Interrupciones o errores en el sitio web.',
                        'Pérdida de datos del usuario.',
                        'Daños a bienes personales del usuario dentro de las propiedades, salvo negligencia comprobada de la empresa.',
                        'Situaciones de caso fortuito o fuerza mayor.',
                    ]; foreach ($limits as $l): ?>
                    <li class="flex gap-3 text-sm"><span class="w-2 h-2 rounded-full bg-gray-300 mt-1.5 flex-shrink-0"></span><?= $l ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">8. Legislación aplicable y jurisdicción</h2>
                <p>Estos términos se rigen por las leyes de los Estados Unidos Mexicanos. Para cualquier controversia derivada de su interpretación o cumplimiento, las partes se someten a la jurisdicción de los tribunales competentes de la Ciudad de México, renunciando expresamente a cualquier otro fuero que pudiera corresponderles.</p>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">9. Contacto</h2>
                <p>Para cualquier duda sobre estos términos, contáctenos en:</p>
                <div class="mt-4 bg-park-cream rounded-2xl p-5 space-y-1.5">
                    <p class="text-sm"><span class="font-semibold text-park-blue">Email:</span> <a href="mailto:<?= e($email) ?>" class="hover:text-park-blue transition-colors"><?= e($email) ?></a></p>
                    <?php if ($tel): ?><p class="text-sm"><span class="font-semibold text-park-blue">Teléfono:</span> <?= e($tel) ?></p><?php endif; ?>
                </div>
            </section>

        </div>

        <!-- Footer de página -->
        <div class="mt-14 pt-8 border-t border-gray-100 flex flex-wrap items-center justify-between gap-4">
            <a href="/" class="inline-flex items-center gap-2 text-sm font-semibold text-park-blue hover:opacity-70 transition-opacity">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver al inicio
            </a>
            <a href="/legal" class="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-park-blue transition-colors">
                <i data-lucide="shield" class="w-4 h-4"></i>Aviso de Privacidad
            </a>
        </div>

    </div>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
