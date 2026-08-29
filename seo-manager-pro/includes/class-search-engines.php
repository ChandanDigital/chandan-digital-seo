<?php
if (!defined('ABSPATH')) exit;

final class SEO_Manager_Search_Engines {
    const CRON = 'seom_indexnow_queue';
    const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';
    const BING_ENDPOINT = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrl';

    public static function init(): void {
        add_action('admin_post_seom_search_engine_save', [__CLASS__, 'save_settings']);
        add_action('admin_post_seom_search_engine_test', [__CLASS__, 'test_submit']);
        add_action('admin_post_seom_indexnow_key', [__CLASS__, 'generate_key']);
        add_action('admin_post_seom_indexnow_clear_logs', [__CLASS__, 'clear_logs']);
        add_action('save_post', [__CLASS__, 'on_save'], 35, 3);
        add_action('trashed_post', [__CLASS__, 'on_trash'], 35, 1);
        add_action(self::CRON, [__CLASS__, 'process_queue']);
        self::ensure_schedule();
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_action('template_redirect', [__CLASS__, 'serve_key_file'], 0);
    }

    private static function ensure_schedule(): void {
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 15 * MINUTE_IN_SECONDS, 'hourly', self::CRON);
    }

    public static function enabled(): bool {
        return (bool) seom_get('indexnow_enabled', 0) && (string) seom_get('indexnow_key', '');
    }

    public static function indexnow_key_url(): string {
        $key = trim((string) seom_get('indexnow_key', ''));
        return $key ? home_url('/' . rawurlencode($key) . '.txt') : '';
    }

    public static function generate_key(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_indexnow_key')) wp_die('Unauthorized.');
        $key = strtolower(wp_generate_password(32, false, false));
        $key = preg_replace('/[^a-z0-9-]/', '', $key);
        seom_update('indexnow_key', $key);
        self::write_physical_key_file($key);
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'search','saved'=>1], admin_url('admin.php')));
        exit;
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_search_engine_save')) wp_die('Unauthorized.');
        seom_update('indexnow_enabled', isset($_POST['indexnow_enabled']) ? 1 : 0);
        seom_update('indexnow_auto_update', isset($_POST['indexnow_auto_update']) ? 1 : 0);
        seom_update('indexnow_auto_delete', isset($_POST['indexnow_auto_delete']) ? 1 : 0);
        seom_update('indexnow_submit_homepage', isset($_POST['indexnow_submit_homepage']) ? 1 : 0);
        seom_update('bing_api_enabled', isset($_POST['bing_api_enabled']) ? 1 : 0);
        seom_update('bing_api_key', self::encrypt(sanitize_text_field(wp_unslash($_POST['bing_api_key'] ?? ''))));
        seom_update('indexnow_daily_limit', max(1, min(10000, absint($_POST['indexnow_daily_limit'] ?? 200))));
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'search','saved'=>1], admin_url('admin.php')));
        exit;
    }

    public static function test_submit(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_search_engine_test')) wp_die('Unauthorized.');
        $url = esc_url_raw(wp_unslash($_POST['url'] ?? home_url('/')));
        $provider = sanitize_key($_POST['provider'] ?? 'indexnow');
        $action = sanitize_key($_POST['action_type'] ?? 'update');
        if (!$url || !in_array($action, ['update','delete'], true)) {
            wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'search','test'=>'error','message'=>rawurlencode('Enter a valid URL and action.')], admin_url('admin.php'))); exit;
        }
        $result = $provider === 'bing' ? self::submit_bing([$url], $action, 0, true) : self::submit_indexnow([$url], $action, 0, true);
        $msg = is_wp_error($result) ? $result->get_error_message() : sprintf('Submitted successfully. Success: %d, Failed: %d.', (int)($result['success'] ?? 0), (int)($result['failed'] ?? 0));
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'search','test'=>is_wp_error($result)?'error':'success','message'=>rawurlencode($msg)], admin_url('admin.php'))); exit;
    }

    public static function on_save(int $post_id, WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_status !== 'publish') return;
        if (!self::enabled() || !(int)seom_get('indexnow_auto_update', 1) || !current_user_can('edit_post', $post_id)) return;
        $url = get_permalink($post_id);
        if (!$url || self::is_excluded_url($url) || ($post_id === (int)get_option('page_on_front') && !(int)seom_get('indexnow_submit_homepage', 1))) return;
        self::enqueue($url, 'update', $post_id);
    }

    public static function on_trash(int $post_id): void {
        if (!self::enabled() || !(int)seom_get('indexnow_auto_delete', 1)) return;
        $url = get_permalink($post_id);
        if (!$url || self::is_excluded_url($url)) return;
        self::enqueue($url, 'delete', $post_id);
    }

    private static function is_excluded_url(string $url): bool {
        $host = parse_url(home_url('/'), PHP_URL_HOST);
        return !$host || parse_url($url, PHP_URL_HOST) !== $host;
    }

    private static function enqueue(string $url, string $action, int $object_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'seom_indexing_logs';
        $now = current_time('mysql');
        $provider = 'indexnow';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE url=%s AND action=%s AND provider=%s AND status='queued' LIMIT 1", $url, $action, $provider));
        if ($exists) return;
        $wpdb->insert($table, ['url'=>$url,'action'=>$action,'status'=>'queued','response_code'=>0,'message'=>'Queued for IndexNow submission.','object_id'=>$object_id,'created_at'=>$now,'updated_at'=>$now,'attempts'=>0,'provider'=>$provider], ['%s','%s','%s','%d','%s','%d','%s','%s','%d','%s']);
    }

    public static function process_queue(): void {
        if (!self::enabled()) return;
        global $wpdb;
        $table = $wpdb->prefix . 'seom_indexing_logs';
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE provider='indexnow' AND status='queued' AND attempts < 3 ORDER BY id ASC LIMIT 25", ARRAY_A);
        foreach ($rows as $row) {
            $result = self::submit_indexnow([(string)$row['url']], (string)$row['action'], absint($row['object_id']), false);
            $wpdb->update($table, [
                'status' => is_wp_error($result) ? 'queued' : 'success',
                'message' => is_wp_error($result) ? $result->get_error_message() : 'IndexNow retry submitted.',
                'attempts' => absint($row['attempts']) + 1,
                'updated_at' => current_time('mysql'),
            ], ['id'=>absint($row['id'])], ['%s','%s','%d','%s'], ['%d']);
        }
    }

    public static function submit_indexnow(array $urls, string $action='update', int $object_id=0, bool $manual=true) {
        $key = trim((string)seom_get('indexnow_key', ''));
        if (!$key) return new WP_Error('indexnow_key', 'Generate an IndexNow API key first.');
        $urls = self::sanitize_urls($urls);
        if (!$urls) return new WP_Error('no_urls', 'No valid same-domain URLs were supplied.');
        $remaining = self::remaining_quota('indexnow');
        if ($remaining <= 0) return new WP_Error('quota', 'The local daily IndexNow safety budget is exhausted.');
        $urls = array_slice($urls, 0, min(10000, $remaining));
        $payload = [
            'host' => parse_url(home_url('/'), PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => self::indexnow_key_url(),
            'urlList' => array_values($urls),
        ];
        $resp = wp_remote_post(self::INDEXNOW_ENDPOINT, ['timeout'=>20,'headers'=>['Content-Type'=>'application/json; charset=utf-8','Accept'=>'application/json'],'body'=>wp_json_encode($payload)]);
        $code = is_wp_error($resp) ? 0 : (int)wp_remote_retrieve_response_code($resp);
        $raw = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_body($resp);
        $ok = !is_wp_error($resp) && in_array($code, [200, 202], true);
        $message = $ok ? 'URL(s) accepted by IndexNow.' : ((string)$raw ?: 'IndexNow request failed.');
        foreach ($urls as $url) self::log($url, $action, $ok ? 'success' : ($manual ? 'failed' : 'queued'), $code, $message, $object_id, 'indexnow');
        return ['success'=>$ok ? count($urls) : 0,'failed'=>$ok ? 0 : count($urls),'provider'=>'indexnow','code'=>$code,'message'=>$message];
    }

    public static function submit_bing(array $urls, string $action='update', int $object_id=0, bool $manual=true) {
        if ($action === 'delete') return new WP_Error('bing_delete', 'Bing direct URL Submission does not provide a delete notification endpoint. Use IndexNow for URL deletion notifications.');
        $api_key = trim((string)self::decrypt((string)seom_get('bing_api_key','')));
        if (!(int)seom_get('bing_api_enabled',0) || !$api_key) return new WP_Error('bing_key', 'Enable Bing URL Submission API and add the Bing API key.');
        $urls = self::sanitize_urls($urls);
        if (!$urls) return new WP_Error('no_urls', 'No valid same-domain URLs were supplied.');
        $remaining = self::remaining_quota('bing');
        if ($remaining <= 0) return new WP_Error('quota', 'The local daily Bing safety budget is exhausted.');
        $urls = array_slice($urls, 0, min(10000, $remaining));
        $site = home_url('/');
        $success = 0; $failed = 0; $last_code = 0; $last_message = '';
        foreach ($urls as $url) {
            $endpoint = add_query_arg('apikey', rawurlencode($api_key), self::BING_ENDPOINT);
            $resp = wp_remote_post($endpoint, ['timeout'=>20,'headers'=>['Content-Type'=>'application/json; charset=utf-8'],'body'=>wp_json_encode(['siteUrl'=>$site,'url'=>$url])]);
            $code = is_wp_error($resp) ? 0 : (int)wp_remote_retrieve_response_code($resp);
            $raw = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_body($resp);
            $ok = !is_wp_error($resp) && $code >= 200 && $code < 300;
            $msg = $ok ? 'URL submitted to Bing URL Submission API.' : ((string)$raw ?: 'Bing submission failed.');
            self::log($url, $action, $ok ? 'success' : ($manual ? 'failed' : 'queued'), $code, $msg, $object_id, 'bing');
            $success += $ok ? 1 : 0; $failed += $ok ? 0 : 1; $last_code = $code; $last_message = $msg;
        }
        return ['success'=>$success,'failed'=>$failed,'provider'=>'bing','code'=>$last_code,'message'=>$last_message];
    }

    public static function manual_bulk_submit(array $urls, string $provider='indexnow', string $action='update') {
        return $provider === 'bing' ? self::submit_bing($urls, $action, 0, true) : self::submit_indexnow($urls, $action, 0, true);
    }

    private static function sanitize_urls(array $urls): array {
        $host = parse_url(home_url('/'), PHP_URL_HOST); $out = [];
        foreach ($urls as $url) { $url = esc_url_raw(wp_unslash((string)$url)); if (!$url || parse_url($url, PHP_URL_HOST) !== $host) continue; $out[] = $url; }
        return array_values(array_unique($out));
    }

    private static function remaining_quota(string $provider): int {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $used = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}seom_indexing_logs WHERE provider=%s AND created_at >= %s AND status IN ('success','failed')", $provider, $since));
        return max(0, (int)seom_get('indexnow_daily_limit', 200) - $used);
    }

    private static function log(string $url, string $action, string $status, int $code, string $message, int $object_id, string $provider): void {
        global $wpdb; $table = $wpdb->prefix . 'seom_indexing_logs'; $now = current_time('mysql');
        $wpdb->insert($table, ['url'=>$url,'action'=>$action,'status'=>$status,'response_code'=>$code,'message'=>substr(wp_strip_all_tags($message),0,2000),'object_id'=>$object_id,'created_at'=>$now,'updated_at'=>$now,'attempts'=>1,'provider'=>$provider], ['%s','%s','%s','%d','%s','%d','%s','%s','%d','%s']);
    }

    private static function write_physical_key_file(string $key): bool {
        $root = trailingslashit(ABSPATH) . $key . '.txt';
        if (!is_writable(ABSPATH) && !file_exists($root)) return false;
        $result = @file_put_contents($root, $key . "\n");
        return $result !== false;
    }

    public static function serve_key_file(): void {
        $var = get_query_var('seom_indexnow_key_file');
        if (!$var) {
            $path = '/' . ltrim((string)parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
            $key = trim((string)seom_get('indexnow_key',''));
            if (!$key || untrailingslashit($path) !== '/' . $key . '.txt') return;
            $var = $key;
        }
        $key = trim((string)seom_get('indexnow_key',''));
        if (!$key || !hash_equals($key, (string)$var)) return;
        nocache_headers(); header('Content-Type: text/plain; charset=utf-8'); echo $key; exit;
    }

    public static function query_vars(array $vars): array { $vars[] = 'seom_indexnow_key_file'; return $vars; }

    public static function clear_logs(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_indexnow_clear_logs')) wp_die('Unauthorized.');
        global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}seom_indexing_logs WHERE provider IN ('indexnow','bing')");
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'search','cleared'=>1], admin_url('admin.php'))); exit;
    }

    private static function encrypt(string $value): string {
        if ($value === '') return '';
        if (!function_exists('openssl_encrypt')) return base64_encode($value);
        $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
        $iv = random_bytes(16); $cipher = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }
    private static function decrypt(string $value): string {
        if ($value === '') return '';
        if (!function_exists('openssl_decrypt')) return (string)base64_decode($value);
        $raw = base64_decode($value, true); if (!$raw || strlen($raw) < 17) return '';
        $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true); $iv = substr($raw, 0, 16); $cipher = substr($raw, 16);
        $out = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv); return is_string($out) ? $out : '';
    }
    public static function bing_key_present(): bool { return (bool)self::decrypt((string)seom_get('bing_api_key','')); }
}
