<?php
/**
 * Plugin Name: Chandan Digital SEO
 * Description: Chandan Digital SEO is an advanced all-in-one SEO toolkit for WordPress with on-page SEO, technical SEO, schema, sitemap, redirects, 404 monitoring, bulk editor, audits, social SEO, local SEO, WooCommerce and indexing integrations.
 * Version: 4.9.4
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Chandan Digital
 * Text Domain: seo-manager
 */
if (!defined('ABSPATH')) exit;
define('SEOM_VERSION','4.9.4');
define('SEOM_FILE',__FILE__);
define('SEOM_DIR',plugin_dir_path(__FILE__));
define('SEOM_URL',plugin_dir_url(__FILE__));
$files=['helpers.php','class-db.php','class-settings.php','class-meta.php','class-schema.php','class-entity-schema.php','class-entity-controller.php','class-sitemap.php','class-robots.php','class-redirects.php','class-404-monitor.php','class-content-analysis.php','class-audit.php','class-taxonomy.php','class-image-seo.php','class-local-seo.php','class-social.php','class-migration.php','class-google.php','class-import-export.php','class-advanced-features.php','class-instant-indexing.php','class-search-engines.php','class-google-business-profile.php','class-rankmath-parity.php','class-updater.php','class-admin.php'];
foreach($files as $file) require_once SEOM_DIR.'includes/'.$file;
register_activation_hook(__FILE__,['SEO_Manager_DB','activate']);
register_deactivation_hook(__FILE__,['SEO_Manager_DB','deactivate']);
final class SEO_Manager_Plugin {
    private static $instance=null;
    public function __construct(){
        $modules = [
            'SEO_Manager_Settings','SEO_Manager_Meta','SEO_Manager_Schema','SEO_Manager_Entity_Schema','SEO_Manager_Entity_Controller',
            'SEO_Manager_Sitemap','SEO_Manager_Robots',
            'SEO_Manager_Redirects','SEO_Manager_404','SEO_Manager_Content_Analysis','SEO_Manager_Audit','SEO_Manager_Taxonomy',
            'SEO_Manager_Image_SEO','SEO_Manager_Social','SEO_Manager_Migration','SEO_Manager_Google',
            'SEO_Manager_Import_Export','SEO_Manager_Advanced_Features','SEO_Manager_Instant_Indexing','SEO_Manager_Search_Engines','SEO_Manager_RankMath_Parity','SEO_Manager_Updater',
            'SEO_Manager_Admin'
        ];
        foreach ($modules as $module) {
            if (class_exists($module) && is_callable([$module, 'init'])) {
                call_user_func([$module, 'init']);
            }
        }
        add_action('init',[$this,'maybe_upgrade'],0);
        add_action('template_redirect',[$this,'capture_404'],20);
        add_action('template_redirect',function(){ if(class_exists('SEO_Manager_Search_Engines')) SEO_Manager_Search_Engines::serve_key_file(); },0);
        add_filter('query_vars',function($vars){$vars[]='seom_robots';return $vars;});
    }
    public static function bootstrap(): void { self::instance(); }
    public static function instance(){if(self::$instance===null){self::$instance=new self();}return self::$instance;}
    public function capture_404():void{SEO_Manager_404::capture();}
    public function maybe_upgrade():void{
        $installed=(string)get_option('seom_version','');
        if($installed!==SEOM_VERSION){
            SEO_Manager_DB::activate();
            // This callback runs at 'init' priority 0, before SEO_Manager_Sitemap's
            // own 'init' (default priority 10) has registered its rules in this
            // same request, so the rules are re-registered here explicitly before
            // flushing to guarantee the flush captures the full, current rule set.
            SEO_Manager_Sitemap::register_rewrites();
            flush_rewrite_rules(false);
            update_option('seom_version', SEOM_VERSION, false);
        }
    }
}
// Bootstrap after WordPress has loaded all active plugins. Keeping module registration out of the
// file-load phase prevents fatal errors caused by optional dependencies or load-order conflicts.
add_action('plugins_loaded', ['SEO_Manager_Plugin', 'bootstrap'], 20);

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    if (current_user_can('activate_plugins')) {
        $settings_url = admin_url('admin.php?page=seo-manager');
        array_unshift($links, '<a href="' . esc_url($settings_url) . '">Settings</a>');
    }
    return $links;
});
