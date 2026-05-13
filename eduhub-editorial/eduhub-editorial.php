<?php
/**
 * Plugin Name: EduHub Editorial Intelligence
 * Description: Monitora posts, comentários e materiais da comunidade para sugerir pautas e novos conteúdos com base em IA.
 * Version:     1.0.0
 * Author:      EduHub
 * Text Domain: eduhub-editorial
 * Requires PHP: 8.0
 */
if ( ! defined('ABSPATH') ) exit;

define( 'EHEI_VERSION', '1.0.0' );
define( 'EHEI_PATH',    plugin_dir_path(__FILE__) );
define( 'EHEI_URL',     plugin_dir_url(__FILE__) );

// ── Ativação ──────────────────────────────────────────────────
register_activation_hook( __FILE__, 'ehei_activate' );
function ehei_activate(): void {
    ehei_create_tables();
    if ( ! wp_next_scheduled('ehei_daily_analysis') ) {
        wp_schedule_event( strtotime('tomorrow 03:00:00'), 'daily', 'ehei_daily_analysis' );
    }
}

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook('ehei_daily_analysis');
});

// ── Criação de tabelas ────────────────────────────────────────
function ehei_create_tables(): void {
    global $wpdb;
    $c = $wpdb->get_charset_collate();

    // Snapshots de conteúdo coletado
    $wpdb->query("CREATE TABLE IF NOT EXISTS `ehei_content_log` (
        `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `source`       VARCHAR(30) NOT NULL COMMENT 'peepso_post|peepso_comment|ehtm_material|ehtm_comment|wp_comment',
        `source_id`    BIGINT UNSIGNED NOT NULL,
        `author_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `content`      TEXT NOT NULL,
        `collected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `processed`    TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_source` (`source`, `source_id`),
        KEY `idx_processed` (`processed`),
        KEY `idx_collected` (`collected_at`)
    ) {$c}");

    // Análises geradas pela IA
    $wpdb->query("CREATE TABLE IF NOT EXISTS `ehei_analyses` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `period_start` DATE NOT NULL,
        `period_end`   DATE NOT NULL,
        `total_posts`  INT UNSIGNED NOT NULL DEFAULT 0,
        `themes`       LONGTEXT NOT NULL COMMENT 'JSON: temas detectados com frequência',
        `sentiments`   LONGTEXT NOT NULL COMMENT 'JSON: distribuição de sentimento',
        `keywords`     LONGTEXT NOT NULL COMMENT 'JSON: palavras-chave rankeadas',
        `raw_response` LONGTEXT NULL COMMENT 'Resposta bruta da IA',
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_period` (`period_start`, `period_end`)
    ) {$c}");

    // Sugestões de pauta geradas
    $wpdb->query("CREATE TABLE IF NOT EXISTS `ehei_suggestions` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `analysis_id`   INT UNSIGNED NULL,
        `type`          VARCHAR(30) NOT NULL DEFAULT 'pauta' COMMENT 'pauta|debate|derivacao|tema',
        `title`         VARCHAR(255) NOT NULL,
        `description`   TEXT NOT NULL,
        `justification` TEXT NOT NULL COMMENT 'Por que essa pauta é relevante',
        `source_themes` LONGTEXT NULL COMMENT 'JSON: temas que geraram esta sugestão',
        `priority`      TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1=alta 2=media 3=baixa',
        `status`        VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected|in_production',
        `reviewed_by`   BIGINT UNSIGNED NULL,
        `notes`         TEXT NULL COMMENT 'Notas do editor',
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_status`   (`status`),
        KEY `idx_type`     (`type`),
        KEY `idx_priority` (`priority`)
    ) {$c}");
}

// ── Bootstrap ─────────────────────────────────────────────────
add_action('plugins_loaded', function() {
    if (is_admin()) {
        require_once EHEI_PATH . 'admin-editorial.php';
    }

    // Hooks de coleta em tempo real
    add_action('ehei_daily_analysis', 'ehei_run_daily_analysis');

    // PeepSo: capturar novos posts
    add_action('peepso_activity_post_after_save', 'ehei_capture_peepso_post', 10, 2);
    add_action('peepso_activity_comment_after_save', 'ehei_capture_peepso_comment', 10, 2);

    // WordPress: capturar comentários
    add_action('comment_post', 'ehei_capture_wp_comment', 10, 2);

    // EduHub: capturar materiais aprovados
    add_action('ehtm_material_approved', 'ehei_capture_material', 10, 1);
});

