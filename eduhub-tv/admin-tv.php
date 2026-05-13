<?php
/* admin-tv.php — Painel de gestão da TV Escolar */
if ( ! defined('ABSPATH') ) exit;

add_action('admin_menu', function() {
    add_menu_page(
        'TV Escolar', 'TV Escolar',
        'edit_posts', 'ehtv',
        'ehtv_admin_page',
        'dashicons-video-alt3', 27
    );
});

function ehtv_admin_page(): void {
    global $wpdb;

    $nonce    = wp_create_nonce('ehtv_admin');
    $playlist = $wpdb->get_results(
        "SELECT p.*, u.display_name AS added_by_name
         FROM ehtm_tv_playlist p
         LEFT JOIN {$wpdb->users} u ON u.ID = p.added_by
         ORDER BY p.sort_order ASC"
    ) ?: [];

    // Materiais aprovados do EduHub (só vídeos)
    $available_materials = [];
    if ( function_exists('EduHubTurmas') ) {
        $all = EduHubTurmas()->db->get_materials(['status'=>'approved','type'=>'video','limit'=>100]);
        $in_playlist_ids = array_filter(array_column($playlist, 'material_id'));
        foreach ($all as $m) {
            if (!in_array($m->id, $in_playlist_ids)) {
                $available_materials[] = $m;
            }
        }
    }

    // Mensagens
    $msgs = [
        'added'    => ['✅ Item adicionado à playlist!', 'success'],
        'removed'  => ['🗑 Item removido.', ''],
        'reordered'=> ['✅ Ordem salva!', 'success'],
        'invalid_yt'=> ['❌ URL do YouTube inválida.', 'error'],
        'invalid_material' => ['❌ Material não encontrado ou não aprovado.', 'error'],
        'missing_fields'   => ['❌ Preencha todos os campos obrigatórios.', 'error'],
    ];
    $msg_key = sanitize_key($_GET['msg'] ?? $_GET['err'] ?? '');
    ?>

<div class="wrap">
<style>
.ehtv-admin { font-family: -apple-system, sans-serif; }
.ehtv-card  { background:#fff; border:1px solid #ddd; border-radius:10px; padding:1.25rem; margin-bottom:1.25rem; }
.ehtv-card h2 { margin:0 0 1rem; font-size:1rem; color:#1A1A2E; display:flex; align-items:center; gap:.5rem; }
.ehtv-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; }
.ehtv-field label { display:block; font-weight:600; font-size:.82rem; color:#555; margin-bottom:4px; }
.ehtv-field input, .ehtv-field select, .ehtv-field textarea {
    width:100%; padding:7px 10px; border:1px solid #ccc; border-radius:6px;
    font-size:.88rem; box-sizing:border-box;
}
.ehtv-notice { padding:10px 14px; border-radius:6px; margin-bottom:1rem; font-size:.88rem; }
.ehtv-notice.success { background:#D1FAE5; color:#065F46; }
.ehtv-notice.error   { background:#FEE2E2; color:#991B1B; }
.ehtv-notice.default { background:#F7F2EB; color:#7A5C44; }

/* Playlist table */
.ehtv-playlist-table { width:100%; border-collapse:collapse; }
.ehtv-playlist-table th { background:#f9f9f9; padding:8px 10px; text-align:left; font-size:.8rem; border-bottom:2px solid #eee; }
.ehtv-playlist-table td { padding:8px 10px; border-bottom:1px solid #f0f0f0; vertical-align:middle; font-size:.85rem; }
.ehtv-playlist-table tr:hover td { background:#fafafa; }
.ehtv-playlist-table tr.inactive td { opacity:.5; }

.ehtv-drag-handle { cursor:grab; color:#ccc; font-size:1.1rem; padding:0 6px; }
.ehtv-drag-handle:active { cursor:grabbing; }
.ehtv-thumb-sm {
    width:64px; height:40px; object-fit:cover;
    border-radius:4px; background:#f0f0f0;
    display:flex; align-items:center; justify-content:center;
    font-size:1.2rem; flex-shrink:0; overflow:hidden;
}
.ehtv-thumb-sm img { width:100%; height:100%; object-fit:cover; }
.ehtv-type-pill {
    display:inline-block; padding:2px 7px; border-radius:99px;
    font-size:.68rem; font-weight:700;
}
.ehtv-type-pill.youtube  { background:#FFE0E0; color:#CC0000; }
.ehtv-type-pill.material { background:#DBEAFE; color:#1E40AF; }
.ehtv-type-pill.url      { background:#F3E8FF; color:#6B21A8; }

.ehtv-inline-form input { border:1px solid #ddd; border-radius:4px; padding:4px 8px; font-size:.82rem; }
.ehtv-inline-form button { padding:4px 10px; font-size:.78rem; }

/* Preview da TV */
.ehtv-preview-link {
    display:inline-flex; align-items:center; gap:.5rem;
    background:#E94560; color:#fff; padding:8px 16px;
    border-radius:99px; text-decoration:none; font-weight:700;
    font-size:.88rem; transition:opacity .15s;
}
.ehtv-preview-link:hover { opacity:.85; color:#fff; text-decoration:none; }

.ehtv-stat { text-align:center; }
.ehtv-stat-num { font-size:2rem; font-weight:800; color:#1A1A2E; line-height:1; }
.ehtv-stat-lbl { font-size:.72rem; color:#777; margin-top:3px; }
</style>

<div class="ehtv-admin">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <h1 style="margin:0">📺 TV Escolar — Gerenciar Playlist</h1>
    <div style="display:flex;gap:.75rem;align-items:center">
        <?php
        $tv_page = get_page_by_path('tv');
        $tv_url  = $tv_page ? get_permalink($tv_page) : home_url('/tv/');
        ?>
        <a href="<?= esc_url($tv_url) ?>" target="_blank" class="ehtv-preview-link">
            📺 Ver TV ao vivo
        </a>
    </div>
</div>

<?php if ($msg_key && isset($msgs[$msg_key])) :
    [$txt, $type] = $msgs[$msg_key];
?>
<div class="ehtv-notice <?= $type ?: 'default' ?>"><?= esc_html($txt) ?></div>
<?php endif; ?>

<!-- Estatísticas rápidas -->
<div class="ehtv-card">
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem">
        <div class="ehtv-stat">
            <div class="ehtv-stat-num"><?= count($playlist) ?></div>
            <div class="ehtv-stat-lbl">Total na playlist</div>
        </div>
        <div class="ehtv-stat">
            <div class="ehtv-stat-num"><?= count(array_filter($playlist, fn($p)=>$p->active)) ?></div>
            <div class="ehtv-stat-lbl">Ativos</div>
        </div>
        <div class="ehtv-stat">
            <div class="ehtv-stat-num"><?= count(array_filter($playlist, fn($p)=>$p->type==='youtube')) ?></div>
            <div class="ehtv-stat-lbl">YouTube</div>
        </div>
        <div class="ehtv-stat">
            <div class="ehtv-stat-num"><?= count(array_filter($playlist, fn($p)=>$p->type==='material')) ?></div>
            <div class="ehtv-stat-lbl">Do banco EduHub</div>
        </div>
    </div>
</div>

<!-- Grid de 3 formas de adicionar -->
<div class="ehtv-grid-3" style="margin-bottom:1.25rem">

    <!-- 1. YouTube -->
    <div class="ehtv-card">
        <h2>▶️ Adicionar YouTube</h2>
        <form method="POST">
            <input type="hidden" name="ehtv_action" value="add_youtube">
            <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
            <div class="ehtv-field" style="margin-bottom:.6rem">
                <label>URL ou ID do vídeo *</label>
                <input type="text" name="youtube_url" placeholder="https://youtube.com/watch?v=..." required>
            </div>
            <div class="ehtv-field" style="margin-bottom:.75rem">
                <label>Título (opcional)</label>
                <input type="text" name="title" placeholder="Título do vídeo">
            </div>
            <button type="submit" class="button button-primary">+ Adicionar</button>
        </form>
    </div>

    <!-- 2. Material do banco -->
    <div class="ehtv-card">
        <h2>📁 Adicionar do Repositório</h2>
        <?php if (empty($available_materials)) : ?>
        <p style="color:#999;font-size:.85rem">
            <?= function_exists('EduHubTurmas') ? 'Nenhum vídeo aprovado disponível (ou todos já estão na playlist).' : 'Plugin EduHub Turmas não está ativo.' ?>
        </p>
        <?php else : ?>
        <form method="POST">
            <input type="hidden" name="ehtv_action" value="add_material">
            <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
            <div class="ehtv-field" style="margin-bottom:.75rem">
                <label>Selecione o vídeo aprovado *</label>
                <select name="material_id" required style="width:100%">
                    <option value="">Escolha um material...</option>
                    <?php foreach ($available_materials as $m) : ?>
                    <option value="<?= $m->id ?>"><?= esc_html($m->title) ?> — <?= esc_html($m->class_name??'') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="button button-primary">+ Adicionar</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- 3. URL externa -->
    <div class="ehtv-card">
        <h2>🔗 Adicionar URL externa</h2>
        <form method="POST">
            <input type="hidden" name="ehtv_action" value="add_url">
            <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
            <div class="ehtv-field" style="margin-bottom:.5rem">
                <label>URL do vídeo MP4 *</label>
                <input type="url" name="video_url" placeholder="https://..." required>
            </div>
            <div class="ehtv-field" style="margin-bottom:.5rem">
                <label>Título *</label>
                <input type="text" name="title" placeholder="Nome do vídeo" required>
            </div>
            <div class="ehtv-field" style="margin-bottom:.75rem">
                <label>URL da thumbnail (opcional)</label>
                <input type="url" name="thumbnail" placeholder="https://...">
            </div>
            <button type="submit" class="button button-primary">+ Adicionar</button>
        </form>
    </div>

</div>

<!-- Playlist atual -->
<div class="ehtv-card">
    <h2>📋 Playlist atual
        <span style="font-size:.75rem;font-weight:400;color:#777;margin-left:.5rem">
            Arraste as linhas para reordenar, depois clique em "Salvar ordem"
        </span>
    </h2>

    <?php if (empty($playlist)) : ?>
    <p style="color:#999;text-align:center;padding:2rem">Nenhum item na playlist ainda. Adicione vídeos acima.</p>
    <?php else : ?>

    <form method="POST" id="ehtv-reorder-form">
        <input type="hidden" name="ehtv_action" value="reorder">
        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">

        <table class="ehtv-playlist-table">
            <thead>
                <tr>
                    <th style="width:30px"></th>
                    <th style="width:40px">#</th>
                    <th style="width:72px"></th>
                    <th>Título</th>
                    <th style="width:80px">Tipo</th>
                    <th style="width:90px">Adicionado por</th>
                    <th style="width:60px">Ativo</th>
                    <th style="width:80px">Ações</th>
                </tr>
            </thead>
            <tbody id="ehtv-sortable">
            <?php foreach ($playlist as $i => $item) :
                $thumb = ehtv_thumb($item);
            ?>
            <tr data-id="<?= $item->id ?>" class="<?= !$item->active ? 'inactive' : '' ?>">
                <td>
                    <span class="ehtv-drag-handle" title="Arrastar para reordenar">⠿</span>
                    <input type="hidden" name="sort_order[<?= $item->id ?>]" value="<?= $i ?>" class="sort-input">
                </td>
                <td style="font-weight:700;color:#999;font-size:.8rem"><?= $i + 1 ?></td>
                <td>
                    <div class="ehtv-thumb-sm">
                        <?php if ($thumb) : ?><img src="<?= esc_url($thumb) ?>" alt=""><?php else : ?>🎬<?php endif; ?>
                    </div>
                </td>
                <td>
                    <strong><?= esc_html($item->title) ?></strong>
                    <?php if ($item->type === 'youtube' && $item->youtube_id) : ?>
                    <br><a href="https://youtube.com/watch?v=<?= esc_attr($item->youtube_id) ?>" target="_blank" style="font-size:.72rem;color:#999">youtube.com/watch?v=<?= esc_html($item->youtube_id) ?></a>
                    <?php elseif ($item->type === 'material') : ?>
                    <br><span style="font-size:.72rem;color:#999">Material #<?= $item->material_id ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="ehtv-type-pill <?= esc_attr($item->type) ?>">
                        <?= $item->type === 'youtube' ? '▶ YouTube' : ($item->type === 'material' ? '📁 Banco' : '🔗 URL') ?>
                    </span>
                </td>
                <td style="font-size:.78rem;color:#777"><?= esc_html($item->added_by_name ?? '—') ?></td>
                <td>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="ehtv_action" value="toggle">
                        <input type="hidden" name="item_id" value="<?= $item->id ?>">
                        <input type="hidden" name="active" value="<?= $item->active ?>">
                        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
                        <button type="submit" class="button button-small" style="<?= $item->active ? 'color:green' : 'color:#999' ?>">
                            <?= $item->active ? '✅ Sim' : '⏸ Não' ?>
                        </button>
                    </form>
                </td>
                <td>
                    <?php
                    $preview = $item->type === 'youtube'
                        ? 'https://youtube.com/watch?v=' . $item->youtube_id
                        : ehtv_video_url($item);
                    ?>
                    <a href="<?= esc_url($preview) ?>" target="_blank" class="button button-small" title="Ver">▶</a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Remover da playlist?')">
                        <input type="hidden" name="ehtv_action" value="remove">
                        <input type="hidden" name="item_id" value="<?= $item->id ?>">
                        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
                        <button type="submit" class="button button-small" style="color:red" title="Remover">✕</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:1rem;display:flex;gap:.5rem;align-items:center">
            <button type="submit" class="button button-primary" id="ehtv-save-order">💾 Salvar ordem</button>
            <span id="ehtv-order-status" style="font-size:.82rem;color:#999"></span>
        </div>
    </form>

    <?php endif; ?>
</div>

<!-- Dica de uso -->
<div class="ehtv-card" style="background:#F7F2EB;border-color:#E0D5C5">
    <h2 style="color:#7A5C44">💡 Como usar</h2>
    <ul style="margin:0;padding-left:1.25rem;color:#7A5C44;font-size:.88rem;line-height:1.8">
        <li>Crie uma página no WordPress e adicione o shortcode <code>[ehtv_player]</code></li>
        <li>A TV roda em loop automático — ao terminar o último vídeo, volta ao primeiro</li>
        <li>Vídeos YouTube são incorporados via iframe (requerem conexão com a internet)</li>
        <li>Vídeos do banco EduHub são servidos via proxy seguro</li>
        <li>Itens desativados (⏸ Não) não aparecem no player público</li>
        <li>A ordem da playlist pode ser alterada arrastando as linhas da tabela</li>
    </ul>
</div>

</div><!-- .ehtv-admin -->
</div><!-- .wrap -->

<!-- Drag & Drop para reordenar -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var tbody  = document.getElementById('ehtv-sortable');
    if (!tbody) return;

    var dragging = null;

    tbody.querySelectorAll('tr').forEach(function(row) {
        row.setAttribute('draggable', true);

        row.addEventListener('dragstart', function() {
            dragging = row;
            setTimeout(function() { row.style.opacity = '.4'; }, 0);
        });
        row.addEventListener('dragend', function() {
            row.style.opacity = '1';
            dragging = null;
            updateNumbers();
            document.getElementById('ehtv-order-status').textContent = '⚠️ Ordem alterada — clique em Salvar';
        });
        row.addEventListener('dragover', function(e) {
            e.preventDefault();
            if (!dragging || dragging === row) return;
            var rect = row.getBoundingClientRect();
            if (e.clientY < rect.top + rect.height / 2) tbody.insertBefore(dragging, row);
            else tbody.insertBefore(dragging, row.nextSibling);
        });
    });

    function updateNumbers() {
        var rows = tbody.querySelectorAll('tr');
        rows.forEach(function(row, i) {
            var cells = row.querySelectorAll('td');
            if (cells[1]) cells[1].textContent = i + 1;
            var inp = row.querySelector('.sort-input');
            if (inp) inp.value = i;
        });
    }

    // Limpar status ao salvar
    document.getElementById('ehtv-reorder-form')?.addEventListener('submit', function() {
        document.getElementById('ehtv-order-status').textContent = '⏳ Salvando...';
    });
});
</script>
<?php
}
