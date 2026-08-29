<?php
if (!defined('ABSPATH')) exit;

final class SEO_Manager_Google {
    const CRON = 'seom_google_sync';
    const OAUTH_SCOPE = 'openid email profile https://www.googleapis.com/auth/webmasters';
    const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';
    const API_BASE = 'https://www.googleapis.com/webmasters/v3';
    const INSPECTION_BASE = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

    public static function init(): void {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action('admin_post_seom_google_start', [__CLASS__, 'start_oauth']);
        add_action('admin_post_seom_google_callback', [__CLASS__, 'callback']);
        add_action('admin_post_seom_google_disconnect', [__CLASS__, 'disconnect']);
        add_action('admin_post_seom_google_save', [__CLASS__, 'save_credentials']);
        add_action('admin_post_seom_google_refresh_sites', [__CLASS__, 'handle_refresh_sites']);
        add_action('admin_post_seom_google_submit_sitemap', [__CLASS__, 'submit_sitemap']);
        add_action('wp_ajax_seom_google_analytics', [__CLASS__, 'ajax_analytics']);
        add_action('wp_ajax_seom_google_inspect', [__CLASS__, 'ajax_inspect']);
        add_action(self::CRON, [__CLASS__, 'sync']);
        self::ensure_schedule();
    }


    public static function cron_schedules(array $schedules): array {
        if (!isset($schedules['seom_twicedaily'])) {
            $schedules['seom_twicedaily'] = ['interval'=>12*HOUR_IN_SECONDS,'display'=>__('SEO Manager twice daily','seo-manager')];
        }
        return $schedules;
    }

