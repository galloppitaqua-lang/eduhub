<?php
/* player.php — TV Escolar pública v2 */
if ( ! defined('ABSPATH') ) exit;

$playlist = ehtv_get_playlist();
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
        'scheduled_time' => $item->scheduled_time ?? '',
    ];
}

$clf_labels = [
    'conteudo'                 => 'Conteúdo',
    'propaganda_externa'       => 'Prop. Externa',
    'propaganda_institucional' => 'Prop. Institucional',
];
?>

<style>
/* ── TV Player v2 ─────────────────────────────────────────── */
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

.ehtv-layout {
    display: flex;
    flex-direction: column;
}

/* Player */
.ehtv-player-area {
    position: relative;
    background: #000;
    width: 100%;
    aspect-ratio: 16/9;
}

#ehtv-video-el {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}

/* Container YouTube — ocupa toda a área para garantir playback mobile */
#ehtv-yt-container {
    position: absolute;
    inset: 0;
    display: none;
    background: #000;
}
#ehtv-yt-player {
    width: 100%;
    height: 100%;
}
#ehtv-yt-container iframe {
    width: 100% !important;
    height: 100% !important;
    position: absolute;
    inset: 0;
}

/* Overlay */
.ehtv-overlay {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    padding: 1rem 1.25rem;
    background: linear-gradient(transparent, rgba(0,0,0,.85));
    pointer-events: none;
    transition: opacity .3s;
}
.ehtv-overlay-title { font-weight: 800; font-size: 1rem; margin-bottom: 2px; }
.ehtv-overlay-meta  { display: flex; align-items: center; gap: .4rem; margin-bottom: 3px; flex-wrap: wrap; }
.ehtv-overlay-sub   { font-size: .75rem; opacity: .65; }
.ehtv-clf-tag {
    font-size: .6rem; font-weight: 800; padding: 2px 6px;
    border-radius: 3px; letter-spacing: .04em;
}
.ehtv-clf-tag.conteudo                 { background: #16a34a; color: #fff; }
.ehtv-clf-tag.propaganda_externa       { background: #d97706; color: #fff; }
.ehtv-clf-tag.propaganda_institucional { background: #2563eb; color: #fff; }

/* Splash */
#ehtv-splash {
    position: absolute; inset: 0;
    background: #0a0a0f;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 1rem;
}
#ehtv-splash-icon { font-size: 4rem; opacity: .4; }
#ehtv-splash-text { font-size: .9rem; opacity: .4; font-family: Nunito, sans-serif; }
#ehtv-play-first  {
    padding: .65rem 1.5rem;
    background: #E94560;
    border: none; border-radius: 99px;
    color: #fff; font-family: Nunito, sans-serif;
    font-size: .9rem; font-weight: 800;
    cursor: pointer; transition: opacity .15s;
}
#ehtv-play-first:hover { opacity: .85; }

/* Controles */
.ehtv-controls {
    display: flex; align-items: center; justify-content: center;
    gap: .75rem;
    padding: .75rem 1rem;
    background: rgba(255,255,255,.03);
    border-bottom: 1px solid rgba(255,255,255,.06);
    flex-wrap: wrap;
}
.ehtv-ctrl {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: rgba(255,255,255,.08);
    border: none; color: rgba(255,255,255,.75);
    font-size: 1rem; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s; flex-shrink: 0;
}
.ehtv-ctrl:hover { background: rgba(255,255,255,.16); }
.ehtv-ctrl-play {
    width: 54px; height: 54px; font-size: 1.3rem;
    background: #E94560 !important; color: #fff !important;
}
.ehtv-ctrl-play:hover { background: #c73451 !important; }
.ehtv-ctrl-grade { background: rgba(255,255,255,.06); }
.ehtv-ctrl-grade.open { background: rgba(233,69,96,.25); color: #E94560; }

.ehtv-autoplay-toggle {
    display: flex; align-items: center; gap: .4rem;
    font-size: .72rem; color: rgba(255,255,255,.5);
    cursor: pointer; margin-left: auto;
    font-family: Nunito, sans-serif; font-weight: 700;
    user-select: none;
}
.ehtv-toggle-dot {
    width: 28px; height: 16px;
    background: rgba(255,255,255,.15); border-radius: 99px;
    position: relative; transition: background .2s;
}
.ehtv-toggle-dot::after {
    content: ''; position: absolute; top: 2px; left: 2px;
    width: 12px; height: 12px; border-radius: 50%; background: #fff;
    transition: transform .2s;
}
.ehtv-toggle-dot.on { background: #E94560; }
.ehtv-toggle-dot.on::after { transform: translateX(12px); }

/* Playlist */
.ehtv-playlist {
    background: #111116;
    max-height: 340px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.1) transparent;
}
.ehtv-playlist-label {
    padding: .6rem 1rem;
    font-size: .6rem; font-weight: 800;
    letter-spacing: .12em; text-transform: uppercase;
    color: rgba(255,255,255,.3);
    border-bottom: 1px solid rgba(255,255,255,.05);
}
.ehtv-item {
    display: flex; align-items: center; gap: .65rem;
    padding: .6rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,.04);
    cursor: pointer; transition: background .15s;
}
.ehtv-item:hover { background: rgba(255,255,255,.05); }
.ehtv-item.active { background: rgba(255,255,255,.08); }
.ehtv-item.active .ehtv-item-title { color: #E94560; }
.ehtv-item-thumb {
    width: 72px; height: 44px; object-fit: cover;
    border-radius: 6px; background: rgba(255,255,255,.06);
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; color: rgba(255,255,255,.25); overflow: hidden;
}
.ehtv-item-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ehtv-item-info { flex: 1; min-width: 0; }
.ehtv-item-title {
    font-size: .82rem; font-weight: 700;
    color: rgba(255,255,255,.85);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ehtv-item-sub  { font-size: .68rem; color: rgba(255,255,255,.35); margin-top: 2px; }
.ehtv-item-num  { font-size: .7rem; font-weight: 800; color: rgba(255,255,255,.2); width: 20px; text-align: center; flex-shrink: 0; }
.ehtv-now-tag   { font-size: .58rem; font-weight: 800; background: #E94560; color: #fff; padding: 2px 6px; border-radius: 4px; letter-spacing: .04em; flex-shrink: 0; }
.ehtv-yt-tag    { font-size: .58rem; font-weight: 800; background: #ff0000; color: #fff; padding: 2px 5px; border-radius: 3px; flex-shrink: 0; }

/* ── Painel "A seguir" ───────────────────────────────────── */
.ehtv-grade-drawer {
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 280px;
    background: rgba(10,10,20,.97);
    backdrop-filter: blur(12px);
    border-left: 1px solid rgba(255,255,255,.08);
    display: flex; flex-direction: column;
    transform: translateX(100%);
    transition: transform .3s cubic-bezier(.4,0,.2,1);
    z-index: 50;
    overflow: hidden;
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
    cursor: pointer; font-size: 1rem; padding: 0 4px;
    line-height: 1;
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
    align-items: flex-start;
}
.ehtv-grade-entry.now-entry { background: rgba(233,69,96,.12); }
.ehtv-grade-time {
    font-family: monospace; font-size: .72rem;
    color: rgba(255,255,255,.4); width: 42px; flex-shrink: 0;
    padding-top: 2px;
}
.ehtv-grade-thumb {
    width: 52px; height: 32px; border-radius: 4px;
    object-fit: cover; background: rgba(255,255,255,.05);
    flex-shrink: 0; overflow: hidden;
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem; color: rgba(255,255,255,.2);
}
.ehtv-grade-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ehtv-grade-info { flex: 1; min-width: 0; }
.ehtv-grade-title {
    font-size: .78rem; font-weight: 700;
    color: rgba(255,255,255,.8);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ehtv-grade-sub { font-size: .66rem; color: rgba(255,255,255,.35); margin-top: 1px; }
.ehtv-grade-now-badge {
    font-size: .56rem; font-weight: 800; background: #E94560;
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

    /* No desktop, o drawer fica sobre a área do player */
    .ehtv-grade-drawer { width: 260px; }
}

/* Mobile */
@media (max-width: 719px) {
    .ehtv-grade-drawer { width: 85vw; max-width: 300px; }
}

.ehtv-empty {
    padding: 3rem 1rem; text-align: center;
    color: rgba(255,255,255,.3); font-family: Nunito, sans-serif;
}
</style>

<!-- YouTube IFrame API — mobile-friendly -->
<script>
// Garante que a API só carrega uma vez por página
if (!window._ehtvYTApiLoading) {
    window._ehtvYTApiLoading = true;
    var s = document.createElement('script');
    s.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(s);
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
               preload="metadata"
               x-webkit-airplay="allow">
        </video>

        <!-- Container YouTube -->
        <div id="ehtv-yt-container">
            <div id="ehtv-yt-player"></div>
        </div>

        <!-- Overlay info -->
        <div class="ehtv-overlay" id="ehtv-overlay">
            <div class="ehtv-overlay-meta" id="ehtv-overlay-meta"></div>
            <div class="ehtv-overlay-title" id="ehtv-overlay-title">Pronto para reproduzir</div>
            <div class="ehtv-overlay-sub"   id="ehtv-overlay-sub"></div>
        </div>

        <!-- Splash inicial -->
        <div id="ehtv-splash">
            <div id="ehtv-splash-icon">📺</div>
            <div id="ehtv-splash-text">TV Escolar · Inclusão Midiática</div>
            <button id="ehtv-play-first">▶ Iniciar reprodução</button>
        </div>

        <!-- Drawer "A seguir" (oculto por padrão) -->
        <div class="ehtv-grade-drawer" id="ehtv-grade-drawer">
            <div class="ehtv-grade-header">
                <span>📅 A seguir na TV</span>
                <button class="ehtv-grade-close" id="ehtv-grade-close" title="Fechar">✕</button>
            </div>
            <div class="ehtv-grade-scroll" id="ehtv-grade-scroll"></div>
        </div>
    </div>

    <!-- Controles -->
    <div class="ehtv-controls">
        <button class="ehtv-ctrl" id="ehtv-btn-prev" title="Anterior (P)">⏮</button>
        <button class="ehtv-ctrl ehtv-ctrl-play" id="ehtv-btn-play" title="Play/Pause (Espaço)">▶</button>
        <button class="ehtv-ctrl" id="ehtv-btn-next" title="Próximo (N)">⏭</button>
        <button class="ehtv-ctrl" id="ehtv-btn-fs" title="Tela cheia (F)">⛶</button>
        <button class="ehtv-ctrl ehtv-ctrl-grade" id="ehtv-btn-grade" title="Grade de programação">📅</button>
        <label class="ehtv-autoplay-toggle" title="Autoplay loop">
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
                    <?php if ($item->type === 'youtube') : ?>
                    <span class="ehtv-yt-tag">YT</span>
                    <?php else : ?>
                    <?= $item->duration ? floor($item->duration/60).':'.str_pad($item->duration%60,2,'0',STR_PAD_LEFT) : 'Vídeo' ?>
                    <?php endif; ?>
                    <?php if ($item->tema) : ?> · <?= esc_html($item->tema) ?><?php endif; ?>
                </div>
            </div>
            <?php if (!empty($item->scheduled_time)) : ?>
            <span style="font-size:.62rem;color:rgba(255,255,255,.35);flex-shrink:0">🕐<?= esc_html(substr($item->scheduled_time,0,5)) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

</div><!-- .ehtv-layout -->

<?php endif; ?>
</div><!-- .ehtv-wrap -->

<script>
var EHTV_ITEMS   = <?= wp_json_encode($items_js) ?>;
var EHTV_SITE    = <?= wp_json_encode(home_url()) ?>;
var EHTV_AJAX    = <?= wp_json_encode(admin_url('admin-ajax.php')) ?>;
var ytPlayer     = null;
var ytReady      = false;
var ytPendingIdx = -1;

// ── YouTube IFrame API ────────────────────────────────────────
function onYouTubeIframeAPIReady() {
    if (ytReady) return; // evitar dupla inicialização
    ytReady = true;
    ytPlayer = new YT.Player('ehtv-yt-player', {
        width: '100%', height: '100%',
        playerVars: {
            autoplay:        0,
            controls:        1,
            rel:             0,
            modestbranding:  1,
            playsinline:     1,   // essencial para iOS
            fs:              1,
            enablejsapi:     1,
            origin:          window.location.origin,
            iv_load_policy:  3,
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
                if (e.data === YT.PlayerState.ENDED)   ehtv.onEnded();
                if (e.data === YT.PlayerState.PLAYING)  ehtv.onPlay();
                if (e.data === YT.PlayerState.PAUSED)   ehtv.onPause();
                if (e.data === YT.PlayerState.BUFFERING) ehtv.onPlay();
            },
            onError: function() {
                // Fallback: tentar abrir no YouTube diretamente em mobile
                var item = EHTV_ITEMS[ehtv.current];
                if (item && item.youtube_id && /Mobi|Android|iPhone/i.test(navigator.userAgent)) {
                    setTimeout(function() { ehtv.next(); }, 2000);
                }
            }
        }
    });
}

// ── Analytics: sessão ─────────────────────────────────────────
var ehtvSession = (function() {
    var key = localStorage.getItem('ehtv_session');
    if (!key) { key = Math.random().toString(36).substr(2,16) + Date.now().toString(36); localStorage.setItem('ehtv_session', key); }
    return key;
})();
var ehtvViewId   = 0;
var ehtvHbTimer  = null;

function ehtvTrackView(playlistId) {
    if (!playlistId) return;
    clearInterval(ehtvHbTimer);
    fetch(EHTV_AJAX, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=ehtv_track_view&playlist_id=' + playlistId + '&session_key=' + encodeURIComponent(ehtvSession),
        keepalive: true,
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.success) {
            ehtvViewId = d.data.view_id;
            ehtvHbTimer = setInterval(function() {
                fetch(EHTV_AJAX, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=ehtv_heartbeat&view_id=' + ehtvViewId + '&session_key=' + encodeURIComponent(ehtvSession),
                    keepalive: true,
                });
            }, 25000); // heartbeat a cada 25s
        }
    }).catch(function(){});
}

// ── Grade "A seguir" ──────────────────────────────────────────
function ehtvBuildGrade() {
    if (!EHTV_ITEMS.length) return;
    var scroll  = document.getElementById('ehtv-grade-scroll');
    if (!scroll) return;
    scroll.innerHTML = '';

    var now    = new Date();
    var cursor = new Date(now);
    var startIdx = ehtv.current >= 0 ? ehtv.current : 0;
    var shown  = 0;
    var maxShown = 15;
    var i = startIdx;

    while (shown < maxShown) {
        var item = EHTV_ITEMS[i % EHTV_ITEMS.length];
        var dur  = (item.duration || 300) * 1000;
        var isNow = (shown === 0 && ehtv.current >= 0);

        var entry = document.createElement('div');
        entry.className = 'ehtv-grade-entry' + (isNow ? ' now-entry' : '');

        var timeStr = cursor.getHours().toString().padStart(2,'0') + ':' +
                      cursor.getMinutes().toString().padStart(2,'0');

        var thumbHtml = item.thumb
            ? '<img src="' + ehtvEsc(item.thumb) + '" alt="" loading="lazy">'
            : '🎬';

        var clfMap = {
            'conteudo': 'Conteúdo',
            'propaganda_externa': 'Prop. Externa',
            'propaganda_institucional': 'Prop. Institucional',
        };
        var clfLabel = clfMap[item.classification] || 'Conteúdo';

        entry.innerHTML =
            '<div class="ehtv-grade-time">' + timeStr + '</div>' +
            '<div class="ehtv-grade-thumb">' + thumbHtml + '</div>' +
            '<div class="ehtv-grade-info">' +
                (isNow ? '<span class="ehtv-grade-now-badge">▶ AO VIVO</span><br>' : '') +
                '<div class="ehtv-grade-title">' + ehtvEsc(item.title) + '</div>' +
                '<div class="ehtv-grade-sub">' +
                    clfLabel +
                    (item.tema ? ' · ' + ehtvEsc(item.tema) : '') +
                '</div>' +
            '</div>';

        // Clique: ir para o item
        (function(idx){ entry.addEventListener('click', function(){ ehtv.play(idx % EHTV_ITEMS.length); }); })(i);
        entry.style.cursor = 'pointer';

        scroll.appendChild(entry);
        cursor = new Date(cursor.getTime() + dur);
        i++;
        shown++;
    }
}

function ehtvEsc(str) {
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
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

        // Touch: tap para play/pause em mobile
        document.getElementById('ehtv-stage').addEventListener('click', function(e) {
            if (e.target === this || e.target.id === 'ehtv-video-el') {
                if (ehtv.started) ehtv.togglePlay();
            }
        });

        document.getElementById('ehtv-btn-play').addEventListener('click', function() { ehtv.togglePlay(); });
        document.getElementById('ehtv-btn-prev').addEventListener('click', function() { ehtv.prev(); });
        document.getElementById('ehtv-btn-next').addEventListener('click', function() { ehtv.next(); });
        document.getElementById('ehtv-btn-fs').addEventListener('click',   function() { ehtv.fullscreen(); });

        // Botão grade
        document.getElementById('ehtv-btn-grade').addEventListener('click', function() {
            ehtv.toggleGrade();
        });
        document.getElementById('ehtv-grade-close').addEventListener('click', function() {
            ehtv.closeGrade();
        });

        document.getElementById('ehtv-play-first')?.addEventListener('click', function() {
            ehtv.started = true;
            document.getElementById('ehtv-splash').style.display = 'none';
            ehtv.play(0);
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
    },

    play: function(idx) {
        if (!EHTV_ITEMS.length) return;
        idx = ((idx % EHTV_ITEMS.length) + EHTV_ITEMS.length) % EHTV_ITEMS.length;
        var item = EHTV_ITEMS[idx];
        this.current = idx;

        // Rastrear visualização
        ehtvTrackView(item.id);

        var splash = document.getElementById('ehtv-splash');
        if (splash) splash.style.display = 'none';
        this.started = true;

        // Overlay
        var metaEl  = document.getElementById('ehtv-overlay-meta');
        var titleEl = document.getElementById('ehtv-overlay-title');
        var subEl   = document.getElementById('ehtv-overlay-sub');
        if (metaEl) {
            var clfClass = item.classification || 'conteudo';
            var clfMap2 = {'conteudo':'Conteúdo','propaganda_externa':'Prop. Externa','propaganda_institucional':'Prop. Institucional'};
            metaEl.innerHTML = '<span class="ehtv-clf-tag ' + clfClass + '">' + (clfMap2[clfClass]||'') + '</span>' +
                               (item.tema ? '<span style="font-size:.7rem;opacity:.6">' + ehtvEsc(item.tema) + '</span>' : '');
        }
        if (titleEl) titleEl.textContent = item.title;
        if (subEl)   subEl.textContent   = item.description ? item.description.substring(0,100) : '';

        // Highlight playlist
        document.querySelectorAll('.ehtv-item').forEach(function(el) { el.classList.remove('active'); });
        var activeEl = document.querySelector('#ehtv-item-' + item.id);
        if (activeEl) {
            activeEl.classList.add('active');
            activeEl.scrollIntoView({behavior:'smooth', block:'nearest'});
        }

        // Atualizar grade se aberta
        ehtvBuildGrade();

        var vid    = document.getElementById('ehtv-video-el');
        var ytWrap = document.getElementById('ehtv-yt-container');

        if (item.type === 'youtube') {
            vid.pause(); vid.src = ''; vid.style.display = 'none';
            ytWrap.style.display = 'block';

            if (!ytReady || !ytPlayer || typeof ytPlayer.loadVideoById !== 'function') {
                ytPendingIdx = idx;
                return;
            }
            try {
                ytPlayer.loadVideoById({ videoId: item.youtube_id, suggestedQuality: 'hd720' });
                ytPlayer.playVideo();
            } catch(e) {
                ytPendingIdx = idx;
            }
        } else {
            ytWrap.style.display = 'none';
            if (ytPlayer && typeof ytPlayer.pauseVideo === 'function') ytPlayer.pauseVideo();
            vid.style.display = 'block';
            var url = item.file_url || item.ext_url;
            vid.src = url;
            vid.load();
            var playPromise = vid.play();
            if (playPromise !== undefined) {
                playPromise.catch(function() {
                    // Autoplay bloqueado (comum em mobile) — exibir botão play
                    document.getElementById('ehtv-btn-play').textContent = '▶';
                });
            }
        }

        document.getElementById('ehtv-btn-play').textContent = '⏸';
    },

    next: function() {
        if (!EHTV_ITEMS.length) return;
        this.play((this.current + 1) % EHTV_ITEMS.length);
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
        var isOpen = drawer.classList.contains('open');
        if (isOpen) {
            this.closeGrade();
        } else {
            ehtvBuildGrade();
            drawer.classList.add('open');
            btn.classList.add('open');
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
