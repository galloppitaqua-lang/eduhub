<?php
/* admin-tv.php — Painel de gestão da TV Escolar v2.1 */
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
    $base_url = admin_url('admin.php?page=ehtv');

    // Data selecionada para Grade/Análises (padrão: hoje em SP)
    $today       = ehtv_today();
    $grade_date  = sanitize_text_field($_GET['grade_date'] ?? $today);
    if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $grade_date) ) $grade_date = $today;

    $grade_dt    = new DateTime($grade_date, ehtv_tz());
    $grade_dt_prev = (clone $grade_dt)->modify('-1 day');
    $grade_dt_next = (clone $grade_dt)->modify('+1 day');

    $is_past    = $grade_date < $today;
    $is_today   = $grade_date === $today;
    $is_future  = $grade_date > $today;

    // Playlist completa
    $playlist = $wpdb->get_results(
        "SELECT p.*, u.display_name AS added_by_name
         FROM ehtm_tv_playlist p
         LEFT JOIN {$wpdb->users} u ON u.ID = p.added_by
         ORDER BY p.sort_order ASC"
    ) ?: [];
    $active_playlist = array_values(array_filter($playlist, fn($p) => $p->active));

    // Materiais disponíveis
    $available_materials = [];
    if ( function_exists('EduHubTurmas') ) {
        $all = EduHubTurmas()->db->get_materials(['status'=>'approved','type'=>'video','limit'=>100]);
        $in_ids = array_filter(array_column($playlist, 'material_id'));
        foreach ($all as $m) {
            if ( ! in_array($m->id, $in_ids) ) $available_materials[] = $m;
        }
    }

    $msgs = [
        'added'            => ['Item adicionado à playlist!', 'success'],
        'removed'          => ['Item removido.', ''],
        'reordered'        => ['Ordem salva!', 'success'],
        'saved'            => ['Alterações salvas!', 'success'],
        'scheduled'        => ['Agendamento salvo na grade!', 'success'],
        'copied'           => ['Grade copiada com sucesso!', 'success'],
        'invalid_yt'       => ['URL do YouTube inválida.', 'error'],
        'invalid_material' => ['Material não encontrado ou não aprovado.', 'error'],
        'missing_fields'   => ['Preencha todos os campos obrigatórios.', 'error'],
        'invalid_schedule' => ['Data ou hora inválida para o agendamento.', 'error'],
    ];
    $msg_key = sanitize_key($_GET['msg'] ?? $_GET['err'] ?? '');

    $classifications = [
        'conteudo'                 => 'Conteúdo',
        'propaganda_externa'       => 'Propaganda Externa',
        'propaganda_institucional' => 'Propaganda Institucional',
    ];

    $dia_semana = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
    $grade_label = $dia_semana[$grade_dt->format('w')] . ', ' . $grade_dt->format('d/m/Y');
    ?>
