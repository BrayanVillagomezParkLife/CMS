<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/layout.php';

$csrf   = adminCsrf();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminVerifyCsrf($_POST['csrf_token'] ?? '')) adminRedirect('amenidades.php', 'error', 'Token inválido.');
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete') {
        dbExecute("DELETE FROM amenidades_catalogo WHERE id=?", [(int)$_POST['id']]);
        adminRedirect('amenidades.php', 'success', 'Amenidad eliminada.');
    }

    if ($postAction === 'save_catalogo') {
        $data = [
            'nombre'       => sanitizeStr($_POST['nombre'] ?? ''),
            'nombre_en'    => sanitizeStr($_POST['nombre_en'] ?? ''),
            'icono_lucide' => sanitizeStr($_POST['icono_lucide'] ?? ''),
            'descripcion'  => sanitizeStr($_POST['descripcion'] ?? ''),
            'activa'       => isset($_POST['activa']) ? 1 : 0,
        ];
        $editId = (int)($_POST['id'] ?? 0);
        if ($editId) {
            dbExecute("UPDATE amenidades_catalogo SET nombre=?,nombre_en=?,icono_lucide=?,descripcion=?,activa=? WHERE id=?",
                [...array_values($data), $editId]);
        } else {
            dbInsert("INSERT INTO amenidades_catalogo (nombre,nombre_en,icono_lucide,descripcion,activa) VALUES (?,?,?,?,?)",
                array_values($data));
        }
        adminRedirect('amenidades.php', 'success', 'Amenidad guardada.');
    }

    // Guardar asignaciones de amenidades a propiedad
    if ($postAction === 'save_asignacion') {
        $propId = (int)$_POST['propiedad_id'];
        // Borrar todas las asignaciones actuales
        dbExecute("DELETE FROM propiedad_amenidades WHERE propiedad_id=?", [$propId]);
        // Reinsertar las seleccionadas
        $seleccionadas = $_POST['amenidades'] ?? [];
        $descs         = $_POST['descripciones'] ?? [];
        foreach ($seleccionadas as $amId) {
            $amId = (int)$amId;
            $desc = sanitizeStr($descs[$amId] ?? '');
            dbExecute(
                "INSERT INTO propiedad_amenidades (propiedad_id, amenidad_id, descripcion_custom) VALUES (?,?,?)",
                [$propId, $amId, $desc]
            );
        }
        dbCacheInvalidate();
        adminRedirect('amenidades.php?tab=asignar&propiedad_id=' . $propId, 'success', 'Amenidades actualizadas.');
    }
}

$tab         = $_GET['tab'] ?? 'catalogo';
$propiedades = dbFetchAll("SELECT id, nombre FROM propiedades WHERE activo=1 ORDER BY nombre");
$propId      = (int)($_GET['propiedad_id'] ?? ($propiedades[0]['id'] ?? 0));

