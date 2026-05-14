<?php
/**
 * Plugin Name: EduHub TV
 * Description: TV Escolar — grade por dia, classificação, analytics de audiência, player mobile-friendly.
 * Version:     2.1.0
 * Author:      EduHub
 * Text Domain: eduhub-tv
 * Requires PHP: 8.0
 */
if ( ! defined('ABSPATH') ) exit;

define( 'EHTV_VERSION', '2.1.0' );
define( 'EHTV_PATH',    plugin_dir_path(__FILE__) );
define( 'EHTV_URL',     plugin_dir_url(__FILE__) );
define( 'EHTV_TZ',      'America/Sao_Paulo' );

// ── Bootstrap ─────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    global $wpdb;
    if ( ! $wpdb->get_var("SHOW TABLES LIKE 'ehtm_tv_playlist'") ) {
        ehtv_create_tables();
    } else {
        ehtv_migrate_tables();
    }

    if ( is_admin() ) {
        require_once EHTV_PATH . 'admin-tv.php';
    }

    add_shortcode( 'ehtv_player', 'ehtv_shortcode_player' );
    add_action( 'admin_init', 'ehtv_handle_admin_actions' );

    foreach ( ['wp_ajax_ehtv_track_view', 'wp_ajax_nopriv_ehtv_track_view'] as $h ) {
        add_action( $h, 'ehtv_ajax_track_view' );
    }
    foreach ( ['wp_ajax_ehtv_heartbeat', 'wp_ajax_nopriv_ehtv_heartbeat'] as $h ) {
        add_action( $h, 'ehtv_ajax_heartbeat' );
    }
} );

register_activation_hook( __FILE__, 'ehtv_create_tables' );

// ── Timezone helpers ──────────────────────────────────────────
function ehtv_tz(): DateTimeZone {
    return new DateTimeZone( EHTV_TZ );
}

function ehtv_now(): DateTime {
    return new DateTime( 'now', ehtv_tz() );
}

function ehtv_today(): string {
    return ehtv_now()->format('Y-m-d');
}

/** Converte DATE Y-m-d + TIME H:i:s para Unix timestamp (SP) */
function ehtv_to_timestamp( string $date, string $time ): int {
    $dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $time, ehtv_tz() );
    return $dt ? $dt->getTimestamp() : 0;
}

