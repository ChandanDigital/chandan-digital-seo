<?php
if (!defined('ABSPATH')) exit;

final class SEO_Manager_Redirects {
    public static function init(): void {
        add_action('template_redirect', [__CLASS__, 'maybe_redirect'], 0);
        add_action('admin_post_seom_add_redirect', [__CLASS__, 'add']);
        add_action('admin_post_seom_delete_redirect', [__CLASS__, 'delete']);
        add_action('admin_post_seom_toggle_redirect', [__CLASS__, 'toggle']);
        add_action('admin_post_seom_save_redirect_settings', [__CLASS__, 'save_settings']);
        add_action('post_updated', [__CLASS__, 'auto_post_redirect'], 20, 3);
    }

    public static function maybe_redirect(): void {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;

        global $wpdb;
        $path = seom_clean_path(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $table = $wpdb->prefix . 'seom_redirects';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE source=%s AND enabled=1 LIMIT 1",
            $path
        ));

        if ($row) {
            $target = self::append_query($row->destination);
            if ($target === home_url('/') && self::same_url($path, $target)) return;

            if ((int) seom_get('redirect_debug', 0) === 1 && current_user_can('manage_options')) {
                self::debug('Explicit redirect matched', [
                    'source' => $path,
                    'destination' => $target,
                    'type' => (int) $row->type,
                    'hits_before' => (int) $row->hits,
                ]);
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET hits=hits+1, updated_at=%s WHERE id=%d",
                current_time('mysql'),
                (int) $row->id
            ));