// Formulario catálogo
if (in_array($action, ['new','edit']) && $tab === 'catalogo') {
    $am = $id ? dbFetchOne("SELECT * FROM amenidades_catalogo WHERE id=?", [$id]) : null;
    adminLayoutOpen($am ? 'Editar Amenidad' : 'Nueva Amenidad');
    $v = $am ?? [];
    ?>
    <div class="mb-6"><a href="amenidades.php" class="text-sm text-gray-500 hover:text-pk flex items-center gap-1"><i data-lucide="arrow-left" class="w-4 h-4"></i>Volver</a></div>
    <form method="post" class="max-w-lg space-y-5">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save_catalogo">
        <input type="hidden" name="id" value="<?= (int)($v['id'] ?? 0) ?>">
        <div class="card space-y-4">
            <div><label class="form-label">Nombre ES *</label><input type="text" name="nombre" required class="form-input" value="<?= e($v['nombre'] ?? '') ?>"></div>
            <div><label class="form-label">Nombre EN</label><input type="text" name="nombre_en" class="form-input" value="<?= e($v['nombre_en'] ?? '') ?>"></div>

            <!-- Icon Picker -->
            <div>
                <label class="form-label">Ícono Lucide</label>
                <input type="hidden" name="icono_lucide" id="icono_lucide_val" value="<?= e($v['icono_lucide'] ?? '') ?>">

                <!-- Preview + búsqueda -->
                <div class="flex gap-2 mb-2">
                    <div id="icon-preview" class="w-10 h-10 bg-pk/10 rounded-xl flex items-center justify-center flex-shrink-0">
                        <i data-lucide="<?= e(($v['icono_lucide'] ?? '') ?: 'smile') ?>" class="w-5 h-5 text-pk" id="icon-preview-el"></i>
                    </div>
                    <input type="text" id="icon-search" class="form-input"
                           placeholder="Busca: wifi, car, star, home..."
                           value="<?= e($v['icono_lucide'] ?? '') ?>"
                           autocomplete="new-password"
                           autocorrect="off" autocapitalize="off"
                           spellcheck="false"
                           role="combobox">
                </div>

                <!-- Grid de íconos -->
                <div id="icon-grid"
                     class="grid grid-cols-8 gap-1 max-h-48 overflow-y-auto border border-gray-200 rounded-xl p-2 bg-white">
                    <!-- Populated by JS -->
                </div>
                <p class="text-xs text-gray-400 mt-1.5">Escribe para filtrar · haz clic para seleccionar</p>
            </div>

            <div><label class="form-label">Descripción por defecto</label><input type="text" name="descripcion" class="form-input" value="<?= e($v['descripcion'] ?? '') ?>" placeholder="Descripción que aparece si no hay una personalizada"></div>
            <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="activa" class="w-4 h-4 accent-pk" <?= !empty($v['activa']) ? 'checked' : '' ?>><span class="text-sm font-medium text-gray-700">Activa</span></label>
        </div>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i>Guardar</button>
            <a href="amenidades.php" class="btn-secondary">Cancelar</a>
        </div>
    </form>

    <script>
    // Catálogo completo Lucide (~600 íconos)
    const ICONS = [
        // Conectividad
        'wifi','wifi-off','bluetooth','bluetooth-off','signal','signal-zero','signal-low','signal-medium','signal-high',
        'radio','satellite','antenna','router','network','server','database','cloud','cloud-off','cloud-rain','cloud-snow','cloud-sun','cloud-lightning',
        // Dispositivos
        'monitor','laptop','laptop-2','tablet','smartphone','phone','phone-call','phone-off','phone-missed','phone-incoming','phone-outgoing',
        'tv','tv-2','printer','keyboard','mouse','mouse-pointer','headphones','headphones-off','speaker','speaker-off','microphone','microphone-off',
        'camera','camera-off','video','video-off','webcam',
        // Hogar y espacios
        'home','house','building','building-2','castle','warehouse','factory','school','hospital','church','store','hotel',
        'door-open','door-closed','window','fence','mailbox','lamp','lamp-floor','lamp-desk','sofa','armchair','bed','bed-double','bed-single',
        'bath','shower','toilet','sink','refrigerator','microwave','washing-machine','trash','trash-2',
        // Servicios
        'wifi','car','dumbbell','waves','sparkles','tv','utensils','key','key-round','concierge-bell',
        'parking','elevator','stairs','pool','gym','spa','restaurant','bar-chart',
        'scissors','wrench','hammer','tool','settings','settings-2','sliders','sliders-horizontal',
        // Transporte
        'car','car-front','truck','bus','train','plane','plane-takeoff','plane-landing','ship','bike','bicycle','motorbike',
        'navigation','navigation-2','compass','map','map-pin','map-pinned','route','milestone',
        // Naturaleza
        'tree','tree-pine','flower','flower-2','leaf','leaves','feather','bird','fish','turtle','rabbit','squirrel','cat','dog',
        'bug','snail','shell','mushroom','cactus','sprout','seedling','herb',
        'sun','moon','star','stars','cloud','wind','snowflake','thermometer','umbrella','rainbow','sunrise','sunset',
        'flame','droplets','waves','mountain','mountain-snow','volcano','island',
        // Personas
        'user','users','user-plus','user-minus','user-check','user-x','user-circle','user-square',
        'baby','child','person-standing','smile','meh','frown','laugh','angry','sad',
        'heart','heart-off','heart-handshake','thumbs-up','thumbs-down',
        'hand','hand-helping','hand-shake','hands-clapping',
        // Comida y bebida
        'utensils','utensils-crossed','coffee','cup','beer','wine','cocktail','milk',
        'pizza','sandwich','salad','soup','cookie','cake','candy','apple','cherry','grape','banana','lemon','orange',
        'egg','fish','meat','beef','ham','carrot','broccoli','avocado','wheat','croissant','bread',
        // Trabajo y oficina
        'briefcase','briefcase-business','laptop','monitor','clipboard','clipboard-list','clipboard-check',
        'file','file-text','file-plus','file-minus','file-check','file-x','file-search','file-code',
        'folder','folder-open','folder-plus','folder-minus','folder-check','folder-x',
        'book','book-open','book-marked','bookmark','bookmarks','library','newspaper','scroll',
        'pen','pencil','pen-tool','edit','edit-2','edit-3','highlighter','marker',
        'mail','mail-open','mail-plus','mail-minus','mail-check','mail-x','inbox','send','send-horizontal',
        'calendar','calendar-days','calendar-check','calendar-plus','calendar-minus','calendar-x','calendar-clock',
        'clock','clock-1','clock-2','clock-3','clock-4','clock-5','clock-6','clock-7','clock-8','clock-9','clock-10','clock-11','clock-12',
        'timer','timer-off','alarm-clock','alarm-clock-off','watch',
        // Finanzas
        'dollar-sign','euro','pound-sterling','japanese-yen','bitcoin','credit-card','wallet','banknote','coins','piggy-bank',
        'receipt','shopping-cart','shopping-bag','package','gift','tag','tags','percent','trending-up','trending-down','bar-chart','bar-chart-2','pie-chart','line-chart',
        // Seguridad
        'shield','shield-check','shield-off','shield-alert','shield-x','shield-plus','shield-minus',
        'lock','lock-open','unlock','key','key-round','fingerprint','eye','eye-off','scan','qr-code',
        'alert-circle','alert-triangle','alert-octagon','badge-alert','siren',
        // Médico y salud
        'heart-pulse','activity','stethoscope','pill','pills','syringe','thermometer','bandage','band-aid','ambulance',
        'hospital','cross','plus','first-aid','dna','microscope','test-tube','test-tube-2','flask','atom',
        // Herramientas
        'wrench','hammer','screwdriver','tool','drill','axe','saw','scissors','ruler','tape','magnet',
        'bolt','nut','screw','pipe','valve','faucet','plug','plug-2','battery','battery-charging','battery-full','battery-low','battery-medium',
        // Multimedia
        'play','play-circle','play-square','pause','pause-circle','stop-circle','skip-back','skip-forward','rewind','fast-forward',
        'volume','volume-1','volume-2','volume-x','music','music-2','music-3','music-4','radio','podcast','mic','mic-off',
        'image','image-off','images','gallery-horizontal','gallery-vertical','gallery-thumbnails',
        'film','clapperboard','video','youtube','twitch',
        // Deportes y fitness
        'dumbbell','bicycle','swim','tennis','golf','bowling','target','trophy','medal','award','crown',
        'football','basketball','volleyball','cricket','baseball',
        // Viajes y turismo
        'plane','ship','train','bus','car','hotel','tent','compass','map','globe','earth','flag','passport',
        'luggage','backpack','suitcase','wallet','ticket','boarding-pass',
        // Redes sociales y comunicación
        'message-circle','message-square','messages-square','chat','chat-bubble',
        'share','share-2','link','link-2','external-link','at-sign','hash','at',
        'facebook','twitter','instagram','linkedin','youtube','github','gitlab','slack','discord','whatsapp',
        // Interfaz
        'menu','x','plus','minus','check','chevron-up','chevron-down','chevron-left','chevron-right',
        'arrow-up','arrow-down','arrow-left','arrow-right','arrow-up-right','arrow-down-right','arrow-up-left','arrow-down-left',
        'move','move-horizontal','move-vertical','move-diagonal','move-diagonal-2',
        'zoom-in','zoom-out','maximize','minimize','maximize-2','minimize-2','expand','shrink',
        'refresh-cw','refresh-ccw','rotate-cw','rotate-ccw','repeat','repeat-1','shuffle',
        'search','filter','sort-asc','sort-desc','list','list-ordered','list-checks','list-plus','list-minus',
        'grid','grid-2x2','grid-3x3','layout','layout-grid','layout-list','layout-dashboard','layout-template',
        'sidebar','sidebar-close','sidebar-open','panel-left','panel-right','panel-top','panel-bottom',
        'toggle-left','toggle-right','switch','radio-button',
        'circle','square','triangle','hexagon','octagon','pentagon','diamond','star','heart',
        'loader','loader-2','more-horizontal','more-vertical','dots','grip','grip-vertical','grip-horizontal',
        // Notificaciones
        'bell','bell-off','bell-ring','bell-plus','bell-minus','bell-dot',
        'info','help-circle','alert-circle','check-circle','x-circle',
        'badge','badge-check','badge-alert','badge-info','badge-plus','badge-minus','badge-x',
        // Colores y diseño
        'palette','pipette','paintbrush','paintbrush-2','paint-bucket','eraser','crop','cut','copy','paste',
        'wand','wand-2','sparkles','zap','zap-off','lightning','flashlight','flashlight-off',
        // Accesibilidad
        'accessibility','glasses','ear','ear-off','eye','eye-off','mouse-pointer-2','hand','pointer',
        // Extras útiles
        'anchor','aperture','archive','archive-restore','award','axis-3d',
        'binary','blocks','bone','book-copy','book-down','book-up','box','boxes',
        'calculator','calendar-heart','candlestick-chart','car-front','chart-area','chart-bar','chart-line',
        'chef-hat','cigarette','cigarette-off','circle-dot','clapperboard','clipboard-copy',
        'clock-alert','code','code-2','columns','combine','command','component','computer',
        'construction','container','contrast','cookie','copy-check','copyright',
        'cpu','cylinder','data','delete','diff','disc','divide',
        'door-open','download','download-cloud','dribbble','drop','droplet',
        'equal','eraser','expand','eye-off',
        'factory','figma','file-archive','file-audio','file-video','file-image',
        'filter','fingerprint','flag','flag-off','flower','focus',
        'framer','function','gamepad','gamepad-2','gauge','gem','ghost','gift-card',
        'glass','globe-2','graduation-cap','grid-4x4',
        'hard-drive','hard-hat','hash','heading','help','highlighter',
        'ice-cream','inbox','indent','infinity','italic',
        'joystick','kanban','key-square','landmark','lasso','layers',
        'layout-panel-left','layout-panel-top','lightbulb','lightbulb-off',
        'locate','locate-fixed','locate-off','log-in','log-out',
        'map-pin-off','maximize-2','merge','milestone','minimize-2',
        'minus-circle','minus-square','monitor-off','monitor-speaker',
        'more-horizontal','mouse-pointer-click','move-3d',
        'navigation-off','network','octagon','omega','option',
        'package-2','package-check','package-minus','package-open','package-plus','package-search','package-x',
        'paperclip','parentheses','party-popper','pause-octagon','pencil-line','pencil-ruler',
        'percent','person-standing','phone-forwarded','photo',
        'picture-in-picture','picture-in-picture-2','pin','pin-off',
        'pocket','pocket-knife','power','power-off','puzzle',
        'ratio','receipt','rectangle-horizontal','rectangle-vertical',
        'redo','redo-2','redo-dot','replace','replace-all','reply','reply-all',
        'rocket','rss','save','save-all',
        'scale','scaling','scan-face','scan-line','screen-share','screen-share-off',
        'send-to-back','server-crash','server-off','share-2',
        'signpost','signpost-big','skip-back','skip-forward','skip-to-end','slash',
        'slice','smartwatch','smile-plus','socket',
        'split','split-square-horizontal','split-square-vertical',
        'stamp','star-half','star-off','sticker','strikethrough','subscript','subtract',
        'sun-dim','sun-medium','sun-moon','sunrise','sunset','superscript',
        'swatch','table','table-2','table-cells-merge','table-cells-split',
        'table-columns-split','table-properties','table-rows-split',
        'tally-1','tally-2','tally-3','tally-4','tally-5',
        'tangent','terminal','text','text-cursor','text-cursor-input',
        'timer-reset','toggle-left','toggle-right','touchpad','touchpad-off',
        'type','undo','undo-2','undo-dot','ungroup','unlink','unlink-2',
        'upload','upload-cloud','usb','user-cog','user-minus','user-round',
        'variable','vault','vegan','venetian-mask','verified',
        'vibrate','vibrate-off','video-off','view','voicemail',
        'wallet-cards','waypoints','webhook','weight',
        'wifi-high','wifi-low','wifi-zero','wind','workflow',
        'wrap-text','x-octagon','x-square','zoom-in','zoom-out',
    ];

    const grid     = document.getElementById('icon-grid');
    const search   = document.getElementById('icon-search');
    const valInput = document.getElementById('icono_lucide_val');
    const previewEl= document.getElementById('icon-preview-el');

    let selected = valInput.value || '';

    function renderGrid(filter = '') {
        const q    = filter.toLowerCase().trim();
        const list = q ? ICONS.filter(i => i.includes(q)) : ICONS;
        grid.innerHTML = '';
        list.slice(0, 80).forEach(icon => {
            const btn = document.createElement('button');
            btn.type  = 'button';
            btn.title = icon;
            btn.dataset.icon = icon;
            btn.style.cssText = `
                display:flex;align-items:center;justify-content:center;
                width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;
                background:${icon === selected ? '#202944' : '#f8fafc'};
                color:${icon === selected ? '#fff' : '#64748b'};
                transition:all .15s;
            `;
            btn.innerHTML = `<i data-lucide="${icon}" style="width:16px;height:16px;pointer-events:none"></i>`;
            btn.addEventListener('mouseenter', () => {
                if (icon !== selected) btn.style.background = '#e2e8f0';
            });
            btn.addEventListener('mouseleave', () => {
                if (icon !== selected) btn.style.background = '#f8fafc';
            });
            btn.addEventListener('click', () => selectIcon(icon));
            grid.appendChild(btn);
        });
        lucide.createIcons();
    }

    function selectIcon(icon) {
        selected    = icon;
        valInput.value = icon;
        search.value   = icon;
        // Actualizar preview
        previewEl.setAttribute('data-lucide', icon);
        lucide.createIcons();
        // Re-render grid para destacar seleccionado
        renderGrid(search.value !== icon ? search.value : '');
    }

    search.addEventListener('input', () => renderGrid(search.value));
    search.addEventListener('focus', () => renderGrid(search.value));

    // Render inicial
    renderGrid(selected);
    </script>
    <?php adminLayoutClose(); exit;
}

