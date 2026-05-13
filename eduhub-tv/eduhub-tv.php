<?php
/**
 * Plugin Name: EduHub TV
 * Description: TV Escolar — playlist pública com autoplay, loop, suporte a vídeos do banco EduHub e YouTube.
 * Version:     1.0.0
 * Author:      EduHub
 * Text Domain: eduhub-tv
 * Requires PHP: 8.0
 */
if ( ! defined('ABSPATH') ) exit;

define( 'EHTV_VERSION', '1.0.0' );
define( 'EHTV_PATH',    plugin_dir_path(__FILE__) );
define( 'EHTV_URL',     plugin_dir_url(__FILE__) );

// ── Bootstrap ─────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    // Criar tabela se não existir
    global $wpdb;
    if ( ! $wpdb->get_var("SHOW TABLES LIKE 'ehtm_tv_playlist'") ) {
        ehtv_create_table();
    }

    // Admin
    if ( is_admin() ) {
        require_once EHTV_PATH . 'admin-tv.php';
    }

    // Shortcodes
    add_shortcode( 'ehtv_player', 'ehtv_shortcode_player' );

    // Processar ações admin POST
    add_action( 'admin_init', 'ehtv_handle_admin_actions' );
} );

register_activation_hook( __FILE__, 'ehtv_create_table' );

function ehtv_create_table(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $wpdb->query("CREATE TABLE IF NOT EXISTS `ehtm_tv_playlist` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sort_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `type`         VARCHAR(20) NOT NULL DEFAULT 'material',
        `material_id`  INT UNSIGNED NULL,
        `youtube_id`   VARCHAR(20) NULL,
        `external_url` VARCHAR(512) NULL,
        `title`        VARCHAR(255) NOT NULL DEFAULT '',
        `description`  TEXT NULL,
        `thumbnail`    VARCHAR(512) NULL,
        `duration`     INT UNSIGNED NULL,
        `active`       TINYINT(1) NOT NULL DEFAULT 1,
        `added_by`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_sort` (`sort_order`),
        KEY `idx_active` (`active`)
    ) {$charset}");
}

// ── Shortcode: player público ─────────────────────────────────
function ehtv_shortcode_player( $atts ): string {
    ob_start();
    include EHTV_PATH . 'player.php';
    return ob_get_clean();
}

// ── Helper: obter playlist ativas ─────────────────────────────
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

// ── Processar ações do painel ─────────────────────────────────
function ehtv_handle_admin_actions(): void {
    if ( ! isset($_POST['ehtv_action']) ) return;
    if ( ! current_user_can('manage_options') && ! current_user_can('eduhub_approve_content') ) return;
    if ( ! wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ehtv_admin') ) wp_die('Nonce inválido.');

    global $wpdb;
    $action = sanitize_key($_POST['ehtv_action']);
    $back   = admin_url('admin.php?page=ehtv');

    // ── Adicionar YouTube
    if ( $action === 'add_youtube' ) {
        $raw = sanitize_text_field($_POST['youtube_url'] ?? '');
        $vid = ehtv_extract_youtube_id($raw);
        if ( $vid ) {
            $title = sanitize_text_field($_POST['title'] ?? 'Vídeo YouTube');
            $max   = (int)($wpdb->get_var("SELECT MAX(sort_order) FROM ehtm_tv_playlist") ?? 0);
            $wpdb->insert('ehtm_tv_playlist', [
                'type'       => 'youtube',
                'youtube_id' => $vid,
                'title'      => $title ?: 'Vídeo YouTube',
                'thumbnail'  => "https://img.youtube.com/vi/{$vid}/mqdefault.jpg",
                'sort_order' => $max + 1,
                'active'     => 1,
                'added_by'   => get_current_user_id(),
            ]);
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
            $wpdb->insert('ehtm_tv_playlist', [
                'type'         => 'url',
                'external_url' => $url,
                'title'        => $title,
                'thumbnail'    => $thumb ?: null,
                'sort_order'   => $max + 1,
                'active'       => 1,
                'added_by'     => get_current_user_id(),
            ]);
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
                // Verificar se já está na playlist
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM ehtm_tv_playlist WHERE material_id=%d", $mid
                ));
                if ( ! $exists ) {
                    $max = (int)($wpdb->get_var("SELECT MAX(sort_order) FROM ehtm_tv_playlist") ?? 0);
                    $wpdb->insert('ehtm_tv_playlist', [
                        'type'        => 'material',
                        'material_id' => $mid,
                        'title'       => $m->title,
                        'description' => $m->description,
                        'sort_order'  => $max + 1,
                        'active'      => 1,
                        'added_by'    => get_current_user_id(),
                    ]);
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
        wp_redirect($back); exit;
    }

    // ── Reordenar (salvo via form com sort_order[id]=ordem)
    if ( $action === 'reorder' ) {
        $orders = $_POST['sort_order'] ?? [];
        foreach ($orders as $id => $order) {
            $wpdb->update('ehtm_tv_playlist', ['sort_order' => absint($order)], ['id' => absint($id)]);
        }
        wp_redirect($back . '&msg=reordered'); exit;
    }

    // ── Editar título/ativo
    if ( $action === 'edit' ) {
        $id    = absint($_POST['item_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        if ($id && $title) $wpdb->update('ehtm_tv_playlist', ['title'=>$title], ['id'=>$id]);
        wp_redirect($back); exit;
    }
}

// ── Extrair ID do YouTube ─────────────────────────────────────
function ehtv_extract_youtube_id( string $url ): string {
    // Formatos: youtu.be/ID, youtube.com/watch?v=ID, youtube.com/embed/ID, youtube.com/shorts/ID
    $patterns = [
        '/youtu\.be\/([a-zA-Z0-9_-]{11})/',
        '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
        '/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',
        '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
        '/youtube\.com\/v\/([a-zA-Z0-9_-]{11})/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $url, $m)) return $m[1];
    }
    // Pode ser só o ID
    if (preg_match('/^[a-zA-Z0-9_-]{11}$/', trim($url))) return trim($url);
    return '';
}
