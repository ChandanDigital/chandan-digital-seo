<?php
if (!defined('ABSPATH')) exit;
function seom_get(string $key,$default=''){return get_option('seom_'.$key,$default);}
function seom_update(string $key,$value):bool{return update_option('seom_'.$key,$value,false);}
function seom_meta(int $post_id,string $key,$default=''){ $v=get_post_meta($post_id,'_seom_'.$key,true); return $v!==''?$v:$default; }
function seom_delete_meta(int $post_id,string $key):void{delete_post_meta($post_id,'_seom_'.$key);}
function seom_sanitize_text($v):string{return sanitize_text_field(wp_unslash((string)$v));}
function seom_sanitize_url($v):string{return esc_url_raw(wp_unslash((string)$v));}
function seom_clean_path($v):string{ $v='/'.ltrim((string)$v,'/'); $p=parse_url($v,PHP_URL_PATH); return untrailingslashit($p?:'/')?:'/'; }
function seom_title_for_post(int $id):string{ $custom=seom_meta($id,'title'); if($custom)return $custom; $title=get_the_title($id); $fmt=(string)seom_get('title_format','%%title%% | %%sitename%%'); return str_replace(['%%title%%','%%sitename%%','%%separator%%'],[$title,get_bloginfo('name'),'|'],$fmt); }
function seom_desc_for_post(int $id):string{ $custom=seom_meta($id,'description'); if($custom)return $custom; $fallback=(string)seom_get('default_description','')?:(string)get_bloginfo('description'); $post=get_post($id); if(!$post)return $fallback; $text=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags(strip_shortcodes($post->post_content)))); return wp_trim_words($text?:$fallback,28,'…'); }
function seom_default_description():string{ return (string)seom_get('default_description','')?:(string)get_bloginfo('description'); }
function seom_title_for_archive(string $label):string{ $fmt=(string)seom_get('title_format','%%title%% | %%sitename%%'); return str_replace(['%%title%%','%%sitename%%','%%separator%%'],[$label,get_bloginfo('name'),'|'],$fmt); }
function seom_default_image():string{ $img=(string)seom_get('default_social_image',''); return $img?:''; }
function seom_robots_array(int $id):array{ $raw=seom_meta($id,'robots',''); if(!$raw)return ['index'=>'index','follow'=>'follow']; $arr=json_decode((string)$raw,true); return is_array($arr)?$arr:['index'=>'index','follow'=>'follow']; }
function seom_score_label(int $score):string{return $score>=80?'Good':($score>=60?'Needs Improvement':'Critical');}
function seom_readability(string $text):int{ $text=trim($text); if($text==='')return 0; $sentences=max(1,preg_match_all('/[.!?]+/u',$text,$m)); $words=max(1,count(preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY))); $syllables=0; foreach(preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY) as $word){$w=mb_strtolower(preg_replace('/[^\pL]/u','',$word)); $syllables+=max(1,preg_match_all('/[aeiouy]+/u',$w,$m));} $grade=206.835-1.015*($words/$sentences)-84.6*($syllables/$words); return (int)max(0,min(100,round($grade))); }
function seom_post_types():array{ return array_values(get_post_types(['public'=>true],'names')); }

function seom_normalize_destination($value): string {
    $value = trim(wp_unslash((string)$value));
    if ($value === '') return '';
    if ($value === '/' || substr($value, 0, 1) === '/') return home_url('/' . ltrim($value, '/'));
    $value = esc_url_raw($value);
    return $value && wp_http_validate_url($value) ? $value : '';
}

function seom_admin_page_url(string $section='dashboard', string $subtab=''): string {
    $map=[
        'dashboard'=>'seo-manager',
        'appearance'=>'seo-manager-appearance',
        'technical'=>'seo-manager-technical',
        'indexing'=>'seo-manager-indexing-group',
        'links'=>'seo-manager-links-group',
        'content'=>'seo-manager-content',
        'tools'=>'seo-manager-tools-group',
        'business'=>'seo-manager-business',
    ];
    $slug=$map[$section]??$map['dashboard'];
    $args=['page'=>$slug];
    if($subtab!=='') $args['subtab']=sanitize_key($subtab);
    return add_query_arg($args,admin_url('admin.php'));
}
