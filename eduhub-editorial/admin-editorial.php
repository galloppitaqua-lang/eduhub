<?php
/* admin-editorial.php */
if ( ! defined('ABSPATH') ) exit;

add_action('admin_menu', function() {
    add_menu_page('Editorial IA','🧠 Editorial IA','manage_options','ehei','ehei_admin_page','dashicons-lightbulb',26);
    add_submenu_page('ehei','Dashboard','Dashboard','manage_options','ehei','ehei_admin_page');
    add_submenu_page('ehei','Sugestões','Sugestões de Pauta','manage_options','ehei-suggestions','ehei_admin_suggestions');
    add_submenu_page('ehei','Conteúdo Monitorado','Conteúdo Monitorado','manage_options','ehei-content','ehei_admin_content');
    add_submenu_page('ehei','Configurações','Configurações','manage_options','ehei-settings','ehei_admin_settings');
});

// Processar ações
add_action('admin_init', function() {
    if (!isset($_POST['ehei_action'])) return;
    if (!current_user_can('manage_options')) return;
    if (!wp_verify_nonce($_POST['_wpnonce']??'','ehei_admin')) wp_die('Nonce inválido.');

    $action = sanitize_key($_POST['ehei_action']);

    if ($action === 'run_analysis') {
        $result = ehei_run_daily_analysis();
        $status = isset($result['error']) ? 'err_'.urlencode($result['error']) : 'analyzed_'.$result['analysis_id'];
        wp_redirect(admin_url('admin.php?page=ehei&result='.$status)); exit;
    }

    if ($action === 'collect_history') {
        $days  = absint($_POST['days'] ?? 30);
        $count = ehei_collect_historical($days);
        wp_redirect(admin_url('admin.php?page=ehei-content&collected='.$count)); exit;
    }

    if ($action === 'update_suggestion') {
        $id     = absint($_POST['suggestion_id'] ?? 0);
        $status = sanitize_key($_POST['status'] ?? '');
        $notes  = sanitize_textarea_field($_POST['notes'] ?? '');
        if ($id && $status) ehei_update_suggestion($id, $status, $notes);
        wp_redirect(admin_url('admin.php?page=ehei-suggestions&updated=1')); exit;
    }

    if ($action === 'save_settings') {
        update_option('ehei_gemini_key', sanitize_text_field($_POST['gemini_key'] ?? ''));
        update_option('ehei_analysis_days', absint($_POST['analysis_days'] ?? 7));
        wp_redirect(admin_url('admin.php?page=ehei-settings&saved=1')); exit;
    }

    if ($action === 'test_connection') {
        $key = get_option('ehei_gemini_key','');
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $key;
        $r = wp_remote_post($endpoint,[
            'timeout' => 15,
            'headers' => ['Content-Type'=>'application/json','x-goog-api-key'=>$key],
            'body'    => json_encode(['contents'=>[['parts'=>[['text'=>'OK']]]],'generationConfig'=>['maxOutputTokens'=>5]]),
        ]);
        if (is_wp_error($r)) {
            wp_redirect(admin_url('admin.php?page=ehei-settings&test=fail&msg='.urlencode($r->get_error_message()))); exit;
        }
        $code = wp_remote_retrieve_response_code($r);
        if ($code === 200) {
            wp_redirect(admin_url('admin.php?page=ehei-settings&test=ok')); exit;
        }
        $body = json_decode(wp_remote_retrieve_body($r),true);
        $body_data = json_decode(wp_remote_retrieve_body($r), true);
        wp_redirect(admin_url('admin.php?page=ehei-settings&test=fail&msg='.urlencode($body_data['error']['message']??'HTTP '.$code))); exit;
    }
});

