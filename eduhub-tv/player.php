<?php
/* player.php — TV Escolar pública v2.1 */
if ( ! defined('ABSPATH') ) exit;

$playlist = ehtv_get_playlist();

// Montar dados JS da playlist
$items_js = [];
foreach ($playlist as $item) {
    $items_js[] = [
        'id'             => (int)$item->id,
        'type'           => $item->type,
        'title'          => $item->title,
        'description'    => $item->description ?? '',
        'tema'           => $item->tema ?? '',
        'classification' => $item->classification ?? 'conteudo',
        'thumb'          => ehtv_thumb($item),
        'youtube_id'     => $item->youtube_id ?? '',
        'file_url'       => $item->type === 'material' ? ehtv_video_url($item) : '',
        'ext_url'        => $item->type === 'url' ? ($item->external_url ?? '') : '',
        'duration'       => (int)($item->duration ?? 0),
    ];
}

// Grade de hoje (fuso SP) com timestamps Unix
$today_sp      = ehtv_today();
$sched_entries = ehtv_get_schedule_for_date($today_sp);

$schedule_js   = [];
foreach ($sched_entries as $entry) {
    $ts  = ehtv_to_timestamp($today_sp, $entry->start_time);
    $idx = -1;
    foreach ($playlist as $i => $pl_item) {
        if ( (int)$pl_item->id === (int)$entry->playlist_id ) { $idx = $i; break; }
    }
    $schedule_js[] = [
        'id'          => (int)$entry->id,
        'playlist_id' => (int)$entry->playlist_id,
        'timestamp'   => $ts,
        'time_str'    => substr($entry->start_time, 0, 5),
        'title'       => $entry->title,
        'item_idx'    => $idx,
        'triggered'   => false,
    ];
}
?>

<style>
/* ── TV Player v2.1 ───────────────────────────────────────── */
.ehtv-wrap {
    background: #0a0a0f;
    border-radius: 16px;
    overflow: hidden;
    font-family: 'Nunito', sans-serif;
    color: #fff;
    max-width: 1100px;
    margin: 0 auto;
    position: relative;
}
.ehtv-layout { display: flex; flex-direction: column; }

/* Player area */
.ehtv-player-area {
    position: relative; background: #000;
    width: 100%; aspect-ratio: 16/9;
}
#ehtv-video-el {
    width: 100%; height: 100%;
    object-fit: contain; display: block;
}
#ehtv-yt-container {
    position: absolute; inset: 0; display: none; background: #000;
}
#ehtv-yt-player { width: 100%; height: 100%; }
#ehtv-yt-container iframe {
    width: 100% !important; height: 100% !important;
    position: absolute; inset: 0;
}