function ehtv_seconds_to_hhmm( int $s ): string {
    return sprintf('%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
}

function ehtv_time_to_seconds( string $time ): int {
    $p = array_map('intval', explode(':', $time));
    return ($p[0] * 3600) + (($p[1] ?? 0) * 60) + ($p[2] ?? 0);
}

// ── Criação de tabelas ─────────────────────────────────────────
function ehtv_create_tables(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS `ehtm_tv_playlist` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sort_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `type`           VARCHAR(20) NOT NULL DEFAULT 'material',
        `material_id`    INT UNSIGNED NULL,
        `youtube_id`     VARCHAR(20) NULL,
        `external_url`   VARCHAR(512) NULL,
        `title`          VARCHAR(255) NOT NULL DEFAULT '',
        `description`    TEXT NULL,
        `tema`           VARCHAR(255) NULL,
        `classification` VARCHAR(30) NOT NULL DEFAULT 'conteudo',
        `thumbnail`      VARCHAR(512) NULL,
        `duration`       INT UNSIGNED NULL,
        `active`         TINYINT(1) NOT NULL DEFAULT 1,
        `added_by`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_sort`   (`sort_order`),
        KEY `idx_active` (`active`)
    ) {$charset}");

    // Tabela de agendamentos por data específica
    $wpdb->query("CREATE TABLE IF NOT EXISTS `ehtm_tv_schedule` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `playlist_id`   INT UNSIGNED NOT NULL,
        `schedule_date` DATE NOT NULL            COMMENT 'Data de exibição (fuso SP)',
        `start_time`    TIME NOT NULL            COMMENT 'Hora de início (fuso SP)',
        `active`        TINYINT(1) NOT NULL DEFAULT 1,
        `created_by`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unq_date_time` (`schedule_date`, `start_time`),
        KEY `idx_date` (`schedule_date`),
        KEY `idx_playlist` (`playlist_id`)
    ) {$charset}");

    // Tabela de audiência
    $wpdb->query("CREATE TABLE IF NOT EXISTS `ehtm_tv_views` (
        `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `playlist_id`    INT UNSIGNED NOT NULL,
        `session_key`    VARCHAR(64) NOT NULL,
        `started_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_heartbeat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `ended_at`       DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_playlist`  (`playlist_id`),
        KEY `idx_heartbeat` (`last_heartbeat`),
        KEY `idx_session`   (`session_key`),
        KEY `idx_started`   (`started_at`)
    ) {$charset}");
}

// ── Migração ──────────────────────────────────────────────────
function ehtv_migrate_tables(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $cols = $wpdb->get_col("DESCRIBE ehtm_tv_playlist", 0);
    if ( ! in_array('tema', $cols) ) {
        $wpdb->query("ALTER TABLE ehtm_tv_playlist ADD COLUMN `tema` VARCHAR(255) NULL AFTER `description`");
    }
    if ( ! in_array('classification', $cols) ) {
        $wpdb->query("ALTER TABLE ehtm_tv_playlist ADD COLUMN `classification` VARCHAR(30) NOT NULL DEFAULT 'conteudo' AFTER `tema`");
    }
    // Coluna legada — mantida para compat, mas scheduling agora usa ehtm_tv_schedule
    if ( in_array('scheduled_time', $cols) ) {
        // Migrar entradas legadas para a nova tabela (apenas se não existirem ainda)
        if ( $wpdb->get_var("SHOW TABLES LIKE 'ehtm_tv_schedule'") ) {
            $legacy = $wpdb->get_results(
                "SELECT id, scheduled_time FROM ehtm_tv_playlist WHERE scheduled_time IS NOT NULL AND active=1"
            );
            $today = ehtv_today();
            foreach ($legacy as $row) {
                // Criar entrada recorrente para os próximos 30 dias, se ainda não existe
                for ($d = 0; $d < 30; $d++) {
                    $dt = new DateTime($today, ehtv_tz());
                    $dt->modify("+{$d} day");
                    $date = $dt->format('Y-m-d');
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM ehtm_tv_schedule WHERE playlist_id=%d AND schedule_date=%s",
                        $row->id, $date
                    ));
                    if ( ! $existing ) {
                        $wpdb->insert('ehtm_tv_schedule', [
                            'playlist_id'   => $row->id,
                            'schedule_date' => $date,
                            'start_time'    => $row->scheduled_time,
                            'active'        => 1,
                            'created_by'    => 0,
                        ]);
                    }
                }
        	}
        }
        $wpdb->query("ALTER TABLE ehtm_tv_playlist DROP COLUMN `scheduled_time`");
    }

    if ( ! $wpdb->get_var("SHOW TABLES LIKE 'ehtm_tv_schedule'") ) {
        $wpdb->query("CREATE TABLE IF NOT EXISTS `ehtm_tv_schedule` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `playlist_id`   INT UNSIGNED NOT NULL,
            `schedule_date` DATE NOT NULL,
            `start_time`    TIME NOT NULL,
            `active`        TINYINT(1) NOT NULL DEFAULT 1,
            `created_by`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unq_date_time` (`schedule_date`, `start_time`),
            KEY `idx_date` (`schedule_date`),
            KEY `idx_playlist` (`playlist_id`)
        ) {$charset}");
    }

    if ( ! $wpdb->get_var("SHOW TABLES LIKE 'ehtm_tv_views'") ) {
        $wpdb->query("CREATE TABLE IF NOT EXISTS `ehtm_tv_views` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `playlist_id`    INT UNSIGNED NOT NULL,
            `session_key`    VARCHAR(64) NOT NULL,
            `started_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_heartbeat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ended_at`       DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_playlist`  (`playlist_id`),
            KEY `idx_heartbeat` (`last_heartbeat`),
            KEY `idx_session`   (`session_key`),
            KEY `idx_started`   (`started_at`)
        ) {$charset}");
    } else {
        $vcols = $wpdb->get_col("DESCRIBE ehtm_tv_views", 0);
        if ( ! in_array('idx_started', $vcols) ) {
            $wpdb->query("ALTER TABLE ehtm_tv_views ADD KEY `idx_started` (`started_at`)");
        }
    }
}

// ── Shortcode ─────────────────────────────────────────────────
function ehtv_shortcode_player( $atts ): string {
    ob_start();
    include EHTV_PATH . 'player.php';
    return ob_get_clean();
}

// ── Helpers: playlist, url, thumb ────────────────────────────
function ehtv_get_playlist(): array {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT * FROM ehtm_tv_playlist WHERE active=1 ORDER BY sort_order ASC"
    ) ?: [];
}

function ehtv_video_url( object $item ): string {
    if ( $item->type === 'youtube' && $item->youtube_id ) {
        return 'https://www.youtube.com/watch?v=' . $item->youtube_id;
    }
    if ( $item->type === 'material' && $item->material_id ) {
        return add_query_arg([
            'ehtm_file'  => $item->material_id,
            'ehtm_nonce' => wp_create_nonce('ehtm_file_' . $item->material_id),
        ], home_url('/'));
    }
    return $item->external_url ?? '';
}

function ehtv_thumb( object $item ): string {
    if ( $item->thumbnail ) return $item->thumbnail;
    if ( $item->type === 'youtube' && $item->youtube_id ) {
        return "https://img.youtube.com/vi/{$item->youtube_id}/mqdefault.jpg";
    }
    return '';
}

function ehtv_classification_label( string $c ): string {
    return match($c) {
        'propaganda_externa'       => 'Prop. Externa',
        'propaganda_institucional' => 'Prop. Institucional',
        default                    => 'Conteúdo',
    };
}

// ── Agendamentos para um dia específico ──────────────────────
/**
 * Retorna entradas agendadas para a data informada, com dados da playlist.
 * Horários no fuso América/São_Paulo.
 */
function ehtv_get_schedule_for_date( string $date ): array {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT s.*, p.title, p.description, p.tema, p.classification, p.type,
                p.youtube_id, p.external_url, p.material_id, p.thumbnail, p.duration
         FROM ehtm_tv_schedule s
         JOIN ehtm_tv_playlist p ON p.id = s.playlist_id
         WHERE s.schedule_date = %s AND s.active = 1 AND p.active = 1
         ORDER BY s.start_time ASC",
        $date
    ) ) ?: [];
}

// ── Grade do dia ──────────────────────────────────────────────
/**
 * Gera programação completa de 24h para uma data específica.
 * Itens fixos vêm de ehtm_tv_schedule, lacunas preenchidas com playlist em loop.
 *
 * @return array{ entries: array, gaps: array, loops: int, has_fixed: bool }
 */
function ehtv_build_day_schedule( string $date = '' ): array {
    global $wpdb;

    if ( ! $date ) $date = ehtv_today();

    $fixed_entries = ehtv_get_schedule_for_date($date);

    // Todos os itens ativos da playlist (para preencher lacunas)
    $all_items = $wpdb->get_results(
        "SELECT * FROM ehtm_tv_playlist WHERE active=1 ORDER BY sort_order ASC"
    ) ?: [];

    // IDs que estão fixados neste dia
    $fixed_ids = array_column($fixed_entries, 'playlist_id');
    $free_pool = array_values(array_filter($all_items, fn($i) => ! in_array($i->id, $fixed_ids)));

    $default_dur = 300; // 5 min p/ itens sem duração
    $day_sec     = 86400;
    $entries     = [];
    $gaps        = [];
    $loops       = 0;
    $cursor      = 0;
    $free_idx    = 0;
    $fi          = 0;

    while ( $cursor < $day_sec ) {
        $next_fixed_sec = isset($fixed_entries[$fi])
            ? ehtv_time_to_seconds($fixed_entries[$fi]->start_time)
            : $day_sec;

        // Preencher com conteúdo livre até o próximo fixo
        if ( $cursor < $next_fixed_sec && ! empty($free_pool) ) {
            $item = $free_pool[$free_idx % count($free_pool)];
            $dur  = (int)($item->duration ?: $default_dur);

            if ( $cursor + $dur <= $next_fixed_sec ) {
                $entries[] = [
                    'item'  => $item,
                    'start' => $cursor,
                    'end'   => $cursor + $dur,
                    'fixed' => false,
                    'loop'  => $loops,
                    'sched' => null,
                ];
                $cursor += $dur;
                $free_idx++;
                if ( $free_idx > 0 && $free_idx % count($free_pool) === 0 ) $loops++;
            } else {
                // Não cabe inteiro: registrar lacuna e avançar
                if ( $cursor < $next_fixed_sec ) {
                    $gaps[] = ['start' => $cursor, 'end' => $next_fixed_sec, 'duration' => $next_fixed_sec - $cursor];
                }
                $cursor = $next_fixed_sec;
            }
        } elseif ( $cursor < $next_fixed_sec ) {
            // Sem conteúdo livre: gap
            $gaps[] = ['start' => $cursor, 'end' => $next_fixed_sec, 'duration' => $next_fixed_sec - $cursor];
            $cursor = $next_fixed_sec;
        }

        // Inserir item fixo
        if ( isset($fixed_entries[$fi]) ) {
            $sched = $fixed_entries[$fi];
            $dur   = (int)($sched->duration ?: $default_dur);
            $entries[] = [
                'item'  => $sched,
                'start' => $next_fixed_sec,
                'end'   => $next_fixed_sec + $dur,
                'fixed' => true,
                'loop'  => 0,
                'sched' => $sched,
            ];
            $cursor = $next_fixed_sec + $dur;
            $fi++;
        }

        // Sem conteúdo livre e sem mais fixos: terminar
        if ( empty($free_pool) && $fi >= count($fixed_entries) ) break;
    }

    return [
        'entries'   => $entries,
        'gaps'      => $gaps,
        'loops'     => $loops,
        'has_fixed' => count($fixed_entries) > 0,
    ];
}

// ── AJAX: registrar visualização ──────────────────────────────
function ehtv_ajax_track_view(): void {
    $playlist_id = absint($_POST['playlist_id'] ?? 0);
    $session_key = sanitize_text_field($_POST['session_key'] ?? '');
    if ( ! $playlist_id || ! $session_key ) { wp_send_json_error([], 400); return; }

    global $wpdb;
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM ehtm_tv_views
         WHERE playlist_id=%d AND session_key=%s AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
         LIMIT 1",
        $playlist_id, $session_key
    ) );

    if ( ! $existing ) {
        $wpdb->insert('ehtm_tv_views', [
            'playlist_id'    => $playlist_id,
            'session_key'    => $session_key,
            'started_at'     => current_time('mysql'),
            'last_heartbeat' => current_time('mysql'),
        ]);
        wp_send_json_success(['view_id' => (int)$wpdb->insert_id]);
    } else {
        $wpdb->update('ehtm_tv_views',
            ['last_heartbeat' => current_time('mysql')],
            ['id' => $existing]
        );
        wp_send_json_success(['view_id' => (int)$existing]);
    }
}

// ── AJAX: heartbeat ───────────────────────────────────────────
function ehtv_ajax_heartbeat(): void {
    $view_id     = absint($_POST['view_id'] ?? 0);
    $session_key = sanitize_text_field($_POST['session_key'] ?? '');
    if ( ! $view_id || ! $session_key ) { wp_send_json_error([], 400); return; }

    global $wpdb;
    $wpdb->update('ehtm_tv_views',
        ['last_heartbeat' => current_time('mysql')],
        ['id' => $view_id, 'session_key' => $session_key]
    );
    wp_send_json_success();
}

// ── Processar ações do painel ─────────────────────────────────
function ehtv_handle_admin_actions(): void {
    if ( ! isset($_POST['ehtv_action']) ) return;
    if ( ! current_user_can('manage_options') && ! current_user_can('eduhub_approve_content') ) return;
    if ( ! wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ehtv_admin') ) wp_die('Nonce inválido.');

    global $wpdb;
    $action   = sanitize_key($_POST['ehtv_action']);
    $back_tab = sanitize_key($_POST['_tab'] ?? 'playlist');
    $back     = admin_url('admin.php?page=ehtv&tab=' . $back_tab);

    // Campos comuns
    $meta = [
        'tema'           => sanitize_text_field($_POST['tema'] ?? ''),
        'classification' => sanitize_key($_POST['classification'] ?? 'conteudo'),
        'description'    => sanitize_textarea_field($_POST['description'] ?? ''),
    ];

    // ── Adicionar YouTube
    if ( $action === 'add_youtube' ) {
        $raw = sanitize_text_field($_POST['youtube_url'] ?? '');
        $vid = ehtv_extract_youtube_id($raw);
        if ( $vid ) {
            $title = sanitize_text_field($_POST['title'] ?? '') ?: 'Vídeo YouTube';
            $max   = (int)($wpdb->get_var("SELECT MAX(sort_order) FROM ehtm_tv_playlist") ?? 0);
            $wpdb->insert('ehtm_tv_playlist', array_merge([
                'type'       => 'youtube',
                'youtube_id' => $vid,
                'title'      => $title,
                'thumbnail'  => "https://img.youtube.com/vi/{$vid}/mqdefault.jpg",
                'sort_order' => $max + 1,
                'active'     => 1,
                'added_by'   => get_current_user_id(),
            ], $meta));
            wp_redirect($back . '&msg=added'); exit;
        }
        wp_redirect($back . '&err=invalid_yt'); exit;
    }

    // ── Adicionar URL externa
    if ( $action === 'add_url' ) {
        $url   = esc_url_raw($_POST['video_url'] ?? '');
        $title = sanitize_text_field($_POST['title'] ?? '');
        $thumb = esc_url_raw($_POST['thumbnail'] ?? '');
        if ( $url && $title ) {
            $max = (int)($wpdb->get_var("SELECT MAX(sort_order) FROM ehtm_tv_playlist") ?? 0);
            $wpdb->insert('ehtm_tv_playlist', array_merge([
                'type'         => 'url',
                'external_url' => $url,
                'title'        => $title,
                'thumbnail'    => $thumb ?: null,
                'sort_order'   => $max + 1,
                'active'       => 1,
                'added_by'     => get_current_user_id(),
            ], $meta));
            wp_redirect($back . '&msg=added'); exit;
        }
        wp_redirect($back . '&err=missing_fields'); exit;
    }

    // ── Adicionar material do banco EduHub
    if ( $action === 'add_material' ) {
        $mid = absint($_POST['material_id'] ?? 0);
        if ( $mid && function_exists('EduHubTurmas') ) {
            $m = EduHubTurmas()->db->get_material($mid);
            if ( $m && $m->status === 'approved' ) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM ehtm_tv_playlist WHERE material_id=%d", $mid
                ));
                if ( ! $exists ) {
                    $max = (int)($wpdb->get_var("SELECT MAX(sort_order) FROM ehtm_tv_playlist") ?? 0);
                    $title_custom = sanitize_text_field($_POST['title'] ?? '');
                    $desc_custom  = sanitize_textarea_field($_POST['description'] ?? '');
                    $wpdb->insert('ehtm_tv_playlist', array_merge([
                        'type'        => 'material',
                        'material_id' => $mid,
                        'title'       => $title_custom ?: $m->title,
                        'description' => $desc_custom  ?: $m->description,
                        'sort_order'  => $max + 1,
                        'active'      => 1,
                        'added_by'    => get_current_user_id(),
                    ], ['tema' => $meta['tema'], 'classification' => $meta['classification']]));
                }
                wp_redirect($back . '&msg=added'); exit;
            }
        }
        wp_redirect($back . '&err=invalid_material'); exit;
    }

    // ── Remover item da playlist
    if ( $action === 'remove' ) {
        $id = absint($_POST['item_id'] ?? 0);
        if ( $id ) {
            $wpdb->delete('ehtm_tv_playlist', ['id' => $id]);
            $wpdb->delete('ehtm_tv_schedule', ['playlist_id' => $id]);
        }
        wp_redirect($back . '&msg=removed'); exit;
    }

    // ── Ativar/desativar item
    if ( $action === 'toggle' ) {
        $id  = absint($_POST['item_id'] ?? 0);
        $val = absint($_POST['active'] ?? 0);
        if ( $id ) $wpdb->update('ehtm_tv_playlist', ['active' => $val ? 0 : 1], ['id' => $id]);
        wp_redirect($back); exit;
    }

    // ── Reordenar
    if ( $action === 'reorder' ) {
        $orders = $_POST['sort_order'] ?? [];
        foreach ($orders as $id => $order) {
            $wpdb->update('ehtm_tv_playlist', ['sort_order' => absint($order)], ['id' => absint($id)]);
        }
        wp_redirect($back . '&msg=reordered'); exit;
    }

    // ── Editar item completo
    if ( $action === 'edit' ) {
        $id    = absint($_POST['item_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        if ( $id && $title ) {
            $wpdb->update('ehtm_tv_playlist', array_merge(['title' => $title], $meta), ['id' => $id]);
        }
        wp_redirect($back . '&msg=saved'); exit;
    }

    // ── Adicionar agendamento na grade
    if ( $action === 'add_schedule' ) {
        $playlist_id = absint($_POST['playlist_id'] ?? 0);
        $sched_date  = sanitize_text_field($_POST['schedule_date'] ?? '');
        $start_time  = sanitize_text_field($_POST['start_time'] ?? '');

        // Validar data e hora
        $dt_valid = DateTime::createFromFormat('Y-m-d', $sched_date, ehtv_tz());
        $tm_valid = preg_match('/^\d{2}:\d{2}$/', $start_time);

        if ( $playlist_id && $dt_valid && $tm_valid ) {
            $time_full = $start_time . ':00';
            // Verificar se já existe outro item nesse slot
            $conflict = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM ehtm_tv_schedule WHERE schedule_date=%s AND start_time=%s",
                $sched_date, $time_full
            ));
            if ( $conflict ) {
                // Substituir
                $wpdb->update('ehtm_tv_schedule',
                    ['playlist_id' => $playlist_id, 'active' => 1, 'created_by' => get_current_user_id()],
                    ['id' => $conflict]
                );
            } else {
                $wpdb->insert('ehtm_tv_schedule', [
                    'playlist_id'   => $playlist_id,
                    'schedule_date' => $sched_date,
                    'start_time'    => $time_full,
                    'active'        => 1,
                    'created_by'    => get_current_user_id(),
                ]);
            }
            wp_redirect($back . '&grade_date=' . urlencode($sched_date) . '&msg=scheduled'); exit;
        }
        wp_redirect($back . '&err=invalid_schedule'); exit;
    }

    // ── Remover agendamento da grade
    if ( $action === 'remove_schedule' ) {
        $id         = absint($_POST['schedule_id'] ?? 0);
        $grade_date = sanitize_text_field($_POST['grade_date'] ?? '');
        if ( $id ) $wpdb->delete('ehtm_tv_schedule', ['id' => $id]);
        $url = $back . ($grade_date ? '&grade_date=' . urlencode($grade_date) : '') . '&msg=removed';
        wp_redirect($url); exit;
    }

    // ── Copiar agendamentos para múltiplos dias
    if ( $action === 'copy_schedule' ) {
        $source_date  = sanitize_text_field($_POST['source_date'] ?? '');
        $target_dates = array_map('sanitize_text_field', $_POST['target_dates'] ?? []);
        if ( $source_date && ! empty($target_dates) ) {
            $source_entries = ehtv_get_schedule_for_date($source_date);
            foreach ($target_dates as $tdate) {
                $dt_v = DateTime::createFromFormat('Y-m-d', $tdate, ehtv_tz());
                if ( ! $dt_v ) continue;
                foreach ($source_entries as $entry) {
                    $conflict = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM ehtm_tv_schedule WHERE schedule_date=%s AND start_time=%s",
                        $tdate, $entry->start_time
                    ));
                    if ( ! $conflict ) {
                        $wpdb->insert('ehtm_tv_schedule', [
                            'playlist_id'   => $entry->playlist_id,
                            'schedule_date' => $tdate,
                            'start_time'    => $entry->start_time,
                            'active'        => 1,
                            'created_by'    => get_current_user_id(),
                        ]);
                    }
                }
            }
        }
        wp_redirect($back . '&grade_date=' . urlencode($source_date) . '&msg=copied'); exit;
    }
}

// ── Extrair ID do YouTube ─────────────────────────────────────
function ehtv_extract_youtube_id( string $url ): string {
    $patterns = [
        '/youtu\.be\/([a-zA-Z0-9_-]{11})/',
        '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
        '/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',
        '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
        '/youtube\.com\/v\/([a-zA-Z0-9_-]{11})/',
    ];
    foreach ($patterns as $p) {
        if ( preg_match($p, $url, $m) ) return $m[1];
    }
    if ( preg_match('/^[a-zA-Z0-9_-]{11}$/', trim($url)) ) return trim($url);
    return '';
}
