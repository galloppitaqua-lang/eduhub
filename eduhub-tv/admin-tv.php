<?php
/* admin-tv.php — Painel de gestão da TV Escolar v2 */
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
    $tab      = sanitize_key($_GET['tab'] ?? 'playlist');
    $playlist = $wpdb->get_results(
        "SELECT p.*, u.display_name AS added_by_name
         FROM ehtm_tv_playlist p
         LEFT JOIN {$wpdb->users} u ON u.ID = p.added_by
         ORDER BY p.sort_order ASC"
    ) ?: [];

    $available_materials = [];
    if ( function_exists('EduHubTurmas') ) {
        $all = EduHubTurmas()->db->get_materials(['status'=>'approved','type'=>'video','limit'=>100]);
        $in_playlist_ids = array_filter(array_column($playlist, 'material_id'));
        foreach ($all as $m) {
            if ( ! in_array($m->id, $in_playlist_ids) ) $available_materials[] = $m;
        }
    }

    $msgs = [
        'added'            => ['Item adicionado à playlist!', 'success'],
        'removed'          => ['Item removido.', ''],
        'reordered'        => ['Ordem salva!', 'success'],
        'saved'            => ['Alterações salvas!', 'success'],
        'invalid_yt'       => ['URL do YouTube inválida.', 'error'],
        'invalid_material' => ['Material não encontrado ou não aprovado.', 'error'],
        'missing_fields'   => ['Preencha todos os campos obrigatórios.', 'error'],
    ];
    $msg_key = sanitize_key($_GET['msg'] ?? $_GET['err'] ?? '');

    $classifications = [
        'conteudo'                 => 'Conteúdo',
        'propaganda_externa'       => 'Propaganda Externa',
        'propaganda_institucional' => 'Propaganda Institucional',
    ];

    $base_url = admin_url('admin.php?page=ehtv');
    ?>