/* Overlay */
.ehtv-overlay {
    position: absolute; bottom: 0; left: 0; right: 0;
    padding: 1rem 1.25rem;
    background: linear-gradient(transparent, rgba(0,0,0,.85));
    pointer-events: none; transition: opacity .3s;
}
.ehtv-overlay-meta  { display: flex; align-items: center; gap: .4rem; margin-bottom: 3px; flex-wrap: wrap; }
.ehtv-overlay-title { font-weight: 800; font-size: 1rem; margin-bottom: 2px; }
.ehtv-overlay-sub   { font-size: .75rem; opacity: .65; }
.ehtv-clf-tag {
    font-size: .6rem; font-weight: 800; padding: 2px 6px;
    border-radius: 3px; letter-spacing: .04em;
}
.ehtv-clf-tag.conteudo                 { background: #16a34a; color: #fff; }
.ehtv-clf-tag.propaganda_externa       { background: #d97706; color: #fff; }
.ehtv-clf-tag.propaganda_institucional { background: #2563eb; color: #fff; }

/* Tela inicial */
#ehtv-splash {
    position: absolute; inset: 0; background: #0a0a0f;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 1rem;
}
#ehtv-splash-icon { font-size: 4rem; opacity: .4; }
#ehtv-splash-text { font-size: .9rem; opacity: .4; font-family: Nunito, sans-serif; }
#ehtv-play-first  {
    padding: .65rem 1.5rem; background: #E94560;
    border: none; border-radius: 99px; color: #fff;
    font-family: Nunito, sans-serif; font-size: .9rem; font-weight: 800;
    cursor: pointer; transition: opacity .15s;
}
#ehtv-play-first:hover { opacity: .85; }

/* Controles */
.ehtv-controls {
    display: flex; align-items: center; justify-content: center;
    gap: .75rem; padding: .75rem 1rem;
    background: rgba(255,255,255,.03);
    border-bottom: 1px solid rgba(255,255,255,.06);
    flex-wrap: wrap;
}
.ehtv-ctrl {
    width: 42px; height: 42px; border-radius: 50%;
    background: rgba(255,255,255,.08); border: none;
    color: rgba(255,255,255,.75); font-size: 1rem; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s; flex-shrink: 0;
}
.ehtv-ctrl:hover { background: rgba(255,255,255,.16); }
.ehtv-ctrl-play  { width: 54px; height: 54px; font-size: 1.3rem; background: #E94560 !important; color: #fff !important; }
.ehtv-ctrl-play:hover { background: #c73451 !important; }
.ehtv-ctrl-grade { background: rgba(255,255,255,.06); }
.ehtv-ctrl-grade.open { background: rgba(233,69,96,.25); color: #E94560; }

.ehtv-autoplay-toggle {
    display: flex; align-items: center; gap: .4rem;
    font-size: .72rem; color: rgba(255,255,255,.5);
    cursor: pointer; margin-left: auto;
    font-family: Nunito, sans-serif; font-weight: 700; user-select: none;
}
.ehtv-toggle-dot {
    width: 28px; height: 16px; background: rgba(255,255,255,.15);
    border-radius: 99px; position: relative; transition: background .2s;
}
.ehtv-toggle-dot::after {
    content: ''; position: absolute; top: 2px; left: 2px;
    width: 12px; height: 12px; border-radius: 50%; background: #fff;
    transition: transform .2s;
}
.ehtv-toggle-dot.on { background: #E94560; }
.ehtv-toggle-dot.on::after { transform: translateX(12px); }

/* Relógio SP nos controles */
#ehtv-clock {
    font-size: .72rem; color: rgba(255,255,255,.4);
    font-family: monospace; min-width: 38px; text-align: center;
}

/* Playlist lateral */
.ehtv-playlist {
    background: #111116; max-height: 340px; overflow-y: auto;
    scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.1) transparent;
}
.ehtv-playlist-label {
    padding: .6rem 1rem; font-size: .6rem; font-weight: 800;
    letter-spacing: .12em; text-transform: uppercase;
    color: rgba(255,255,255,.3); border-bottom: 1px solid rgba(255,255,255,.05);
}
.ehtv-item {
    display: flex; align-items: center; gap: .65rem;
    padding: .6rem 1rem; border-bottom: 1px solid rgba(255,255,255,.04);
    cursor: pointer; transition: background .15s;
}
.ehtv-item:hover { background: rgba(255,255,255,.05); }
.ehtv-item.active { background: rgba(255,255,255,.08); }
.ehtv-item.active .ehtv-item-title { color: #E94560; }
.ehtv-item-thumb {
    width: 72px; height: 44px; object-fit: cover; border-radius: 6px;
    background: rgba(255,255,255,.06); flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; color: rgba(255,255,255,.25); overflow: hidden;
}
.ehtv-item-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ehtv-item-info { flex: 1; min-width: 0; }
.ehtv-item-title {
    font-size: .82rem; font-weight: 700; color: rgba(255,255,255,.85);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ehtv-item-sub { font-size: .68rem; color: rgba(255,255,255,.35); margin-top: 2px; }
.ehtv-item-num { font-size: .7rem; font-weight: 800; color: rgba(255,255,255,.2); width: 20px; text-align: center; flex-shrink: 0; }
.ehtv-yt-tag   { font-size: .58rem; font-weight: 800; background: #ff0000; color: #fff; padding: 2px 5px; border-radius: 3px; flex-shrink: 0; }

/* ── Drawer "A seguir na TV" ─────────────────────────────── */
.ehtv-grade-drawer {
    position: absolute; top: 0; right: 0; bottom: 0;
    width: 280px; background: rgba(8,8,16,.97);
    backdrop-filter: blur(12px);
    border-left: 1px solid rgba(255,255,255,.08);
    display: flex; flex-direction: column;
    transform: translateX(100%);
    transition: transform .3s cubic-bezier(.4,0,.2,1);
    z-index: 50; overflow: hidden;
}
.ehtv-grade-drawer.open { transform: translateX(0); }
.ehtv-grade-header {
    padding: .75rem 1rem;
    display: flex; align-items: center; justify-content: space-between;
    border-bottom: 1px solid rgba(255,255,255,.08);
    font-size: .78rem; font-weight: 800;
    color: rgba(255,255,255,.7);
    text-transform: uppercase; letter-spacing: .1em;
}
.ehtv-grade-close {
    background: none; border: none; color: rgba(255,255,255,.4);
    cursor: pointer; font-size: 1rem; padding: 0 4px; line-height: 1;
}
.ehtv-grade-close:hover { color: #fff; }
.ehtv-grade-scroll {
    flex: 1; overflow-y: auto;
    scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.1) transparent;
}
.ehtv-grade-entry {
    display: flex; gap: .6rem;
    padding: .65rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,.05);
    align-items: flex-start; cursor: pointer;
}
.ehtv-grade-entry:hover { background: rgba(255,255,255,.04); }
.ehtv-grade-entry.now-entry { background: rgba(233,69,96,.12); }
.ehtv-grade-time {
    font-family: monospace; font-size: .72rem;
    color: rgba(255,255,255,.4); width: 42px; flex-shrink: 0; padding-top: 2px;
}
.ehtv-grade-thumb {
    width: 52px; height: 32px; border-radius: 4px;
    background: rgba(255,255,255,.05); flex-shrink: 0; overflow: hidden;
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem; color: rgba(255,255,255,.2);
}
.ehtv-grade-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ehtv-grade-info { flex: 1; min-width: 0; }
.ehtv-grade-title {
    font-size: .78rem; font-weight: 700; color: rgba(255,255,255,.8);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ehtv-grade-sub { font-size: .66rem; color: rgba(255,255,255,.35); margin-top: 1px; }
.ehtv-grade-now-badge {
    font-size: .56rem; font-weight: 800; background: #E94560;
    color: #fff; padding: 1px 5px; border-radius: 3px;
    display: inline-block; margin-bottom: 2px;
}
.ehtv-grade-sched-badge {
    font-size: .56rem; font-weight: 800; background: #3B82F6;
    color: #fff; padding: 1px 5px; border-radius: 3px;
    display: inline-block; margin-bottom: 2px;
}

/* Desktop */
@media (min-width: 720px) {
    .ehtv-layout {
        display: grid;
        grid-template-columns: 1fr 280px;
        grid-template-rows: auto auto;
    }
    .ehtv-player-area { grid-column: 1; grid-row: 1; }
    .ehtv-controls    { grid-column: 1; grid-row: 2; }
    .ehtv-playlist    { grid-column: 2; grid-row: 1 / 3; max-height: none; overflow-y: auto; }
    .ehtv-grade-drawer { width: 260px; }
}

/* Mobile */
@media (max-width: 719px) {
    .ehtv-grade-drawer { width: 88vw; max-width: 300px; }
}

.ehtv-empty {
    padding: 3rem 1rem; text-align: center;
    color: rgba(255,255,255,.3); font-family: Nunito, sans-serif;
}
</style>

<!-- YouTube IFrame API — carregamento único por página -->
<script>
if (!window._ehtvYTLoaded) {
    window._ehtvYTLoaded = true;
    var _s = document.createElement('script');
    _s.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(_s);
}
</script>

<div class="ehtv-wrap">

<?php if (empty($playlist)) : ?>
<div class="ehtv-empty">
    <div style="font-size:3rem;margin-bottom:.75rem">📺</div>
    <div style="font-weight:700">Nenhum vídeo na playlist ainda.</div>
    <div style="font-size:.8rem;margin-top:.4rem">Acesse o painel para adicionar conteúdos.</div>
</div>
<?php else : ?>

<div class="ehtv-layout">

    <!-- Player -->
    <div class="ehtv-player-area" id="ehtv-stage">
        <video id="ehtv-video-el"
               playsinline
               webkit-playsinline
               x-webkit-airplay="allow"
               preload="metadata">
        </video>

        <div id="ehtv-yt-container">
            <div id="ehtv-yt-player"></div>
        </div>

        <div class="ehtv-overlay" id="ehtv-overlay">
            <div class="ehtv-overlay-meta"  id="ehtv-overlay-meta"></div>
            <div class="ehtv-overlay-title" id="ehtv-overlay-title">Pronto para reproduzir</div>
            <div class="ehtv-overlay-sub"   id="ehtv-overlay-sub"></div>
        </div>

        <div id="ehtv-splash">
            <div id="ehtv-splash-icon">📺</div>
            <div id="ehtv-splash-text">TV Escolar · Inclusão Midiática</div>
            <button id="ehtv-play-first">▶ Iniciar reprodução</button>
        </div>

        <!-- Drawer "A seguir" -->
        <div class="ehtv-grade-drawer" id="ehtv-grade-drawer">
            <div class="ehtv-grade-header">
                <span>📅 A seguir na TV</span>
                <button class="ehtv-grade-close" id="ehtv-grade-close">✕</button>
            </div>
            <div class="ehtv-grade-scroll" id="ehtv-grade-scroll"></div>
        </div>
    </div>

    <!-- Controles -->
    <div class="ehtv-controls">
        <button class="ehtv-ctrl" id="ehtv-btn-prev"  title="Anterior (P)">⏮</button>
        <button class="ehtv-ctrl ehtv-ctrl-play" id="ehtv-btn-play" title="Play/Pause (Espaço)">▶</button>
        <button class="ehtv-ctrl" id="ehtv-btn-next"  title="Próximo (N)">⏭</button>
        <button class="ehtv-ctrl" id="ehtv-btn-fs"    title="Tela cheia (F)">⛶</button>
        <button class="ehtv-ctrl ehtv-ctrl-grade" id="ehtv-btn-grade" title="Grade (G)">📅</button>
        <span id="ehtv-clock" title="Horário de Brasília"></span>
        <label class="ehtv-autoplay-toggle">
            <div class="ehtv-toggle-dot on" id="ehtv-autoplay-dot"></div>
            Autoplay
        </label>
    </div>

    <!-- Playlist lateral -->
    <div class="ehtv-playlist">
        <div class="ehtv-playlist-label">📺 Playlist — <?= count($playlist) ?> vídeo<?= count($playlist)!==1?'s':'' ?></div>
        <?php foreach ($playlist as $i => $item) :
            $thumb = ehtv_thumb($item);
            $clf   = $item->classification ?? 'conteudo';
        ?>
        <div class="ehtv-item" id="ehtv-item-<?= $item->id ?>" onclick="ehtv.play(<?= $i ?>)">
            <span class="ehtv-item-num"><?= $i + 1 ?></span>
            <div class="ehtv-item-thumb">
                <?php if ($thumb) : ?><img src="<?= esc_url($thumb) ?>" alt="" loading="lazy"><?php else : ?>🎬<?php endif; ?>
            </div>
            <div class="ehtv-item-info">
                <div class="ehtv-item-title"><?= esc_html($item->title) ?></div>
                <div class="ehtv-item-sub">
                    <?php if ($item->type === 'youtube') : ?><span class="ehtv-yt-tag">YT</span><?php
                    else : ?><?= $item->duration ? floor($item->duration/60).':'.str_pad($item->duration%60,2,'0',STR_PAD_LEFT) : 'Vídeo' ?><?php endif; ?>
                    <?php if ($item->tema) : ?> · <?= esc_html($item->tema) ?><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</div><!-- .ehtv-layout -->
<?php endif; ?>
</div><!-- .ehtv-wrap -->

<script>
var EHTV_ITEMS    = <?= wp_json_encode($items_js) ?>;
var EHTV_SITE     = <?= wp_json_encode(home_url()) ?>;
var EHTV_AJAX     = <?= wp_json_encode(admin_url('admin-ajax.php')) ?>;
var EHTV_SCHEDULE = <?= wp_json_encode($schedule_js) ?>;

// ── YouTube IFrame API ────────────────────────────────────────
var ytPlayer     = null;
var ytReady      = false;
var ytPendingIdx = -1;

function onYouTubeIframeAPIReady() {
    if (ytReady) return;
    ytReady = true;
    ytPlayer = new YT.Player('ehtv-yt-player', {
        width: '100%', height: '100%',
        playerVars: {
            autoplay:       0,
            controls:       1,
            rel:            0,
            modestbranding: 1,
            playsinline:    1,    // essencial para iOS / Android
            fs:             1,
            enablejsapi:    1,
            origin:         window.location.origin,
            iv_load_policy: 3,
        },
        events: {
            onReady: function() {
                if (ytPendingIdx >= 0) {
                    var idx = ytPendingIdx;
                    ytPendingIdx = -1;
                    ehtv.play(idx);
                }
            },
            onStateChange: function(e) {
                if (e.data === YT.PlayerState.ENDED)    ehtv.onEnded();
                if (e.data === YT.PlayerState.PLAYING)  ehtv.onPlay();
                if (e.data === YT.PlayerState.PAUSED)   ehtv.onPause();
                if (e.data === YT.PlayerState.BUFFERING) ehtv.onPlay();
            },
            onError: function() {
                // Fallback para mobile: avança para o próximo após 3s
                setTimeout(function() { ehtv.next(); }, 3000);
            }
        }
    });
}

// ── Analytics ─────────────────────────────────────────────────
var ehtvSession = (function() {
    var k = localStorage.getItem('ehtv_session');
    if (!k) { k = Math.random().toString(36).substr(2,16) + Date.now().toString(36); localStorage.setItem('ehtv_session', k); }
    return k;
})();
var ehtvViewId  = 0;
var ehtvHbTimer = null;

function ehtvTrackView(playlistId) {
    if (!playlistId || !EHTV_AJAX) return;
    clearInterval(ehtvHbTimer);
    var body = 'action=ehtv_track_view&playlist_id=' + playlistId + '&session_key=' + encodeURIComponent(ehtvSession);
    fetch(EHTV_AJAX, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body, keepalive:true})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.success) {
                ehtvViewId = d.data.view_id;
                ehtvHbTimer = setInterval(function() {
                    fetch(EHTV_AJAX, {
                        method:'POST',
                        headers:{'Content-Type':'application/x-www-form-urlencoded'},
                        body:'action=ehtv_heartbeat&view_id=' + ehtvViewId + '&session_key=' + encodeURIComponent(ehtvSession),
                        keepalive:true,
                    });
                }, 25000);
            }
        }).catch(function(){});
}

// ── Relógio São Paulo ─────────────────────────────────────────
function ehtvSpTime() {
    // Converte UTC para America/Sao_Paulo usando Intl.DateTimeFormat
    try {
        var f = new Intl.DateTimeFormat('pt-BR', {
            timeZone: 'America/Sao_Paulo',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
        });
        return f.format(new Date());
    } catch(e) { return ''; }
}

function ehtvSpTimestamp() {
    // Timestamp Unix atual em segundos (igual ao servidor)
    return Math.floor(Date.now() / 1000);
}

function ehtvClockTick() {
    var el = document.getElementById('ehtv-clock');
    if (el) el.textContent = ehtvSpTime();
}

// ── Verificador de grade agendada ────────────────────────────
// Quando chega o horário de um item fixo, muda o vídeo automaticamente
function ehtvScheduleWatcher() {
    if (!EHTV_SCHEDULE.length || !ehtv.started) return;
    var now = ehtvSpTimestamp();
    for (var i = 0; i < EHTV_SCHEDULE.length; i++) {
        var entry = EHTV_SCHEDULE[i];
        if (entry.triggered) continue;
        if (entry.item_idx < 0) continue;
        // Janela: de 5s antes até 90s após o horário agendado
        if (now >= entry.timestamp - 5 && now < entry.timestamp + 90) {
            entry.triggered = true;
            if (ehtv.current !== entry.item_idx) {
                console.log('[EduHub TV] Grade: ' + entry.time_str + ' — ' + entry.title);
                ehtv.play(entry.item_idx);
            }
        }
    }
}

// ── Grade "A seguir" ──────────────────────────────────────────
function ehtvBuildGrade() {
    var scroll = document.getElementById('ehtv-grade-scroll');
    if (!scroll || !EHTV_ITEMS.length) return;
    scroll.innerHTML = '';

    var startIdx = ehtv.current >= 0 ? ehtv.current : 0;
    var cursor   = new Date(); // horário local do browser (compatível)
    var shown    = 0;
    var maxShown = 18;

    // Verificar próximos agendamentos para o dia
    var nowTs = ehtvSpTimestamp();
    var upcomingFixed = EHTV_SCHEDULE.filter(function(s) {
        return s.item_idx >= 0 && s.timestamp > nowTs - 300;
    }).sort(function(a,b){ return a.timestamp - b.timestamp; });

    var i = startIdx;
    while (shown < maxShown) {
        var item = EHTV_ITEMS[i % EHTV_ITEMS.length];
        var dur  = (item.duration || 300) * 1000;
        var isNow = (shown === 0 && ehtv.current >= 0);

        // Verificar se existe item fixo neste horário
        var fixedEntry = null;
        for (var fi = 0; fi < upcomingFixed.length; fi++) {
            var fe = upcomingFixed[fi];
            var feTime = fe.timestamp * 1000;
            if (feTime >= cursor.getTime() && feTime < cursor.getTime() + dur) {
                fixedEntry = fe;
                break;
            }
        }

        var timeStr = cursor.toLocaleTimeString('pt-BR', {
            timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit', hour12: false,
        });

        var entry = document.createElement('div');
        entry.className = 'ehtv-grade-entry' + (isNow ? ' now-entry' : '');

        var thumbHtml = item.thumb
            ? '<img src="' + ehtvEsc(item.thumb) + '" alt="" loading="lazy">'
            : '🎬';

        var clfMap = {'conteudo':'Conteúdo','propaganda_externa':'Prop. Externa','propaganda_institucional':'Prop. Institucional'};
        var clfLabel = clfMap[item.classification] || 'Conteúdo';

        var badgesHtml = '';
        if (isNow) badgesHtml += '<span class="ehtv-grade-now-badge">▶ AO VIVO</span><br>';
        if (fixedEntry) badgesHtml += '<span class="ehtv-grade-sched-badge">🕐 ' + fixedEntry.time_str + ' FIXO</span><br>';

        entry.innerHTML =
            '<div class="ehtv-grade-time">' + timeStr + '</div>' +
            '<div class="ehtv-grade-thumb">' + thumbHtml + '</div>' +
            '<div class="ehtv-grade-info">' +
                badgesHtml +
                '<div class="ehtv-grade-title">' + ehtvEsc(item.title) + '</div>' +
                '<div class="ehtv-grade-sub">' + clfLabel + (item.tema ? ' · ' + ehtvEsc(item.tema) : '') + '</div>' +
            '</div>';

        (function(idx){ entry.addEventListener('click', function(){ ehtv.play(idx % EHTV_ITEMS.length); }); })(i);
        scroll.appendChild(entry);

        cursor = new Date(cursor.getTime() + dur);
        i++;
        shown++;
    }
}

function ehtvEsc(str) {
    return String(str||'')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Player principal ──────────────────────────────────────────
var ehtv = {
    current:  -1,
    autoplay: true,
    started:  false,

    init: function() {
        var vid = document.getElementById('ehtv-video-el');
        if (!vid) return;

        vid.addEventListener('ended',  function() { ehtv.onEnded(); });
        vid.addEventListener('play',   function() { ehtv.onPlay(); });
        vid.addEventListener('pause',  function() { ehtv.onPause(); });
        vid.addEventListener('error',  function() { setTimeout(function(){ ehtv.next(); }, 2000); });

        // Tap na área do player para play/pause
        document.getElementById('ehtv-stage').addEventListener('click', function(e) {
            if ((e.target === this || e.target === vid) && ehtv.started) ehtv.togglePlay();
        });

        document.getElementById('ehtv-btn-play').addEventListener('click', function() { ehtv.togglePlay(); });
        document.getElementById('ehtv-btn-prev').addEventListener('click', function() { ehtv.prev(); });
        document.getElementById('ehtv-btn-next').addEventListener('click', function() { ehtv.next(); });
        document.getElementById('ehtv-btn-fs').addEventListener('click',   function() { ehtv.fullscreen(); });
        document.getElementById('ehtv-btn-grade').addEventListener('click', function() { ehtv.toggleGrade(); });
        document.getElementById('ehtv-grade-close').addEventListener('click', function() { ehtv.closeGrade(); });

        document.getElementById('ehtv-play-first')?.addEventListener('click', function() {
            ehtv.started = true;
            document.getElementById('ehtv-splash').style.display = 'none';
            ehtv.play(ehtv.findCurrentScheduledIdx());
        });

        document.getElementById('ehtv-autoplay-dot').parentElement.addEventListener('click', function() {
            ehtv.autoplay = !ehtv.autoplay;
            document.getElementById('ehtv-autoplay-dot').classList.toggle('on', ehtv.autoplay);
        });

        // Teclado
        document.addEventListener('keydown', function(e) {
            if (e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA') return;
            if (e.key===' '||e.key==='k')  { e.preventDefault(); ehtv.togglePlay(); }
            if (e.key==='ArrowRight'||e.key==='n') ehtv.next();
            if (e.key==='ArrowLeft' ||e.key==='p') ehtv.prev();
            if (e.key==='f') ehtv.fullscreen();
            if (e.key==='g') ehtv.toggleGrade();
            if (e.key==='Escape') ehtv.closeGrade();
        });

        // Relógio (atualiza a cada segundo)
        ehtvClockTick();
        setInterval(ehtvClockTick, 1000);

        // Verificador de grade (a cada 10 segundos)
        setInterval(ehtvScheduleWatcher, 10000);
        // Verificar logo ao iniciar (após o player estar pronto)
        setTimeout(ehtvScheduleWatcher, 3000);
    },

    // Ao pressionar "Iniciar reprodução", toca o item certo da grade ou o primeiro da lista
    findCurrentScheduledIdx: function() {
        var now = ehtvSpTimestamp();
        var best = null;
        for (var i = 0; i < EHTV_SCHEDULE.length; i++) {
            var e = EHTV_SCHEDULE[i];
            if (e.item_idx >= 0 && e.timestamp <= now && e.timestamp > now - 3600) {
                if (!best || e.timestamp > best.timestamp) best = e;
            }
        }
        return best ? best.item_idx : 0;
    },

    play: function(idx) {
        if (!EHTV_ITEMS.length) return;
        idx = ((idx % EHTV_ITEMS.length) + EHTV_ITEMS.length) % EHTV_ITEMS.length;
        var item = EHTV_ITEMS[idx];
        this.current = idx;

        ehtvTrackView(item.id);

        var splash = document.getElementById('ehtv-splash');
        if (splash) splash.style.display = 'none';
        this.started = true;

        // Overlay
        var metaEl  = document.getElementById('ehtv-overlay-meta');
        var titleEl = document.getElementById('ehtv-overlay-title');
        var subEl   = document.getElementById('ehtv-overlay-sub');
        var clfMap2 = {'conteudo':'Conteúdo','propaganda_externa':'Prop. Externa','propaganda_institucional':'Prop. Institucional'};
        var clf     = item.classification || 'conteudo';
        if (metaEl) metaEl.innerHTML =
            '<span class="ehtv-clf-tag ' + clf + '">' + (clfMap2[clf]||'') + '</span>' +
            (item.tema ? '<span style="font-size:.7rem;opacity:.6">' + ehtvEsc(item.tema) + '</span>' : '');
        if (titleEl) titleEl.textContent = item.title;
        if (subEl)   subEl.textContent   = item.description ? item.description.substring(0,100) : '';

        // Highlight na playlist
        document.querySelectorAll('.ehtv-item').forEach(function(el) { el.classList.remove('active'); });
        var activeEl = document.querySelector('#ehtv-item-' + item.id);
        if (activeEl) { activeEl.classList.add('active'); activeEl.scrollIntoView({behavior:'smooth', block:'nearest'}); }

        ehtvBuildGrade();

        var vid    = document.getElementById('ehtv-video-el');
        var ytWrap = document.getElementById('ehtv-yt-container');

        if (item.type === 'youtube') {
            vid.pause(); vid.src = ''; vid.style.display = 'none';
            ytWrap.style.display = 'block';

            if (!ytReady || !ytPlayer || typeof ytPlayer.loadVideoById !== 'function') {
                ytPendingIdx = idx; return;
            }
            try {
                ytPlayer.loadVideoById({ videoId: item.youtube_id, suggestedQuality: 'hd720' });
                ytPlayer.playVideo();
            } catch(e) { ytPendingIdx = idx; }

        } else {
            ytWrap.style.display = 'none';
            if (ytPlayer && typeof ytPlayer.pauseVideo === 'function') ytPlayer.pauseVideo();
            vid.style.display = 'block';
            vid.src = item.file_url || item.ext_url;
            vid.load();
            var pp = vid.play();
            if (pp !== undefined) pp.catch(function() {
                document.getElementById('ehtv-btn-play').textContent = '▶';
            });
        }

        document.getElementById('ehtv-btn-play').textContent = '⏸';
    },

    next: function() {
        if (!EHTV_ITEMS.length) return;
        // Verificar se há item fixo pendente a seguir antes de avançar linearmente
        var now = ehtvSpTimestamp();
        var nextFixed = null;
        for (var i = 0; i < EHTV_SCHEDULE.length; i++) {
            var e = EHTV_SCHEDULE[i];
            if (e.triggered || e.item_idx < 0) continue;
            if (e.timestamp <= now + 30 && e.timestamp > now - 90) {
                nextFixed = e; break;
            }
        }
        if (nextFixed) {
            nextFixed.triggered = true;
            this.play(nextFixed.item_idx);
        } else {
            this.play((this.current + 1) % EHTV_ITEMS.length);
        }
    },

    prev: function() {
        if (!EHTV_ITEMS.length) return;
        this.play(((this.current - 1) + EHTV_ITEMS.length) % EHTV_ITEMS.length);
    },

    onEnded: function() {
        if (this.autoplay) setTimeout(function() { ehtv.next(); }, 1000);
    },

    onPlay: function() {
        document.getElementById('ehtv-btn-play').textContent = '⏸';
    },

    onPause: function() {
        document.getElementById('ehtv-btn-play').textContent = '▶';
    },

    togglePlay: function() {
        var item = EHTV_ITEMS[this.current];
        if (!item) { this.play(0); return; }
        if (item.type === 'youtube' && ytPlayer && typeof ytPlayer.getPlayerState === 'function') {
            var st = ytPlayer.getPlayerState();
            if (st === 1) ytPlayer.pauseVideo(); else ytPlayer.playVideo();
        } else {
            var vid = document.getElementById('ehtv-video-el');
            if (vid.paused) vid.play().catch(function(){}); else vid.pause();
        }
    },

    fullscreen: function() {
        var stage = document.getElementById('ehtv-stage');
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        } else {
            var req = stage.requestFullscreen || stage.webkitRequestFullscreen;
            if (req) req.call(stage);
        }
    },

    toggleGrade: function() {
        var drawer = document.getElementById('ehtv-grade-drawer');
        var btn    = document.getElementById('ehtv-btn-grade');
        if (!drawer) return;
        if (drawer.classList.contains('open')) {
            this.closeGrade();
        } else {
            ehtvBuildGrade();
            drawer.classList.add('open');
            if (btn) btn.classList.add('open');
        }
    },

    closeGrade: function() {
        var drawer = document.getElementById('ehtv-grade-drawer');
        var btn    = document.getElementById('ehtv-btn-grade');
        if (drawer) drawer.classList.remove('open');
        if (btn)    btn.classList.remove('open');
    },
};

document.addEventListener('DOMContentLoaded', function() { ehtv.init(); });
</script>