// Datos para listado
$catalogo   = dbFetchAll("SELECT * FROM amenidades_catalogo ORDER BY nombre");
$asignadas  = $propId ? dbFetchAll(
    "SELECT pa.amenidad_id, pa.descripcion_custom FROM propiedad_amenidades pa WHERE pa.propiedad_id=?",
    [$propId]
) : [];
$asignadasIds = array_column($asignadas, null, 'amenidad_id');

adminLayoutOpen('Amenidades');
?>

<!-- Tabs -->
<div class="flex gap-1 mb-6 bg-white border border-gray-200 rounded-xl p-1 w-fit">
    <a href="amenidades.php?tab=catalogo" class="px-5 py-2 rounded-lg text-sm font-medium transition-all <?= $tab === 'catalogo' ? 'bg-pk text-white' : 'text-gray-600 hover:bg-gray-50' ?>">Catálogo</a>
    <a href="amenidades.php?tab=asignar" class="px-5 py-2 rounded-lg text-sm font-medium transition-all <?= $tab === 'asignar' ? 'bg-pk text-white' : 'text-gray-600 hover:bg-gray-50' ?>">Asignar a propiedad</a>
</div>

<?php if ($tab === 'catalogo'): ?>
<div class="flex justify-end mb-5">
    <a href="?action=new&tab=catalogo" class="btn-primary"><i data-lucide="plus" class="w-4 h-4"></i>Nueva amenidad</a>
