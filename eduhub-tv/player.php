<?php
/* player.php — TV Escolar pública */
if ( ! defined('ABSPATH') ) exit;

$playlist = ehtv_get_playlist();
// Preparar dados para JS
$items_js = [];
foreach ($playlist as $item) {
    $items_js[] = [
        'id'          => (int)$item->id,
        'type'        => $item->type,
        'title'       => $item->title,
        'description' => $item->description ?? '',
        'thumb'       => ehtv_thumb($item),
        'youtube_id'  => $item->youtube_id ?? '',
        'file_url'    => $item->type === 'material' ? ehtv_video_url($item) : '',
        'ext_url'     => $item->type === 'url' ? ($item->external_url ?? '') : '',
        'duration'    => (int)($item->duration ?? 0),
    ];
}
?>

<style>
/* ── TV Player ────────────────────────────────────────────── */
.ehtv-wrap {
    background: #0a0a0f;
    border-radius: 16px;
    overflow: hidden;
    font-family: 'Nunito', sans-serif;
    color: #fff;
    max-width: 1100px;
    margin: 0 auto;
}

/* Layout: player + sidebar */
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

#ehtv-yt-frame {
    width: 100%;
    height: 100%;
    border: none;
    display: none;
    position: absolute;
    top: 0; left: 0;
}

/* Overlay de info */
.ehtv-overlay {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    padding: 1rem 1.25rem;
    background: linear-gradient(transparent, rgba(0,0,0,.85));
    pointer-events: none;
    transition: opacity .3s;
}
.ehtv-overlay-title { font-weight: 800; font-size: 1rem; margin-bottom: 2px; }
.ehtv-overlay-sub   { font-size: .75rem; opacity: .65; }

