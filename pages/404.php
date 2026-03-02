<?php
// Si se llama directo (no desde propiedad.php), cargar config
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/functions.php';
    http_response_code(404);
}
$config = function_exists('getConfig') ? getConfig() : [];
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página no encontrada — Park Life Properties</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Asap+Condensed:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <script>tailwind.config={theme:{extend:{colors:{'park-blue':'#202944','park-sage':'#BAC4B9','park-cream':'#f9f9f9'},fontFamily:{asap:['Asap Condensed','sans-serif'],jakarta:['Plus Jakarta Sans','sans-serif']}}}}</script>
</head>
<body class="font-jakarta bg-park-blue min-h-screen flex items-center justify-center px-4">
    <div class="text-center text-white">
        <p class="font-asap text-9xl font-bold text-park-sage/30 leading-none">404</p>
        <h1 class="font-asap text-4xl sm:text-5xl font-bold mt-4 mb-4">Página no encontrada</h1>
        <p class="text-white/60 text-lg mb-10 max-w-md mx-auto">La página que buscas no existe o fue movida.</p>
        <a href="/" class="inline-flex items-center gap-2 bg-park-sage text-park-blue px-8 py-4 rounded-xl font-semibold hover:bg-white transition-all duration-300">
            ← Volver al inicio
        </a>
    </div>
</body>
</html>
