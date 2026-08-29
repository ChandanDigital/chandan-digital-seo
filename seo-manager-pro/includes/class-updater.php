<?php
if (!defined('ABSPATH')) exit;

/**
 * Native WordPress updater bridge for GitHub Releases.
 * The plugin never replaces WordPress core's upgrader; it feeds WordPress a
 * validated package URL and adds optional backup/rollback controls.
 */
final class SEO_Manager_Updater {
    const OPTION = 'seom_update_settings';
    const STATE = 'seom_update_state';
    const BACKUP_DIR = 'seom-backups';

    public static function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 20, 3);
        add_filter('auto_update_plugin', [__CLASS__, 'auto_update'], 20, 2);
        add_filter('upgrader_pre_install', [__CLASS__, 'backup_before_install'], 20, 2);
        add_filter('upgrader_pre_download', [__CLASS__, 'pre_download'], 10, 4);
        add_filter('upgrader_source_selection', [__CLASS__, 'source_selection'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'after_update'], 20, 2);
        add_action('admin_post_seom_update_save', [__CLASS__, 'save_settings']);
        add_action('admin_post_seom_update_check', [__CLASS__, 'manual_check']);
        add_action('admin_post_seom_rollback', [__CLASS__, 'rollback']);
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
    }

    public static function defaults(): array {
        return [
            'owner' => '',
            'repo' => '',
            'branch' => 'main',
            'asset' => '',
            'token' => '',
            'auto_update' => 0,
            'backup_retention' => 3,
        ];
    }

    public static function settings(): array {
        $v = get_option(self::OPTION, []);
        return wp_parse_args(is_array($v) ? $v : [], self::defaults());
    }

    private static function repo_ready(): bool {
        $s = self::settings();
        return (bool) preg_match('/^[A-Za-z0-9_.-]+$/', (string)$s['owner'])
            && (bool) preg_match('/^[A-Za-z0-9_.-]+$/', (string)$s['repo']);
    }

    private static function headers(): array {
        $h = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'Chandan-Digital-SEO/'.SEOM_VERSION,
        ];
        $s = self::settings();
        $token = trim(self::decrypt((string)$s['token']));
        if ($token !== '') $h['Authorization'] = 'Bearer '.$token;
        return $h;
    }

    // The GitHub token can carry repo scope, so it is encrypted at rest the
    // same way the Google/Bing credentials are (AES-256-CBC keyed from the
    // site's own WordPress salts), instead of being stored as plain text in
    // wp_options like the rest of this settings array.
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
        $iv = substr($raw, 0, 16); $cipher = substr($raw, 16);
        $out = openssl_decrypt($cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return is_string($out) ? $out : '';
    }

    private static function latest_release() {
        if (!self::repo_ready()) return new WP_Error('seom_no_repo', 'GitHub repository is not configured.');
        $s = self::settings();
        $url = 'https://api.github.com/repos/'.rawurlencode($s['owner']).'/'.rawurlencode($s['repo']).'/releases/latest';
        $response = wp_remote_get($url, ['timeout'=>15, 'headers'=>self::headers(), 'redirection'=>3]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $message = is_array($body) && !empty($body['message']) ? sanitize_text_field($body['message']) : 'GitHub release request failed.';
            return new WP_Error('seom_github_http', $message, ['status'=>$code]);
        }
        if (empty($body['tag_name'])) return new WP_Error('seom_no_release', 'No published GitHub release was found.');
        return $body;
    }

    private static function version_from_release(array $release): string {
        $tag = trim((string)($release['tag_name'] ?? ''));
        $tag = ltrim($tag, 'vV');
        return preg_match('/^\d+(?:\.\d+){1,3}(?:[-+._A-Za-z0-9]+)?$/', $tag) ? $tag : '';
    }

    private static function package_from_release(array $release): string {
        return self::asset_target($release)['package'];
    }

    /**
     * Resolves both the public package URL (used to advertise the update to
     * WordPress) and the GitHub API asset URL (used to actually fetch the
     * file with an Authorization header for private repositories). The
     * plain browser_download_url only works unauthenticated for public
     * repos; private-repo assets must be fetched from the API asset
     * endpoint with an "Accept: application/octet-stream" header, which is
     * why pre_download() exists below instead of relying on WordPress's
     * default unauthenticated package download.
     */
    private static function asset_target(array $release): array {
        $s = self::settings();
        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
        $wanted = trim((string)$s['asset']);
        foreach ($assets as $asset) {
            $name = (string)($asset['name'] ?? '');
            if (!$name || substr(strtolower($name), -4) !== '.zip') continue;
            if ($wanted !== '' && $name !== $wanted) continue;
            return [
                'package' => esc_url_raw((string)($asset['browser_download_url'] ?? '')),
                'api_url' => esc_url_raw((string)($asset['url'] ?? '')),
            ];
        }
        if ($wanted !== '') return ['package' => '', 'api_url' => ''];
        $zip = !empty($release['zipball_url']) ? esc_url_raw($release['zipball_url']) : '';
        return ['package' => $zip, 'api_url' => $zip];
    }

    /**
     * Intercepts WordPress's own package download for this plugin only,
     * and only when a GitHub token is configured, so private-repo release
     * assets (which 404 without an Authorization header) can be fetched.
     * Public, tokenless updates are left to WordPress's default download.
     */
    public static function pre_download($reply, $package, $upgrader, $hook_extra) {
        if ($reply !== false) return $reply;
        if (!is_array($hook_extra) || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::plugin_basename()) return $reply;
        $s = self::settings();
        if (empty($s['token'])) return $reply;
        $release = get_transient('seom_latest_release_cache');
        if (!is_array($release) || empty($release['tag_name'])) return $reply;
        $target = self::asset_target($release);
        if (!$target['package'] || $target['package'] !== $package) return $reply;
        $download_url = $target['api_url'] ?: $target['package'];
        $tmp = wp_tempnam($download_url);
        if (!$tmp) return new WP_Error('seom_tmp_file', 'Could not create a temporary file for the update download.');
        $headers = self::headers();
        $headers['Accept'] = 'application/octet-stream';
        $response = wp_remote_get($download_url, ['timeout' => 300, 'stream' => true, 'filename' => $tmp, 'headers' => $headers, 'redirection' => 5]);
        if (is_wp_error($response)) { @unlink($tmp); return $response; }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) { @unlink($tmp); return new WP_Error('seom_download_http', sprintf('Update download failed with HTTP %d.', $code)); }
        return $tmp;
    }

    private static function plugin_basename(): string {
        return plugin_basename(SEOM_FILE);
    }

    /**
     * Forces the extracted package folder to match the installed plugin
     * folder name.
     *
     * A GitHub release without an attached .zip asset falls back to
     * zipball_url, whose archive unpacks to "<owner>-<repo>-<commit>/"
     * rather than the plugin's own directory name. WordPress installs a
     * plugin into whatever folder the archive contains, so without this the
     * update would land in a new directory, leaving the original copy
     * deactivated and every stored setting orphaned. Renaming the source
     * before install keeps the update in place.
     */
    public static function source_selection($source, $remote_source, $upgrader, $hook_extra = null) {
        if (!is_array($hook_extra) || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::plugin_basename()) return $source;
        global $wp_filesystem;
        if (!$wp_filesystem) return $source;
        $desired = trailingslashit($remote_source) . dirname(self::plugin_basename());
        if (untrailingslashit($source) === $desired) return $source;
        if ($wp_filesystem->exists($desired)) $wp_filesystem->delete($desired, true);
        if (!$wp_filesystem->move($source, $desired)) {
            return new WP_Error('seom_source_rename', 'Could not normalise the update package folder name.');
        }
        return trailingslashit($desired);
    }

    public static function inject_update($transient) {
        if (!is_object($transient) || !self::repo_ready()) return $transient;
        $cached = get_transient('seom_latest_release_cache');
        if ($cached === false) {
            $release = self::latest_release();
            if (is_wp_error($release)) {
                set_transient('seom_latest_release_cache', ['error'=>$release->get_error_message()], 30 * MINUTE_IN_SECONDS);
                return $transient;
            }
            set_transient('seom_latest_release_cache', $release, 30 * MINUTE_IN_SECONDS);
        } else {
            $release = $cached;
        }
        if (!is_array($release) || empty($release['tag_name'])) return $transient;
        $new_version = self::version_from_release($release);
        $package = self::package_from_release($release);
        if ($new_version === '' || $package === '' || !version_compare($new_version, SEOM_VERSION, '>')) return $transient;
        $item = (object)[
            'slug' => dirname(self::plugin_basename()),
            'plugin' => self::plugin_basename(),
            'new_version' => $new_version,
            'url' => esc_url_raw((string)($release['html_url'] ?? '')),
            'package' => $package,
            'icons' => [],
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'compatibility' => new stdClass(),
        ];
        $transient->response[self::plugin_basename()] = $item;
        return $transient;
    }

    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug)) return $result;
        $basename = self::plugin_basename();
        $slug = dirname($basename);
        if ($args->slug !== $slug && $args->slug !== basename($slug)) return $result;
        $release = get_transient('seom_latest_release_cache');
        if (!is_array($release) || empty($release['tag_name'])) {
            $release = self::latest_release();
        }
        if (is_wp_error($release)) return $result;
        $version = self::version_from_release($release);
        return (object)[
            'name' => 'Chandan Digital SEO',
            'slug' => $slug,
            'version' => $version ?: SEOM_VERSION,
            'author' => '<a href="https://chandandigital.com/">Chandan Digital</a>',
            'homepage' => esc_url_raw((string)($release['html_url'] ?? '')),
            'requires' => '6.4',
            'requires_php' => '7.4',
            'download_link' => self::package_from_release($release),
            'sections' => ['description'=>'Advanced all-in-one SEO toolkit for WordPress.','changelog'=>wp_kses_post((string)($release['body'] ?? ''))],
        ];
    }

    public static function auto_update($update, $item) {
        if (!is_object($item) || empty($item->plugin) || $item->plugin !== self::plugin_basename()) return $update;
        return (bool)self::settings()['auto_update'];
    }

    private static function backup_root(): string {
        $base = trailingslashit(dirname(WP_CONTENT_DIR)).'wp-content/upgrade/'.self::BACKUP_DIR;
        if (!wp_mkdir_p($base)) return '';
        // Defense in depth: block direct HTTP access to the backup zips in case
        // the host does not already deny access under wp-content/upgrade.
        $htaccess = trailingslashit($base).'.htaccess';
        if (!file_exists($htaccess)) @file_put_contents($htaccess, "Require all denied\n" . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        $index = trailingslashit($base).'index.php';
        if (!file_exists($index)) @file_put_contents($index, "<?php\n// Silence is golden.\n");
        return $base;
    }

    private static function backup_current(): array {
        $root = self::backup_root();
        if (!$root || !class_exists('ZipArchive')) return ['ok'=>false,'message'=>'ZipArchive is unavailable; backup skipped.'];
        $zip = trailingslashit($root).'chandan-digital-seo-'.SEOM_VERSION.'-'.gmdate('Ymd-His').'.zip';
        $source = untrailingslashit(SEOM_DIR);
        $archive = new ZipArchive();
        if ($archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return ['ok'=>false,'message'=>'Could not create backup archive.'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        $baseLen = strlen($source) + 1;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $local = substr($file->getPathname(), $baseLen);
                $archive->addFile($file->getPathname(), $local);
            }
        }
        $archive->close();
        self::prune_backups();
        return ['ok'=>true,'path'=>$zip];
    }

    private static function prune_backups(): void {
        $s = self::settings();
        $root = self::backup_root(); if (!$root) return;
        $files = glob(trailingslashit($root).'chandan-digital-seo-*.zip');
        if (!is_array($files)) return;
        rsort($files, SORT_STRING);
        $keep = max(1, absint($s['backup_retention']));
        foreach (array_slice($files, $keep) as $file) @unlink($file);
    }

    public static function backup_before_install($return, $hook_extra) {
        if (!is_array($hook_extra) || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::plugin_basename()) return $return;
        $backup = self::backup_current();
        update_option('seom_last_backup', $backup, false);
        return $return;
    }

    public static function after_update($upgrader, $hook_extra): void {
        if (!is_array($hook_extra) || ($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') return;
        $plugins = isset($hook_extra['plugins']) ? (array)$hook_extra['plugins'] : [];
        if (!in_array(self::plugin_basename(), $plugins, true)) return;
        delete_transient('seom_latest_release_cache');
        update_option('seom_last_update', ['time'=>time(),'version'=>SEOM_VERSION], false);
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_update_save')) wp_die('Unauthorized.');
        $s = self::settings();
        $s['owner'] = sanitize_key(wp_unslash($_POST['owner'] ?? ''));
        $s['repo'] = sanitize_key(wp_unslash($_POST['repo'] ?? ''));
        $s['branch'] = sanitize_key(wp_unslash($_POST['branch'] ?? 'main')) ?: 'main';
        $s['asset'] = sanitize_file_name(wp_unslash($_POST['asset'] ?? ''));
        if (isset($_POST['token']) && trim((string)wp_unslash($_POST['token'])) !== '') $s['token'] = self::encrypt(sanitize_text_field(wp_unslash($_POST['token'])));
        $s['auto_update'] = !empty($_POST['auto_update']) ? 1 : 0;
        $s['backup_retention'] = min(20, max(1, absint($_POST['backup_retention'] ?? 3)));
        update_option(self::OPTION, $s, false);
        delete_transient('seom_latest_release_cache');
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-tools-group','subtab'=>'updates','updated'=>1], admin_url('admin.php'))); exit;
    }

    public static function manual_check(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_update_check')) wp_die('Unauthorized.');
        delete_transient('seom_latest_release_cache');
        $release = self::latest_release();
        if (is_wp_error($release)) $msg = $release->get_error_message();
        else $msg = self::version_from_release($release) && version_compare(self::version_from_release($release), SEOM_VERSION, '>') ? 'update' : 'latest';
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-tools-group','subtab'=>'updates','check'=>$msg], admin_url('admin.php'))); exit;
    }

    public static function rollback(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_rollback')) wp_die('Unauthorized.');
        $path = isset($_GET['backup']) ? rawurldecode(wp_unslash($_GET['backup'])) : '';
        $root = realpath(self::backup_root());
        $real = $path ? realpath($path) : false;
        if (!$root || !$real || strpos($real, trailingslashit($root)) !== 0 || strtolower(substr($real, -4)) !== '.zip' || !class_exists('ZipArchive')) wp_die('Invalid backup.');
        $zip = new ZipArchive(); if ($zip->open($real) !== true) wp_die('Could not open backup.');
        $temp = trailingslashit(WP_CONTENT_DIR).'upgrade/seom-rollback-'.wp_generate_password(8,false,false);
        wp_mkdir_p($temp); $zip->extractTo($temp); $zip->close();
        global $wp_filesystem;
        require_once ABSPATH.'wp-admin/includes/file.php';
        WP_Filesystem();
        if (!$wp_filesystem) wp_die('WordPress filesystem could not initialize.');
        $source = trailingslashit($temp); $target = untrailingslashit(SEOM_DIR);
        // Move the live plugin folder aside instead of deleting it outright, so a
        // failed restore below still leaves a working copy the site can fall back
        // to instead of a missing plugin directory.
        $safety = trailingslashit(WP_CONTENT_DIR).'upgrade/seom-rollback-safety-'.wp_generate_password(8,false,false);
        if (!$wp_filesystem->move($target, $safety)) {
            $wp_filesystem->delete($temp, true);
            wp_die('Rollback failed: could not move the current plugin files aside.');
        }
        if (!$wp_filesystem->move($source, $target)) {
            // Restore failed: put the original files back so the site keeps working.
            $wp_filesystem->move($safety, $target);
            $wp_filesystem->delete($temp, true);
            wp_die('Rollback failed: the backup could not be restored. Your previous plugin files were kept in place.');
        }
        $wp_filesystem->delete($safety, true);
        delete_transient('seom_latest_release_cache');
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-tools-group','subtab'=>'updates','rollback'=>1], admin_url('admin.php'))); exit;
    }

    public static function admin_notice(): void {
        if (!current_user_can('manage_options')) return;
        $release = get_transient('seom_latest_release_cache');
        if (!is_array($release) || empty($release['tag_name'])) return;
        $new = self::version_from_release($release); if (!$new || !version_compare($new, SEOM_VERSION, '>')) return;
        $url = admin_url('plugins.php');
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Chandan Digital SEO update available:</strong> '.esc_html($new).' — <a href="'.esc_url($url).'">Open Plugins → Updates</a>.</p></div>';
    }

    public static function view(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $s = self::settings();
        $release = get_transient('seom_latest_release_cache');
        $last = get_option('seom_last_update', []);
        $backup = get_option('seom_last_backup', []);
        $root = self::backup_root();
        $backups = $root ? glob(trailingslashit($root).'chandan-digital-seo-*.zip') : [];
        if (!is_array($backups)) $backups = [];
        rsort($backups, SORT_STRING);
        include SEOM_DIR.'admin/views/updates.php';
    }
}
