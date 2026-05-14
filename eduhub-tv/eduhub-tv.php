<?php
/**
 * Plugin Name: EduHub TV
 * Description: TV Escolar — classificação de conteúdo, grade do dia, analytics de audiência, player mobile-friendly.
 * Version:     2.0.0
 * Author:      EduHub
 * Text Domain: eduhub-tv
 * Requires PHP: 8.0
 */
if ( ! defined('ABSPATH') ) exit;

define( 'EHTV_VERSION', '2.0.0' );
define( 'EHTV_PATH',    plugin_dir_path(__FILE__) );
define( 'EHTV_URL',     plugin_dir_url(__FILE__) );

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

    // AJAX analytics
    foreach ( ['wp_ajax_ehtv_track_view', 'wp_ajax_nopriv_ehtv_track_view'] as $hook ) {
        add_action( $hook, 'ehtv_ajax_track_view' );
    }
    foreach ( ['wp_ajax_ehtv_heartbeat', 'wp_ajax_nopriv_ehtv_heartbeat'] as $hook ) {
        add_action( $hook, 'ehtv_ajax_heartbeat' );
    }
} );

register_activation_hook( __FILE__, 'ehtv_create_tables' );

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
        `scheduled_time` TIME NULL,
        `thumbnail`      VARCHAR(512) NULL,
        `duration`       INT UNSIGNED NULL,
        `active`         TINYINT(1) NOT NULL DEFAULT 1,
        `added_by`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_sort`   (`sort_order`),
        KEY `idx_active` (`active`)
    ) {$charset}");

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
        KEY `idx_session`   (`session_key`)
    ) {$charset}");
}

// ── Migração para instalações existentes ──────────────────────
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
    if ( ! in_array('scheduled_time', $cols) ) {
        $wpdb->query("ALTER TABLE ehtm_tv_playlist ADD COLUMN `scheduled_time` TIME NULL AFTER `classification`");
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
            KEY `idx_session`   (`session_key`)
        ) {$charset}");
    }
}

// ── Shortcode: player público ─────────────────────────────────
function ehtv_shortcode_player( $atts ): string {
    ob_start();
    include EHTV_PATH . 'player.php';
    return ob_get_clean();
}

// ── Helper: playlist ativa ────────────────────────────────────
function ehtv_get_playlist(): array {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT * FROM ehtm_tv_playlist WHERE active=1 ORDER BY sort_order ASC"
    ) ?: [];
}

// ── Helper: URL do vídeo ──────────────────────────────────────
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

// ── Helper: thumbnail ─────────────────────────────────────────
function ehtv_thumb( object $item ): string {
    if ( $item->thumbnail ) return $item->thumbnail;
    if ( $item->type === 'youtube' && $item->youtube_id ) {
        return "https://img.youtube.com/vi/{$item->youtube_id}/mqdefault.jpg";
    }
    return '';
}

// ── Helper: label de classificação ───────────────────────────
function ehtv_classification_label( string $c ): string {
    return match($c) {
        'propaganda_externa'       => 'Prop. Externa',
        'propaganda_institucional' => 'Prop. Institucional',
        default                    => 'Conteúdo',
    };
}

// ── Helper: converter TIME para segundos ──────────────────────
function ehtv_time_to_seconds( string $time ): int {
    $parts = array_map('intval', explode(':', $time));
    return ($parts[0] * 3600) + (($parts[1] ?? 0) * 60) + ($parts[2] ?? 0);
}

function ehtv_seconds_to_hhmm( int $s ): string {
    return sprintf('%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
}

// ── Gerar grade do dia ────────────────────────────────────────
function ehtv_build_day_schedule(): array {
    global $wpdb;
    $items = $wpdb->get_results(
        "SELECT * FROM ehtm_tv_playlist WHERE active=1 ORDER BY sort_order ASC"
    ) ?: [];

    if ( empty($items) ) return ['entries' => [], 'gaps' => [], 'loops' => 0];

    $default_dur = 300; // 5 min para itens sem duração definida
    $day_sec     = 86400;
    $entries     = [];
    $gaps        = [];
    $loops       = 0;

    // Separar fixos e livres
    $fixed = array_values(array_filter($items, fn($i) => !empty($i->scheduled_time)));
    $free  = array_values(array_filter($items, fn($i) => empty($i->scheduled_time)));
    usort($fixed, fn($a,$b) => ehtv_time_to_seconds($a->scheduled_time) - ehtv_time_to_seconds($b->scheduled_time));

    $cursor   = 0;
    $free_idx = 0;
    $fi       = 0; // ponteiro dos itens fixos

    while ( $cursor < $day_sec ) {
        // Próximo item fixo?
        $next_fixed_sec = ( $fi < count($fixed) )
            ? ehtv_time_to_seconds($fixed[$fi]->scheduled_time)
            : $day_sec;

        // Preencher com conteúdo livre até o próximo fixo
        while ( $cursor < $next_fixed_sec && ! empty($free) ) {
            $item = $free[$free_idx % count($free)];
            $dur  = (int)($item->duration ?: $default_dur);

            if ( $cursor + $dur <= $next_fixed_sec ) {
                $entries[] = [
                    'item'  => $item,
                    'start' => $cursor,
                    'end'   => $cursor + $dur,
                    'fixed' => false,
                    'loop'  => $loops,
                ];
                $cursor += $dur;
                $free_idx++;
                // Se completou uma volta
                if ( $free_idx > 0 && $free_idx % count($free) === 0 ) $loops++;
            } else {
                // Não cabe: registrar lacuna
                if ( $cursor < $next_fixed_sec ) {
                    $gaps[] = [
                        'start'    => $cursor,
                        'end'      => $next_fixed_sec,
                        'duration' => $next_fixed_sec - $cursor,
                    ];
                }
                $cursor = $next_fixed_sec;
            }
        }

        // Lacuna caso sem conteúdo livre
        if ( $cursor < $next_fixed_sec ) {
            $gaps[] = [
                'start'    => $cursor,
                'end'      => $next_fixed_sec,
                'duration' => $next_fixed_sec - $cursor,
            ];
            $cursor = $next_fixed_sec;
        }

        // Inserir item fixo
        if ( $fi < count($fixed) ) {
            $item = $fixed[$fi];
            $dur  = (int)($item->duration ?: $default_dur);
            $entries[] = [
                'item'  => $item,
                'start' => $next_fixed_sec,
                'end'   => $next_fixed_sec + $dur,
                'fixed' => true,
                'loop'  => 0,
            ];
            $cursor = $next_fixed_sec + $dur;
            $fi++;
        }

        // Sem conteúdo livre e sem fixos: parar para evitar loop infinito
        if ( empty($free) && $fi >= count($fixed) ) break;
    }

    return ['entries' => $entries, 'gaps' => $gaps, 'loops' => $loops];
}

// ── AJAX: registrar visualização ──────────────────────────────
function ehtv_ajax_track_view(): void {
    $playlist_id = absint($_POST['playlist_id'] ?? 0);
    $session_key = sanitize_text_field($_POST['session_key'] ?? '');
    if ( ! $playlist_id || ! $session_key ) wp_send_json_error([], 400);

    global $wpdb;
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM ehtm_tv_views
         WHERE playlist_id=%d AND session_key=%s AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
         LIMIT 1",
        $playlist_id, $session_key
    ));

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
    if ( ! $view_id || ! $session_key ) wp_send_json_error([], 400);

    global $wpdb;
    $wpdb->update(
        'ehtm_tv_views',
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
    $action = sanitize_key($_POST['ehtv_action']);
    $back   = admin_url('admin.php?page=ehtv');

    // Campos comuns de metadados
    $common_fields = function() use ($wpdb): array {
        return [
            'tema'           => sanitize_text_field($_POST['tema'] ?? ''),
            'classification' => sanitize_key($_POST['classification'] ?? 'conteudo'),
            'description'    => sanitize_textarea_field($_POST['description'] ?? ''),
            'scheduled_time' => sanitize_text_field($_POST['scheduled_time'] ?? '') ?: null,
        ];
    };

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
            ], $common_fields()));
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
            ], $common_fields()));
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
                    $cf  = $common_fields();
                    $wpdb->insert('ehtm_tv_playlist', array_merge([
                        'type'        => 'material',
                        'material_id' => $mid,
                        'title'       => sanitize_text_field($_POST['title'] ?? '') ?: $m->title,
                        'description' => $cf['description'] ?: $m->description,
                        'sort_order'  => $max + 1,
                        'active'      => 1,
                        'added_by'    => get_current_user_id(),
                    ], ['tema' => $cf['tema'], 'classification' => $cf['classification'], 'scheduled_time' => $cf['scheduled_time']]));
                }
                wp_redirect($back . '&msg=added'); exit;
            }
        }
        wp_redirect($back . '&err=invalid_material'); exit;
    }

    // ── Remover item
    if ( $action === 'remove' ) {
        $id = absint($_POST['item_id'] ?? 0);
        if ($id) $wpdb->delete('ehtm_tv_playlist', ['id' => $id]);
        wp_redirect($back . '&msg=removed'); exit;
    }

    // ── Ativar/desativar item
    if ( $action === 'toggle' ) {
        $id  = absint($_POST['item_id'] ?? 0);
        $val = absint($_POST['active'] ?? 0);
        if ($id) $wpdb->update('ehtm_tv_playlist', ['active' => $val ? 0 : 1], ['id' => $id]);
        wp_redirect($back . '&tab=' . sanitize_key($_POST['_tab'] ?? 'playlist')); exit;
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
        $time  = sanitize_text_field($_POST['scheduled_time'] ?? '');
        if ( $id && $title ) {
            $wpdb->update('ehtm_tv_playlist', array_merge(
                ['title' => $title],
                $common_fields(),
                ['scheduled_time' => $time ?: null]
            ), ['id' => $id]);
        }
        wp_redirect($back . '&msg=saved'); exit;
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