            wp_safe_redirect($target, (int) $row->type);
            exit;
        }

        // Fallback behaviour only applies to real frontend 404 pages.
        if (!is_404()) return;

        $fallback = sanitize_key((string) seom_get('redirect_fallback', '404'));
        if ($fallback === '404') return;

        $target = '';
        if ($fallback === 'homepage') {
            $target = home_url('/');
        } elseif ($fallback === 'custom') {
            $target = seom_sanitize_url(seom_get('redirect_fallback_url', ''));
        }

        if (!$target || self::same_url($path, $target)) return;

        if ((int) seom_get('redirect_debug', 0) === 1 && current_user_can('manage_options')) {
            self::debug('404 fallback would redirect', [
                'source' => $path,
                'destination' => $target,
                'type' => (int) seom_get('redirect_default_type', 301),
                'fallback' => $fallback,
            ]);
        }

        wp_safe_redirect(self::append_query($target), (int) seom_get('redirect_default_type', 301));
        exit;
    }

    private static function append_query(string $destination): string {
        if (!(int) seom_get('redirect_preserve_query', 1)) return $destination;
        if (empty($_GET) || strpos($destination, '?') !== false) return $destination;
        $query = http_build_query(wp_unslash($_GET));
        return $query ? $destination . '?' . $query : $destination;
    }

    private static function same_url(string $source_path, string $target): bool {
        $target_path = seom_clean_path((string) parse_url($target, PHP_URL_PATH));
        return $source_path === $target_path;
    }

    private static function debug(string $title, array $details): void {
        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>SEO Manager Redirect Debug</title><style>body{font:14px/1.6 system-ui,sans-serif;background:#f6f7f9;margin:0;padding:32px;color:#1d2327}.box{max-width:860px;margin:auto;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:24px;box-shadow:0 4px 14px rgba(0,0,0,.05)}h1{font-size:22px;margin:0 0 10px}pre{background:#f6f7f9;padding:16px;border-radius:8px;overflow:auto}</style></head><body><div class="box"><h1>SEO Manager Redirect Debug</h1><p>'.esc_html($title).'</p><pre>'.esc_html(print_r($details,true)).'</pre><p>Only administrators can see this screen. Disable <strong>Debug Redirections</strong> to enable normal redirects.</p></div></body></html>';
        exit;
    }

    public static function add(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_add_redirect')) wp_die('Unauthorized');
        global $wpdb;
        $src = seom_clean_path($_POST['source'] ?? '');
        $dst = seom_normalize_destination($_POST['destination'] ?? '');
        $type = absint($_POST['type'] ?? seom_get('redirect_default_type', 301));
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        if (!$src || !$dst || !in_array($type, [301,302,307,308], true)) wp_die('Invalid redirect');

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}seom_redirects WHERE source=%s LIMIT 1", $src));
        $data = [
            'destination' => $dst,
            'type' => $type,
            'enabled' => $enabled,
            'updated_at' => current_time('mysql'),
        ];
        if ($exists) {
            $wpdb->update($wpdb->prefix.'seom_redirects', $data, ['id'=>(int)$exists], ['%s','%d','%d','%s'], ['%d']);
        } else {
            $wpdb->insert($wpdb->prefix.'seom_redirects', array_merge([
                'source' => $src,
                'hits' => 0,
                'created_at' => current_time('mysql'),
            ], $data));
        }
        wp_safe_redirect(add_query_arg(['redirect_status'=>'saved'],seom_admin_page_url('links','redirects')));
        exit;
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_save_redirect_settings')) wp_die('Unauthorized');

        seom_update('redirect_debug', isset($_POST['redirect_debug']) ? 1 : 0);
        seom_update('redirect_auto_post', isset($_POST['redirect_auto_post']) ? 1 : 0);
        seom_update('redirect_preserve_query', isset($_POST['redirect_preserve_query']) ? 1 : 0);

        $fallback = sanitize_key((string)($_POST['redirect_fallback'] ?? '404'));
        if (!in_array($fallback, ['404','homepage','custom'], true)) $fallback = '404';
        seom_update('redirect_fallback', $fallback);
        seom_update('redirect_fallback_url', seom_normalize_destination($_POST['redirect_fallback_url'] ?? ''));

        $type = absint($_POST['redirect_default_type'] ?? 301);
        if (!in_array($type, [301,302,307,308], true)) $type = 301;
        seom_update('redirect_default_type', $type);

        wp_safe_redirect(add_query_arg(['redirect_status'=>'settings_saved'],seom_admin_page_url('links','redirects')));
        exit;
    }

    public static function auto_post_redirect(int $post_id, WP_Post $post_after, WP_Post $post_before): void {
        if (!(int) seom_get('redirect_auto_post', 0)) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if ($post_after->post_type === 'revision' || $post_before->post_type === 'revision') return;
        if ($post_after->post_status !== 'publish' || !in_array($post_before->post_status, ['publish','private'], true)) return;
        if ($post_after->post_name === $post_before->post_name) return;

        $old_url = get_permalink($post_before);
        $new_url = get_permalink($post_after);
        if (!$old_url || !$new_url || self::same_url(seom_clean_path((string)$old_url), $new_url)) return;

        $old_path = seom_clean_path((string)$old_url);
        $new_path = seom_clean_path((string)$new_url);
        if ($old_path === $new_path || $old_path === '/') return;

        global $wpdb;
        $table = $wpdb->prefix.'seom_redirects';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE source=%s LIMIT 1", $old_path));
        $data = [
            'destination' => $new_url,
            'type' => 301,
            'enabled' => 1,
            'updated_at' => current_time('mysql'),
        ];
        if ($exists) {
            $wpdb->update($table, $data, ['id'=>(int)$exists]);
        } else {
            $wpdb->insert($table, array_merge([
                'source' => $old_path,
                'hits' => 0,
                'created_at' => current_time('mysql'),
            ], $data));
        }
    }

    public static function delete(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_delete_redirect')) wp_die('Unauthorized');
        global $wpdb;
        $wpdb->delete($wpdb->prefix.'seom_redirects', ['id'=>absint($_POST['id']??0)], ['%d']);
        wp_safe_redirect(add_query_arg(['redirect_status'=>'deleted'],seom_admin_page_url('links','redirects')));
        exit;
    }

    public static function toggle(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_toggle_redirect')) wp_die('Unauthorized');
        global $wpdb;
        $id=absint($_POST['id']??0);
        $enabled=(int)$wpdb->get_var($wpdb->prepare("SELECT enabled FROM {$wpdb->prefix}seom_redirects WHERE id=%d",$id));
        $wpdb->update($wpdb->prefix.'seom_redirects',['enabled'=>$enabled?0:1,'updated_at'=>current_time('mysql')],['id'=>$id]);
        wp_safe_redirect(add_query_arg(['redirect_status'=>'updated'],seom_admin_page_url('links','redirects')));
        exit;
    }
}