/* Tela de início (quando nenhum vídeo tocando) */
#ehtv-splash {
    position: absolute; inset: 0;
    background: #0a0a0f;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 1rem;
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
    width: 54px; height: 54px;
    font-size: 1.3rem;
    background: #E94560 !important;
    color: #fff !important;
}
.ehtv-ctrl-play:hover { background: #c73451 !important; }

.ehtv-autoplay-toggle {
    display: flex; align-items: center; gap: .4rem;
    font-size: .72rem; color: rgba(255,255,255,.5);
    cursor: pointer; margin-left: auto;
    font-family: Nunito, sans-serif; font-weight: 700;
    user-select: none;
}
.ehtv-toggle-dot {
    width: 28px; height: 16px;
    background: rgba(255,255,255,.15);
    border-radius: 99px; position: relative;
    transition: background .2s;
}
.ehtv-toggle-dot::after {
    content: '';
    position: absolute; top: 2px; left: 2px;
    width: 12px; height: 12px;
    border-radius: 50%; background: #fff;
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
    cursor: pointer;
    transition: background .15s;
}
.ehtv-item:hover { background: rgba(255,255,255,.05); }
.ehtv-item.active { background: rgba(255,255,255,.08); }
.ehtv-item.active .ehtv-item-title { color: #E94560; }
.ehtv-item-thumb {
    width: 72px; height: 44px;
    object-fit: cover;
    border-radius: 6px;
    background: rgba(255,255,255,.06);
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; color: rgba(255,255,255,.25);
    overflow: hidden;
}
.ehtv-item-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ehtv-item-info { flex: 1; min-width: 0; }
.ehtv-item-title {
    font-size: .82rem; font-weight: 700;
    color: rgba(255,255,255,.85);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ehtv-item-sub {
    font-size: .68rem; color: rgba(255,255,255,.35);
    margin-top: 2px;
}
.ehtv-item-num {
    font-size: .7rem; font-weight: 800;
    color: rgba(255,255,255,.2);
    width: 20px; text-align: center; flex-shrink: 0;
}
.ehtv-now-tag {
    font-size: .58rem; font-weight: 800;
    background: #E94560; color: #fff;
    padding: 2px 6px; border-radius: 4px;
    letter-spacing: .04em; flex-shrink: 0;
}
.ehtv-yt-tag {
    font-size: .58rem; font-weight: 800;
    background: #ff0000; color: #fff;
    padding: 2px 5px; border-radius: 3px;
    flex-shrink: 0;
}

/* Desktop: lado a lado */
@media (min-width: 720px) {
    .ehtv-layout {
        display: grid;
        grid-template-columns: 1fr 280px;
        grid-template-rows: auto auto;
    }
    .ehtv-player-area  { grid-column: 1; grid-row: 1; }
    .ehtv-controls     { grid-column: 1; grid-row: 2; }
    .ehtv-playlist     { grid-column: 2; grid-row: 1 / 3; max-height: none; overflow-y: auto; }
}

/* Empty */
.ehtv-empty {
    padding: 3rem 1rem; text-align: center;
    color: rgba(255,255,255,.3); font-family: Nunito, sans-serif;
}
</style>

<!-- API do YouTube -->
<script src="https://www.youtube.com/iframe_api"></script>

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
        <video id="ehtv-video-el" playsinline preload="metadata"></video>
        <div id="ehtv-yt-container" style="position:absolute;inset:0;display:none">
            <div id="ehtv-yt-player"></div>
        </div>
        <div class="ehtv-overlay" id="ehtv-overlay">
            <div class="ehtv-overlay-title" id="ehtv-overlay-title">Pronto para reproduzir</div>
            <div class="ehtv-overlay-sub"   id="ehtv-overlay-sub"></div>
        </div>
        <div id="ehtv-splash">
            <div id="ehtv-splash-icon">📺</div>
            <div id="ehtv-splash-text">TV Escolar · Inclusão Midiática</div>
            <button id="ehtv-play-first">▶ Iniciar reprodução</button>
        </div>
    </div>

    <!-- Controles -->
    <div class="ehtv-controls">
        <button class="ehtv-ctrl" id="ehtv-btn-prev" title="Anterior">⏮</button>
        <button class="ehtv-ctrl ehtv-ctrl-play" id="ehtv-btn-play" title="Play/Pause">▶</button>
        <button class="ehtv-ctrl" id="ehtv-btn-next" title="Próximo">⏭</button>
        <button class="ehtv-ctrl" id="ehtv-btn-fs" title="Tela cheia">⛶</button>
        <label class="ehtv-autoplay-toggle" title="Autoplay">
            <div class="ehtv-toggle-dot on" id="ehtv-autoplay-dot"></div>
            Autoplay
        </label>
    </div>

    <!-- Playlist -->
    <div class="ehtv-playlist">
        <div class="ehtv-playlist-label">📺 Playlist — <?= count($playlist) ?> vídeo<?= count($playlist)!==1?'s':'' ?></div>
        <?php foreach ($playlist as $i => $item) :
            $thumb = ehtv_thumb($item);
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
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</div><!-- .ehtv-layout -->

<?php endif; ?>

</div><!-- .ehtv-wrap -->

<script>
var EHTV_ITEMS   = <?= wp_json_encode($items_js) ?>;
var EHTV_SITE    = <?= wp_json_encode(home_url()) ?>;
var ytPlayer     = null;
var ytReady      = false;
var ytPendingIdx = -1;

// ── YouTube IFrame API ready ──────────────────────────────────
function onYouTubeIframeAPIReady() {
    ytReady = true;
    ytPlayer = new YT.Player('ehtv-yt-player', {
        width: '100%', height: '100%',
        playerVars: {
            autoplay: 0, controls: 1, rel: 0,
            modestbranding: 1, playsinline: 1,
        },
        events: {
            onStateChange: function(e) {
                if (e.data === YT.PlayerState.ENDED)  ehtv.onEnded();
                if (e.data === YT.PlayerState.PLAYING) ehtv.onPlay();
                if (e.data === YT.PlayerState.PAUSED)  ehtv.onPause();
            }
        }
    });
    if (ytPendingIdx >= 0) { ehtv.play(ytPendingIdx); ytPendingIdx = -1; }
}

// ── Player principal ──────────────────────────────────────────
var ehtv = {
    current:  -1,
    autoplay: true,
    started:  false,

    init: function() {
        var vid = document.getElementById('ehtv-video-el');
        vid.addEventListener('ended',  function() { ehtv.onEnded(); });
        vid.addEventListener('play',   function() { ehtv.onPlay(); });
        vid.addEventListener('pause',  function() { ehtv.onPause(); });
        vid.addEventListener('error',  function() { setTimeout(function(){ ehtv.next(); }, 2000); });

        // Botões
        document.getElementById('ehtv-btn-play').addEventListener('click', function() { ehtv.togglePlay(); });
        document.getElementById('ehtv-btn-prev').addEventListener('click', function() { ehtv.prev(); });
        document.getElementById('ehtv-btn-next').addEventListener('click', function() { ehtv.next(); });
        document.getElementById('ehtv-btn-fs').addEventListener('click',   function() { ehtv.fullscreen(); });
        document.getElementById('ehtv-play-first')?.addEventListener('click', function() {
            ehtv.started = true;
            document.getElementById('ehtv-splash').style.display = 'none';
            ehtv.play(0);
        });

        // Toggle autoplay
        document.getElementById('ehtv-autoplay-dot').parentElement.addEventListener('click', function() {
            ehtv.autoplay = !ehtv.autoplay;
            document.getElementById('ehtv-autoplay-dot').classList.toggle('on', ehtv.autoplay);
        });

        // Teclas
        document.addEventListener('keydown', function(e) {
            if (e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA') return;
            if (e.key===' '||e.key==='k')  { e.preventDefault(); ehtv.togglePlay(); }
            if (e.key==='ArrowRight'||e.key==='n') ehtv.next();
            if (e.key==='ArrowLeft'||e.key==='p')  ehtv.prev();
            if (e.key==='f') ehtv.fullscreen();
        });
    },

    play: function(idx) {
        if (!EHTV_ITEMS.length) return;
        idx = ((idx % EHTV_ITEMS.length) + EHTV_ITEMS.length) % EHTV_ITEMS.length;
        var item = EHTV_ITEMS[idx];
        this.current = idx;

        // Ocultar splash
        var splash = document.getElementById('ehtv-splash');
        if (splash) splash.style.display = 'none';
        this.started = true;

        // Atualizar overlay
        document.getElementById('ehtv-overlay-title').textContent = item.title;
        document.getElementById('ehtv-overlay-sub').textContent   = item.description ? item.description.substring(0,80) : '';

        // Highlight na playlist
        document.querySelectorAll('.ehtv-item').forEach(function(el) { el.classList.remove('active'); });
        var activeEl = document.querySelector('#ehtv-item-' + item.id);
        if (activeEl) {
            activeEl.classList.add('active');
            activeEl.scrollIntoView({behavior:'smooth', block:'nearest'});
        }

        var vid    = document.getElementById('ehtv-video-el');
        var ytWrap = document.getElementById('ehtv-yt-container');

        if (item.type === 'youtube') {
            // YouTube
            vid.pause(); vid.src = ''; vid.style.display = 'none';
            ytWrap.style.display = 'block';
            ytWrap.style.position = 'absolute'; ytWrap.style.inset = '0';

            if (!ytReady || !ytPlayer || !ytPlayer.loadVideoById) {
                ytPendingIdx = idx;
                return;
            }
            ytPlayer.loadVideoById({ videoId: item.youtube_id, suggestedQuality: 'hd720' });
            ytPlayer.playVideo();
        } else {
            // Vídeo nativo
            ytWrap.style.display = 'none';
            if (ytPlayer && ytPlayer.pauseVideo) ytPlayer.pauseVideo();
            vid.style.display = 'block';
            var url = item.file_url || item.ext_url;
            vid.src = url;
            vid.load();
            vid.play().catch(function() {});
        }

        document.getElementById('ehtv-btn-play').textContent = '⏸';
    },

    next: function() {
        if (!EHTV_ITEMS.length) return;
        var next = (this.current + 1) % EHTV_ITEMS.length;
        this.play(next);
    },

    prev: function() {
        if (!EHTV_ITEMS.length) return;
        var prev = ((this.current - 1) + EHTV_ITEMS.length) % EHTV_ITEMS.length;
        this.play(prev);
    },

    onEnded: function() {
        if (this.autoplay) {
            setTimeout(function() { ehtv.next(); }, 1000);
        }
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

        if (item && item.type === 'youtube' && ytPlayer && ytPlayer.getPlayerState) {
            var st = ytPlayer.getPlayerState();
            if (st === 1) ytPlayer.pauseVideo(); else ytPlayer.playVideo();
        } else {
            var vid = document.getElementById('ehtv-video-el');
            if (vid.paused) vid.play().catch(function(){}); else vid.pause();
        }
    },

    fullscreen: function() {
        var stage = document.getElementById('ehtv-stage');
        if (document.fullscreenElement) { document.exitFullscreen(); }
        else { stage.requestFullscreen?.() || stage.webkitRequestFullscreen?.(); }
    }
};

document.addEventListener('DOMContentLoaded', function() { ehtv.init(); });
</script>