<div class="wrap">
<style>
.ehtv-admin{font-family:-apple-system,sans-serif}
.ehtv-card{background:#fff;border:1px solid #ddd;border-radius:10px;padding:1.25rem;margin-bottom:1.25rem}
.ehtv-card h2{margin:0 0 1rem;font-size:1rem;color:#1A1A2E;display:flex;align-items:center;gap:.5rem}
.ehtv-field{margin-bottom:.65rem}
.ehtv-field label{display:block;font-weight:600;font-size:.82rem;color:#555;margin-bottom:4px}
.ehtv-field input,.ehtv-field select,.ehtv-field textarea{width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:.88rem;box-sizing:border-box}
.ehtv-field textarea{resize:vertical;min-height:58px}
.ehtv-notice{padding:10px 14px;border-radius:6px;margin-bottom:1rem;font-size:.88rem}
.ehtv-notice.success{background:#D1FAE5;color:#065F46}
.ehtv-notice.error{background:#FEE2E2;color:#991B1B}
.ehtv-notice.default{background:#F7F2EB;color:#7A5C44}
/* Tabs */
.ehtv-tabs{display:flex;gap:4px;margin-bottom:1.25rem;border-bottom:2px solid #eee}
.ehtv-tab{padding:8px 18px;border-radius:6px 6px 0 0;font-size:.88rem;font-weight:700;text-decoration:none;color:#555;background:#f5f5f5;border:1px solid #ddd;border-bottom:none;position:relative;bottom:-2px}
.ehtv-tab:hover{background:#e8e8e8;color:#333;text-decoration:none}
.ehtv-tab.active{background:#fff;color:#1A1A2E;border-color:#ddd #ddd #fff}
/* Playlist table */
.ehtv-tbl{width:100%;border-collapse:collapse}
.ehtv-tbl th{background:#f9f9f9;padding:8px 10px;text-align:left;font-size:.78rem;border-bottom:2px solid #eee}
.ehtv-tbl td{padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle;font-size:.83rem}
.ehtv-tbl tr:hover td{background:#fafafa}
.ehtv-tbl tr.inactive td{opacity:.5}
.ehtv-drag{cursor:grab;color:#ccc;font-size:1.1rem;padding:0 6px}
.ehtv-drag:active{cursor:grabbing}
.ehtv-thumb{width:64px;height:40px;object-fit:cover;border-radius:4px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;overflow:hidden}
.ehtv-thumb img{width:100%;height:100%;object-fit:cover}
.ehtv-pill{display:inline-block;padding:2px 7px;border-radius:99px;font-size:.68rem;font-weight:700}
.ehtv-pill.youtube{background:#FFE0E0;color:#CC0000}
.ehtv-pill.material{background:#DBEAFE;color:#1E40AF}
.ehtv-pill.url{background:#F3E8FF;color:#6B21A8}
.ehtv-pill.conteudo{background:#D1FAE5;color:#065F46}
.ehtv-pill.propaganda_externa{background:#FEF3C7;color:#92400E}
.ehtv-pill.propaganda_institucional{background:#DBEAFE;color:#1E40AF}
.ehtv-stat{text-align:center}
.ehtv-stat-num{font-size:2rem;font-weight:800;color:#1A1A2E;line-height:1}
.ehtv-stat-lbl{font-size:.72rem;color:#777;margin-top:3px}
.ehtv-preview-link{display:inline-flex;align-items:center;gap:.5rem;background:#E94560;color:#fff;padding:8px 16px;border-radius:99px;text-decoration:none;font-weight:700;font-size:.88rem;transition:opacity .15s}
.ehtv-preview-link:hover{opacity:.85;color:#fff;text-decoration:none}
/* Edit row */
.ehtv-edit-row td{background:#F0F7FF!important;padding:.75rem 1rem!important}
.ehtv-edit-row input,.ehtv-edit-row select,.ehtv-edit-row textarea{width:100%;padding:5px 8px;border:1px solid #b0c4de;border-radius:5px;font-size:.82rem;box-sizing:border-box;margin-bottom:.35rem}
.ehtv-edit-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem}
/* Date nav */
.ehtv-date-nav{display:flex;align-items:center;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap}
.ehtv-date-nav a,.ehtv-date-nav button{padding:6px 14px;border-radius:6px;font-size:.85rem;font-weight:600;text-decoration:none;border:1px solid #ddd;background:#fff;cursor:pointer;color:#333;display:inline-flex;align-items:center;gap:.3rem}
.ehtv-date-nav a:hover,.ehtv-date-nav button:hover{background:#f5f5f5}
.ehtv-date-nav .today-btn{background:#E94560;color:#fff;border-color:#E94560}
.ehtv-date-nav .today-btn:hover{opacity:.85;color:#fff}
.ehtv-date-label{font-size:1rem;font-weight:800;color:#1A1A2E;padding:0 .5rem}
/* Grade timeline */
.ehtv-timeline{display:flex;flex-direction:column;gap:3px}
.ehtv-tl-row{display:flex;align-items:stretch;gap:.5rem}
.ehtv-tl-time{font-family:monospace;font-size:.78rem;color:#555;width:50px;flex-shrink:0;display:flex;align-items:center}
.ehtv-tl-bar{flex:1;border-radius:6px;padding:7px 10px;font-size:.8rem;line-height:1.35;position:relative}
.ehtv-tl-bar.fixed{background:#EFF6FF;border-left:4px solid #3B82F6}
.ehtv-tl-bar.free{background:#F0FDF4;border-left:4px solid #22C55E}
.ehtv-tl-bar.gap{background:#FEF2F2;border-left:4px solid #EF4444;opacity:.85;font-style:italic}
.ehtv-tl-bar.now{box-shadow:0 0 0 2px #E94560}
.ehtv-tl-actions{display:flex;gap:4px;position:absolute;top:6px;right:8px}
.ehtv-tl-del{background:none;border:none;color:#EF4444;cursor:pointer;font-size:.82rem;padding:1px 4px;border-radius:3px;line-height:1}
.ehtv-tl-del:hover{background:#FEE2E2}
.ehtv-loop-badge{font-size:.64rem;background:#F3E8FF;color:#7C3AED;padding:1px 5px;border-radius:3px;margin-left:.3rem}
.ehtv-live-badge{font-size:.64rem;background:#E94560;color:#fff;padding:1px 6px;border-radius:3px;font-weight:800;margin-right:.25rem}
/* Quick add to grade */
.ehtv-qadd{background:#F0F7FF;border:1px dashed #3B82F6;border-radius:10px;padding:1rem;margin-bottom:1.25rem}
.ehtv-qadd h3{margin:0 0 .75rem;font-size:.9rem;color:#1E40AF;display:flex;align-items:center;gap:.4rem}
.ehtv-qadd-grid{display:grid;grid-template-columns:1fr auto auto auto;gap:.5rem;align-items:end}
.ehtv-qadd-grid select,.ehtv-qadd-grid input{padding:7px 10px;border:1px solid #b0c4de;border-radius:6px;font-size:.85rem;box-sizing:border-box;width:100%}
/* Analytics */
.ehtv-analytics-tbl{width:100%;border-collapse:collapse}
.ehtv-analytics-tbl th{background:#f9f9f9;padding:8px 12px;text-align:left;font-size:.78rem;border-bottom:2px solid #eee}
.ehtv-analytics-tbl td{padding:8px 12px;border-bottom:1px solid #f0f0f0;font-size:.83rem}
.ehtv-bar-wrap{background:#eee;border-radius:4px;height:8px;min-width:60px}
.ehtv-bar-fill{height:8px;border-radius:4px;background:#E94560}
/* Copy schedule */
.ehtv-copy-form{background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:.85rem;margin-top:.85rem}
.ehtv-copy-form h4{margin:0 0 .5rem;font-size:.82rem;color:#92400E}
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

<div class="ehtv-tabs">
    <a href="<?= esc_url($base_url . '&tab=playlist') ?>" class="ehtv-tab <?= $tab==='playlist'?'active':'' ?>">📋 Playlist</a>
    <a href="<?= esc_url($base_url . '&tab=grade&grade_date=' . $today) ?>" class="ehtv-tab <?= $tab==='grade'?'active':'' ?>">📅 Grade do Dia</a>
    <a href="<?= esc_url($base_url . '&tab=analytics&grade_date=' . $today) ?>" class="ehtv-tab <?= $tab==='analytics'?'active':'' ?>">📊 Análises</a>
</div>

<?php if ($msg_key && isset($msgs[$msg_key])) :
    [$txt, $type] = $msgs[$msg_key];
?>
<div class="ehtv-notice <?= $type ?: 'default' ?>"><?= esc_html($txt) ?></div>
<?php endif; ?>

<?php /* ═══ TAB PLAYLIST ═══════════════════════════════════════ */
if ( $tab === 'playlist' ) : ?>

<!-- Estatísticas -->
<div class="ehtv-card">
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem">
        <div class="ehtv-stat">
            <div class="ehtv-stat-num"><?= count($playlist) ?></div>
            <div class="ehtv-stat-lbl">Total</div>
        </div>
        <div class="ehtv-stat">
            <div class="ehtv-stat-num"><?= count($active_playlist) ?></div>
            <div class="ehtv-stat-lbl">Ativos</div>
        </div>
        <div class="ehtv-stat">
            <div class="ehtv-stat-num"><?= count(array_filter($playlist, fn($p)=>$p->type==='youtube')) ?></div>
            <div class="ehtv-stat-lbl">YouTube</div>
        </div>
        <div class="ehtv-stat">
            <?php
            $total_dur = array_sum(array_map(fn($p) => (int)($p->duration ?? 0), $active_playlist));
            $h = intdiv($total_dur, 3600); $min = intdiv($total_dur % 3600, 60);
            ?>
            <div class="ehtv-stat-num"><?= $h ?>h<?= $min ?>m</div>
            <div class="ehtv-stat-lbl">Duração total</div>
        </div>
    </div>
</div>

<!-- Formulários de adição -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.25rem">

    <div class="ehtv-card">
        <h2>▶️ Adicionar YouTube</h2>
        <form method="POST">
            <input type="hidden" name="ehtv_action" value="add_youtube">
            <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
            <input type="hidden" name="_tab" value="playlist">
            <div class="ehtv-field"><label>URL ou ID do vídeo *</label>
                <input type="text" name="youtube_url" placeholder="https://youtube.com/watch?v=..." required></div>
            <div class="ehtv-field"><label>Título *</label>
                <input type="text" name="title" placeholder="Nome do programa" required></div>
            <div class="ehtv-field"><label>Tema</label>
                <input type="text" name="tema" placeholder="Ex: Matemática, Notícias..."></div>
            <div class="ehtv-field"><label>Classificação</label>
                <select name="classification">
                    <?php foreach ($classifications as $v => $l) : ?>
                    <option value="<?= $v ?>"><?= esc_html($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ehtv-field"><label>Mini descrição</label>
                <textarea name="description" placeholder="Breve descrição do conteúdo..."></textarea></div>
            <button type="submit" class="button button-primary">+ Adicionar</button>
        </form>
    </div>

    <div class="ehtv-card">
        <h2>📁 Adicionar do Repositório</h2>
        <?php if (empty($available_materials)) : ?>
        <p style="color:#999;font-size:.85rem">
            <?= function_exists('EduHubTurmas')
                ? 'Nenhum vídeo aprovado disponível.'
                : 'Plugin EduHub Turmas não está ativo.' ?>
        </p>
        <?php else : ?>
        <form method="POST">
            <input type="hidden" name="ehtv_action" value="add_material">
            <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
            <input type="hidden" name="_tab" value="playlist">
            <div class="ehtv-field"><label>Selecione o vídeo *</label>
                <select name="material_id" required>
                    <option value="">Escolha um material...</option>
                    <?php foreach ($available_materials as $m) : ?>
                    <option value="<?= $m->id ?>"><?= esc_html($m->title) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ehtv-field"><label>Título personalizado</label>
                <input type="text" name="title" placeholder="Deixe vazio para usar o original"></div>
            <div class="ehtv-field"><label>Tema</label>
                <input type="text" name="tema" placeholder="Ex: Ciências, Histórico..."></div>
            <div class="ehtv-field"><label>Classificação</label>
                <select name="classification">
                    <?php foreach ($classifications as $v => $l) : ?>
                    <option value="<?= $v ?>"><?= esc_html($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ehtv-field"><label>Mini descrição</label>
                <textarea name="description" placeholder="Deixe vazio para usar a descrição original"></textarea></div>
            <button type="submit" class="button button-primary">+ Adicionar</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="ehtv-card">
        <h2>🔗 Adicionar URL externa</h2>
        <form method="POST">
            <input type="hidden" name="ehtv_action" value="add_url">
            <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
            <input type="hidden" name="_tab" value="playlist">
            <div class="ehtv-field"><label>URL do vídeo MP4 *</label>
                <input type="url" name="video_url" placeholder="https://..." required></div>
            <div class="ehtv-field"><label>Título *</label>
                <input type="text" name="title" placeholder="Nome do programa" required></div>
            <div class="ehtv-field"><label>Tema</label>
                <input type="text" name="tema" placeholder="Assunto principal"></div>
            <div class="ehtv-field"><label>Classificação</label>
                <select name="classification">
                    <?php foreach ($classifications as $v => $l) : ?>
                    <option value="<?= $v ?>"><?= esc_html($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ehtv-field"><label>Mini descrição</label>
                <textarea name="description" placeholder="Breve descrição..."></textarea></div>
            <div class="ehtv-field"><label>URL da thumbnail (opcional)</label>
                <input type="url" name="thumbnail" placeholder="https://..."></div>
            <button type="submit" class="button button-primary">+ Adicionar</button>
        </form>
    </div>

</div>

<!-- Playlist -->
<div class="ehtv-card">
    <h2>📋 Playlist
        <span style="font-size:.75rem;font-weight:400;color:#777;margin-left:.5rem">
            Arraste para reordenar · ✏️ para editar · Agende na aba Grade do Dia
        </span>
    </h2>

    <?php if (empty($playlist)) : ?>
    <p style="color:#999;text-align:center;padding:2rem">Nenhum item. Adicione vídeos acima.</p>
    <?php else : ?>

    <form method="POST" id="ehtv-reorder-form">
        <input type="hidden" name="ehtv_action" value="reorder">
        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
        <input type="hidden" name="_tab" value="playlist">

        <table class="ehtv-tbl">
            <thead>
                <tr>
                    <th style="width:30px"></th>
                    <th style="width:36px">#</th>
                    <th style="width:72px"></th>
                    <th>Título / Tema</th>
                    <th style="width:75px">Tipo</th>
                    <th style="width:135px">Classificação</th>
                    <th style="width:55px">Ativo</th>
                    <th style="width:75px">Ações</th>
                </tr>
            </thead>
            <tbody id="ehtv-sortable">
            <?php foreach ($playlist as $i => $item) :
                $thumb = ehtv_thumb($item);
                $clf   = $item->classification ?? 'conteudo';
            ?>
            <tr data-id="<?= $item->id ?>" class="<?= !$item->active ? 'inactive' : '' ?>">
                <td>
                    <span class="ehtv-drag" title="Arrastar">⠿</span>
                    <input type="hidden" name="sort_order[<?= $item->id ?>]" value="<?= $i ?>" class="sort-input">
                </td>
                <td style="font-weight:700;color:#999;font-size:.78rem"><?= $i + 1 ?></td>
                <td><div class="ehtv-thumb">
                    <?php if ($thumb) : ?><img src="<?= esc_url($thumb) ?>" alt=""><?php else : ?>🎬<?php endif; ?>
                </div></td>
                <td>
                    <strong><?= esc_html($item->title) ?></strong>
                    <?php if ($item->tema) : ?><br><span style="font-size:.72rem;color:#777">📌 <?= esc_html($item->tema) ?></span><?php endif; ?>
                    <?php if ($item->description) : ?><br><span style="font-size:.7rem;color:#999"><?= esc_html(mb_strimwidth($item->description, 0, 55, '…')) ?></span><?php endif; ?>
                </td>
                <td><span class="ehtv-pill <?= esc_attr($item->type) ?>"><?= $item->type==='youtube'?'▶ YT':($item->type==='material'?'📁 Banco':'🔗 URL') ?></span></td>
                <td><span class="ehtv-pill <?= esc_attr($clf) ?>"><?= esc_html(ehtv_classification_label($clf)) ?></span></td>
                <td>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="ehtv_action" value="toggle">
                        <input type="hidden" name="item_id" value="<?= $item->id ?>">
                        <input type="hidden" name="active" value="<?= $item->active ?>">
                        <input type="hidden" name="_tab" value="playlist">
                        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
                        <button type="submit" class="button button-small" style="<?= $item->active?'color:green':'color:#999' ?>"><?= $item->active?'✅':'⏸' ?></button>
                    </form>
                </td>
                <td>
                    <button type="button" class="button button-small ehtv-edit-btn" data-id="<?= $item->id ?>" title="Editar">✏️</button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Remover da playlist?')">
                        <input type="hidden" name="ehtv_action" value="remove">
                        <input type="hidden" name="item_id" value="<?= $item->id ?>">
                        <input type="hidden" name="_tab" value="playlist">
                        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
                        <button type="submit" class="button button-small" style="color:red" title="Remover">✕</button>
                    </form>
                </td>
            </tr>
            <!-- Edit row -->
            <tr id="ehtv-edit-row-<?= $item->id ?>" class="ehtv-edit-row" style="display:none">
                <td colspan="8">
                    <form method="POST">
                        <input type="hidden" name="ehtv_action" value="edit">
                        <input type="hidden" name="item_id" value="<?= $item->id ?>">
                        <input type="hidden" name="_tab" value="playlist">
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

<?php /* ═══ TAB GRADE ═══════════════════════════════════════════ */
elseif ( $tab === 'grade' ) :

    $sched_data = ehtv_build_day_schedule($grade_date);
    $entries    = $sched_data['entries'];
    $gaps       = $sched_data['gaps'];
    $now_sp     = ehtv_now();
    $now_sec    = (int)$now_sp->format('H') * 3600 + (int)$now_sp->format('i') * 60 + (int)$now_sp->format('s');
    $sched_entries_raw = ehtv_get_schedule_for_date($grade_date);

    // Contagens de tipo para o dia
    $total_fixed = count($sched_entries_raw);

    // Views reais do dia (para dias passados ou hoje)
    $view_stats = [];
    if ( ! $is_future ) {
        $view_stats = $wpdb->get_results( $wpdb->prepare(
            "SELECT playlist_id, COUNT(*) AS views, COUNT(DISTINCT session_key) AS unique_viewers
             FROM ehtm_tv_views
             WHERE DATE(started_at) = %s
             GROUP BY playlist_id",
            $grade_date
        ) ) ?: [];
        $view_stats = array_column($view_stats, null, 'playlist_id');
    }
?>

<!-- Navegação de data -->
<div class="ehtv-date-nav">
    <a href="<?= esc_url($base_url . '&tab=grade&grade_date=' . $grade_dt_prev->format('Y-m-d')) ?>">← Anterior</a>
    <span class="ehtv-date-label"><?= esc_html($grade_label) ?></span>
    <a href="<?= esc_url($base_url . '&tab=grade&grade_date=' . $grade_dt_next->format('Y-m-d')) ?>">Próximo →</a>

    <?php if (!$is_today) : ?>
    <a href="<?= esc_url($base_url . '&tab=grade&grade_date=' . $today) ?>" class="today-btn">Hoje</a>
    <?php endif; ?>

    <form method="GET" style="margin-left:.5rem;display:flex;align-items:center;gap:.4rem">
        <input type="hidden" name="page" value="ehtv">
        <input type="hidden" name="tab" value="grade">
        <input type="date" name="grade_date" value="<?= esc_attr($grade_date) ?>"
               style="padding:5px 8px;border:1px solid #ddd;border-radius:6px;font-size:.85rem">
        <button type="submit" class="button button-small">Ir</button>
    </form>

    <span style="font-size:.78rem;color:#777;margin-left:auto">
        ⏰ Fuso: América/São Paulo &nbsp;|&nbsp;
        <?= $is_past ? '📁 Dia passado' : ($is_today ? '🔴 Hoje' : '📅 Dia futuro') ?>
        &nbsp;|&nbsp; <?= $total_fixed ?> item(ns) fixo(s)
    </span>
</div>

<?php if ( ! empty($gaps) ) : ?>
<div class="ehtv-notice error">
    ⚠️ <?= count($gaps) ?> lacuna(s) detectada(s):
    <?php foreach ($gaps as $g) : ?>
    <strong><?= ehtv_seconds_to_hhmm($g['start']) ?>–<?= ehtv_seconds_to_hhmm($g['end']) ?></strong> (<?= gmdate('H\hi', $g['duration']) ?>)
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Quick add -->
<?php if ( ! $is_past ) : ?>
<div class="ehtv-qadd">
    <h3>➕ Adicionar à grade de <?= esc_html($grade_label) ?></h3>
    <form method="POST">
        <input type="hidden" name="ehtv_action" value="add_schedule">
        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
        <input type="hidden" name="_tab" value="grade">
        <div class="ehtv-qadd-grid">
            <div>
                <label style="font-size:.78rem;font-weight:700;color:#333;display:block;margin-bottom:4px">Conteúdo *</label>
                <select name="playlist_id" required style="width:100%">
                    <option value="">Selecione um conteúdo...</option>
                    <?php foreach ($active_playlist as $ap) :
                        $ap_clf = $ap->classification ?? 'conteudo';
                    ?>
                    <option value="<?= $ap->id ?>">
                        [<?= esc_html(ehtv_classification_label($ap_clf)) ?>]
                        <?= esc_html($ap->title) ?>
                        <?= $ap->tema ? ' — ' . esc_html($ap->tema) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:700;color:#333;display:block;margin-bottom:4px">Data *</label>
                <input type="date" name="schedule_date" value="<?= esc_attr($grade_date) ?>" required min="<?= $today ?>">
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:700;color:#333;display:block;margin-bottom:4px">Horário *</label>
                <input type="time" name="start_time" required>
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:700;color:#333;display:block;margin-bottom:4px">&nbsp;</label>
                <button type="submit" class="button button-primary" style="width:100%">+ Adicionar</button>
            </div>
        </div>
    </form>

    <?php if ( $total_fixed > 0 ) : ?>
    <!-- Copiar grade para outros dias -->
    <div class="ehtv-copy-form">
        <h4>📋 Copiar esta grade para outros dias</h4>
        <form method="POST" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
            <input type="hidden" name="ehtv_action" value="copy_schedule">
            <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
            <input type="hidden" name="_tab" value="grade">
            <input type="hidden" name="source_date" value="<?= esc_attr($grade_date) ?>">
            <input type="date" name="target_dates[]" min="<?= $today ?>" style="padding:5px 8px;border:1px solid #d4a;border-radius:5px;font-size:.82rem">
            <input type="date" name="target_dates[]" min="<?= $today ?>" style="padding:5px 8px;border:1px solid #d4a;border-radius:5px;font-size:.82rem">
            <input type="date" name="target_dates[]" min="<?= $today ?>" style="padding:5px 8px;border:1px solid #d4a;border-radius:5px;font-size:.82rem">
            <button type="submit" class="button button-small">Copiar grade</button>
            <span style="font-size:.72rem;color:#777">(deixe campos em branco para ignorar)</span>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Timeline -->
<div class="ehtv-card">
    <h2>📅 Programação — <?= esc_html($grade_label) ?>
        <span style="font-size:.75rem;font-weight:400;color:#777;margin-left:.5rem">
            <?= count($entries) ?> bloco(s) · <?= $sched_data['loops'] ?> loop(s) completo(s)
        </span>
    </h2>

    <?php if (empty($entries) && empty($gaps)) : ?>
    <p style="color:#999;text-align:center;padding:2rem">Nenhum conteúdo ativo na playlist.</p>
    <?php else : ?>

    <div class="ehtv-timeline" id="ehtv-timeline" style="max-height:600px;overflow-y:auto">
    <?php
    $current_entry_idx = -1;
    if ($is_today) {
        foreach ($entries as $idx => $e) {
            if ($e['start'] <= $now_sec && $e['end'] > $now_sec) { $current_entry_idx = $idx; break; }
        }
    }
    foreach ($entries as $idx => $e) :
        $item   = $e['item'];
        $is_now = ($is_today && $idx === $current_entry_idx);
        $is_past_entry = $is_past || ($is_today && $e['end'] <= $now_sec);
        $clf    = $item->classification ?? 'conteudo';
        $dur    = $e['end'] - $e['start'];
        $dur_label = $dur >= 3600
            ? sprintf('%dh%02dm', intdiv($dur,3600), intdiv($dur%3600,60))
            : round($dur/60) . ' min';

        // Views do dia (passado/hoje)
        $vstat  = $view_stats[$item->id] ?? null;
    ?>
    <div class="ehtv-tl-row" style="<?= $is_past_entry && !$is_now ? 'opacity:.5' : '' ?>">
        <div class="ehtv-tl-time"><?= ehtv_seconds_to_hhmm($e['start']) ?></div>
        <div class="ehtv-tl-bar <?= $e['fixed']?'fixed':'free' ?> <?= $is_now?'now':'' ?>">
            <?php if ($e['fixed']) : ?>
            <div class="ehtv-tl-actions">
                <form method="POST" style="display:inline" onsubmit="return confirm('Remover este agendamento?')">
                    <input type="hidden" name="ehtv_action" value="remove_schedule">
                    <input type="hidden" name="schedule_id" value="<?= $e['sched']->id ?>">
                    <input type="hidden" name="grade_date" value="<?= esc_attr($grade_date) ?>">
                    <input type="hidden" name="_tab" value="grade">
                    <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
                    <button type="submit" class="ehtv-tl-del" title="Remover agendamento">✕</button>
                </form>
            </div>
            <?php endif; ?>
            <div style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;margin-bottom:2px">
                <?php if ($is_now) : ?><span class="ehtv-live-badge">▶ AO VIVO</span><?php endif; ?>
                <?php if ($e['fixed']) : ?><span style="font-size:.62rem;background:#3B82F6;color:#fff;padding:1px 5px;border-radius:3px">FIXO</span><?php endif; ?>
                <span class="ehtv-pill <?= esc_attr($clf) ?>" style="font-size:.62rem"><?= esc_html(ehtv_classification_label($clf)) ?></span>
                <?php if ($e['loop']>0) : ?><span class="ehtv-loop-badge">Loop <?= $e['loop']+1 ?>×</span><?php endif; ?>
                <?php if ($vstat) : ?>
                <span style="font-size:.62rem;background:#FEF3C7;color:#92400E;padding:1px 5px;border-radius:3px">
                    👁 <?= (int)$vstat->unique_viewers ?> espect.
                </span>
                <?php endif; ?>
            </div>
            <div style="font-weight:700;font-size:.82rem"><?= esc_html($item->title) ?></div>
            <?php if ($item->tema) : ?><div style="font-size:.72rem;color:#555">📌 <?= esc_html($item->tema) ?></div><?php endif; ?>
            <div style="font-size:.7rem;color:#777;margin-top:1px">
                <?= ehtv_seconds_to_hhmm($e['start']) ?>–<?= ehtv_seconds_to_hhmm($e['end']) ?>
                · <?= $dur_label ?>
                <?php if (!$item->duration) : ?> · <em style="color:#f97316">duração estimada</em><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <p style="margin-top:.6rem;font-size:.75rem;color:#999">
        * Itens sem duração cadastrada usam estimativa de 5 minutos. Fuso: América/São Paulo (<?= $now_sp->format('H:i') ?>).
    </p>
    <?php endif; ?>
</div>

<?php /* ═══ TAB ANALYTICS ═══════════════════════════════════════ */
elseif ( $tab === 'analytics' ) :
    // Views de hoje para cards do topo
    $live_viewers = (int)$wpdb->get_var(
        "SELECT COUNT(DISTINCT session_key) FROM ehtm_tv_views WHERE last_heartbeat > DATE_SUB(NOW(), INTERVAL 90 SECOND)"
    );
    $views_24h = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM ehtm_tv_views WHERE started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );

    // Stats por vídeo para o dia selecionado
    $stats = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            p.id, p.title, p.classification, p.tema, p.type,
            COUNT(v.id)       AS total_views,
            COUNT(DISTINCT v.session_key) AS unique_viewers,
            MAX(v.last_heartbeat) AS last_seen,
            AVG(TIMESTAMPDIFF(SECOND, v.started_at, COALESCE(v.ended_at, v.last_heartbeat))) AS avg_sec
         FROM ehtm_tv_playlist p
         LEFT JOIN ehtm_tv_views v ON v.playlist_id = p.id AND DATE(v.started_at) = %s
         WHERE p.active = 1
         GROUP BY p.id
         ORDER BY total_views DESC, p.sort_order ASC",
        $grade_date
    ) ) ?: [];

    $max_views = max(array_column($stats, 'total_views') ?: [1]);
?>

<!-- Navegação de data -->
<div class="ehtv-date-nav">
    <a href="<?= esc_url($base_url . '&tab=analytics&grade_date=' . $grade_dt_prev->format('Y-m-d')) ?>">← Anterior</a>
    <span class="ehtv-date-label">Análises — <?= esc_html($grade_label) ?></span>
    <a href="<?= esc_url($base_url . '&tab=analytics&grade_date=' . $grade_dt_next->format('Y-m-d')) ?>">Próximo →</a>
    <?php if (!$is_today) : ?>
    <a href="<?= esc_url($base_url . '&tab=analytics&grade_date=' . $today) ?>" class="today-btn">Hoje</a>
    <?php endif; ?>
    <form method="GET" style="margin-left:.5rem;display:flex;align-items:center;gap:.4rem">
        <input type="hidden" name="page" value="ehtv">
        <input type="hidden" name="tab" value="analytics">
        <input type="date" name="grade_date" value="<?= esc_attr($grade_date) ?>"
               style="padding:5px 8px;border:1px solid #ddd;border-radius:6px;font-size:.85rem">
        <button type="submit" class="button button-small">Ir</button>
    </form>
</div>

<!-- Cards de hoje sempre -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem">
    <div class="ehtv-card" style="margin:0;text-align:center">
        <div class="ehtv-stat-num" style="color:#E94560"><?= $live_viewers ?></div>
        <div class="ehtv-stat-lbl">Ao vivo agora</div>
    </div>
    <div class="ehtv-card" style="margin:0;text-align:center">
        <div class="ehtv-stat-num"><?= $views_24h ?></div>
        <div class="ehtv-stat-lbl">Views (24h)</div>
    </div>
    <div class="ehtv-card" style="margin:0;text-align:center">
        <div class="ehtv-stat-num"><?= array_sum(array_column($stats, 'total_views')) ?></div>
        <div class="ehtv-stat-lbl">Views em <?= $grade_dt->format('d/m') ?></div>
    </div>
    <div class="ehtv-card" style="margin:0;text-align:center">
        <div class="ehtv-stat-num"><?= array_sum(array_column($stats, 'unique_viewers')) ?></div>
        <div class="ehtv-stat-lbl">Espect. únicos em <?= $grade_dt->format('d/m') ?></div>
    </div>
</div>

<div class="ehtv-card">
    <h2>📊 Audiência por vídeo — <?= esc_html($grade_label) ?></h2>

    <?php if (array_sum(array_column($stats, 'total_views')) === 0) : ?>
    <p style="color:#999;text-align:center;padding:2rem">
        <?= $is_future
            ? 'Dia futuro — ainda sem dados de audiência.'
            : 'Nenhuma visualização registrada para este dia.' ?>
    </p>
    <?php else : ?>
    <table class="ehtv-analytics-tbl">
        <thead>
            <tr>
                <th>Vídeo</th>
                <th style="width:130px">Classificação</th>
                <th style="width:100px">Visualizações</th>
                <th style="width:120px">Espect. únicos</th>
                <th style="width:110px">Duração média</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($stats as $s) :
            if (!$s->total_views) continue;
            $clf   = $s->classification ?? 'conteudo';
            $pct   = $max_views > 0 ? round(($s->total_views / $max_views) * 100) : 0;
            $avg_m = $s->avg_sec ? round($s->avg_sec / 60, 1) : 0;
        ?>
        <tr>
            <td>
                <strong><?= esc_html($s->title) ?></strong>
                <?php if ($s->tema) : ?><br><span style="font-size:.72rem;color:#777">📌 <?= esc_html($s->tema) ?></span><?php endif; ?>
            </td>
            <td><span class="ehtv-pill <?= esc_attr($clf) ?>"><?= esc_html(ehtv_classification_label($clf)) ?></span></td>
            <td>
                <strong><?= (int)$s->total_views ?></strong>
                <div class="ehtv-bar-wrap" style="margin-top:3px"><div class="ehtv-bar-fill" style="width:<?= $pct ?>%"></div></div>
            </td>
            <td style="font-weight:700"><?= (int)$s->unique_viewers ?></td>
            <td><?= $avg_m > 0 ? "{$avg_m} min" : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; // fim das tabs ?>

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
                var st = document.getElementById('ehtv-order-status');
                if (st) st.textContent = '⚠️ Ordem alterada — clique em Salvar';
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
            var st = document.getElementById('ehtv-order-status');
            if (st) st.textContent = '⏳ Salvando...';
        });
    }

    // ── Edit rows ──
    document.querySelectorAll('.ehtv-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id  = this.dataset.id;
            var row = document.getElementById('ehtv-edit-row-' + id);
            if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
        });
    });
    document.querySelectorAll('.ehtv-edit-cancel').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var row = document.getElementById('ehtv-edit-row-' + this.dataset.id);
            if (row) row.style.display = 'none';
        });
    });

    // ── Rolar até item "AO VIVO" ──
    var live = document.querySelector('.ehtv-tl-bar.now');
    if (live) {
        setTimeout(function() {
            live.scrollIntoView({behavior:'smooth', block:'center'});
        }, 300);
    }
});
</script>
<?php
}
