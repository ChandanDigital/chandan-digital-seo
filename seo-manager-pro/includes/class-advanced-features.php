<?php
if (!defined('ABSPATH')) exit;

final class SEO_Manager_Advanced_Features {
    public static function init(): void {
        add_action('admin_init',[__CLASS__,'register']);
        add_action('wp_head',[__CLASS__,'verification_tags'],2);
        add_filter('the_content',[__CLASS__,'external_link_tweaks'],99);
        add_filter('term_link',[__CLASS__,'strip_category_base'],10,3);
        add_filter('category_rewrite_rules',[__CLASS__,'category_rewrite_rules']);
        add_action('template_redirect',[__CLASS__,'attachment_redirect'],2);
        add_filter('wp_robots',[__CLASS__,'robots_tweaks']);
        add_action('init',[__CLASS__,'llms_route']);
        add_filter('query_vars',[__CLASS__,'query_vars']);
        add_action('template_redirect',[__CLASS__,'serve_llms'],3);
        add_action('save_post',[__CLASS__,'notify_search_engines'],30,3);
        add_action('admin_post_seom_clear_update_history',[__CLASS__,'clear_update_history']);
        add_action('admin_post_seom_save_advanced',[__CLASS__,'save_admin']);
    }
    public static function register(): void {
        $keys=['google_verification','bing_verification','yandex_verification','baidu_verification','analytics_id','nofollow_external','open_external_blank','nofollow_image_links','noindex_empty_archives','redirect_attachments','redirect_orphan_attachments','orphan_attachment_url','strip_category_base','llms_enabled','llms_extra','indexnow_enabled','indexnow_key','image_auto_alt','image_auto_title','video_auto_schema'];
        foreach($keys as $key) register_setting('seom_advanced',$key);
    }
    /**
     * Which option keys each form on the admin side actually owns.
     *
     * Both the "SEO Tweaks" screen and the "SEO Automation" screen submit to
     * this same handler, but they render different subsets of the fields.
     * Saving must therefore only touch the keys belonging to the submitting
     * form: an unchecked checkbox and a field the form never rendered look
     * identical in $_POST, so saving every key from a partial form would
     * blank out settings the user could not even see.
     */
    private const SCOPES = [
        'automation' => [
            'text' => [],
            'bool' => ['image_auto_alt','image_auto_title','video_auto_schema'],
            'page' => ['page'=>'seo-manager-content','subtab'=>'automation'],
        ],
        'tweaks' => [
            'text' => ['google_verification','bing_verification','yandex_verification','baidu_verification','analytics_id','llms_extra'],
            'bool' => ['nofollow_external','open_external_blank','nofollow_image_links','noindex_empty_archives','redirect_attachments','redirect_orphan_attachments','strip_category_base','llms_enabled'],
            'page' => ['page'=>'seo-manager-technical','subtab'=>'tweaks'],
        ],
    ];

    private static function scope(string $scope): array {
        return self::SCOPES[$scope] ?? self::SCOPES['tweaks'];
    }

    public static function save_admin(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_save_advanced')) wp_die('Unauthorized.');
        $scope = sanitize_key(wp_unslash($_POST['seom_scope'] ?? 'tweaks'));
        if (!isset(self::SCOPES[$scope])) $scope = 'tweaks';
        self::save($_POST, $scope);
        flush_rewrite_rules(false);
        // Return to the screen the form was actually submitted from, instead of
        // always landing on SEO Tweaks.
        wp_safe_redirect(add_query_arg(self::scope($scope)['page'] + ['advanced_saved'=>1], admin_url('admin.php')));
        exit;
    }

