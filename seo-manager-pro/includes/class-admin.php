<?php
if (!defined('ABSPATH')) exit;

final class SEO_Manager_Admin {
    public static function init(): void {
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
        add_action('admin_init',[__CLASS__,'privacy_setting']);
        add_action('wp_ajax_seom_bulk_list',[__CLASS__,'bulk_list']);
        add_action('wp_ajax_seom_bulk_save',[__CLASS__,'bulk_save']);
        add_action('admin_post_seom_repair_rewrites',[__CLASS__,'repair_rewrites']);
        add_filter('post_row_actions',[__CLASS__,'post_actions'],10,2);
        add_filter('page_row_actions',[__CLASS__,'post_actions'],10,2);
    }
    public static function privacy_setting(): void {
        register_setting('seom_privacy','seom_delete_data_on_uninstall',[
            'type'=>'boolean','sanitize_callback'=>'rest_sanitize_boolean','default'=>0,
        ]);
    }
    public static function menu(): void {
        add_menu_page('Chandan Digital SEO','Chandan Digital SEO','manage_options','seo-manager',[__CLASS__,'dashboard'],'dashicons-chart-area',58);
        $groups=[
            'seo-manager-appearance'=>['Search Appearance','render_appearance'],
            'seo-manager-technical'=>['Technical SEO','render_technical'],
            'seo-manager-indexing-group'=>['Google & Indexing','render_indexing_group'],
            'seo-manager-links-group'=>['Links & Redirects','render_links_group'],
            'seo-manager-content'=>['Content SEO','render_content'],
            'seo-manager-tools-group'=>['Tools & Data','render_tools_group'],
            'seo-manager-business'=>['Google Business Profile','render_business_profile'],
            'seo-manager-commerce'=>['WooCommerce SEO','render_woocommerce'],
        ];
        foreach($groups as $slug=>$item){
            add_submenu_page('seo-manager',$item[0],$item[0],'manage_options',$slug,[__CLASS__,$item[1]]);
        }

        // Register legacy page slugs as hidden compatibility routes.
        // Older bookmarks, menu links and cached admin URLs can still point to these slugs.
        // They must remain registered or WordPress returns "Sorry, you are not allowed to access this page."
        $legacy=[
            'seo-manager-settings'=>'render_settings',
            'seo-manager-titles'=>'render_settings',
            'seo-manager-sitemap'=>'render_technical',
            'seo-manager-google'=>'render_google',
            'seo-manager-instant-indexing'=>'render_instant_indexing',
            'seo-manager-search'=>'render_search',
            'seo-manager-redirects'=>'render_redirects',
            'seo-manager-404'=>'render_404',
            'seo-manager-audit'=>'render_audit',
            'seo-manager-schema'=>'render_schema',
            'seo-manager-links'=>'render_links',
            'seo-manager-images'=>'render_images',
            'seo-manager-bulk'=>'render_bulk',
            'seo-manager-import-export'=>'render_import_export',
            'seo-manager-tools'=>'render_tools',
            'seo-manager-advanced'=>'render_advanced',
        ];
        foreach($legacy as $slug=>$callback){
            add_submenu_page('seo-manager','', '', 'manage_options', $slug, [__CLASS__,$callback]);
            remove_submenu_page('seo-manager',$slug);
        }
    }
    public static function assets($hook): void {
        $hook=is_string($hook)?$hook:'';
        if(strpos($hook,'seo-manager')===false && !in_array($hook,['post.php','post-new.php'],true)) return;
        wp_enqueue_style('seom-admin',SEOM_URL.'admin/css/admin.css',[],SEOM_VERSION);
        wp_enqueue_script('seom-admin',SEOM_URL.'admin/js/admin.js',['jquery'],SEOM_VERSION,true);
        wp_localize_script('seom-admin','SEOM',['ajaxurl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('seom_admin')]);
    }
    private static function guard(): void { if(!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to access this page.','seo-manager')); }
    private static function group_shell(string $section,string $title,array $tabs): void {
        self::guard();
        $subtab=sanitize_key($_GET['subtab']??($tabs[0]['key']??''));
        $valid=array_column($tabs,'key'); if(!in_array($subtab,$valid,true))$subtab=$valid[0]??'';
        $view=$tabs[array_search($subtab,$valid,true)]['view']??'';
        include SEOM_DIR.'admin/views/shell.php';
        if($view && file_exists(SEOM_DIR.'admin/views/'.$view.'.php')) include SEOM_DIR.'admin/views/'.$view.'.php';
        echo '</div></main></div></div>';
    }
    public static function dashboard(): void { self::guard(); include SEOM_DIR.'admin/views/dashboard.php'; }
    public static function render_appearance(): void { self::group_shell('appearance','Search Appearance',[['key'=>'general','label'=>'General SEO','view'=>'settings'],['key'=>'social','label'=>'Social SEO','view'=>'social'],['key'=>'schema','label'=>'Schema','view'=>'schema'],['key'=>'templates','label'=>'Schema Templates','view'=>'schema-templates'],['key'=>'local','label'=>'Local SEO','view'=>'local']]); }
    public static function render_technical(): void { self::group_shell('technical','Technical SEO',[['key'=>'sitemap','label'=>'Sitemap','view'=>'sitemap'],['key'=>'robots','label'=>'Robots & Crawl','view'=>'robots'],['key'=>'tweaks','label'=>'SEO Tweaks','view'=>'advanced']]); }
    public static function render_indexing_group(): void { self::group_shell('indexing','Google & Indexing',[['key'=>'google','label'=>'Google Search Console','view'=>'google'],['key'=>'instant','label'=>'Google Instant Indexing','view'=>'instant-indexing'],['key'=>'search','label'=>'Bing & IndexNow','view'=>'search']]); }
    public static function render_links_group(): void { self::group_shell('links','Links & Redirects',[['key'=>'redirects','label'=>'Redirections','view'=>'redirects'],['key'=>'404','label'=>'404 Monitor','view'=>'errors'],['key'=>'internal','label'=>'Internal Links','view'=>'links']]); }
    public static function render_content(): void { self::group_shell('content','Content SEO',[['key'=>'audit','label'=>'SEO Analyzer','view'=>'audit'],['key'=>'images','label'=>'Image SEO','view'=>'images'],['key'=>'bulk','label'=>'Bulk Editor','view'=>'bulk'],['key'=>'automation','label'=>'SEO Automation','view'=>'automation']]); }
    public static function render_woocommerce(): void { self::group_shell('commerce','WooCommerce SEO',[['key'=>'settings','label'=>'Product SEO','view'=>'woocommerce']]); }
    public static function render_business_profile(): void { self::group_shell('business','Google Business Profile',[['key'=>'profile','label'=>'Profile & Locations','view'=>'business-profile']]); }
    public static function render_tools_group(): void { self::group_shell('tools','Tools & Data',[['key'=>'import','label'=>'Import / Export','view'=>'import-export'],['key'=>'status','label'=>'Status & Tools','view'=>'tools'],['key'=>'roles','label'=>'Role Manager','view'=>'role-manager'],['key'=>'updates','label'=>'Plugin Updates','view'=>'updates']]); }
    // Legacy entry points retained so older bookmarks/actions continue to work.
    public static function render_settings(): void { self::group_shell('appearance','Search Appearance',[['key'=>'general','label'=>'General SEO','view'=>'settings'],['key'=>'social','label'=>'Social SEO','view'=>'social'],['key'=>'schema','label'=>'Schema','view'=>'schema'],['key'=>'templates','label'=>'Schema Templates','view'=>'schema-templates'],['key'=>'local','label'=>'Local SEO','view'=>'local']]); }
    public static function render_schema_templates(): void { self::guard(); SEO_Manager_RankMath_Parity::render_schema_templates(); }
    public static function render_google(): void { self::group_shell('indexing','Google & Indexing',[['key'=>'google','label'=>'Google Search Console','view'=>'google'],['key'=>'instant','label'=>'Google Instant Indexing','view'=>'instant-indexing'],['key'=>'search','label'=>'Bing & IndexNow','view'=>'search']]); }
    public static function render_instant_indexing(): void { self::group_shell('indexing','Google & Indexing',[['key'=>'instant','label'=>'Google Instant Indexing','view'=>'instant-indexing'],['key'=>'google','label'=>'Google Search Console','view'=>'google'],['key'=>'search','label'=>'Bing & IndexNow','view'=>'search']]); }
    public static function render_search(): void { self::group_shell('indexing','Google & Indexing',[['key'=>'search','label'=>'Bing & IndexNow','view'=>'search'],['key'=>'google','label'=>'Google Search Console','view'=>'google'],['key'=>'instant','label'=>'Google Instant Indexing','view'=>'instant-indexing']]); }
    public static function render_redirects(): void { self::group_shell('links','Links & Redirects',[['key'=>'redirects','label'=>'Redirections','view'=>'redirects'],['key'=>'404','label'=>'404 Monitor','view'=>'errors'],['key'=>'internal','label'=>'Internal Links','view'=>'links']]); }
    public static function render_404(): void { self::group_shell('links','Links & Redirects',[['key'=>'404','label'=>'404 Monitor','view'=>'errors'],['key'=>'redirects','label'=>'Redirections','view'=>'redirects'],['key'=>'internal','label'=>'Internal Links','view'=>'links']]); }
    public static function render_audit(): void { self::group_shell('content','Content SEO',[['key'=>'audit','label'=>'SEO Analyzer','view'=>'audit'],['key'=>'images','label'=>'Image SEO','view'=>'images'],['key'=>'bulk','label'=>'Bulk Editor','view'=>'bulk'],['key'=>'automation','label'=>'SEO Automation','view'=>'automation']]); }
    public static function render_schema(): void { self::group_shell('appearance','Search Appearance',[['key'=>'schema','label'=>'Schema','view'=>'schema'],['key'=>'templates','label'=>'Schema Templates','view'=>'schema-templates'],['key'=>'general','label'=>'General SEO','view'=>'settings'],['key'=>'social','label'=>'Social SEO','view'=>'social'],['key'=>'local','label'=>'Local SEO','view'=>'local']]); }
    public static function render_links(): void { self::group_shell('links','Links & Redirects',[['key'=>'internal','label'=>'Internal Links','view'=>'links'],['key'=>'redirects','label'=>'Redirections','view'=>'redirects'],['key'=>'404','label'=>'404 Monitor','view'=>'errors']]); }
    public static function render_images(): void { self::group_shell('content','Content SEO',[['key'=>'images','label'=>'Image SEO','view'=>'images'],['key'=>'audit','label'=>'SEO Analyzer','view'=>'audit'],['key'=>'bulk','label'=>'Bulk Editor','view'=>'bulk'],['key'=>'automation','label'=>'SEO Automation','view'=>'automation']]); }
    public static function render_bulk(): void { self::group_shell('content','Content SEO',[['key'=>'bulk','label'=>'Bulk Editor','view'=>'bulk'],['key'=>'audit','label'=>'SEO Analyzer','view'=>'audit'],['key'=>'images','label'=>'Image SEO','view'=>'images']]); }
    public static function render_import_export(): void { self::group_shell('tools','Tools & Data',[['key'=>'import','label'=>'Import / Export','view'=>'import-export'],['key'=>'status','label'=>'Status & Tools','view'=>'tools'],['key'=>'roles','label'=>'Role Manager','view'=>'role-manager']]); }
    public static function render_tools(): void { self::group_shell('tools','Tools & Data',[['key'=>'status','label'=>'Status & Tools','view'=>'tools'],['key'=>'roles','label'=>'Role Manager','view'=>'role-manager'],['key'=>'import','label'=>'Import / Export','view'=>'import-export']]); }
    public static function render_advanced(): void { self::group_shell('technical','Technical SEO',[['key'=>'tweaks','label'=>'SEO Tweaks','view'=>'advanced'],['key'=>'sitemap','label'=>'Sitemap','view'=>'sitemap'],['key'=>'robots','label'=>'Robots & Crawl','view'=>'robots']]); }

    public static function repair_rewrites(): void {
        self::guard();
        if(!check_admin_referer('seom_repair_rewrites')) wp_die('Invalid request.');
        flush_rewrite_rules(false);
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-tools-group','subtab'=>'status','repaired'=>1],admin_url('admin.php')));
        exit;
    }
    public static function post_actions($actions,$post){
        if(!($post instanceof WP_Post)||!current_user_can('edit_post',$post->ID)||(int)seom_get('indexing_enabled',0)!==1)return $actions;
        $url=add_query_arg(['action'=>'seom_indexing_submit','post_id'=>$post->ID,'_wpnonce'=>wp_create_nonce('seom_indexing_manual')],admin_url('admin-post.php'));
        $actions['seom_indexing']='<a href="'.esc_url($url).'">'.esc_html__('Instant Index','seo-manager').'</a>';
        return $actions;
    }
    public static function bulk_list(): void {
        check_ajax_referer('seom_admin','nonce'); if(!current_user_can('edit_posts'))wp_send_json_error();
        $page=max(1,absint($_POST['page']??1));
        $ids=get_posts(['post_type'=>seom_post_types(),'post_status'=>['publish','draft','pending','future'],'posts_per_page'=>100,'paged'=>$page,'fields'=>'ids','orderby'=>'date','order'=>'DESC']);
        $rows=[]; foreach($ids as $id)$rows[]=['id'=>$id,'type'=>get_post_type($id),'title'=>get_the_title($id),'url'=>get_permalink($id),'seo_title'=>seom_meta($id,'title'),'description'=>seom_meta($id,'description'),'keyword'=>seom_meta($id,'keyword')];
        wp_send_json_success($rows);
    }
    public static function bulk_save(): void {
        check_ajax_referer('seom_admin','nonce'); if(!current_user_can('edit_posts'))wp_send_json_error();
        $items=$_POST['items']??[]; if(!is_array($items))wp_send_json_error(); $saved=0;
        foreach($items as $item){if(!is_array($item))continue;$id=absint($item['id']??0);if(!$id||!current_user_can('edit_post',$id))continue;
            foreach(['seo_title'=>'text','description'=>'textarea','keyword'=>'text'] as $field=>$kind){if(!array_key_exists($field,$item))continue;$v=wp_unslash((string)$item[$field]);$v=$kind==='textarea'?sanitize_textarea_field($v):sanitize_text_field($v);$key='_seom_'.($field==='seo_title'?'title':$field);if($v==='')delete_post_meta($id,$key);else update_post_meta($id,$key,$v);}
            $saved++;
        }
        wp_send_json_success(['saved'=>$saved]);
    }
}
