<?php
if (!defined('ABSPATH')) exit;

/**
 * Google Business Profile integration.
 * Uses the current Business Profile APIs and a dedicated OAuth grant with
 * the business.manage scope. Knowledge Panel tools are diagnostic/editorial
 * helpers; Google does not expose a public API that lets a site owner directly
 * edit or force a Knowledge Panel.
 */
final class SEO_Manager_Google_Business_Profile {
    const OAUTH_SCOPE = 'openid email profile https://www.googleapis.com/auth/business.manage';
    const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    const ACCOUNT_API = 'https://mybusinessaccountmanagement.googleapis.com/v1';
    const BUSINESS_API = 'https://mybusinessbusinessinformation.googleapis.com/v1';
    const POSTS_API = 'https://mybusiness.googleapis.com/v4';
    const REVIEWS_API = 'https://mybusiness.googleapis.com/v4';
    const PERFORMANCE_API = 'https://businessprofileperformance.googleapis.com/v1';
    const KG_API = 'https://kgsearch.googleapis.com/v1/entities:search';

    public static function init() {
        add_action('admin_post_seom_gbp_start', array(__CLASS__, 'start_oauth'));
        add_action('admin_post_seom_gbp_callback', array(__CLASS__, 'callback'));
        add_action('admin_post_seom_gbp_disconnect', array(__CLASS__, 'disconnect'));
        add_action('admin_post_seom_gbp_sync', array(__CLASS__, 'sync_all'));
        add_action('admin_post_seom_gbp_save_location', array(__CLASS__, 'save_location'));
        add_action('admin_post_seom_gbp_select', array(__CLASS__, 'select_context'));
        add_action('admin_post_seom_gbp_invite_admin', array(__CLASS__, 'invite_admin'));
        add_action('admin_post_seom_gbp_remove_admin', array(__CLASS__, 'remove_admin'));
        add_action('admin_post_seom_gbp_create_post', array(__CLASS__, 'create_post'));
        add_action('admin_post_seom_gbp_reply_review', array(__CLASS__, 'reply_review'));
        add_action('admin_post_seom_gbp_delete_reply', array(__CLASS__, 'delete_reply'));
        add_action('admin_post_seom_gbp_save_knowledge', array(__CLASS__, 'save_knowledge'));
        add_action('admin_post_seom_gbp_save_credentials', array(__CLASS__, 'save_credentials'));
        add_action('admin_post_seom_gbp_search_entity', array(__CLASS__, 'search_entity'));
        add_action('admin_post_seom_gbp_refresh_performance', array(__CLASS__, 'refresh_performance'));
    }