</div>
<div class="card p-0 overflow-hidden">
    <table class="w-full">
        <thead class="bg-slate-50 border-b border-gray-100">
            <tr>
                <th class="table-th">Ícono</th>
                <th class="table-th">Nombre</th>
                <th class="table-th">Descripción default</th>
                <th class="table-th text-center">Estado</th>
                <th class="table-th"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            <?php foreach ($catalogo as $am): ?>
            <tr class="hover:bg-slate-50">
                <td class="table-td">
                    <div class="w-9 h-9 bg-pk/10 rounded-lg flex items-center justify-center">
                        <i data-lucide="<?= e($am['icono_lucide'] ?: 'check') ?>" class="w-4 h-4 text-pk"></i>
                    </div>
                </td>
                <td class="table-td"><div class="font-medium text-gray-800"><?= e($am['nombre']) ?></div><?php if ($am['nombre_en']): ?><div class="text-xs text-gray-400"><?= e($am['nombre_en']) ?></div><?php endif; ?></td>
                <td class="table-td text-sm text-gray-500 max-w-xs truncate"><?= e($am['descripcion'] ?? '—') ?></td>
                <td class="table-td text-center"><?= badgeStatus((int)$am['activa']) ?></td>
                <td class="table-td">
                    <div class="flex items-center gap-2 justify-end">
                        <a href="?action=edit&id=<?= $am['id'] ?>&tab=catalogo" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-pk"><i data-lucide="pencil" class="w-4 h-4"></i></a>
                        <form method="post" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $am['id'] ?>">
                            <button type="submit" data-confirm="¿Eliminar esta amenidad?" class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php else: // tab = asignar ?>