// ══════════════════════════════════════════════════════════════
// COLETA DE CONTEÚDO
// ══════════════════════════════════════════════════════════════

function ehei_log_content( string $source, int $source_id, int $author_id, string $content ): void {
    if ( strlen(trim($content)) < 10 ) return;
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO ehei_content_log (source, source_id, author_id, content)
         VALUES (%s, %d, %d, %s)",
        $source, $source_id, $author_id, mb_substr($content, 0, 2000)
    ));
}

function ehei_capture_peepso_post( $post, $activity = null ): void {
    if ( is_object($post) ) {
        $content = '';
        if ( isset($post->act_feed_obj_data) ) {
            $data = is_string($post->act_feed_obj_data) ? json_decode($post->act_feed_obj_data, true) : (array)$post->act_feed_obj_data;
            $content = $data['status'] ?? $data['text'] ?? '';
        }
        if ( ! $content && isset($post->act_status) ) $content = $post->act_status;
        if ( $content ) {
            ehei_log_content('peepso_post', (int)($post->act_id ?? 0), (int)($post->act_user_id ?? 0), $content);
        }
    }
}

function ehei_capture_peepso_comment( $comment, $activity = null ): void {
    if ( is_object($comment) ) {
        $content = $comment->apc_comment ?? $comment->comment ?? '';
        if ( $content ) {
            ehei_log_content('peepso_comment', (int)($comment->apc_id ?? 0), (int)($comment->apc_user_id ?? 0), $content);
        }
    }
}

function ehei_capture_wp_comment( int $comment_id, $approved ): void {
    if ( $approved !== 1 ) return;
    $c = get_comment($comment_id);
    if ( $c && strlen($c->comment_content) >= 10 ) {
        ehei_log_content('wp_comment', $comment_id, (int)$c->user_id, $c->comment_content);
    }
}

function ehei_capture_material( int $material_id ): void {
    if ( ! function_exists('EduHubTurmas') ) return;
    $m = EduHubTurmas()->db->get_material($material_id);
    if ( $m && $m->description ) {
        ehei_log_content('ehtm_material', $material_id, (int)$m->uploaded_by, $m->title . ': ' . $m->description);
    }
}