    private static function redirect($args = array()) {
        $args = array_merge(array('page' => 'seo-manager-business'), $args);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function redirect_uri() {
        return admin_url('admin-post.php?action=seom_gbp_callback');
    }

    public static function credentials() {
        $id = (string) seom_get('google_client_id', '');
        $secret = (string) self::decrypt((string) seom_get('google_client_secret', ''));
        if ((string) seom_get('gbp_client_id', '') !== '') $id = (string) seom_get('gbp_client_id', '');
        if ((string) seom_get('gbp_client_secret', '') !== '') $secret = (string) self::decrypt((string) seom_get('gbp_client_secret', ''));
        return array('client_id' => $id, 'client_secret' => $secret);
    }

    public static function connected() {
        return (bool) seom_get('gbp_connected', 0) && (bool) seom_get('gbp_refresh_token', '');
    }

    public static function start_oauth() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $c = self::credentials();
        if (!$c['client_id'] || !$c['client_secret']) self::redirect(array('gbp_error' => 'credentials'));
        $state = wp_generate_password(32, false, false);
        set_transient('seom_gbp_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);
        $url = add_query_arg(array(
            'client_id' => $c['client_id'],
            'redirect_uri' => self::redirect_uri(),
            'response_type' => 'code',
            'scope' => self::OAUTH_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ), self::AUTH_ENDPOINT);
        wp_safe_redirect($url);
        exit;
    }

    public static function callback() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $state = sanitize_text_field(wp_unslash(isset($_GET['state']) ? $_GET['state'] : ''));
        $saved = get_transient('seom_gbp_oauth_state_' . get_current_user_id());
        delete_transient('seom_gbp_oauth_state_' . get_current_user_id());
        if (!$state || !$saved || !hash_equals((string) $saved, $state)) wp_die('Invalid OAuth state.');
        if (!empty($_GET['error'])) self::redirect(array('gbp_error' => sanitize_key($_GET['error'])));
        $code = sanitize_text_field(wp_unslash(isset($_GET['code']) ? $_GET['code'] : ''));
        if (!$code) wp_die('Google did not return an authorization code.');
        $c = self::credentials();
        $resp = wp_remote_post(self::TOKEN_ENDPOINT, array(
            'timeout' => 25,
            'body' => array(
                'code' => $code,
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'redirect_uri' => self::redirect_uri(),
                'grant_type' => 'authorization_code',
            ),
        ));
        if (is_wp_error($resp)) wp_die(esc_html($resp->get_error_message()));
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body) || empty($body['access_token'])) wp_die(esc_html(isset($body['error_description']) ? $body['error_description'] : 'Google Business Profile token exchange failed.'));
        $refresh = (string) (isset($body['refresh_token']) ? $body['refresh_token'] : seom_get('gbp_refresh_token', ''));
        seom_update('gbp_access_token', self::encrypt((string) $body['access_token']));
        seom_update('gbp_refresh_token', self::encrypt($refresh));
        seom_update('gbp_expires_at', time() + absint(isset($body['expires_in']) ? $body['expires_in'] : 3600) - 60);
        seom_update('gbp_connected', 1);
        $me = self::request('https://openidconnect.googleapis.com/v1/userinfo', array(), 'GET', (string) $body['access_token']);
        if (!is_wp_error($me)) {
            seom_update('gbp_email', sanitize_email(isset($me['email']) ? $me['email'] : ''));
            seom_update('gbp_name', sanitize_text_field(isset($me['name']) ? $me['name'] : ''));
        }
        self::sync_all(false);
        self::redirect(array('gbp_connected' => 1));
    }

    public static function disconnect() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_disconnect')) wp_die('Unauthorized.');
        foreach (array('gbp_access_token','gbp_refresh_token','gbp_expires_at','gbp_email','gbp_name','gbp_connected','gbp_accounts','gbp_locations','gbp_selected_account','gbp_selected_location','gbp_reviews','gbp_posts','gbp_performance') as $key) delete_option('seom_' . $key);
        self::redirect(array('gbp_disconnected' => 1));
    }

    public static function save_credentials() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_save_credentials')) wp_die('Unauthorized.');
        seom_update('gbp_client_id', sanitize_text_field(wp_unslash(isset($_POST['gbp_client_id']) ? $_POST['gbp_client_id'] : '')));
        $secret = sanitize_text_field(wp_unslash(isset($_POST['gbp_client_secret']) ? $_POST['gbp_client_secret'] : ''));
        if ($secret !== '') seom_update('gbp_client_secret', self::encrypt($secret));
        seom_update('gbp_knowledge_graph_key', sanitize_text_field(wp_unslash(isset($_POST['gbp_knowledge_graph_key']) ? $_POST['gbp_knowledge_graph_key'] : '')));
        self::redirect(array('gbp_saved' => 1));
    }

    public static function sync_all($do_redirect = true) {
        if (!self::connected()) { if ($do_redirect) self::redirect(array('gbp_error' => 'not_connected')); return array(); }
        $accounts = self::api(self::ACCOUNT_API . '/accounts');
        if (is_wp_error($accounts)) { if ($do_redirect) self::redirect(array('gbp_error' => 'accounts')); return array(); }
        $list = array();
        foreach ((array) (isset($accounts['accounts']) ? $accounts['accounts'] : array()) as $a) {
            $list[] = array(
                'name' => sanitize_text_field(isset($a['name']) ? $a['name'] : ''),
                'accountName' => sanitize_text_field(isset($a['accountName']) ? $a['accountName'] : ''),
                'type' => sanitize_text_field(isset($a['type']) ? $a['type'] : ''),
                'role' => sanitize_text_field(isset($a['role']) ? $a['role'] : ''),
            );
        }
        seom_update('gbp_accounts', $list);
        $selected = (string) seom_get('gbp_selected_account', '');
        if (!$selected && $list) $selected = $list[0]['name'];
        if ($selected && !in_array($selected, array_column($list, 'name'), true)) $selected = $list ? $list[0]['name'] : '';
        seom_update('gbp_selected_account', $selected);
        $locations = array();
        if ($selected) {
            $url = self::BUSINESS_API . '/accounts/' . rawurlencode(basename($selected)) . '/locations';
            $url = add_query_arg(array('readMask' => 'name,title,storeCode,websiteUri,phoneNumbers,regularHours,categories,storefrontAddress,openInfo,metadata'), $url);
            $data = self::api($url);
            if (!is_wp_error($data)) {
                foreach ((array) (isset($data['locations']) ? $data['locations'] : array()) as $l) $locations[] = self::normalize_location($l);
            }
        }
        seom_update('gbp_locations', $locations);
        $selected_location = (string) seom_get('gbp_selected_location', '');
        if (!$selected_location && $locations) $selected_location = $locations[0]['name'];
        if ($selected_location && !in_array($selected_location, array_column($locations, 'name'), true)) $selected_location = $locations ? $locations[0]['name'] : '';
        seom_update('gbp_selected_location', $selected_location);
        if ($selected && $selected_location) {
            self::sync_reviews($selected, $selected_location);
            self::sync_posts($selected, $selected_location);
            self::list_admins($selected_location);
        }
        if ($do_redirect) self::redirect(array('gbp_synced' => 1));
        return $locations;
    }

    private static function normalize_location($l) {
        $phone = '';
        if (isset($l['phoneNumbers']['primaryPhone'])) $phone = (string) $l['phoneNumbers']['primaryPhone'];
        $address = '';
        if (isset($l['storefrontAddress']['addressLines']) && is_array($l['storefrontAddress']['addressLines'])) $address = implode(', ', array_map('sanitize_text_field', $l['storefrontAddress']['addressLines']));
        if (!empty($l['storefrontAddress']['locality'])) $address .= ($address ? ', ' : '') . sanitize_text_field($l['storefrontAddress']['locality']);
        if (!empty($l['storefrontAddress']['administrativeArea'])) $address .= ($address ? ', ' : '') . sanitize_text_field($l['storefrontAddress']['administrativeArea']);
        if (!empty($l['storefrontAddress']['postalCode'])) $address .= ($address ? ' ' : '') . sanitize_text_field($l['storefrontAddress']['postalCode']);
        return array(
            'name' => sanitize_text_field(isset($l['name']) ? $l['name'] : ''),
            'title' => sanitize_text_field(isset($l['title']) ? $l['title'] : ''),
            'storeCode' => sanitize_text_field(isset($l['storeCode']) ? $l['storeCode'] : ''),
            'websiteUri' => esc_url_raw(isset($l['websiteUri']) ? $l['websiteUri'] : ''),
            'phone' => sanitize_text_field($phone),
            'address' => $address,
            'state' => sanitize_text_field(isset($l['openInfo']['status']) ? $l['openInfo']['status'] : ''),
            'mapsUri' => esc_url_raw(isset($l['metadata']['mapsUri']) ? $l['metadata']['mapsUri'] : ''),
        );
    }

    private static function sync_reviews($account, $location) {
        $url = self::REVIEWS_API . '/' . rawurlencode($account) . '/locations/' . rawurlencode(basename($location)) . '/reviews?pageSize=50&orderBy=updateTime%20desc';
        $data = self::api($url);
        if (is_wp_error($data)) return;
        $reviews = array();
        foreach ((array) (isset($data['reviews']) ? $data['reviews'] : array()) as $r) {
            $reviews[] = array(
                'name' => sanitize_text_field(isset($r['name']) ? $r['name'] : ''),
                'rating' => sanitize_text_field(isset($r['starRating']) ? $r['starRating'] : ''),
                'comment' => sanitize_textarea_field(isset($r['comment']) ? $r['comment'] : ''),
                'reviewer' => sanitize_text_field(isset($r['reviewer']['displayName']) ? $r['reviewer']['displayName'] : 'Google user'),
                'updateTime' => sanitize_text_field(isset($r['updateTime']) ? $r['updateTime'] : ''),
                'reply' => sanitize_textarea_field(isset($r['reviewReply']['comment']) ? $r['reviewReply']['comment'] : ''),
                'replyUrl' => esc_url_raw(isset($r['reviewReplyUrl']) ? $r['reviewReplyUrl'] : ''),
            );
        }
        self::store_temp('gbp_reviews', $reviews);
    }

    private static function sync_posts($account, $location) {
        $url = self::POSTS_API . '/' . rawurlencode($account) . '/locations/' . rawurlencode(basename($location)) . '/localPosts?pageSize=100';
        $data = self::api($url);
        if (is_wp_error($data)) return;
        $posts = array();
        foreach ((array) (isset($data['localPosts']) ? $data['localPosts'] : array()) as $p) {
            $posts[] = array(
                'name' => sanitize_text_field(isset($p['name']) ? $p['name'] : ''),
                'summary' => sanitize_textarea_field(isset($p['summary']) ? $p['summary'] : ''),
                'topicType' => sanitize_text_field(isset($p['topicType']) ? $p['topicType'] : ''),
                'state' => sanitize_text_field(isset($p['state']) ? $p['state'] : ''),
                'createTime' => sanitize_text_field(isset($p['createTime']) ? $p['createTime'] : ''),
                'searchUrl' => esc_url_raw(isset($p['searchUrl']) ? $p['searchUrl'] : ''),
            );
        }
        self::store_temp('gbp_posts', $posts);
    }

    public static function select_context() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_select')) wp_die('Unauthorized.');
        $account = sanitize_text_field(wp_unslash(isset($_POST['gbp_selected_account']) ? $_POST['gbp_selected_account'] : ''));
        $location = sanitize_text_field(wp_unslash(isset($_POST['gbp_selected_location']) ? $_POST['gbp_selected_location'] : ''));
        if ($account) seom_update('gbp_selected_account', $account);
        if ($location) seom_update('gbp_selected_location', $location);
        if ($account && $location) {
            self::sync_reviews($account, $location);
            self::sync_posts($account, $location);
        }
        self::redirect(array('gbp_context_saved' => 1));
    }

    public static function save_location() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_save_location')) wp_die('Unauthorized.');
        $name = sanitize_text_field(wp_unslash(isset($_POST['location_name']) ? $_POST['location_name'] : ''));
        if (!$name) self::redirect(array('gbp_error' => 'location'));
        $title = sanitize_text_field(wp_unslash(isset($_POST['title']) ? $_POST['title'] : ''));
        $website = esc_url_raw(wp_unslash(isset($_POST['website_uri']) ? $_POST['website_uri'] : ''));
        $phone = sanitize_text_field(wp_unslash(isset($_POST['phone']) ? $_POST['phone'] : ''));
        $body = array();
        $mask = array();
        if ($title !== '') { $body['title'] = $title; $mask[] = 'title'; }
        if ($website !== '') { $body['websiteUri'] = $website; $mask[] = 'websiteUri'; }
        if ($phone !== '') { $body['phoneNumbers'] = array('primaryPhone' => $phone); $mask[] = 'phoneNumbers.primaryPhone'; }
        if (!$mask) self::redirect(array('gbp_error' => 'nothing_to_update'));
        $url = self::BUSINESS_API . '/' . ltrim($name, '/') . '?updateMask=' . rawurlencode(implode(',', $mask));
        $res = self::api($url, 'PATCH', $body);
        if (is_wp_error($res)) self::redirect(array('gbp_error' => 'location_update', 'gbp_message' => rawurlencode($res->get_error_message())));
        self::sync_all(false);
        self::redirect(array('gbp_location_saved' => 1));
    }

    public static function create_post() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_create_post')) wp_die('Unauthorized.');
        $account = (string) seom_get('gbp_selected_account', '');
        $location = (string) seom_get('gbp_selected_location', '');
        if (!$account || !$location) self::redirect(array('gbp_error' => 'location'));
        $summary = sanitize_textarea_field(wp_unslash(isset($_POST['summary']) ? $_POST['summary'] : ''));
        $url = esc_url_raw(wp_unslash(isset($_POST['cta_url']) ? $_POST['cta_url'] : ''));
        $action = sanitize_key(isset($_POST['action_type']) ? $_POST['action_type'] : 'LEARN_MORE');
        $topic = sanitize_key(isset($_POST['topic_type']) ? $_POST['topic_type'] : 'STANDARD');
        if ($summary === '') self::redirect(array('gbp_error' => 'post_summary'));
        $body = array('languageCode' => 'en-US', 'summary' => $summary, 'topicType' => strtoupper($topic));
        if ($url) $body['callToAction'] = array('actionType' => strtoupper($action), 'url' => $url);
        if (strtoupper($topic) === 'OFFER') {
            $body['offer'] = array_filter(array(
                'couponCode' => sanitize_text_field(wp_unslash(isset($_POST['coupon_code']) ? $_POST['coupon_code'] : '')),
                'redeemOnlineUrl' => esc_url_raw(wp_unslash(isset($_POST['redeem_url']) ? $_POST['redeem_url'] : '')),
                'termsConditions' => sanitize_textarea_field(wp_unslash(isset($_POST['terms']) ? $_POST['terms'] : '')),
            ));
        }
        $res = self::api(self::POSTS_API . '/' . ltrim($account, '/') . '/locations/' . rawurlencode(basename($location)) . '/localPosts', 'POST', $body);
        if (is_wp_error($res)) self::redirect(array('gbp_error' => 'post_create', 'gbp_message' => rawurlencode($res->get_error_message())));
        self::sync_posts($account, $location);
        self::redirect(array('gbp_post_created' => 1));
    }

    public static function reply_review() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_reply_review')) wp_die('Unauthorized.');
        $review = sanitize_text_field(wp_unslash(isset($_POST['review_name']) ? $_POST['review_name'] : ''));
        $comment = sanitize_textarea_field(wp_unslash(isset($_POST['reply']) ? $_POST['reply'] : ''));
        if (!$review || $comment === '') self::redirect(array('gbp_error' => 'review_reply'));
        $res = self::api(self::REVIEWS_API . '/' . ltrim($review, '/') . '/reply', 'PUT', array('comment' => $comment));
        if (is_wp_error($res)) self::redirect(array('gbp_error' => 'review_reply_failed', 'gbp_message' => rawurlencode($res->get_error_message())));
        self::sync_reviews((string) seom_get('gbp_selected_account',''), (string) seom_get('gbp_selected_location',''));
        self::redirect(array('gbp_reply_saved' => 1));
    }

    public static function delete_reply() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_delete_reply')) wp_die('Unauthorized.');
        $review = sanitize_text_field(wp_unslash(isset($_POST['review_name']) ? $_POST['review_name'] : ''));
        if (!$review) self::redirect(array('gbp_error' => 'review_reply'));
        $res = self::api(self::REVIEWS_API . '/' . ltrim($review, '/') . '/reply', 'DELETE');
        if (is_wp_error($res)) self::redirect(array('gbp_error' => 'review_delete_failed', 'gbp_message' => rawurlencode($res->get_error_message())));
        self::sync_reviews((string) seom_get('gbp_selected_account',''), (string) seom_get('gbp_selected_location',''));
        self::redirect(array('gbp_reply_deleted' => 1));
    }

    public static function list_admins($location = '') {
        $location = $location ?: (string) seom_get('gbp_selected_location','');
        if (!$location) return array();
        $url = self::ACCOUNT_API . '/locations/' . rawurlencode(basename($location)) . '/admins';
        $data = self::api($url);
        if (is_wp_error($data)) return array();
        $items = array();
        foreach ((array) (isset($data['admins']) ? $data['admins'] : array()) as $a) {
            $items[] = array(
                'name' => sanitize_text_field(isset($a['name']) ? $a['name'] : ''),
                'adminName' => sanitize_email(isset($a['adminName']) ? $a['adminName'] : ''),
                'role' => sanitize_text_field(isset($a['role']) ? $a['role'] : ''),
            );
        }
        self::store_temp('gbp_admins', $items);
        return $items;
    }

    public static function invite_admin() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_invite_admin')) wp_die('Unauthorized.');
        $location = (string) seom_get('gbp_selected_location','');
        $email = sanitize_email(wp_unslash(isset($_POST['admin_email']) ? $_POST['admin_email'] : ''));
        $role = in_array($_POST['admin_role'] ?? 'MANAGER', array('MANAGER','SITE_MANAGER'), true) ? sanitize_key($_POST['admin_role']) : 'MANAGER';
        if (!$location || !$email) self::redirect(array('gbp_error'=>'admin_invite'));
        $res = self::api(self::ACCOUNT_API . '/locations/' . rawurlencode(basename($location)) . '/admins', 'POST', array('adminName'=>$email,'role'=>strtoupper($role)));
        if (is_wp_error($res)) self::redirect(array('gbp_error'=>'admin_invite_failed','gbp_message'=>rawurlencode($res->get_error_message())));
        self::list_admins($location);
        self::redirect(array('gbp_admin_invited'=>1));
    }

    public static function remove_admin() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_remove_admin')) wp_die('Unauthorized.');
        $name = sanitize_text_field(wp_unslash(isset($_POST['admin_name']) ? $_POST['admin_name'] : ''));
        if (!$name) self::redirect(array('gbp_error'=>'admin_remove'));
        $res = self::api(self::ACCOUNT_API . '/' . ltrim($name,'/'), 'DELETE');
        if (is_wp_error($res)) self::redirect(array('gbp_error'=>'admin_remove_failed','gbp_message'=>rawurlencode($res->get_error_message())));
        self::list_admins();
        self::redirect(array('gbp_admin_removed'=>1));
    }

    public static function refresh_performance() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_performance')) wp_die('Unauthorized.');
        $location = (string) seom_get('gbp_selected_location', '');
        if (!$location) self::redirect(array('gbp_error' => 'location'));
        $end = gmdate('Y-m-d');
        $start = gmdate('Y-m-d', time() - 29 * DAY_IN_SECONDS);
        $metrics = array('WEBSITE_CLICKS','CALL_CLICKS','BUSINESS_DIRECTION_REQUESTS','BUSINESS_IMPRESSIONS_DESKTOP_SEARCH','BUSINESS_IMPRESSIONS_MOBILE_SEARCH','BUSINESS_IMPRESSIONS_DESKTOP_MAPS','BUSINESS_IMPRESSIONS_MOBILE_MAPS');
        $url = self::PERFORMANCE_API . '/' . ltrim($location, '/') . ':fetchMultiDailyMetricsTimeSeries';
        $query = array('dailyMetrics' => $metrics, 'dailyRange.startDate.year' => (int) gmdate('Y', strtotime($start)), 'dailyRange.startDate.month' => (int) gmdate('n', strtotime($start)), 'dailyRange.startDate.day' => (int) gmdate('j', strtotime($start)), 'dailyRange.endDate.year' => (int) gmdate('Y', strtotime($end)), 'dailyRange.endDate.month' => (int) gmdate('n', strtotime($end)), 'dailyRange.endDate.day' => (int) gmdate('j', strtotime($end)));
        $url = add_query_arg($query, $url);
        $res = self::api($url);
        if (is_wp_error($res)) self::redirect(array('gbp_error' => 'performance', 'gbp_message' => rawurlencode($res->get_error_message())));
        self::store_temp('gbp_performance', is_array($res) ? $res : array());
        self::redirect(array('gbp_performance_refreshed' => 1));
    }

    public static function save_knowledge() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_save_knowledge')) wp_die('Unauthorized.');
        foreach (array('entity_name','entity_description','entity_id','place_id','google_maps_url','entity_type') as $key) seom_update('knowledge_' . $key, sanitize_text_field(wp_unslash(isset($_POST[$key]) ? $_POST[$key] : '')));
        foreach (array('entity_logo','entity_sameas') as $key) {
            $value = wp_unslash(isset($_POST[$key]) ? $_POST[$key] : '');
            seom_update('knowledge_' . $key, $key === 'entity_logo' ? esc_url_raw($value) : sanitize_textarea_field($value));
        }
        self::redirect(array('gbp_knowledge_saved' => 1));
    }

    public static function search_entity() {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_gbp_search_entity')) wp_die('Unauthorized.');
        $query = sanitize_text_field(wp_unslash(isset($_POST['entity_query']) ? $_POST['entity_query'] : ''));
        $key = sanitize_text_field(wp_unslash((string) seom_get('gbp_knowledge_graph_key','')));
        if (!$query || !$key) self::redirect(array('gbp_error' => 'knowledge_key'));
        $data = wp_remote_get(add_query_arg(array('query'=>$query,'limit'=>10,'languages'=>'en','key'=>$key), self::KG_API), array('timeout'=>25));
        if (is_wp_error($data)) self::redirect(array('gbp_error' => 'knowledge_search'));
        $body = json_decode(wp_remote_retrieve_body($data), true);
        if (!is_array($body)) self::redirect(array('gbp_error' => 'knowledge_search'));
        self::store_temp('gbp_entity_results', isset($body['itemListElement']) && is_array($body['itemListElement']) ? $body['itemListElement'] : array());
        self::redirect(array('gbp_entity_searched'=>1));
    }

    private static function store_temp($key, $value) {
        // Business Profile API content must be treated as temporary cached content.
        set_transient('seom_temp_' . $key, $value, 29 * DAY_IN_SECONDS);
        seom_update($key . '_synced', time());
    }

    public static function read_temp($key, $default = array()) {
        $v = get_transient('seom_temp_' . $key);
        return $v === false ? (array) $default : $v;
    }

    private static function access_token() {
        if (!self::connected()) return new WP_Error('gbp_not_connected', 'Google Business Profile is not connected.');
        $expires = absint(seom_get('gbp_expires_at', 0));
        $token = (string) self::decrypt((string) seom_get('gbp_access_token', ''));
        if ($token && $expires > time() + 60) return $token;
        $refresh = (string) self::decrypt((string) seom_get('gbp_refresh_token', ''));
        $c = self::credentials();
        if (!$refresh || !$c['client_id'] || !$c['client_secret']) return new WP_Error('gbp_oauth', 'Google Business Profile credentials are incomplete.');
        $resp = wp_remote_post(self::TOKEN_ENDPOINT, array('timeout'=>25,'body'=>array('client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],'refresh_token'=>$refresh,'grant_type'=>'refresh_token')));
        if (is_wp_error($resp)) return $resp;
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data) || empty($data['access_token'])) return new WP_Error('gbp_refresh', isset($data['error_description']) ? $data['error_description'] : 'Could not refresh Google token.');
        seom_update('gbp_access_token', self::encrypt((string) $data['access_token']));
        seom_update('gbp_expires_at', time() + absint(isset($data['expires_in']) ? $data['expires_in'] : 3600) - 60);
        return (string) $data['access_token'];
    }

    private static function api($url, $method='GET', $body=array()) {
        $token = self::access_token();
        if (is_wp_error($token)) return $token;
        return self::request($url, $body, $method, $token);
    }

    private static function request($url, $body=array(), $method='GET', $token=null) {
        $headers = array('Accept'=>'application/json');
        if ($token) $headers['Authorization'] = 'Bearer ' . $token;
        $args = array('timeout'=>30,'headers'=>$headers,'method'=>$method);
        if (in_array($method, array('POST','PUT','PATCH'), true)) {
            $headers['Content-Type']='application/json';
            $args['headers']=$headers;
            $args['body']=wp_json_encode($body);
        }
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300) return new WP_Error('gbp_api', isset($data['error']['message']) ? $data['error']['message'] : 'Google Business Profile API request failed.', array('status'=>$code,'body'=>$data));
        return is_array($data) ? $data : array();
    }

    private static function key() { return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true); }
    private static function encrypt($value) {
        if ($value === '') return '';
        if (!function_exists('openssl_encrypt')) return base64_encode($value);
        $iv = function_exists('random_bytes') ? random_bytes(16) : wp_generate_password(16, false, true);
        if (strlen($iv) !== 16) $iv = substr(hash('sha256', $iv, true), 0, 16);
        $cipher = openssl_encrypt($value, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }
    private static function decrypt($value) {
        if ($value === '') return '';
        if (!function_exists('openssl_decrypt')) return (string) base64_decode($value);
        $raw = base64_decode($value, true);
        if (!$raw || strlen($raw) < 17) return '';
        $iv = substr($raw,0,16); $cipher = substr($raw,16);
        $out = openssl_decrypt($cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return is_string($out) ? $out : '';
    }
}
