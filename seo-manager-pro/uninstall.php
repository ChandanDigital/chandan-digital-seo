<?php
/**
 * Uninstall Chandan Digital SEO.
 * WordPress runs this file only when an administrator explicitly deletes the plugin.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Remove scheduled tasks created by the plugin.
foreach (array('seom_cleanup_404', 'seom_daily_audit', 'seom_google_sync') as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Remove plugin database tables.
$tables = array(
    $wpdb->prefix . 'seom_redirects',
    $wpdb->prefix . 'seom_404_logs',
    $wpdb->prefix . 'seom_audit',
    $wpdb->prefix . 'seom_indexing_logs',
);
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

// Remove all plugin options, including options added by later plugin versions.
$option_names = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'seom\\_%'"
);
if (is_array($option_names)) {
    foreach ($option_names as $option_name) {
        delete_option($option_name);
    }
}

// Remove SEO post metadata created by the plugin.
$post_meta_keys = array(
    '_seom_title',
    '_seom_description',
    '_seom_keyword',
    '_seom_secondary_keywords',
    '_seom_canonical',
    '_seom_robots',
    '_seom_og_title',
    '_seom_og_description',
    '_seom_og_image',
    '_seom_schema_type',
);
foreach ($post_meta_keys as $meta_key) {
    $wpdb->delete($wpdb->postmeta, array('meta_key' => $meta_key), array('%s'));
}

// Remove taxonomy metadata created by the plugin.
if (isset($wpdb->termmeta)) {
    $term_meta_keys = array(
        '_seom_term_title',
        '_seom_term_description',
        '_seom_term_canonical',
        '_seom_term_robots',
        '_seom_term_og_title',
        '_seom_term_og_description',
        '_seom_term_og_image',
    );
    foreach ($term_meta_keys as $meta_key) {
        $wpdb->delete($wpdb->termmeta, array('meta_key' => $meta_key), array('%s'));
    }
}

// Remove plugin transients if any were created.
$transients = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_seom_%' OR option_name LIKE '_transient_timeout_seom_%'"
);
if (is_array($transients)) {
    foreach ($transients as $transient_option) {
        delete_option($transient_option);
    }
}

flush_rewrite_rules(false);

// Remove temporary Business Profile cache.
$temps = array('gbp_reviews','gbp_posts','gbp_performance','gbp_entity_results'); foreach ($temps as $t) delete_transient('seom_temp_'.$t);