<div class="wrap">
<style>
.ehtv-admin { font-family: -apple-system, sans-serif; }
.ehtv-card  { background:#fff; border:1px solid #ddd; border-radius:10px; padding:1.25rem; margin-bottom:1.25rem; }
.ehtv-card h2 { margin:0 0 1rem; font-size:1rem; color:#1A1A2E; display:flex; align-items:center; gap:.5rem; }
.ehtv-field { margin-bottom:.65rem; }
.ehtv-field label { display:block; font-weight:600; font-size:.82rem; color:#555; margin-bottom:4px; }
.ehtv-field input,.ehtv-field select,.ehtv-field textarea {
    width:100%; padding:7px 10px; border:1px solid #ccc; border-radius:6px;
    font-size:.88rem; box-sizing:border-box;
}
.ehtv-field textarea { resize:vertical; min-height:60px; }
.ehtv-notice { padding:10px 14px; border-radius:6px; margin-bottom:1rem; font-size:.88rem; }
.ehtv-notice.success { background:#D1FAE5; color:#065F46; }
.ehtv-notice.error   { background:#FEE2E2; color:#991B1B; }
.ehtv-notice.default { background:#F7F2EB; color:#7A5C44; }

/* Tabs */
.ehtv-tabs { display:flex; gap:4px; margin-bottom:1.25rem; border-bottom:2px solid #eee; }
.ehtv-tab  { padding:8px 18px; border-radius:6px 6px 0 0; font-size:.88rem; font-weight:700;
             text-decoration:none; color:#555; background:#f5f5f5; border:1px solid #ddd;
             border-bottom:none; position:relative; bottom:-2px; }
.ehtv-tab:hover { background:#e8e8e8; color:#333; text-decoration:none; }
.ehtv-tab.active { background:#fff; color:#1A1A2E; border-color:#ddd #ddd #fff; }

/* Playlist table */
.ehtv-playlist-table { width:100%; border-collapse:collapse; }
.ehtv-playlist-table th { background:#f9f9f9; padding:8px 10px; text-align:left; font-size:.78rem; border-bottom:2px solid #eee; }
.ehtv-playlist-table td { padding:8px 10px; border-bottom:1px solid #f0f0f0; vertical-align:middle; font-size:.83rem; }
.ehtv-playlist-table tr:hover td { background:#fafafa; }
.ehtv-playlist-table tr.inactive td { opacity:.5; }

.ehtv-drag-handle { cursor:grab; color:#ccc; font-size:1.1rem; padding:0 6px; }
.ehtv-drag-handle:active { cursor:grabbing; }
.ehtv-thumb-sm { width:64px; height:40px; object-fit:cover; border-radius:4px; background:#f0f0f0;
    display:flex; align-items:center; justify-content:center; font-size:1.2rem;
    flex-shrink:0; overflow:hidden; }
.ehtv-thumb-sm img { width:100%; height:100%; object-fit:cover; }

.ehtv-pill { display:inline-block; padding:2px 7px; border-radius:99px; font-size:.68rem; font-weight:700; }
.ehtv-pill.youtube  { background:#FFE0E0; color:#CC0000; }
.ehtv-pill.material { background:#DBEAFE; color:#1E40AF; }
.ehtv-pill.url      { background:#F3E8FF; color:#6B21A8; }
.ehtv-pill.conteudo                 { background:#D1FAE5; color:#065F46; }
.ehtv-pill.propaganda_externa       { background:#FEF3C7; color:#92400E; }
.ehtv-pill.propaganda_institucional { background:#DBEAFE; color:#1E40AF; }

.ehtv-stat { text-align:center; }
.ehtv-stat-num { font-size:2rem; font-weight:800; color:#1A1A2E; line-height:1; }
.ehtv-stat-lbl { font-size:.72rem; color:#777; margin-top:3px; }

.ehtv-preview-link {
    display:inline-flex; align-items:center; gap:.5rem;
    background:#E94560; color:#fff; padding:8px 16px;
    border-radius:99px; text-decoration:none; font-weight:700;
    font-size:.88rem; transition:opacity .15s;
}
.ehtv-preview-link:hover { opacity:.85; color:#fff; text-decoration:none; }

/* Edit row */
.ehtv-edit-row td { background:#F0F7FF !important; padding:.75rem 1rem !important; }
.ehtv-edit-row input,.ehtv-edit-row select,.ehtv-edit-row textarea {
    width:100%; padding:5px 8px; border:1px solid #b0c4de; border-radius:5px;
    font-size:.82rem; box-sizing:border-box; margin-bottom:.35rem;
}
.ehtv-edit-grid { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:.5rem; }

/* Grade do dia */
.ehtv-schedule-row { display:flex; align-items:stretch; gap:.5rem; margin-bottom:4px; }
.ehtv-sched-time { font-family:monospace; font-size:.78rem; color:#555; width:55px; flex-shrink:0;
    display:flex; align-items:center; }
.ehtv-sched-bar  { flex:1; border-radius:6px; padding:6px 10px; font-size:.8rem; line-height:1.3; }
.ehtv-sched-bar.fixed    { background:#DBEAFE; border-left:4px solid #3B82F6; }
.ehtv-sched-bar.free     { background:#F0FDF4; border-left:4px solid #22C55E; }
.ehtv-sched-bar.gap      { background:#FEF2F2; border-left:4px solid #EF4444; opacity:.8; font-style:italic; }
.ehtv-sched-loop { font-size:.68rem; background:#F3E8FF; color:#7C3AED;
    padding:1px 5px; border-radius:3px; margin-left:.4rem; }

/* Analytics */
.ehtv-analytics-table { width:100%; border-collapse:collapse; }
.ehtv-analytics-table th { background:#f9f9f9; padding:8px 12px; text-align:left; font-size:.78rem; border-bottom:2px solid #eee; }
.ehtv-analytics-table td { padding:8px 12px; border-bottom:1px solid #f0f0f0; font-size:.83rem; }
.ehtv-bar-wrap { background:#eee; border-radius:4px; height:8px; min-width:60px; }
.ehtv-bar-fill { height:8px; border-radius:4px; background:#E94560; }
</style>

<div class="ehtv-admin">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.75rem">
    <h1 style="margin:0">📺 TV Escolar</h1>
    <?php
    $tv_page = get_page_by_path('tv');
    $tv_url  = $tv_page ? get_permalink($tv_page) : home_url('/tv/');
    ?>
    <a href="<?= esc_url($tv_url) ?>" target="_blank" class="ehtv-preview-link">📺 Ver TV ao vivo</a>
</div>

<!-- Tabs -->
<div class="ehtv-tabs">
    <a href="<?= esc_url($base_url . '&tab=playlist') ?>" class="ehtv-tab <?= $tab==='playlist'?'active':'' ?>">📋 Playlist</a>
    <a href="<?= esc_url($base_url . '&tab=grade') ?>"    class="ehtv-tab <?= $tab==='grade'   ?'active':'' ?>">📅 Grade do Dia</a>
    <a href="<?= esc_url($base_url . '&tab=analytics') ?>" class="ehtv-tab <?= $tab==='analytics'?'active':'' ?>">📊 Análises</a>
</div>

<?php if ($msg_key && isset($msgs[$msg_key])) :
    [$txt, $type] = $msgs[$msg_key];
?>
<div class="ehtv-notice <?= $type ?: 'default' ?>"><?= esc_html($txt) ?></div>
<?php endif; ?>

<?php // ═══════════════════════════════════════════════════════
      // TAB: PLAYLIST
      // ═══════════════════════════════════════════════════════
if ( $tab === 'playlist' ) : ?>

<!-- Estatísticas rápidas -->
<div class="ehtv-card">
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1rem">
        <div class="ehtv-stat">
            <div class="ehtv-stat-num"><?= count($playlist) ?></div>
            <div class="ehtv-stat-lbl">Total</div>
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
            <div class="ehtv-stat-num"><?= count(array_filter($playlist, fn($p)=>!empty($p->scheduled_time))) ?></div>
            <div class="ehtv-stat-lbl">Horário fixo</div>
        </div>
        <div class="ehtv-stat">
            <?php
            $total_dur = array_sum(array_map(fn($p) => (int)($p->duration ?? 0), array_filter($playlist, fn($p)=>$p->active)));
            $h = intdiv($total_dur, 3600); $min = intdiv($total_dur % 3600, 60);
            ?>
            <div class="ehtv-stat-num"><?= $h ?>h<?= $min ?>m</div>
            <div class="ehtv-stat-lbl">Duração total</div>
        </div>
    </div>
</div>

<!-- Formulários de adição -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.25rem">

    <!-- YouTube -->
    <div class="ehtv-card">
        <h2>▶️ Adicionar YouTube</h2>
        <form method="POST">
            <input type="hidden" name="ehtv_action" value="add_youtube">
            <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
            <div class="ehtv-field">
                <label>URL ou ID do vídeo *</label>
                <input type="text" name="youtube_url" placeholder="https://youtube.com/watch?v=..." required>
            </div>
            <div class="ehtv-field">
                <label>Título *</label>
                <input type="text" name="title" placeholder="Nome do programa" required>
            </div>
            <div class="ehtv-field">
                <label>Tema</label>
                <input type="text" name="tema" placeholder="Ex: Matemática, Notícias...">
            </div>
            <div class="ehtv-field">
                <label>Classificação</label>
                <select name="classification">
                    <?php foreach ($classifications as $v => $l) : ?>
                    <option value="<?= $v ?>"><?= esc_html($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ehtv-field">
                <label>Mini descrição</label>
                <textarea name="description" placeholder="Breve descrição do conteúdo..."></textarea>
            </div>
            <div class="ehtv-field">
                <label>Horário fixo (opcional)</label>
                <input type="time" name="scheduled_time">
            </div>
            <button type="submit" class="button button-primary">+ Adicionar</button>
        </form>
    </div>

    <!-- Material do banco -->
    <div class="ehtv-card">
        <h2>📁 Adicionar do Repositório</h2>
        <?php if (empty($available_materials)) : ?>
        <p style="color:#999;font-size:.85rem">
            <?= function_exists('EduHubTurmas')
                ? 'Nenhum vídeo aprovado disponível (ou todos já estão na playlist).'
                : 'Plugin EduHub Turmas não está ativo.' ?>
        </p>
        <?php else : ?>
        <form method="POST">
            <input type="hidden" name="ehtv_action" value="add_material">
            <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
            <div class="ehtv-field">
                <label>Selecione o vídeo *</label>
                <select name="material_id" required>
                    <option value="">Escolha um material...</option>
                    <?php foreach ($available_materials as $m) : ?>
                    <option value="<?= $m->id ?>"><?= esc_html($m->title) ?> — <?= esc_html($m->class_name??'') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ehtv-field">
                <label>Título personalizado (opcional)</label>
                <input type="text" name="title" placeholder="Deixe vazio para usar o título original">
            </div>
            <div class="ehtv-field">
                <label>Tema</label>
                <input type="text" name="tema" placeholder="Ex: Ciências, Histórico...">
            </div>
            <div class="ehtv-field">
                <label>Classificação</label>
                <select name="classification">
                    <?php foreach ($classifications as $v => $l) : ?>
                    <option value="<?= $v ?>"><?= esc_html($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ehtv-field">
                <label>Mini descrição (opcional)</label>
                <textarea name="description" placeholder="Deixe vazio para usar a descrição original"></textarea>
            </div>
            <div class="ehtv-field">
                <label>Horário fixo (opcional)</label>
                <input type="time" name="scheduled_time">
            </div>
            <button type="submit" class="button button-primary">+ Adicionar</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- URL externa -->
    <div class="ehtv-card">
        <h2>🔗 Adicionar URL externa</h2>
        <form method="POST">
            <input type="hidden" name="ehtv_action" value="add_url">
            <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
            <div class="ehtv-field">
                <label>URL do vídeo MP4 *</label>
                <input type="url" name="video_url" placeholder="https://..." required>
            </div>
            <div class="ehtv-field">
                <label>Título *</label>
                <input type="text" name="title" placeholder="Nome do programa" required>
            </div>
            <div class="ehtv-field">
                <label>Tema</label>
                <input type="text" name="tema" placeholder="Assunto principal">
            </div>
            <div class="ehtv-field">
                <label>Classificação</label>
                <select name="classification">
                    <?php foreach ($classifications as $v => $l) : ?>
                    <option value="<?= $v ?>"><?= esc_html($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ehtv-field">
                <label>Mini descrição</label>
                <textarea name="description" placeholder="Breve descrição do conteúdo..."></textarea>
            </div>
            <div class="ehtv-field">
                <label>URL da thumbnail (opcional)</label>
                <input type="url" name="thumbnail" placeholder="https://...">
            </div>
            <div class="ehtv-field">
                <label>Horário fixo (opcional)</label>
                <input type="time" name="scheduled_time">
            </div>
            <button type="submit" class="button button-primary">+ Adicionar</button>
        </form>
    </div>

</div>

<!-- Tabela da playlist -->
<div class="ehtv-card">
    <h2>📋 Playlist atual
        <span style="font-size:.75rem;font-weight:400;color:#777;margin-left:.5rem">
            Arraste para reordenar · Clique em ✏️ para editar
        </span>
    </h2>

    <?php if (empty($playlist)) : ?>
    <p style="color:#999;text-align:center;padding:2rem">Nenhum item na playlist ainda.</p>
    <?php else : ?>

    <form method="POST" id="ehtv-reorder-form">
        <input type="hidden" name="ehtv_action" value="reorder">
        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">

        <table class="ehtv-playlist-table">
            <thead>
                <tr>
                    <th style="width:30px"></th>
                    <th style="width:36px">#</th>
                    <th style="width:72px"></th>
                    <th>Título / Tema</th>
                    <th style="width:80px">Tipo</th>
                    <th style="width:140px">Classificação</th>
                    <th style="width:60px">Fixo</th>
                    <th style="width:55px">Ativo</th>
                    <th style="width:90px">Ações</th>
                </tr>
            </thead>
            <tbody id="ehtv-sortable">
            <?php foreach ($playlist as $i => $item) :
                $thumb = ehtv_thumb($item);
                $clf   = $item->classification ?? 'conteudo';
            ?>
            <tr data-id="<?= $item->id ?>" class="<?= !$item->active ? 'inactive' : '' ?>">
                <td>
                    <span class="ehtv-drag-handle" title="Arrastar">⠿</span>
                    <input type="hidden" name="sort_order[<?= $item->id ?>]" value="<?= $i ?>" class="sort-input">
                </td>
                <td style="font-weight:700;color:#999;font-size:.78rem"><?= $i + 1 ?></td>
                <td>
                    <div class="ehtv-thumb-sm">
                        <?php if ($thumb) : ?><img src="<?= esc_url($thumb) ?>" alt=""><?php else : ?>🎬<?php endif; ?>
                    </div>
                </td>
                <td>
                    <strong><?= esc_html($item->title) ?></strong>
                    <?php if ($item->tema) : ?>
                    <br><span style="font-size:.72rem;color:#777">📌 <?= esc_html($item->tema) ?></span>
                    <?php endif; ?>
                    <?php if ($item->description) : ?>
                    <br><span style="font-size:.7rem;color:#999" title="<?= esc_attr($item->description) ?>">
                        <?= esc_html(mb_strimwidth($item->description, 0, 60, '…')) ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="ehtv-pill <?= esc_attr($item->type) ?>">
                        <?= $item->type === 'youtube' ? '▶ YT' : ($item->type === 'material' ? '📁 Banco' : '🔗 URL') ?>
                    </span>
                </td>
                <td>
                    <span class="ehtv-pill <?= esc_attr($clf) ?>">
                        <?= esc_html(ehtv_classification_label($clf)) ?>
                    </span>
                </td>
                <td style="font-size:.78rem;color:#555">
                    <?= $item->scheduled_time ? '🕐 ' . esc_html(substr($item->scheduled_time, 0, 5)) : '—' ?>
                </td>
                <td>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="ehtv_action" value="toggle">
                        <input type="hidden" name="item_id" value="<?= $item->id ?>">
                        <input type="hidden" name="active" value="<?= $item->active ?>">
                        <input type="hidden" name="_tab" value="playlist">
                        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
                        <button type="submit" class="button button-small" style="<?= $item->active ? 'color:green' : 'color:#999' ?>">
                            <?= $item->active ? '✅' : '⏸' ?>
                        </button>
                    </form>
                </td>
                <td>
                    <button type="button" class="button button-small ehtv-edit-btn" data-id="<?= $item->id ?>"
                        data-title="<?= esc_attr($item->title) ?>"
                        data-tema="<?= esc_attr($item->tema ?? '') ?>"
                        data-clf="<?= esc_attr($clf) ?>"
                        data-desc="<?= esc_attr($item->description ?? '') ?>"
                        data-sched="<?= esc_attr($item->scheduled_time ? substr($item->scheduled_time,0,5) : '') ?>"
                        title="Editar">✏️</button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Remover da playlist?')">
                        <input type="hidden" name="ehtv_action" value="remove">
                        <input type="hidden" name="item_id" value="<?= $item->id ?>">
                        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
                        <button type="submit" class="button button-small" style="color:red" title="Remover">✕</button>
                    </form>
                </td>
            </tr>
            <!-- Edit row (hidden) -->
            <tr id="ehtv-edit-row-<?= $item->id ?>" class="ehtv-edit-row" style="display:none">
                <td colspan="9">
                    <form method="POST">
                        <input type="hidden" name="ehtv_action" value="edit">
                        <input type="hidden" name="item_id" value="<?= $item->id ?>">
                        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
                        <div class="ehtv-edit-grid">
                            <div>
                                <label style="font-size:.78rem;font-weight:700;color:#333">Título *</label>
                                <input type="text" name="title" value="<?= esc_attr($item->title) ?>" required>
                            </div>
                            <div>
                                <label style="font-size:.78rem;font-weight:700;color:#333">Tema</label>
                                <input type="text" name="tema" value="<?= esc_attr($item->tema ?? '') ?>">
                            </div>
                            <div>
                                <label style="font-size:.78rem;font-weight:700;color:#333">Classificação</label>
                                <select name="classification">
                                    <?php foreach ($classifications as $v => $l) : ?>
                                    <option value="<?= $v ?>" <?= ($clf===$v)?'selected':'' ?>><?= esc_html($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size:.78rem;font-weight:700;color:#333">Horário fixo</label>
                                <input type="time" name="scheduled_time" value="<?= esc_attr($item->scheduled_time ? substr($item->scheduled_time,0,5) : '') ?>">
                            </div>
                        </div>
                        <div style="margin-top:.4rem">
                            <label style="font-size:.78rem;font-weight:700;color:#333">Mini descrição</label>
                            <textarea name="description" rows="2"><?= esc_textarea($item->description ?? '') ?></textarea>
                        </div>
                        <div style="margin-top:.5rem;display:flex;gap:.5rem">
                            <button type="submit" class="button button-primary button-small">💾 Salvar</button>
                            <button type="button" class="button button-small ehtv-edit-cancel" data-id="<?= $item->id ?>">Cancelar</button>
                        </div>
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

<?php // ═══════════════════════════════════════════════════════
      // TAB: GRADE DO DIA
      // ═══════════════════════════════════════════════════════
elseif ( $tab === 'grade' ) :
    $sched_data = ehtv_build_day_schedule();
    $entries    = $sched_data['entries'];
    $gaps       = $sched_data['gaps'];
    $loops      = $sched_data['loops'];
    $now_sec    = (int)date('H') * 3600 + (int)date('i') * 60 + (int)date('s');
?>

<div class="ehtv-card">
    <h2>📅 Grade do Dia — <?= date('d/m/Y') ?>
        <span style="font-size:.75rem;font-weight:400;color:#777;margin-left:.5rem">
            Horário local do servidor · <?= count($entries) ?> blocos · <?= $loops ?> loop(s) completo(s)
        </span>
    </h2>

    <?php if ( ! empty($gaps) ) : ?>
    <div class="ehtv-notice error" style="margin-bottom:1rem">
        ⚠️ <?= count($gaps) ?> lacuna(s) detectada(s) na grade:
        <?php foreach ($gaps as $g) : ?>
        <strong><?= ehtv_seconds_to_hhmm($g['start']) ?>–<?= ehtv_seconds_to_hhmm($g['end']) ?></strong>
        (<?= gmdate('H\hi', $g['duration']) ?>)
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ( empty($entries) ) : ?>
    <p style="color:#999;text-align:center;padding:2rem">Nenhum conteúdo ativo na playlist. Adicione vídeos na aba Playlist.</p>
    <?php else : ?>

    <div id="ehtv-grade-list" style="max-height:600px;overflow-y:auto;padding-right:4px">
    <?php
    $current_entry_idx = -1;
    foreach ($entries as $idx => $e) {
        if ( $e['start'] <= $now_sec && $e['end'] > $now_sec ) { $current_entry_idx = $idx; break; }
    }
    foreach ($entries as $idx => $e) :
        $item    = $e['item'];
        $is_now  = ($idx === $current_entry_idx);
        $is_past = ($e['end'] <= $now_sec);
        $clf     = $item->classification ?? 'conteudo';
        $dur_min = round(($e['end'] - $e['start']) / 60);
    ?>
    <div class="ehtv-schedule-row" style="<?= $is_past ? 'opacity:.45' : '' ?>">
        <div class="ehtv-sched-time">
            <?= ehtv_seconds_to_hhmm($e['start']) ?>
        </div>
        <div class="ehtv-sched-bar <?= $e['fixed'] ? 'fixed' : 'free' ?>" style="<?= $is_now ? 'box-shadow:0 0 0 2px #E94560;' : '' ?>">
            <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap">
                <?php if ($is_now) : ?><span style="font-size:.68rem;background:#E94560;color:#fff;padding:1px 6px;border-radius:3px;font-weight:800">AO VIVO</span><?php endif; ?>
                <?php if ($e['fixed']) : ?><span style="font-size:.68rem;background:#3B82F6;color:#fff;padding:1px 5px;border-radius:3px">FIXO</span><?php endif; ?>
                <span class="ehtv-pill <?= esc_attr($clf) ?>" style="font-size:.62rem"><?= esc_html(ehtv_classification_label($clf)) ?></span>
                <?php if ($e['loop'] > 0) : ?><span class="ehtv-sched-loop">Loop <?= $e['loop'] + 1 ?>x</span><?php endif; ?>
            </div>
            <div style="font-weight:700;font-size:.82rem;margin-top:2px"><?= esc_html($item->title) ?></div>
            <?php if ($item->tema) : ?>
            <div style="font-size:.72rem;color:#555">📌 <?= esc_html($item->tema) ?></div>
            <?php endif; ?>
            <div style="font-size:.7rem;color:#777;margin-top:1px">
                <?= ehtv_seconds_to_hhmm($e['start']) ?>–<?= ehtv_seconds_to_hhmm($e['end']) ?>
                · <?= $dur_min ?> min
                <?php if (!$item->duration) : ?> · <em style="color:#f97316">duração estimada</em><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div style="margin-top:.75rem;font-size:.78rem;color:#999">
        * Itens sem duração cadastrada usam estimativa de 5 minutos.
        Cadastre a duração dos vídeos para uma grade precisa.
    </div>

    <?php endif; ?>
</div>

<div class="ehtv-card" style="background:#FFF7ED;border-color:#FDE68A">
    <h2 style="color:#92400E">💡 Dicas para a Grade</h2>
    <ul style="margin:0;padding-left:1.25rem;color:#92400E;font-size:.88rem;line-height:1.9">
        <li>Defina um <strong>Horário fixo</strong> em um item para ancorá-lo na grade (ex: 15:00)</li>
        <li>Itens sem horário fixo preenchem os intervalos disponíveis em loop</li>
        <li>Lacunas aparecem quando não há conteúdo suficiente para preencher o tempo antes de um item fixo</li>
        <li>A duração real dos vídeos pode ser cadastrada editando o item</li>
    </ul>
</div>

<?php // ═══════════════════════════════════════════════════════
      // TAB: ANÁLISES
      // ═══════════════════════════════════════════════════════
elseif ( $tab === 'analytics' ) :
    // Estatísticas por vídeo
    $stats = $wpdb->get_results("
        SELECT
            p.id,
            p.title,
            p.classification,
            p.tema,
            p.type,
            COUNT(v.id)                                   AS total_views,
            MAX(concurrent.cnt)                           AS peak_concurrent,
            AVG(TIMESTAMPDIFF(SECOND, v.started_at, COALESCE(v.ended_at, v.last_heartbeat))) AS avg_duration_sec
        FROM ehtm_tv_playlist p
        LEFT JOIN ehtm_tv_views v ON v.playlist_id = p.id
        LEFT JOIN (
            SELECT playlist_id, started_at, COUNT(*) AS cnt
            FROM ehtm_tv_views
            GROUP BY playlist_id, DATE_FORMAT(started_at, '%Y-%m-%d %H:%i')
        ) concurrent ON concurrent.playlist_id = p.id
        WHERE p.active = 1
        GROUP BY p.id
        ORDER BY total_views DESC, p.sort_order ASC
    ") ?: [];

    $total_views_all = array_sum(array_column($stats, 'total_views'));
    $max_views       = max(array_column($stats, 'total_views') ?: [1]);

    // Espectadores ao vivo agora (heartbeat nos últimos 90s)
    $live_viewers = (int)$wpdb->get_var(
        "SELECT COUNT(DISTINCT session_key) FROM ehtm_tv_views WHERE last_heartbeat > DATE_SUB(NOW(), INTERVAL 90 SECOND)"
    );
    // Visualizações últimas 24h
    $views_24h = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM ehtm_tv_views WHERE started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem">
    <div class="ehtv-card" style="margin:0;text-align:center">
        <div class="ehtv-stat-num" style="color:#E94560"><?= $live_viewers ?></div>
        <div class="ehtv-stat-lbl">Espectadores agora</div>
    </div>
    <div class="ehtv-card" style="margin:0;text-align:center">
        <div class="ehtv-stat-num"><?= $views_24h ?></div>
        <div class="ehtv-stat-lbl">Visualizações (24h)</div>
    </div>
    <div class="ehtv-card" style="margin:0;text-align:center">
        <div class="ehtv-stat-num"><?= $total_views_all ?></div>
        <div class="ehtv-stat-lbl">Total de visualizações</div>
    </div>
    <div class="ehtv-card" style="margin:0;text-align:center">
        <div class="ehtv-stat-num"><?= count($stats) ?></div>
        <div class="ehtv-stat-lbl">Vídeos analisados</div>
    </div>
</div>

<div class="ehtv-card">
    <h2>📊 Audiência por Vídeo</h2>

    <?php if ( empty($stats) ) : ?>
    <p style="color:#999;text-align:center;padding:2rem">Ainda sem dados de audiência. Os dados começam a ser coletados assim que o player é utilizado.</p>
    <?php else : ?>
    <table class="ehtv-analytics-table">
        <thead>
            <tr>
                <th>Vídeo</th>
                <th style="width:130px">Classificação</th>
                <th style="width:80px">Tipo</th>
                <th style="width:110px">Visualizações</th>
                <th style="width:130px">Pico simultâneo</th>
                <th style="width:120px">Duração média</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($stats as $s) :
            $clf     = $s->classification ?? 'conteudo';
            $pct     = $max_views > 0 ? round(($s->total_views / $max_views) * 100) : 0;
            $avg_min = $s->avg_duration_sec ? round($s->avg_duration_sec / 60, 1) : 0;
        ?>
        <tr>
            <td>
                <strong><?= esc_html($s->title) ?></strong>
                <?php if ($s->tema) : ?><br><span style="font-size:.72rem;color:#777">📌 <?= esc_html($s->tema) ?></span><?php endif; ?>
            </td>
            <td><span class="ehtv-pill <?= esc_attr($clf) ?>"><?= esc_html(ehtv_classification_label($clf)) ?></span></td>
            <td><span class="ehtv-pill <?= esc_attr($s->type) ?>"><?= esc_html(strtoupper($s->type)) ?></span></td>
            <td>
                <div style="font-weight:700"><?= (int)$s->total_views ?></div>
                <div class="ehtv-bar-wrap" style="margin-top:3px">
                    <div class="ehtv-bar-fill" style="width:<?= $pct ?>%"></div>
                </div>
            </td>
            <td style="font-weight:700;font-size:.95rem"><?= (int)($s->peak_concurrent ?? 0) ?> <span style="font-size:.72rem;font-weight:400;color:#777">espectadores</span></td>
            <td><?= $avg_min > 0 ? "{$avg_min} min" : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; // fim das tabs ?>

<!-- Dica de uso (apenas na playlist) -->
<?php if ($tab === 'playlist') : ?>
<div class="ehtv-card" style="background:#F7F2EB;border-color:#E0D5C5">
    <h2 style="color:#7A5C44">💡 Como usar</h2>
    <ul style="margin:0;padding-left:1.25rem;color:#7A5C44;font-size:.88rem;line-height:1.8">
        <li>Use <code>[ehtv_player]</code> em qualquer página para exibir o player</li>
        <li>A TV roda em loop — ao terminar o último vídeo, volta ao primeiro</li>
        <li>Defina <strong>Horário fixo</strong> para ancorar programas em horários específicos</li>
        <li>Veja a <strong>Grade do Dia</strong> para visualizar a programação completa de 24h</li>
        <li>A aba <strong>Análises</strong> mostra audiência e espectadores simultâneos por vídeo</li>
    </ul>
</div>
<?php endif; ?>

</div><!-- .ehtv-admin -->
</div><!-- .wrap -->

<script>
document.addEventListener('DOMContentLoaded', function() {

    // ── Drag & Drop ──
    var tbody = document.getElementById('ehtv-sortable');
    if (tbody) {
        var dragging = null;
        tbody.querySelectorAll('tr:not(.ehtv-edit-row)').forEach(function(row) {
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
            var rows = tbody.querySelectorAll('tr:not(.ehtv-edit-row)');
            var n = 0;
            rows.forEach(function(row) {
                var cells = row.querySelectorAll('td');
                if (cells[1]) cells[1].textContent = ++n;
                var inp = row.querySelector('.sort-input');
                if (inp) inp.value = n - 1;
            });
        }

        document.getElementById('ehtv-reorder-form')?.addEventListener('submit', function() {
            document.getElementById('ehtv-order-status').textContent = '⏳ Salvando...';
        });
    }

    // ── Edit rows ──
    document.querySelectorAll('.ehtv-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.id;
            var row = document.getElementById('ehtv-edit-row-' + id);
            if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
        });
    });
    document.querySelectorAll('.ehtv-edit-cancel').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.id;
            var row = document.getElementById('ehtv-edit-row-' + id);
            if (row) row.style.display = 'none';
        });
    });

    // ── Rolar até item "AO VIVO" na grade ──
    var liveRow = document.querySelector('.ehtv-sched-bar[style*="box-shadow"]');
    if (liveRow) liveRow.scrollIntoView({behavior:'smooth', block:'center'});
});
</script>
<?php
}
