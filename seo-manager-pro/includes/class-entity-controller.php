<?php
/**
 * Entity Settings Controller.
 *
 * Handles the save request from the merged "Knowledge Panel & Entity SEO"
 * screen — Knowledge Graph fields, the five dedicated social profile
 * fields, and the advanced founder/founding-date/contact fields. Local
 * SEO fields (address, phone, hours) keep using the plugin's existing
 * SEO_Manager_Settings::save_local() handler, since that panel and its
 * option names are unchanged.
 *
 * @package SEO_Manager
 */

if (!defined('ABSPATH')) exit;

final class SEO_Manager_Entity_Controller {

    /**
     * Registers the admin-post handler.
     */
    public static function init(): void {
        add_action('admin_post_seom_save_entity_schema', [__CLASS__, 'save']);
    }

    /**
     * Validates, sanitizes and stores every field on the merged panel.
     */
    public static function save(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_save_entity_schema')) {
            wp_die(esc_html__('Unauthorized.', 'seo-manager'));
        }

        // Plain text fields.
        $text_fields = [
            'entity_name' => 'knowledge_entity_name',
            'entity_type' => 'knowledge_entity_type',
            'entity_id'   => 'knowledge_entity_id',
            'place_id'    => 'knowledge_place_id',
            'founder'     => 'knowledge_founder',
        ];
        foreach ($text_fields as $post_key => $option_key) {
            seom_update($option_key, sanitize_text_field(wp_unslash((string) ($_POST[$post_key] ?? ''))));
        }

        // Long text fields.
        $textarea_fields = [
            'entity_description' => 'knowledge_entity_description',
            'entity_sameas'      => 'knowledge_entity_sameas',
        ];
        foreach ($textarea_fields as $post_key => $option_key) {
            seom_update($option_key, sanitize_textarea_field(wp_unslash((string) ($_POST[$post_key] ?? ''))));
        }

        // URL fields — validated and stripped of anything unsafe.
        $url_fields = [
            'entity_logo'        => 'knowledge_entity_logo',
            'google_maps_url'    => 'knowledge_google_maps_url',
            'social_facebook'    => 'knowledge_social_facebook',
            'social_twitter'     => 'knowledge_social_twitter',
            'social_instagram'   => 'knowledge_social_instagram',
            'social_linkedin'    => 'knowledge_social_linkedin',
            'social_youtube'     => 'knowledge_social_youtube',
        ];
        foreach ($url_fields as $post_key => $option_key) {
            $raw = wp_unslash((string) ($_POST[$post_key] ?? ''));
            seom_update($option_key, $raw !== '' ? esc_url_raw($raw) : '');
        }

        // Founding date — keep only real dates (YYYY, YYYY-MM or YYYY-MM-DD).
        $founding_date = sanitize_text_field(wp_unslash((string) ($_POST['founding_date'] ?? '')));
        seom_update('knowledge_founding_date', preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $founding_date) ? $founding_date : '');

        // Corporate contact number — text field is enough here; the schema
        // generator only ever prints it inside contactPoint, never as a link.
        seom_update('knowledge_contact_phone', sanitize_text_field(wp_unslash((string) ($_POST['contact_phone'] ?? ''))));

        wp_safe_redirect(add_query_arg(
            ['page' => 'seo-manager-business', 'gbp_knowledge_saved' => 1],
            admin_url('admin.php')
        ));
        exit;
    }
}
