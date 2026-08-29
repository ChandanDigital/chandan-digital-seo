<?php
if (!defined('ABSPATH')) exit;
final class SEO_Manager_Settings {
 public static function init():void{
  add_action('admin_init',[__CLASS__,'register']);
  add_action('admin_post_seom_save_settings',[__CLASS__,'save']);
  add_action('admin_post_seom_save_sitemap',[__CLASS__,'save_sitemap']);
  add_action('admin_post_seom_save_robots',[__CLASS__,'save_robots']);
  add_action('admin_post_seom_save_social',[__CLASS__,'save_social']);
  add_action('admin_post_seom_save_local',[__CLASS__,'save_local']);
 }
 public static function register():void{register_setting('seom_settings','seom_title_format',['sanitize_callback'=>'sanitize_text_field']);}
 private static function url($key,$value):void{seom_update($key,seom_normalize_destination($value));}
 public static function save():void{
  if(!current_user_can('manage_options')||!check_admin_referer('seom_save_settings'))wp_die(esc_html__('Unauthorized.','seo-manager'));
  foreach(['title_format','default_description','site_type','org_name','default_robots','twitter_card','schema_type'] as $f)seom_update($f,sanitize_textarea_field(wp_unslash((string)($_POST[$f]??''))));
  foreach(['org_logo','default_social_image'] as $f)self::url($f,$_POST[$f]??'');
  seom_update('404_retention',max(1,min(3650,absint($_POST['404_retention']??30))));
  seom_update('breadcrumbs',isset($_POST['breadcrumbs'])?1:0);
  wp_safe_redirect(add_query_arg(['page'=>'seo-manager-appearance','subtab'=>'general','updated'=>1],admin_url('admin.php')));exit;
 }
 public static function save_social():void{
  if(!current_user_can('manage_options')||!check_admin_referer('seom_save_social'))wp_die(esc_html__('Unauthorized.','seo-manager'));
  foreach(['enable_og','enable_twitter'] as $f)seom_update($f,isset($_POST[$f])?1:0);
  seom_update('twitter_card',in_array($_POST['twitter_card']??'', ['summary','summary_large_image'], true)?sanitize_key($_POST['twitter_card']):'summary_large_image');
  foreach(['og_default_title','og_default_description'] as $f)seom_update($f,sanitize_textarea_field(wp_unslash((string)($_POST[$f]??''))));
  self::url('default_social_image',$_POST['default_social_image']??'');
  wp_safe_redirect(add_query_arg(['page'=>'seo-manager-appearance','subtab'=>'social','updated'=>1],admin_url('admin.php')));exit;
 }
 public static function save_local():void{
  if(!current_user_can('manage_options')||!check_admin_referer('seom_save_local'))wp_die(esc_html__('Unauthorized.','seo-manager'));
  seom_update('local_enabled',isset($_POST['local_enabled'])?1:0);
  foreach(['local_business_type','local_phone','local_email','local_address','local_city','local_state','local_postcode','local_country','local_hours','local_price_range'] as $f)seom_update($f,sanitize_textarea_field(wp_unslash((string)($_POST[$f]??''))));
  seom_update('local_lat',sanitize_text_field(wp_unslash((string)($_POST['local_lat']??'')))); seom_update('local_lng',sanitize_text_field(wp_unslash((string)($_POST['local_lng']??''))));
  wp_safe_redirect(add_query_arg(['page'=>'seo-manager-appearance','subtab'=>'local','updated'=>1],admin_url('admin.php')));exit;
 }
 public static function save_sitemap():void{
  if(!current_user_can('manage_options')||!check_admin_referer('seom_save_sitemap'))wp_die(esc_html__('Unauthorized.','seo-manager'));
  seom_update('enable_sitemap',isset($_POST['enable_sitemap'])?1:0); seom_update('news_sitemap',isset($_POST['news_sitemap'])?1:0); seom_update('video_sitemap',isset($_POST['video_sitemap'])?1:0);
  SEO_Manager_Sitemap::purge_cache(); SEO_Manager_Sitemap::flush_rules();
  wp_safe_redirect(add_query_arg(['page'=>'seo-manager-technical','subtab'=>'sitemap','updated'=>1],admin_url('admin.php')));exit;
 }
 public static function save_robots():void{
  if(!current_user_can('manage_options')||!check_admin_referer('seom_save_robots'))wp_die(esc_html__('Unauthorized.','seo-manager'));
  foreach(['search_noindex','date_noindex','author_noindex','category_noindex','tag_noindex'] as $f)seom_update($f,isset($_POST[$f])?1:0);
  seom_update('robots_custom',sanitize_textarea_field(wp_unslash((string)($_POST['robots_custom']??''))));
  wp_safe_redirect(add_query_arg(['page'=>'seo-manager-technical','subtab'=>'robots','updated'=>1],admin_url('admin.php')));exit;
 }
}