// Coleta manual de conteúdo histórico
function ehei_collect_historical( int $days = 30 ): int {
    global $wpdb;
    $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $count = 0;

    // Comentários WordPress
    $comments = $wpdb->get_results($wpdb->prepare(
        "SELECT comment_ID, user_id, comment_content FROM {$wpdb->comments}
         WHERE comment_approved='1' AND comment_date >= %s AND LENGTH(comment_content) > 10",
        $since
    ));
    foreach ($comments as $c) {
        ehei_log_content('wp_comment', (int)$c->comment_ID, (int)$c->user_id, $c->comment_content);
        $count++;
    }

    // PeepSo activities — detectar colunas disponíveis antes de consultar
    if ( $wpdb->get_var("SHOW TABLES LIKE 'wp_peepso_activities'") ) {
        // Descobrir colunas reais da tabela
        $cols = $wpdb->get_results("SHOW COLUMNS FROM wp_peepso_activities", ARRAY_A);
        $col_names = array_column($cols, 'Field');

        // Mapear nomes possíveis das colunas
        $col_id       = in_array('act_id', $col_names)       ? 'act_id'        : (in_array('id', $col_names) ? 'id' : null);
        $col_user     = in_array('act_user_id', $col_names)   ? 'act_user_id'   : (in_array('act_owner_id', $col_names) ? 'act_owner_id' : (in_array('user_id', $col_names) ? 'user_id' : null));
        $col_data     = in_array('act_feed_obj_data', $col_names) ? 'act_feed_obj_data' : (in_array('act_data', $col_names) ? 'act_data' : null);
        $col_status   = in_array('act_status', $col_names)    ? 'act_status'    : (in_array('act_content', $col_names) ? 'act_content' : null);
        $col_time     = in_array('act_timestamp', $col_names) ? 'act_timestamp' : (in_array('created_at', $col_names) ? 'created_at' : (in_array('act_date', $col_names) ? 'act_date' : null));

        if ( $col_id && $col_time ) {
            $select = array_filter([$col_id, $col_user, $col_data, $col_status]);
            $sql    = $wpdb->prepare(
                "SELECT " . implode(', ', $select) . " FROM wp_peepso_activities WHERE {$col_time} >= %s LIMIT 200",
                $since
            );
            $activities = $wpdb->get_results($sql) ?: [];

            foreach ($activities as $a) {
                $post_content = '';
                if ($col_status && isset($a->$col_status)) $post_content = $a->$col_status;
                if (!$post_content && $col_data && isset($a->$col_data) && $a->$col_data) {
                    $d = json_decode($a->$col_data, true);
                    $post_content = $d['status'] ?? $d['text'] ?? $d['message'] ?? '';
                }
                if ($post_content) {
                    $aid = $col_id   ? (int)$a->$col_id   : 0;
                    $uid = $col_user ? (int)$a->$col_user : 0;
                    ehei_log_content('peepso_post', $aid, $uid, $post_content);
                    $count++;
                }
            }
        }

        // Comentários PeepSo
        if ( $wpdb->get_var("SHOW TABLES LIKE 'wp_peepso_activity_comments'") ) {
            $ccols     = $wpdb->get_results("SHOW COLUMNS FROM wp_peepso_activity_comments", ARRAY_A);
            $cc_names  = array_column($ccols, 'Field');
            $cc_id     = in_array('apc_id', $cc_names)      ? 'apc_id'      : (in_array('id', $cc_names) ? 'id' : null);
            $cc_user   = in_array('apc_user_id', $cc_names) ? 'apc_user_id' : (in_array('user_id', $cc_names) ? 'user_id' : null);
            $cc_text   = in_array('apc_comment', $cc_names) ? 'apc_comment' : (in_array('comment', $cc_names) ? 'comment' : (in_array('apc_content', $cc_names) ? 'apc_content' : null));
            $cc_time   = in_array('apc_timestamp', $cc_names) ? 'apc_timestamp' : (in_array('created_at', $cc_names) ? 'created_at' : null);

            if ($cc_id && $cc_text && $cc_time) {
                $pcomments = $wpdb->get_results($wpdb->prepare(
                    "SELECT {$cc_id}, " . ($cc_user??'0 AS user_id') . ", {$cc_text}
                     FROM wp_peepso_activity_comments
                     WHERE {$cc_time} >= %s AND LENGTH({$cc_text}) > 10 LIMIT 200", $since
                )) ?: [];
                foreach ($pcomments as $pc) {
                    ehei_log_content('peepso_comment', (int)$pc->$cc_id, $cc_user?(int)$pc->$cc_user:0, $pc->$cc_text);
                    $count++;
                }
            }
        }
    }

    // Materiais EduHub
    if ( function_exists('EduHubTurmas') ) {
        $materials = EduHubTurmas()->db->get_materials(['status'=>'approved','limit'=>200]);
        foreach ($materials as $m) {
            if ($m->description) {
                ehei_log_content('ehtm_material', (int)$m->id, (int)$m->uploaded_by, $m->title.': '.$m->description);
                $count++;
            }
        }
    }

    return $count;
}

// ══════════════════════════════════════════════════════════════
// ANÁLISE COM IA
// ══════════════════════════════════════════════════════════════

