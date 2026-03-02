<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seo.php';

$config  = getConfig();
$empresa = cfg('nombre_empresa', 'Park Life Properties');
$email   = cfg('email_info', 'info@parklife.mx');
$tel     = cfg('telefono_ventas', '');
$hoy     = date('d \d\e F \d\e Y');

$seo = [
    'titulo'      => 'Aviso de Privacidad — ' . $empresa,
    'descripcion' => 'Conoce cómo ' . $empresa . ' protege y gestiona tus datos personales conforme a la Ley Federal de Protección de Datos Personales en Posesión de los Particulares.',
    'canonical'   => BASE_URL . '/legal',
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
                Aviso de Privacidad
            </h1>
            <p class="text-gray-400 text-sm mt-4">Última actualización: <?= $hoy ?></p>
        </div>

        <!-- Contenido -->
        <div class="space-y-10 text-gray-600 leading-relaxed">

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">1. Responsable del tratamiento de datos</h2>
                <p><?= e($empresa) ?>, con domicilio en Ciudad de México, México, es responsable del uso y protección de sus datos personales conforme a la Ley Federal de Protección de Datos Personales en Posesión de los Particulares (LFPDPPP).</p>
                <div class="mt-4 bg-park-cream rounded-2xl p-5 space-y-1.5">
                    <p class="text-sm"><span class="font-semibold text-park-blue">Email:</span> <a href="mailto:<?= e($email) ?>" class="hover:text-park-blue transition-colors"><?= e($email) ?></a></p>
                    <?php if ($tel): ?><p class="text-sm"><span class="font-semibold text-park-blue">Teléfono:</span> <?= e($tel) ?></p><?php endif; ?>
                    <p class="text-sm"><span class="font-semibold text-park-blue">Sitio web:</span> <a href="<?= BASE_URL ?>" class="hover:text-park-blue transition-colors"><?= BASE_URL ?></a></p>
                </div>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">2. Datos personales que recabamos</h2>
                <p>Para las finalidades descritas en este aviso, recabamos las siguientes categorías de datos personales:</p>
                <ul class="mt-4 space-y-2.5">
                    <?php $items = [
                        ['Identificación', 'Nombre y apellidos.'],
                        ['Contacto', 'Correo electrónico, número de teléfono o WhatsApp.'],
                        ['Preferencia', 'Tipo de estancia deseada, propiedad de interés, necesidad de mobiliario, tenencia de mascotas.'],
                        ['Navegación', 'Dirección IP, páginas visitadas, fuente de origen (UTM), dispositivo y navegador.'],
                        ['Laborales (solo aplicantes)', 'Área de interés, experiencia profesional y mensaje de presentación.'],
                    ];
                    foreach ($items as $item): ?>
                    <li class="flex gap-3">
                        <span class="w-2 h-2 rounded-full bg-park-sage mt-2 flex-shrink-0"></span>
                        <span><strong class="text-park-blue"><?= $item[0] ?>:</strong> <?= $item[1] ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p class="mt-4 text-sm bg-blue-50 text-blue-700 px-4 py-3 rounded-xl">No recabamos datos personales sensibles (estado de salud, biometría, ideología política, orientación sexual u otros de naturaleza íntima).</p>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">3. Finalidades del tratamiento</h2>
                <p class="font-semibold text-park-blue mb-3">Finalidades primarias (necesarias para el servicio):</p>
                <ul class="space-y-2 mb-6">
                    <?php $primarias = [
                        'Atender solicitudes de información sobre nuestras propiedades y servicios.',
                        'Cotizar y gestionar reservaciones de departamentos amueblados.',
                        'Contactarle por los canales que usted proporcionó (email, WhatsApp, teléfono).',
                        'Procesar su solicitud de empleo cuando aplique a una vacante.',
                    ];
                    foreach ($primarias as $f): ?>
                    <li class="flex gap-3 text-sm"><span class="w-2 h-2 rounded-full bg-park-sage mt-1.5 flex-shrink-0"></span><?= $f ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="font-semibold text-park-blue mb-3">Finalidades secundarias (puede oponerse):</p>
                <ul class="space-y-2 mb-4">
                    <?php $secundarias = [
                        'Enviarle información sobre promociones, nuevas propiedades o noticias de ' . e($empresa) . '.',
                        'Realizar encuestas de satisfacción.',
                        'Análisis estadístico del comportamiento de nuestros usuarios.',
                    ];
                    foreach ($secundarias as $f): ?>
                    <li class="flex gap-3 text-sm"><span class="w-2 h-2 rounded-full bg-gray-300 mt-1.5 flex-shrink-0"></span><?= $f ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-sm text-gray-500">Para oponerse a las finalidades secundarias, envíe un correo a <a href="mailto:<?= e($email) ?>" class="text-park-blue hover:underline"><?= e($email) ?></a> con el asunto <em>"Oposición a finalidades secundarias"</em>.</p>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">4. Transferencia de datos</h2>
                <p>Sus datos podrán ser compartidos con las siguientes terceras partes sin requerir consentimiento adicional, cuando sea necesario para las finalidades primarias:</p>
                <div class="mt-4 grid gap-3">
                    <?php $transfers = [
                        ['Zoho Corporation', 'Plataforma CRM para gestión de leads y seguimiento comercial.'],
                        ['Meta Platforms (WhatsApp Business)', 'Envío de notificaciones relacionadas con su solicitud.'],
                        ['Autoridades competentes', 'Cuando lo exija la legislación mexicana aplicable.'],
                    ]; foreach ($transfers as $t): ?>
                    <div class="bg-park-cream rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-park-blue"><?= $t[0] ?></p>
                        <p class="text-sm text-gray-500 mt-0.5"><?= $t[1] ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="mt-4 text-sm text-gray-500">No vendemos, cedemos ni arrendamos sus datos personales a terceros con fines comerciales propios.</p>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">5. Derechos ARCO</h2>
                <p>Usted tiene derecho a <strong class="text-park-blue">Acceder, Rectificar, Cancelar u Oponerse</strong> (derechos ARCO) al tratamiento de sus datos personales. Para ejercerlos, envíe su solicitud a <a href="mailto:<?= e($email) ?>" class="text-park-blue hover:underline font-medium"><?= e($email) ?></a> incluyendo:</p>
                <ul class="mt-4 space-y-2">
                    <?php $arco = ['Su nombre completo y datos de contacto.','El derecho que desea ejercer.','Descripción clara de los datos involucrados.','Cualquier documento que facilite localizar sus datos.']; foreach ($arco as $a): ?>
                    <li class="flex gap-3 text-sm"><span class="w-2 h-2 rounded-full bg-park-sage mt-1.5 flex-shrink-0"></span><?= $a ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="mt-4 text-sm text-gray-500">Responderemos en un plazo máximo de <strong class="text-park-blue">20 días hábiles</strong>.</p>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">6. Cookies y tecnologías de rastreo</h2>
                <p>Nuestro sitio utiliza cookies y herramientas de analítica (Google Analytics, Google Tag Manager, Facebook Pixel) para mejorar la experiencia y medir el rendimiento del sitio. Puede desactivar las cookies desde la configuración de su navegador, aunque esto podría limitar algunas funcionalidades.</p>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">7. Cambios a este aviso</h2>
                <p>Nos reservamos el derecho de modificar este aviso en cualquier momento para reflejar cambios legales o en nuestros servicios. Cualquier modificación será publicada en esta página con la fecha de actualización correspondiente.</p>
            </section>

            <section>
                <h2 class="font-asap text-2xl font-bold text-park-blue mb-4">8. Marco legal</h2>
                <p>Este aviso se emite en cumplimiento de la <strong class="text-park-blue">Ley Federal de Protección de Datos Personales en Posesión de los Particulares</strong> y su Reglamento, vigentes en los Estados Unidos Mexicanos.</p>
            </section>

        </div>

        <!-- Footer de página -->
        <div class="mt-14 pt-8 border-t border-gray-100 flex flex-wrap items-center justify-between gap-4">
            <a href="/" class="inline-flex items-center gap-2 text-sm font-semibold text-park-blue hover:opacity-70 transition-opacity">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>Volver al inicio
            </a>
            <a href="/terminos" class="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-park-blue transition-colors">
                Términos y Condiciones <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </a>
        </div>

    </div>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