<!-- Selector propiedad -->
<form method="get" class="flex items-center gap-3 mb-6">
    <input type="hidden" name="tab" value="asignar">
    <label class="form-label mb-0 text-sm whitespace-nowrap">Propiedad:</label>
    <select name="propiedad_id" class="form-select text-sm" onchange="this.form.submit()">
        <?php foreach ($propiedades as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $propId == $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save_asignacion">
    <input type="hidden" name="propiedad_id" value="<?= $propId ?>">
    <div class="card mb-5">
        <h2 class="font-bold text-gray-800 mb-5">Amenidades disponibles en el catálogo</h2>
        <div class="grid sm:grid-cols-2 gap-3">
            <?php foreach ($catalogo as $am): ?>
            <?php $asig = $asignadasIds[$am['id']] ?? null; ?>
            <div class="flex gap-3 p-3 rounded-xl border border-gray-100 hover:border-pk/30 transition-all <?= $asig ? 'bg-pk/5 border-pk/30' : '' ?>">
                <div class="flex items-start pt-0.5">
                    <input type="checkbox" name="amenidades[]" value="<?= $am['id'] ?>"
                           id="am_<?= $am['id'] ?>" class="w-4 h-4 accent-pk mt-0.5"
                           <?= $asig ? 'checked' : '' ?>
                           onchange="toggleDesc(<?= $am['id'] ?>, this.checked)">
                </div>
                <div class="flex-1 min-w-0">
                    <label for="am_<?= $am['id'] ?>" class="flex items-center gap-2 cursor-pointer mb-1">
                        <i data-lucide="<?= e($am['icono_lucide'] ?: 'check') ?>" class="w-3.5 h-3.5 text-pk flex-shrink-0"></i>
                        <span class="text-sm font-medium text-gray-800"><?= e($am['nombre']) ?></span>
                    </label>
                    <input type="text" name="descripciones[<?= $am['id'] ?>]"
                           value="<?= e($asig['descripcion_custom'] ?? $am['descripcion'] ?? '') ?>"
                           placeholder="Descripción personalizada (opcional)"
                           id="desc_<?= $am['id'] ?>"
                           class="w-full text-xs px-2 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:border-pk <?= $asig ? '' : 'opacity-40' ?>">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i>Guardar amenidades</button>
</form>

<script>
function toggleDesc(id, checked) {
    const desc = document.getElementById('desc_' + id);
    if (desc) desc.classList.toggle('opacity-40', !checked);
}
</script>
<?php endif; ?>

<?php adminLayoutClose(); ?>