    /**
     * @param string $scope Which form is saving. Defaults to the full legacy
     *                      key set so any existing caller keeps working.
     */
    public static function save(array $post, string $scope = 'all'): void {
        if ($scope === 'all') {
            $text = array_merge(self::SCOPES['tweaks']['text'], self::SCOPES['automation']['text']);
            $bool = array_merge(self::SCOPES['tweaks']['bool'], self::SCOPES['automation']['bool']);
        } else {
            $config = self::scope($scope);
            $text = $config['text'];
            $bool = $config['bool'];
        }
        foreach($text as $k) seom_update($k, $k==='llms_extra'?sanitize_textarea_field(wp_unslash($post[$k]??'')):sanitize_text_field(wp_unslash($post[$k]??'')));
        if(array_key_exists('orphan_attachment_url',$post)) seom_update('orphan_attachment_url',seom_normalize_destination($post['orphan_attachment_url']??''));
        foreach($bool as $k) seom_update($k, isset($post[$k])?1:0);
    }
    public static function verification_tags(): void {
        if(is_admin()) return;
        $map=['google_verification'=>'google-site-verification','bing_verification'=>'msvalidate.01','yandex_verification'=>'yandex-verification','baidu_verification'=>'baidu-site-verification'];
        foreach($map as $opt=>$name){$v=(string)seom_get($opt,'');if($v)echo '<meta name="'.esc_attr($name).'" content="'.esc_attr($v).'">'."\n";}
        $analytics=trim((string)seom_get('analytics_id','')); if($analytics && preg_match('/^(G-[A-Z0-9]+|GT-[A-Z0-9]+)$/i',$analytics)){echo '<script async src="https://www.googletagmanager.com/gtag/js?id='.esc_attr($analytics).'" data-seom-google-analytics="1"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","'.esc_js($analytics).'",{anonymize_ip:true});</script>\n';}
    }
    public static function external_link_tweaks(string $content): string {
        if(is_admin()||!in_the_loop()||!is_main_query())return$content; if(!class_exists('DOMDocument'))return$content; if(!(int)seom_get('nofollow_external',0)&&!(int)seom_get('open_external_blank',0)&&!(int)seom_get('nofollow_image_links',0))return$content;
        $site_host=parse_url(home_url('/'),PHP_URL_HOST); $dom=new DOMDocument(); libxml_use_internal_errors(true); if(!$dom->loadHTML('<?xml encoding="utf-8" ?><div id="seomwrap">'.$content.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD))return$content; $wrap=$dom->getElementById('seomwrap'); if(!$wrap)return$content; foreach($dom->getElementsByTagName('a') as $a){$href=(string)$a->getAttribute('href');$host=parse_url($href,PHP_URL_HOST);if(!$host||!$site_host||strcasecmp($host,$site_host)===0)continue;$rel=preg_split('/\s+/',trim((string)$a->getAttribute('rel')),-1,PREG_SPLIT_NO_EMPTY);if((int)seom_get('nofollow_external',0)&&!in_array('nofollow',$rel,true))$rel[]='nofollow';if((int)seom_get('open_external_blank',0)){$a->setAttribute('target','_blank');if(!in_array('noopener',$rel,true))$rel[]='noopener';if(!in_array('noreferrer',$rel,true))$rel[]='noreferrer';}$a->setAttribute('rel',implode(' ',$rel));}
        foreach($dom->getElementsByTagName('a') as $a){$hasImg=$a->getElementsByTagName('img')->length>0;if(!$hasImg||(int)seom_get('nofollow_image_links',0)===0)continue;$href=(string)$a->getAttribute('href');$host=parse_url($href,PHP_URL_HOST);if(!$host||($site_host&&strcasecmp($host,$site_host)===0))continue;$rel=preg_split('/\s+/',trim((string)$a->getAttribute('rel')),-1,PREG_SPLIT_NO_EMPTY);if(!in_array('nofollow',$rel,true))$rel[]='nofollow';$a->setAttribute('rel',implode(' ',$rel));}
        $out='';foreach($wrap->childNodes as $child)$out.=$dom->saveHTML($child);return$out;
    }
    public static function category_rewrite_rules(array $rules): array {
        if(!(int)seom_get('strip_category_base',0)) return $rules;
        $out=[];
        foreach($rules as $rule=>$query){
            $base=get_option('category_base')?:'category';
            $pattern=preg_quote($base,'#');
            $new=preg_replace('#^'.$pattern.'/+#','',$rule);
            $out[$new?:$rule]=$query;
        }
        return $out;
    }

    public static function strip_category_base(string $termlink, WP_Term $term, string $taxonomy): string { if($taxonomy!=='category'||!(int)seom_get('strip_category_base',0))return$termlink; $base=get_option('category_base');$base=$base?:'category';$path='/'.$base.'/';$home=home_url('/');return str_replace($home.$path,$home,$termlink); }
    public static function attachment_redirect(): void { if(!is_attachment()||!(int)seom_get('redirect_attachments',1))return;global $post;$parent=$post?wp_get_post_parent_id($post->ID):0;$target=$parent?get_permalink($parent):((int)seom_get('redirect_orphan_attachments',0)?(string)seom_get('orphan_attachment_url',''):'');if($target){wp_safe_redirect($target,301);exit;} }
    public static function robots_tweaks(array $robots): array { if((int)seom_get('noindex_empty_archives',1)){if(is_category()||is_tag()){$obj=get_queried_object();if($obj instanceof WP_Term && (int)$obj->count===0){$robots['noindex']=true;$robots['nofollow']=false;}}}return$robots; }
    public static function query_vars(array $vars): array {$vars[]='seom_llms';return$vars;}
    public static function llms_route(): void {add_rewrite_rule('^llms\.txt$','index.php?seom_llms=1','top');}
    public static function serve_llms(): void {if(!get_query_var('seom_llms')||!(int)seom_get('llms_enabled',0))return;header('Content-Type: text/plain; charset=utf-8');echo"# ".get_bloginfo('name')."\n\n";echo wp_strip_all_tags(get_bloginfo('description'))."\n\n";$posts=get_posts(['post_type'=>seom_post_types(),'post_status'=>'publish','posts_per_page'=>200,'orderby'=>'modified','order'=>'DESC']);foreach($posts as $p){echo '- ['.get_the_title($p->ID).']('.get_permalink($p->ID).")\n";}if($extra=trim((string)seom_get('llms_extra','')))echo"\n".$extra."\n";exit;}
    public static function notify_search_engines(int $post_id, WP_Post $post, bool $update): void {if(!$update||$post->post_status!=='publish'||wp_is_post_revision($post_id))return;if((int)seom_get('indexnow_enabled',0))self::indexnow(get_permalink($post_id));}
    public static function indexnow(string $url): void { $key=trim((string)seom_get('indexnow_key','')); if(!$key||!wp_http_validate_url($url))return;$host=parse_url(home_url('/'),PHP_URL_HOST);if(!$host)return;$endpoint='https://api.indexnow.org/indexnow';wp_remote_post($endpoint,['timeout'=>15,'body'=>wp_json_encode(['host'=>$host,'key'=>$key,'urlList'=>[$url]]),'headers'=>['Content-Type'=>'application/json; charset=utf-8']]); }
    public static function clear_update_history(): void {if(!current_user_can('manage_options')||!check_admin_referer('seom_clear_update_history'))wp_die('Unauthorized.');delete_option('seom_google_updates_history');delete_option('seom_google_update_ids');wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'google','cleared'=>1],admin_url('admin.php')));exit;}
}