function ehei_run_daily_analysis(): array {
    global $wpdb;

    $api_key = get_option('ehei_gemini_key', '');
    if ( ! $api_key ) return ['error' => 'Chave Gemini não configurada. Acesse Admin → Editorial IA → Configurações.'];

    // Coletar conteúdo recente
    ehei_collect_historical(7);

    // Buscar conteúdo não processado dos últimos 7 dias
    $items = $wpdb->get_results(
        "SELECT * FROM ehei_content_log
         WHERE processed = 0
           AND collected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY collected_at DESC
         LIMIT 150"
    );

    if ( count($items) < 3 ) {
        return ['error' => 'Conteúdo insuficiente para análise (mínimo 3 itens).'];
    }

    // Montar texto para análise
    $texts = array_map(fn($i) => mb_substr($i->content, 0, 300), $items);
    $corpus = implode("\n---\n", $texts);

    $period_start = date('Y-m-d', strtotime('-7 days'));
    $period_end   = date('Y-m-d');

    // ── Prompt de análise ─────────────────────────────────────
    $n_items = count($items);
    $prompt = <<<PROMPT
Você é um editor pedagógico sênior especializado em educação midiática e comunicação comunitária.

CONTEXTO DO PROJETO:
- Projeto "Inclusão Midiática UFSCar e Etec Ibaté"
- Público: alunos e professores de escola pública brasileira
- Missão: produção audiovisual, jornalismo comunitário, alfabetização midiática
- Objetivo editorial: conteúdos que agreguem valor social, fortaleçam o senso crítico e documentem a realidade local
- Período analisado: {$period_start} a {$period_end} ({$n_items} registros)

CONTEÚDO DA COMUNIDADE (posts, comentários, descrições de materiais):
---
{$corpus}
---

INSTRUÇÕES:
1. Identifique temas emergentes com potencial educacional e social
2. Detecte padrões de interesse, preocupações e curiosidades da comunidade
3. Sugira pautas ORIGINAIS que derivem naturalmente do que a comunidade já está discutindo
4. Priorize temas que conectem realidade local com questões mais amplas da sociedade
5. Considere formatos variados: documentário, debate, podcast, reportagem, fotorreportagem, oficina
6. Aponte lacunas — o que a comunidade ainda não está discutindo mas deveria?

IMPORTANTE: Responda APENAS com JSON válido e bem formado, sem markdown, sem texto antes ou depois.

{
  "temas_emergentes": [
    {
      "tema": "nome do tema",
      "frequencia": 7,
      "exemplos": ["exemplo de frase ou contexto do corpus"],
      "potencial_educacional": "alto",
      "conexao_social": "como esse tema se conecta com questões mais amplas da sociedade"
    }
  ],
  "sentimento_geral": {
    "positivo": 45,
    "neutro": 35,
    "critico": 20,
    "nota": "frase descrevendo o clima geral da comunidade"
  },
  "palavras_chave": [
    {"palavra": "string", "peso": 8}
  ],
  "lacunas_detectadas": [
    "tema importante que aparece pouco ou está ausente das discussões"
  ],
  "sugestoes": [
    {
      "tipo": "pauta",
      "titulo": "Título jornalístico atraente",
      "descricao": "2-3 frases explicando a proposta editorial, o ângulo escolhido e o que torna esse conteúdo relevante",
      "justificativa": "Por que essa pauta agrega valor para a sociedade e para o processo educacional dos alunos",
      "abordagem": "Como explorar o tema: personagens, ângulos, fontes sugeridas",
      "temas_relacionados": ["tema1", "tema2"],
      "prioridade": "alta",
      "formato_sugerido": "documentario",
      "competencias_desenvolvidas": ["senso crítico", "escuta ativa"]
    }
  ],
  "insight_editorial": "Parágrafo de 4-6 frases com análise qualitativa do momento da comunidade, padrões identificados e recomendações estratégicas para o projeto editorial"
}
PROMPT;

    // Fallback automático entre modelos + retry para 503
    $models    = ['gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-flash-latest'];
    $raw       = '';
    $http_code = 0;
    $last_err  = '';

    foreach ( $models as $model ) {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

        for ( $try = 1; $try <= 2; $try++ ) {
            if ( $try > 1 ) sleep(5);

            $response = wp_remote_post($endpoint, [
                'timeout' => 90,
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'x-goog-api-key' => $api_key,
                ],
                'body' => json_encode([
                    'contents'         => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature'      => 0.4,
                        'maxOutputTokens'  => 2000,
                        'responseMimeType' => 'application/json',
                    ],
                ]),
            ]);

            if ( is_wp_error($response) ) { $last_err = $response->get_error_message(); continue; }

            $http_code = wp_remote_retrieve_response_code($response);
            $body_resp = wp_remote_retrieve_body($response);
            $data      = json_decode($body_resp, true);
            $raw       = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $last_err  = $data['error']['message'] ?? "HTTP {$http_code}";

            if ( $http_code === 200 && $raw ) break 2;
            if ( in_array($http_code, [401, 403, 404]) ) break 2; // erro permanente
        }

        if ( $http_code === 503 || $http_code === 429 ) continue; // tentar próximo modelo
        if ( $http_code === 200 && $raw ) break;
    }

    if ( ! $raw ) {
        return ['error' => "Serviço indisponível. Tente novamente em alguns minutos. (Detalhe: {$last_err})"];
    }

    // Limpar e parsear JSON da resposta
    $clean = $raw;

    // Tentar parsear direto primeiro (quando responseMimeType=application/json funciona)
    $result = json_decode($clean, true);

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        // Fallback: remover blocos markdown e escapes desnecessários
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $clean);
        $clean = preg_replace('/```\s*$/m', '', $clean);

        // Remover escapes de aspas duplas que o Gemini às vezes adiciona
        $clean = stripslashes($clean);

        // Extrair objeto JSON (do primeiro { ao último })
        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');
        if ( $start !== false && $end !== false && $end > $start ) {
            $clean = substr($clean, $start, $end - $start + 1);
        }

        $clean  = trim($clean);
        $result = json_decode($clean, true);
    }

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        error_log('[EHEI] JSON parse error: ' . json_last_error_msg() . ' | raw: ' . substr($raw, 0, 500));
        return ['error' => 'JSON inválido (' . json_last_error_msg() . '). Tente novamente.'];
    }

    if ( ! $result || ! isset($result['sugestoes']) ) {
        error_log('[EHEI] Resposta sem sugestoes: ' . substr($raw, 0, 300));
        return ['error' => 'Resposta da IA sem sugestões. Tente novamente.'];
    }

    // Salvar análise
    $wpdb->insert('ehei_analyses', [
        'period_start' => $period_start,
        'period_end'   => $period_end,
        'total_posts'  => count($items),
        'themes'       => json_encode($result['temas_emergentes'] ?? []),
        'sentiments'   => json_encode($result['sentimento_geral'] ?? []),
        'keywords'     => json_encode($result['palavras_chave'] ?? []),
        'raw_response' => $raw,
        'created_at'   => current_time('mysql'),
    ]);
    $analysis_id = (int)$wpdb->insert_id;

    // Salvar sugestões com campos extras
    $priority_map = ['alta'=>1,'media'=>2,'baixa'=>3];
    foreach ($result['sugestoes'] as $s) {
        // Enriquecer description com abordagem e competências se existirem
        $desc = $s['descricao'] ?? '';
        if (!empty($s['abordagem'])) {
            $desc .= "

**Abordagem sugerida:** " . $s['abordagem'];
        }
        if (!empty($s['competencias_desenvolvidas'])) {
            $desc .= "

**Competências:** " . implode(', ', (array)$s['competencias_desenvolvidas']);
        }
        $wpdb->insert('ehei_suggestions', [
            'analysis_id'   => $analysis_id,
            'type'          => sanitize_key($s['tipo'] ?? 'pauta'),
            'title'         => mb_substr($s['titulo'] ?? '', 0, 255),
            'description'   => $desc,
            'justification' => $s['justificativa'] ?? '',
            'source_themes' => json_encode(array_merge(
                $s['temas_relacionados'] ?? [],
                ['formato:'  . ($s['formato_sugerido'] ?? 'livre')]
            )),
            'priority'      => $priority_map[$s['prioridade'] ?? 'media'] ?? 2,
            'status'        => 'pending',
            'created_at'    => current_time('mysql'),
        ]);
    }

    // Marcar itens como processados
    $ids = implode(',', array_map(fn($i) => (int)$i->id, $items));
    $wpdb->query("UPDATE ehei_content_log SET processed=1 WHERE id IN ({$ids})");

    return [
        'analysis_id' => $analysis_id,
        'items'       => count($items),
        'suggestions' => count($result['sugestoes']),
        'insight'     => $result['insight_editorial'] ?? '',
        'result'      => $result,
    ];
}