// ── CSS compartilhado ─────────────────────────────────────────
function ehei_admin_styles(): void { ?>
<style>
.ehei-wrap   { font-family: -apple-system,sans-serif; }
.ehei-card   { background:#fff; border:1px solid #e0e0e0; border-radius:10px; padding:1.25rem; margin-bottom:1.1rem; }
.ehei-card h2{ margin:0 0 .9rem; font-size:1rem; color:#1a1a2e; display:flex; align-items:center; gap:.4rem; }
.ehei-grid   { display:grid; gap:1rem; }
.ehei-grid-2 { grid-template-columns:1fr 1fr; }
.ehei-grid-3 { grid-template-columns:1fr 1fr 1fr; }
.ehei-grid-4 { grid-template-columns:repeat(4,1fr); }
.ehei-stat   { text-align:center; padding:.75rem; }
.ehei-stat-n { font-size:2.2rem; font-weight:800; line-height:1; }
.ehei-stat-l { font-size:.72rem; color:#777; margin-top:3px; }
.ehei-badge  { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.72rem; font-weight:700; }
.ehei-badge.alta   { background:#FEE2E2; color:#991B1B; }
.ehei-badge.media  { background:#FEF3C7; color:#92400E; }
.ehei-badge.baixa  { background:#DBEAFE; color:#1E40AF; }
.ehei-badge.pending      { background:#F3E8FF; color:#6B21A8; }
.ehei-badge.approved     { background:#D1FAE5; color:#065F46; }
.ehei-badge.rejected     { background:#FEE2E2; color:#991B1B; }
.ehei-badge.in_production{ background:#DBEAFE; color:#1E40AF; }
.ehei-tag    { display:inline-block; padding:2px 8px; border-radius:6px; font-size:.7rem; font-weight:700; background:#F0F0F0; color:#555; margin:2px; }
.ehei-kw-bar { height:6px; background:#E9D5FF; border-radius:99px; margin-top:2px; }
.ehei-kw-fill{ height:100%; background:linear-gradient(90deg,#7C3AED,#A855F7); border-radius:99px; }
.ehei-insight { background:linear-gradient(135deg,#1a1a2e,#2D6A4F); color:#fff; border-radius:10px; padding:1.25rem; margin-bottom:1.1rem; }
.ehei-insight p { margin:0; line-height:1.7; font-size:.92rem; opacity:.92; }
.ehei-insight h3{ color:#fff; margin:0 0 .6rem; font-size:1rem; }
.ehei-sug-card { border:1px solid #e0e0e0; border-left:4px solid #7C3AED; border-radius:0 10px 10px 0; padding:1rem 1.1rem; margin-bottom:.65rem; }
.ehei-sug-card.alta   { border-left-color:#DC2626; }
.ehei-sug-card.media  { border-left-color:#D97706; }
.ehei-sug-card.baixa  { border-left-color:#2563EB; }
.ehei-sug-title  { font-weight:700; font-size:.97rem; margin-bottom:.25rem; }
.ehei-sug-desc   { font-size:.85rem; color:#555; margin-bottom:.5rem; line-height:1.55; }
.ehei-sug-just   { font-size:.8rem; color:#7C3AED; background:#F5F3FF; padding:.5rem .75rem; border-radius:6px; margin-bottom:.5rem; }
.ehei-type-icons { pauta:'📰'; debate:'💬'; derivacao:'🔄'; oficina:'🛠'; }
.ehei-sent-bar   { display:flex; height:12px; border-radius:99px; overflow:hidden; margin:.4rem 0; }
.ehei-theme-card { padding:.75rem; border:1px solid #eee; border-radius:8px; }
.ehei-theme-name { font-weight:700; font-size:.88rem; margin-bottom:.15rem; }
.ehei-freq-bar   { height:5px; background:#F0E4CC; border-radius:99px; }
.ehei-freq-fill  { height:100%; background:#C1440E; border-radius:99px; transition:width .5s; }
@media(max-width:900px){ .ehei-grid-3{grid-template-columns:1fr 1fr} .ehei-grid-4{grid-template-columns:1fr 1fr} }
@media(max-width:600px){ .ehei-grid-2,.ehei-grid-3,.ehei-grid-4{grid-template-columns:1fr} }
</style>
<?php }

// ══════════════════════════════════════════════════════════════
// DASHBOARD
// ══════════════════════════════════════════════════════════════
function ehei_admin_page(): void {
    ehei_admin_styles();
    global $wpdb;

    $nonce    = wp_create_nonce('ehei_admin');
    $analysis = ehei_get_latest_analysis();
    $stats    = ehei_get_content_stats();
    $pending  = count(ehei_get_suggestions('pending'));
    $result_msg = $_GET['result'] ?? '';

    $themes    = $analysis ? json_decode($analysis->themes, true) : [];
    $sentiments= $analysis ? json_decode($analysis->sentiments, true) : [];
    $keywords  = $analysis ? json_decode($analysis->keywords, true) : [];
    $raw       = $analysis ? json_decode($analysis->raw_response, true) : [];
    $insight   = $raw['insight_editorial'] ?? '';
    $type_icons= ['pauta'=>'📰','debate'=>'💬','derivacao'=>'🔄','oficina'=>'🛠️'];
    ?>
<div class="wrap ehei-wrap">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <h1 style="margin:0">🧠 Editorial IA — Dashboard</h1>
    <form method="POST" style="display:inline" onsubmit="return confirm('Iniciar análise com IA? Isso pode levar até 30 segundos.')">
        <input type="hidden" name="ehei_action" value="run_analysis">
        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
        <button type="submit" class="button button-primary" style="background:#7C3AED;border-color:#6D28D9">
            🤖 Analisar agora
        </button>
    </form>
</div>

<?php if ($result_msg) :
    if (str_starts_with($result_msg,'analyzed_')) echo '<div class="notice notice-success"><p>✅ Análise concluída! Novas sugestões geradas.</p></div>';
    elseif (str_starts_with($result_msg,'err_')) echo '<div class="notice notice-error"><p>❌ Erro: '.esc_html(urldecode(substr($result_msg,4))).'</p></div>';
endif; ?>

<!-- KPIs -->
<div class="ehei-grid ehei-grid-4" style="margin-bottom:1.1rem">
    <?php $kpis = [
        ['n'=>$stats['total'],'l'=>'Conteúdos coletados','c'=>'#1a1a2e'],
        ['n'=>$stats['recent'],'l'=>'Últimos 7 dias','c'=>'#2D6A4F'],
        ['n'=>count($themes),'l'=>'Temas identificados','c'=>'#7C3AED'],
        ['n'=>$pending,'l'=>'Pautas pendentes','c'=>'#C1440E'],
    ]; foreach ($kpis as $k) : ?>
    <div class="ehei-card ehei-stat">
        <div class="ehei-stat-n" style="color:<?= $k['c'] ?>"><?= $k['n'] ?></div>
        <div class="ehei-stat-l"><?= $k['l'] ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($insight) : ?>
<!-- Insight editorial -->
<div class="ehei-insight">
    <h3>💡 Visão Editorial — <?= esc_html($analysis->period_start) ?> a <?= esc_html($analysis->period_end) ?></h3>
    <p><?= esc_html($insight) ?></p>
</div>
<?php endif; ?>

<?php if ($analysis) : ?>
<div class="ehei-grid ehei-grid-2">

<!-- Temas emergentes -->
<div class="ehei-card">
    <h2>🔥 Temas Emergentes</h2>
    <?php if (empty($themes)) : ?>
    <p style="color:#999">Nenhuma análise disponível ainda.</p>
    <?php else : ?>
    <div class="ehei-grid" style="grid-template-columns:1fr 1fr;gap:.5rem">
    <?php foreach (array_slice($themes,0,8) as $t) :
        $pot_colors = ['alto'=>'#065F46','medio'=>'#92400E','baixo'=>'#1E40AF'];
        $pot = $t['potencial_educacional'] ?? 'medio';
    ?>
    <div class="ehei-theme-card">
        <div class="ehei-theme-name"><?= esc_html($t['tema']) ?></div>
        <div class="ehei-freq-bar"><div class="ehei-freq-fill" style="width:<?= min(100, ($t['frequencia']??5)*10) ?>%"></div></div>
        <div style="font-size:.7rem;color:<?= $pot_colors[$pot]??'#555' ?>;margin-top:3px;font-weight:700"><?= ucfirst($pot) ?> potencial</div>
        <?php if (!empty($t['exemplos'])) : ?>
        <div style="font-size:.7rem;color:#999;margin-top:2px;font-style:italic">"<?= esc_html($t['exemplos'][0]) ?>"</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Sentimento + Keywords -->
<div>
    <div class="ehei-card">
        <h2>📊 Sentimento da Comunidade</h2>
        <?php if (!empty($sentiments)) :
            $pos  = (int)($sentiments['positivo'] ?? 33);
            $neu  = (int)($sentiments['neutro']   ?? 34);
            $crit = (int)($sentiments['critico']  ?? 33);
        ?>
        <div class="ehei-sent-bar">
            <div style="width:<?=$pos?>%;background:#10B981"></div>
            <div style="width:<?=$neu?>%;background:#6B7280"></div>
            <div style="width:<?=$crit?>%;background:#F59E0B"></div>
        </div>
        <div style="display:flex;gap:1rem;font-size:.78rem;margin-top:.4rem">
            <span style="color:#10B981">■ Positivo <?=$pos?>%</span>
            <span style="color:#6B7280">■ Neutro <?=$neu?>%</span>
            <span style="color:#F59E0B">■ Crítico <?=$crit?>%</span>
        </div>
        <?php if (!empty($sentiments['nota'])) : ?>
        <p style="font-size:.82rem;color:#555;margin-top:.5rem;margin-bottom:0;font-style:italic"><?= esc_html($sentiments['nota']) ?></p>
        <?php endif; ?>
        <?php else : ?>
        <p style="color:#999">Sem dados de sentimento.</p>
        <?php endif; ?>
    </div>

    <div class="ehei-card">
        <h2>🔑 Palavras-chave</h2>
        <?php foreach (array_slice($keywords??[],0,10) as $kw) :
            $peso = (int)($kw['peso'] ?? 5);
        ?>
        <div style="margin-bottom:.4rem">
            <div style="display:flex;justify-content:space-between;font-size:.8rem">
                <span><?= esc_html($kw['palavra']) ?></span>
                <span style="color:#999"><?= $peso ?>/10</span>
            </div>
            <div class="ehei-kw-bar"><div class="ehei-kw-fill" style="width:<?= $peso*10 ?>%"></div></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div><!-- grid -->

<!-- Últimas sugestões geradas -->
<div class="ehei-card">
    <h2>📰 Sugestões Recentes
        <a href="<?= admin_url('admin.php?page=ehei-suggestions') ?>" style="font-size:.8rem;font-weight:400;margin-left:auto">Ver todas →</a>
    </h2>
    <?php
    $sugs = ehei_get_suggestions('pending', '', 5);
    if (empty($sugs)) : ?>
    <p style="color:#999">Nenhuma sugestão pendente.</p>
    <?php else : foreach ($sugs as $s) :
        $prio = ['1'=>'alta','2'=>'media','3'=>'baixa'][$s->priority] ?? 'media';
        $icon = $type_icons[$s->type] ?? '📰';
    ?>
    <div class="ehei-sug-card <?= $prio ?>">
        <div style="display:flex;align-items:flex-start;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem">
            <span><?= $icon ?></span>
            <span class="ehei-sug-title"><?= esc_html($s->title) ?></span>
            <span class="ehei-badge <?= $prio ?>" style="margin-left:auto"><?= ucfirst($prio) ?></span>
            <span class="ehei-badge" style="background:#F3E8FF;color:#6B21A8"><?= esc_html($s->type) ?></span>
        </div>
        <div class="ehei-sug-desc"><?= esc_html(mb_substr($s->description,0,160)) ?>...</div>
        <div class="ehei-sug-just">💡 <?= esc_html(mb_substr($s->justification,0,140)) ?>...</div>
        <a href="<?= admin_url('admin.php?page=ehei-suggestions#sug-'.$s->id) ?>" class="button button-small">Ver completo →</a>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- Lacunas detectadas -->
<?php if (!empty($raw['lacunas_detectadas'])) : ?>
<div class="ehei-card">
    <h2>🔍 Lacunas Detectadas <span style="font-size:.75rem;font-weight:400;color:#777">(temas pouco explorados)</span></h2>
    <div style="display:flex;flex-wrap:wrap;gap:.4rem">
        <?php foreach ($raw['lacunas_detectadas'] as $lac) : ?>
        <span class="ehei-tag" style="background:#FEF3C7;color:#92400E">⚠️ <?= esc_html($lac) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php else : // Sem análise ?>
<div class="ehei-card" style="text-align:center;padding:2.5rem">
    <div style="font-size:3rem;margin-bottom:.75rem">🧠</div>
    <h2 style="color:#7C3AED">Nenhuma análise ainda</h2>
    <p style="color:#777;margin-bottom:1.25rem">Colete o conteúdo histórico da comunidade e clique em "Analisar agora" para gerar as primeiras sugestões editoriais.</p>
    <a href="<?= admin_url('admin.php?page=ehei-content') ?>" class="button button-primary" style="margin-right:.5rem">1. Coletar conteúdo</a>
    <form method="POST" style="display:inline">
        <input type="hidden" name="ehei_action" value="run_analysis">
        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
        <button type="submit" class="button" style="border-color:#7C3AED;color:#7C3AED">2. Analisar com IA</button>
    </form>
</div>
<?php endif; ?>

</div><!-- .wrap -->
<?php }

// ══════════════════════════════════════════════════════════════
// SUGESTÕES
// ══════════════════════════════════════════════════════════════
function ehei_admin_suggestions(): void {
    ehei_admin_styles();
    $nonce  = wp_create_nonce('ehei_admin');
    $filter = sanitize_key($_GET['status'] ?? '');
    $ftype  = sanitize_key($_GET['type']   ?? '');
    $sugs   = ehei_get_suggestions($filter, $ftype, 100);
    $type_icons = ['pauta'=>'📰','debate'=>'💬','derivacao'=>'🔄','oficina'=>'🛠️'];
    $status_labels = ['pending'=>'⏳ Pendente','approved'=>'✅ Aprovado','rejected'=>'❌ Rejeitado','in_production'=>'🎬 Em produção'];
    ?>
<div class="wrap ehei-wrap">
<h1>📰 Sugestões de Pauta</h1>
<?php if (!empty($_GET['updated'])) : ?><div class="notice notice-success"><p>✅ Atualizado!</p></div><?php endif; ?>

<!-- Filtros -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
    <?php foreach ([''=>'Todas','pending'=>'Pendentes','approved'=>'Aprovadas','rejected'=>'Rejeitadas','in_production'=>'Em produção'] as $v=>$l) : ?>
    <a href="<?= esc_url(add_query_arg(['status'=>$v,'type'=>$ftype])) ?>"
       class="button <?= $filter===$v?'button-primary':'' ?>"><?= $l ?></a>
    <?php endforeach; ?>
    <span style="margin-left:.5rem">|</span>
    <?php foreach ([''=>'Todos tipos','pauta'=>'📰 Pauta','debate'=>'💬 Debate','derivacao'=>'🔄 Derivação','oficina'=>'🛠️ Oficina'] as $v=>$l) : ?>
    <a href="<?= esc_url(add_query_arg(['type'=>$v,'status'=>$filter])) ?>"
       class="button <?= $ftype===$v?'button-primary':'' ?>"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<p style="color:#777;font-size:.85rem;margin-bottom:1rem"><?= count($sugs) ?> sugestão(ões) encontrada(s)</p>

<?php foreach ($sugs as $s) :
    $prio = ['1'=>'alta','2'=>'media','3'=>'baixa'][$s->priority] ?? 'media';
    $icon = $type_icons[$s->type] ?? '📰';
    $themes = json_decode($s->source_themes??'[]', true);
?>
<div class="ehei-sug-card <?= $prio ?>" id="sug-<?= $s->id ?>">
    <div style="display:flex;align-items:flex-start;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem">
        <span style="font-size:1.2rem"><?= $icon ?></span>
        <div class="ehei-sug-title" style="flex:1;min-width:200px"><?= esc_html($s->title) ?></div>
        <span class="ehei-badge <?= $prio ?>"><?= ucfirst($prio) ?> prioridade</span>
        <span class="ehei-badge" style="background:#F3E8FF;color:#6B21A8"><?= esc_html($s->type) ?></span>
        <span class="ehei-badge <?= esc_attr($s->status) ?>"><?= $status_labels[$s->status]??$s->status ?></span>
    </div>

    <div class="ehei-sug-desc"><?= esc_html($s->description) ?></div>

    <div class="ehei-sug-just">💡 <strong>Por que é relevante:</strong> <?= esc_html($s->justification) ?></div>

    <?php if ($themes) : ?>
    <div style="margin-bottom:.6rem">
        <?php foreach ($themes as $t) : ?><span class="ehei-tag">🏷 <?= esc_html($t) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($s->notes) : ?>
    <div style="background:#F7F2EB;padding:.5rem .75rem;border-radius:6px;font-size:.8rem;margin-bottom:.6rem;color:#7A5C44">
        <strong>Notas do editor:</strong> <?= esc_html($s->notes) ?>
    </div>
    <?php endif; ?>

    <!-- Ações -->
    <form method="POST" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="ehei_action" value="update_suggestion">
        <input type="hidden" name="suggestion_id" value="<?= $s->id ?>">
        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
        <div>
            <label style="font-size:.75rem;font-weight:700;color:#777;display:block;margin-bottom:2px">Status</label>
            <select name="status" style="padding:5px 8px;border:1px solid #ccc;border-radius:5px;font-size:.82rem">
                <?php foreach ($status_labels as $v=>$l) : ?>
                <option value="<?=$v?>" <?=$s->status===$v?'selected':''?>><?=$l?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:200px">
            <label style="font-size:.75rem;font-weight:700;color:#777;display:block;margin-bottom:2px">Notas (opcional)</label>
            <input type="text" name="notes" value="<?= esc_attr($s->notes??'') ?>"
                   placeholder="Observações do editor..." style="width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:5px;font-size:.82rem">
        </div>
        <button type="submit" class="button button-primary" style="align-self:flex-end">Salvar</button>
    </form>
</div>
<?php endforeach; ?>
<?php if (empty($sugs)) : ?>
<div class="ehei-card" style="text-align:center;padding:2rem">
    <p style="color:#999">Nenhuma sugestão encontrada com esses filtros.</p>
</div>
<?php endif; ?>
</div>
<?php }

// ══════════════════════════════════════════════════════════════
// CONTEÚDO MONITORADO
// ══════════════════════════════════════════════════════════════
function ehei_admin_content(): void {
    ehei_admin_styles();
    global $wpdb;
    $nonce  = wp_create_nonce('ehei_admin');
    $stats  = ehei_get_content_stats();
    $recent = $wpdb->get_results(
        "SELECT * FROM ehei_content_log ORDER BY collected_at DESC LIMIT 30"
    ) ?: [];
    $source_labels = [
        'peepso_post'    => '🤝 Post PeepSo',
        'peepso_comment' => '💬 Comentário PeepSo',
        'wp_comment'     => '💬 Comentário WP',
        'ehtm_material'  => '📁 Material EduHub',
        'ehtm_comment'   => '💬 Comentário EduHub',
    ];
    ?>
<div class="wrap ehei-wrap">
<h1>📡 Conteúdo Monitorado</h1>
<?php if (!empty($_GET['collected'])) : ?>
<div class="notice notice-success"><p>✅ <?= absint($_GET['collected']) ?> itens coletados!</p></div>
<?php endif; ?>

<div class="ehei-grid ehei-grid-4" style="margin-bottom:1.1rem">
    <div class="ehei-card ehei-stat"><div class="ehei-stat-n" style="color:#1a1a2e"><?= $stats['total'] ?></div><div class="ehei-stat-l">Total coletado</div></div>
    <div class="ehei-card ehei-stat"><div class="ehei-stat-n" style="color:#2D6A4F"><?= $stats['processed'] ?></div><div class="ehei-stat-l">Processados pela IA</div></div>
    <div class="ehei-card ehei-stat"><div class="ehei-stat-n" style="color:#C1440E"><?= $stats['recent'] ?></div><div class="ehei-stat-l">Últimos 7 dias</div></div>
    <div class="ehei-card ehei-stat"><div class="ehei-stat-n" style="color:#7C3AED"><?= $stats['total'] - $stats['processed'] ?></div><div class="ehei-stat-l">Aguardando análise</div></div>
</div>

<!-- Coletores por fonte -->
<div class="ehei-card">
    <h2>📊 Por fonte</h2>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <?php foreach ($stats['by_source'] as $s) : ?>
        <div style="background:#F7F2EB;padding:.5rem .9rem;border-radius:8px;text-align:center;min-width:120px">
            <div style="font-weight:700;font-size:1.2rem"><?= $s->total ?></div>
            <div style="font-size:.72rem;color:#7A5C44"><?= $source_labels[$s->source]??$s->source ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Coletar histórico -->
<div class="ehei-card">
    <h2>📥 Coletar conteúdo histórico</h2>
    <p style="font-size:.85rem;color:#777;margin-bottom:.75rem">Coleta posts, comentários e materiais dos últimos N dias para enriquecer a próxima análise.</p>
    <form method="POST" style="display:flex;gap:.5rem;align-items:flex-end">
        <input type="hidden" name="ehei_action" value="collect_history">
        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
        <div>
            <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px">Últimos</label>
            <select name="days" style="padding:6px 10px;border:1px solid #ccc;border-radius:5px">
                <option value="7">7 dias</option>
                <option value="14">14 dias</option>
                <option value="30" selected>30 dias</option>
                <option value="60">60 dias</option>
                <option value="90">90 dias</option>
            </select>
        </div>
        <button type="submit" class="button button-primary">📥 Coletar agora</button>
    </form>
</div>

<!-- Itens recentes -->
<div class="ehei-card">
    <h2>📋 Últimos itens coletados</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th style="width:130px">Fonte</th>
            <th>Conteúdo</th>
            <th style="width:130px">Coletado em</th>
            <th style="width:80px">Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($recent as $r) : ?>
        <tr>
            <td><span class="ehei-tag" style="white-space:nowrap"><?= $source_labels[$r->source]??$r->source ?></span></td>
            <td style="font-size:.82rem"><?= esc_html(mb_substr($r->content,0,120)) ?>...</td>
            <td style="font-size:.78rem;color:#777"><?= esc_html(substr($r->collected_at,0,16)) ?></td>
            <td><?= $r->processed ? '<span style="color:#065F46;font-weight:700;font-size:.75rem">✅ Analisado</span>' : '<span style="color:#92400E;font-size:.75rem">⏳ Pendente</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)) : ?><tr><td colspan="4" style="text-align:center;color:#999;padding:1.5rem">Nenhum conteúdo coletado ainda.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>
<?php }

// ══════════════════════════════════════════════════════════════
// CONFIGURAÇÕES
// ══════════════════════════════════════════════════════════════
function ehei_admin_settings(): void {
    ehei_admin_styles();
    $nonce = wp_create_nonce('ehei_admin');
    $key   = get_option('ehei_gemini_key','');
    $days  = get_option('ehei_analysis_days', 7);
    $next  = wp_next_scheduled('ehei_daily_analysis');
    $test  = sanitize_key($_GET['test'] ?? '');
    $tmsg  = urldecode($_GET['msg'] ?? '');
    ?>
<div class="wrap ehei-wrap">
<h1>⚙️ Configurações — Editorial IA</h1>
<?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success"><p>✅ Configurações salvas!</p></div><?php endif; ?>
<?php if ($test === 'ok') : ?><div class="notice notice-success"><p>✅ Conexão com Gemini bem-sucedida!</p></div><?php endif; ?>
<?php if ($test === 'fail') : ?><div class="notice notice-error"><p>❌ Falha na conexão: <?= esc_html($tmsg) ?></p></div><?php endif; ?>

<div class="ehei-card" style="max-width:640px">
    <h2>🤖 Integração Google Gemini</h2>
    <form method="POST">
        <input type="hidden" name="ehei_action" value="save_settings">
        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
        <table class="form-table">
            <tr>
                <th><label>Chave de API *</label></th>
                <td>
                    <input type="password" name="gemini_key" value="<?= esc_attr($key) ?>" class="regular-text" placeholder="AIzaSy...">
                    <p class="description">Começa com <code>AIza...</code> — obtenha em <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com</a></p>
                    <?php if ($key) : ?><p style="color:#065F46;font-size:.82rem;font-weight:700">✅ Chave configurada</p>
                    <?php else : ?><p style="color:#991B1B;font-size:.82rem">⚠️ Chave não configurada — análise com IA indisponível</p><?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label>Modelo</label></th>
                <td>
                    <code style="background:#f5f5f5;padding:4px 8px;border-radius:4px;font-size:.88rem">gemini-2.5-flash</code>
                    <p class="description">Gemini 1.5 Flash — rápido e eficiente para análise editorial</p>
                </td>
            </tr>
            <tr>
                <th><label>Janela de análise</label></th>
                <td>
                    <select name="analysis_days">
                        <?php foreach ([3=>'3 dias',7=>'7 dias (recomendado)',14=>'14 dias',30=>'30 dias'] as $d=>$l) : ?>
                        <option value="<?=$d?>" <?=$days==$d?'selected':''?>><?=$l?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Período de conteúdo analisado em cada rodada automática</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Salvar configurações'); ?>
    </form>
    <?php if ($key) : ?>
    <hr>
    <h3 style="font-size:.9rem;margin-top:.75rem">Testar conexão</h3>
    <form method="POST" style="display:inline">
        <input type="hidden" name="ehei_action" value="test_connection">
        <input type="hidden" name="_wpnonce" value="<?= $nonce ?>">
        <button type="submit" class="button">🔌 Testar API agora</button>
    </form>
    <?php endif; ?>
</div>

<div class="ehei-card" style="max-width:600px">
    <h2>⏰ Análise automática</h2>
    <p>A análise roda automaticamente todo dia às <strong>03:00</strong>.</p>
    <p>Próxima execução: <strong><?= $next ? date('d/m/Y H:i', $next) : 'Não agendada' ?></strong></p>
</div>

<div class="ehei-card" style="max-width:600px;background:#F7F2EB;border-color:#E0D5C5">
    <h2 style="color:#7A5C44">📖 Como funciona</h2>
    <ol style="color:#7A5C44;font-size:.88rem;line-height:2;padding-left:1.25rem;margin:0">
        <li>O plugin coleta posts e comentários do PeepSo, WP e materiais do EduHub</li>
        <li>A cada análise, o GPT-4o-mini processa o corpus e identifica temas emergentes</li>
        <li>São geradas sugestões de pautas, debates, derivações e oficinas</li>
        <li>O editor revisa, aprova ou rejeita cada sugestão no painel</li>
        <li>Sugestões aprovadas ficam disponíveis para a equipe de produção</li>
    </ol>
</div>
</div>
<?php }