    private static function ensure_schedule(): void {
        $enabled=(bool)seom_get('google_monitor_enabled',1);
        $wanted=(string)seom_get('google_monitor_frequency','daily');
        $event=wp_get_scheduled_event(self::CRON);
        if(!$enabled){ if($event)wp_clear_scheduled_hook(self::CRON); return; }
        if($event && (string)$event->schedule === ($wanted==='twicedaily'?'seom_twicedaily':'daily')) return;
        if($event)wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time()+HOUR_IN_SECONDS, $wanted==='twicedaily'?'seom_twicedaily':'daily', self::CRON);
    }

    public static function credentials(): array {
        return [
            'client_id' => (string) seom_get('google_client_id', ''),
            'client_secret' => (string) self::decrypt((string) seom_get('google_client_secret', '')),
        ];
    }

    public static function connected(): bool {
        return (bool) seom_get('google_connected', 0) && (bool) seom_get('google_refresh_token', '');
    }

    public static function redirect_uri(): string {
        return admin_url('admin-post.php?action=seom_google_callback');
    }

    public static function start_oauth(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $creds = self::credentials();
        if (!$creds['client_id'] || !$creds['client_secret']) {
            wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','google_error'=>'credentials'], admin_url('admin.php')));
            exit;
        }
        $state = wp_generate_password(32, false, false);
        set_transient('seom_google_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);
        $url = add_query_arg([
            'client_id' => $creds['client_id'],
            'redirect_uri' => self::redirect_uri(),
            'response_type' => 'code',
            'scope' => self::OAUTH_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], self::AUTH_ENDPOINT);
        wp_safe_redirect($url);
        exit;
    }

    public static function callback(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $saved = get_transient('seom_google_oauth_state_' . get_current_user_id());
        delete_transient('seom_google_oauth_state_' . get_current_user_id());
        if (!$state || !$saved || !hash_equals((string)$saved, $state)) wp_die('Invalid OAuth state.');
        if (!empty($_GET['error'])) {
            wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','google_error'=>sanitize_key($_GET['error'])], admin_url('admin.php')));
            exit;
        }
        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if (!$code) wp_die('Google did not return an authorization code.');
        $creds = self::credentials();
        $resp = wp_remote_post(self::TOKEN_ENDPOINT, [
            'timeout' => 20,
            'body' => [
                'code' => $code,
                'client_id' => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'redirect_uri' => self::redirect_uri(),
                'grant_type' => 'authorization_code',
            ],
        ]);
        if (is_wp_error($resp)) wp_die(esc_html($resp->get_error_message()));
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body) || empty($body['access_token'])) {
            wp_die(esc_html((string)($body['error_description'] ?? 'Google token exchange failed.')));
        }
        $refresh = (string)($body['refresh_token'] ?? seom_get('google_refresh_token', ''));
        seom_update('google_access_token', self::encrypt((string)$body['access_token']));
        seom_update('google_refresh_token', self::encrypt($refresh));
        seom_update('google_expires_at', time() + absint($body['expires_in'] ?? 3600) - 60);
        seom_update('google_connected', 1);
        $me = self::request(self::USERINFO_ENDPOINT, [], 'GET', (string)$body['access_token']);
        if (!is_wp_error($me)) {
            seom_update('google_email', sanitize_email((string)($me['email'] ?? '')));
            seom_update('google_name', sanitize_text_field((string)($me['name'] ?? '')));
        }
        self::refresh_sites(true);
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','google_connected'=>1], admin_url('admin.php')));
        exit;
    }

    public static function disconnect(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_google_disconnect')) wp_die('Unauthorized.');
        foreach (['google_access_token','google_refresh_token','google_expires_at','google_email','google_name','google_connected','google_sites','google_selected_site'] as $key) delete_option('seom_' . $key);
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','google_disconnected'=>1], admin_url('admin.php')));
        exit;
    }

    public static function save_credentials(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_google_save')) wp_die('Unauthorized.');
        seom_update('google_client_id', sanitize_text_field(wp_unslash($_POST['google_client_id'] ?? '')));
        $secret = sanitize_text_field(wp_unslash($_POST['google_client_secret'] ?? ''));
        if ($secret !== '') seom_update('google_client_secret', self::encrypt($secret));
        else if (!seom_get('google_client_secret', '')) seom_update('google_client_secret', '');
        seom_update('google_selected_site', sanitize_text_field(wp_unslash($_POST['google_selected_site'] ?? '')));
        seom_update('google_notify_email', sanitize_email(wp_unslash($_POST['google_notify_email'] ?? get_option('admin_email'))));
        seom_update('google_monitor_enabled', isset($_POST['google_monitor_enabled']) ? 1 : 0);
        seom_update('google_monitor_frequency', in_array($_POST['google_monitor_frequency'] ?? 'daily', ['daily','twicedaily'], true) ? sanitize_key($_POST['google_monitor_frequency']) : 'daily');
        self::ensure_schedule();
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','google_saved'=>1], admin_url('admin.php')));
        exit;
    }

    public static function handle_refresh_sites(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_google_refresh_sites')) wp_die('Unauthorized.');
        self::refresh_sites(true);
    }

    public static function refresh_sites(bool $redirect = false): array {
        if (!self::connected()) {
            if ($redirect) wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','google_error'=>'not_connected'], admin_url('admin.php')));
            if ($redirect) exit;
            return [];
        }
        $data = self::api('/sites');
        if (is_wp_error($data)) {
            if ($redirect) wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','google_error'=>'sites'], admin_url('admin.php')));
            if ($redirect) exit;
            return [];
        }
        $sites = [];
        foreach ((array)($data['siteEntry'] ?? []) as $site) {
            $sites[] = ['siteUrl'=>sanitize_text_field((string)($site['siteUrl'] ?? '')), 'permissionLevel'=>sanitize_text_field((string)($site['permissionLevel'] ?? ''))];
        }
        seom_update('google_sites', $sites);
        if (!$sites) seom_update('google_selected_site', '');
        else if (!in_array((string)seom_get('google_selected_site',''), array_column($sites,'siteUrl'), true)) seom_update('google_selected_site', $sites[0]['siteUrl']);
        if ($redirect) {
            wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','google_refreshed'=>1], admin_url('admin.php')));
            exit;
        }
        return $sites;
    }


    public static function submit_sitemap(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_google_submit_sitemap')) wp_die('Unauthorized.');
        $site = (string)seom_get('google_selected_site','');
        if (!$site || !self::connected()) {
            wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','google_error'=>'not_connected'], admin_url('admin.php'))); exit;
        }
        $sitemap = home_url('/seo-sitemap.xml');
        $path = '/sites/' . rawurlencode($site) . '/sitemaps/' . rawurlencode($sitemap);
        $data = self::api($path, 'PUT');
        $status = is_wp_error($data) ? 'error' : 'submitted';
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','google_sitemap'=>$status], admin_url('admin.php'))); exit;
    }

    public static function sync(): void {
        if (self::connected() && self::monitor_enabled()) self::sync_search_metrics();
        self::sync_google_updates();
    }

    private static function sync_search_metrics(): void {
        $site = (string)seom_get('google_selected_site','');
        if (!$site) return;
        $end = gmdate('Y-m-d', time() - 2 * DAY_IN_SECONDS);
        $start = gmdate('Y-m-d', time() - 31 * DAY_IN_SECONDS);
        $body = ['startDate'=>$start,'endDate'=>$end,'dimensions'=>['date'],'rowLimit'=>1000];
        $data = self::api('/sites/' . rawurlencode($site) . '/searchAnalytics/query', 'POST', $body);
        if (!is_wp_error($data)) {
            seom_update('google_analytics_rows', is_array($data['rows'] ?? null) ? $data['rows'] : []);
            seom_update('google_analytics_synced', time());
        }
    }

    public static function ajax_analytics(): void {
        check_ajax_referer('seom_admin','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Unauthorized']);
        $days = max(7, min(90, absint($_POST['days'] ?? 28)));
        $site = (string)seom_get('google_selected_site','');
        if (!self::connected() || !$site) wp_send_json_error(['message'=>'Connect Google and select a Search Console property first.']);
        $end = gmdate('Y-m-d', time() - 2 * DAY_IN_SECONDS);
        $start = gmdate('Y-m-d', time() - ($days + 2) * DAY_IN_SECONDS);
        $body = ['startDate'=>$start,'endDate'=>$end,'dimensions'=>['date'],'rowLimit'=>1000];
        $data = self::api('/sites/' . rawurlencode($site) . '/searchAnalytics/query', 'POST', $body);
        if (is_wp_error($data)) wp_send_json_error(['message'=>$data->get_error_message()]);
        wp_send_json_success($data);
    }

    public static function ajax_inspect(): void {
        check_ajax_referer('seom_admin','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Unauthorized']);
        if (!self::connected()) wp_send_json_error(['message'=>'Connect Google first.']);
        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        $site = (string)seom_get('google_selected_site','');
        if (!$url || !$site) wp_send_json_error(['message'=>'Enter a URL and select a property.']);
        $data = self::request(self::INSPECTION_BASE, ['inspectionUrl'=>$url,'siteUrl'=>$site,'languageCode'=>'en-US'], 'POST');
        if (is_wp_error($data)) wp_send_json_error(['message'=>$data->get_error_message()]);
        wp_send_json_success($data);
    }

    public static function sync_google_updates(): void {
        if (!(int)seom_get('google_monitor_enabled',1)) return;
        $feeds = [
            'blog' => 'https://feeds.feedburner.com/blogspot/amDG',
            'docs' => 'https://developers.google.com/search/updates/search_docs_updates.rss',
        ];
        $items = [];
        foreach ($feeds as $type=>$url) {
            $resp = wp_remote_get($url, ['timeout'=>20,'headers'=>['Accept'=>'application/rss+xml, application/atom+xml, application/xml, text/xml']]);
            if (is_wp_error($resp)) continue;
            $xml = wp_remote_retrieve_body($resp);
            if (!$xml) continue;
            libxml_use_internal_errors(true);
            $feed = simplexml_load_string($xml);
            if (!$feed) continue;
            if (isset($feed->channel->item)) {
                foreach ($feed->channel->item as $item) {
                    $guid = sanitize_text_field((string)($item->guid ?: $item->link));
                    $items[$guid] = ['type'=>$type,'title'=>sanitize_text_field((string)$item->title),'link'=>esc_url_raw((string)$item->link),'date'=>sanitize_text_field((string)($item->pubDate ?: $item->date))];
                }
            } else {
                foreach ($feed->entry as $entry) {
                    $link = '';
                    if (isset($entry->link['href'])) $link = (string)$entry->link['href'];
                    $guid = sanitize_text_field((string)($entry->id ?: $link));
                    $items[$guid] = ['type'=>$type,'title'=>sanitize_text_field((string)$entry->title),'link'=>esc_url_raw($link),'date'=>sanitize_text_field((string)$entry->updated)];
                }
            }
        }
        $old = (array)seom_get('google_update_ids', []);
        $new = [];
        $fresh = [];
        foreach ($items as $id=>$item) {
            $new[] = $id;
            if (!in_array($id, $old, true)) $fresh[] = $item;
        }
        seom_update('google_update_ids', array_slice($new, 0, 100));
        usort($fresh, static function($a,$b){return strcmp($b['date'], $a['date']);});
        if ($fresh) {
            $history = (array)seom_get('google_updates_history', []);
            $history = array_slice(array_merge($fresh, $history), 0, 50);
            seom_update('google_updates_history', $history);
            $to = sanitize_email(seom_get('google_notify_email', get_option('admin_email')));
            if ($to) {
                $lines = ["New Google Search updates were detected on " . home_url('/'), '', ''];
                foreach (array_slice($fresh, 0, 8) as $u) $lines[] = '- ' . $u['title'] . ' (' . $u['link'] . ')';
                wp_mail($to, 'Google SEO update monitor: new updates', implode("\n", $lines));
            }
        }
        seom_update('google_updates_checked', time());
    }

    private static function monitor_enabled(): bool { return (bool)seom_get('google_monitor_enabled', 1); }

    private static function api(string $path, string $method='GET', array $body=[]) {
        $token = self::access_token();
        if (is_wp_error($token)) return $token;
        return self::request(self::API_BASE . $path, $body, $method, $token);
    }

    private static function access_token() {
        if (!self::connected()) return new WP_Error('not_connected', 'Google is not connected.');
        $expires = absint(seom_get('google_expires_at',0));
        $token = (string)self::decrypt(seom_get('google_access_token',''));
        if ($token && $expires > time() + 60) return $token;
        $refresh = (string)self::decrypt(seom_get('google_refresh_token',''));
        $creds = self::credentials();
        if (!$refresh || !$creds['client_id'] || !$creds['client_secret']) return new WP_Error('oauth', 'Google credentials are incomplete.');
        $resp = wp_remote_post(self::TOKEN_ENDPOINT, ['timeout'=>20,'body'=>['client_id'=>$creds['client_id'],'client_secret'=>$creds['client_secret'],'refresh_token'=>$refresh,'grant_type'=>'refresh_token']]);
        if (is_wp_error($resp)) return $resp;
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data) || empty($data['access_token'])) return new WP_Error('refresh_failed', (string)($data['error_description'] ?? 'Could not refresh Google token.'));
        seom_update('google_access_token', self::encrypt((string)$data['access_token']));
        seom_update('google_expires_at', time()+absint($data['expires_in']??3600)-60);
        return (string)$data['access_token'];
    }

    private static function request(string $url, array $body=[], string $method='GET', $token=null) {
        $headers = ['Accept'=>'application/json'];
        if ($token) $headers['Authorization'] = 'Bearer ' . $token;
        $args = ['timeout'=>25,'headers'=>$headers,'method'=>$method];
        if ($method === 'POST') { $headers['Content-Type']='application/json'; $args['headers']=$headers; $args['body']=wp_json_encode($body); }
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300) return new WP_Error('google_api', (string)($data['error']['message'] ?? 'Google API request failed.'), ['status'=>$code,'body'=>$data]);
        return is_array($data) ? $data : [];
    }

    private static function key(): string { return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true); }
    private static function encrypt(string $value): string {
        if ($value === '') return '';
        if (!function_exists('openssl_encrypt')) return base64_encode($value);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($value, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }
    private static function decrypt(string $value): string {
        if ($value === '') return '';
        if (!function_exists('openssl_decrypt')) return (string)base64_decode($value);
        $raw = base64_decode($value, true);
        if (!$raw || strlen($raw) < 17) return '';
        $iv = substr($raw,0,16); $cipher = substr($raw,16);
        $out = openssl_decrypt($cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return is_string($out) ? $out : '';
    }

    public static function stored_secret_present(): bool { return (bool)seom_get('google_client_secret',''); }
}