// ── Buscar análises ───────────────────────────────────────────
function ehei_get_latest_analysis(): ?object {
    global $wpdb;
    return $wpdb->get_row("SELECT * FROM ehei_analyses ORDER BY id DESC LIMIT 1");
}

function ehei_get_suggestions( string $status = '', string $type = '', int $limit = 50 ): array {
    global $wpdb;
    $where = ['1=1'];
    $params = [];
    if ($status) { $where[] = 'status=%s'; $params[] = $status; }
    if ($type)   { $where[] = 'type=%s';   $params[] = $type; }
    $params[] = $limit;
    $sql = "SELECT * FROM ehei_suggestions WHERE " . implode(' AND ', $where) . " ORDER BY priority ASC, created_at DESC LIMIT %d";
    return $params ? ($wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: []) : ($wpdb->get_results($sql) ?: []);
}

function ehei_update_suggestion( int $id, string $status, string $notes = '' ): void {
    global $wpdb;
    $wpdb->update('ehei_suggestions', [
        'status'      => $status,
        'notes'       => sanitize_textarea_field($notes),
        'reviewed_by' => get_current_user_id(),
    ], ['id' => $id]);
}

function ehei_get_content_stats(): array {
    global $wpdb;
    $total     = (int)$wpdb->get_var("SELECT COUNT(*) FROM ehei_content_log");
    $processed = (int)$wpdb->get_var("SELECT COUNT(*) FROM ehei_content_log WHERE processed=1");
    $by_source = $wpdb->get_results("SELECT source, COUNT(*) AS total FROM ehei_content_log GROUP BY source") ?: [];
    $recent    = (int)$wpdb->get_var("SELECT COUNT(*) FROM ehei_content_log WHERE collected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    return compact('total','processed','by_source','recent');
}
