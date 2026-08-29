<?php
if (!defined('ABSPATH')) exit;
final class SEO_Manager_Meta {
 public static function init():void{add_filter('wp_robots',[__CLASS__,'robots'],10,1);add_action('add_meta_boxes',[__CLASS__,'box']);add_action('save_post',[__CLASS__,'save'],10,3);add_action('wp_head',[__CLASS__,'output'],1);add_filter('document_title_parts',[__CLASS__,'document_title'],99);add_filter('manage_posts_columns',[__CLASS__,'columns']);add_action('manage_posts_custom_column',[__CLASS__,'column'],10,2);add_filter('manage_pages_columns',[__CLASS__,'columns']);add_action('manage_pages_custom_column',[__CLASS__,'column'],10,2);}
 public static function box():void{foreach(seom_post_types() as $type)add_meta_box('seom_box',__('Chandan Digital SEO','seo-manager'),[__CLASS__,'render'],$type,'normal','high');}
 public static function render(WP_Post $post):void{wp_nonce_field('seom_meta_save','seom_meta_nonce');$title=seom_meta($post->ID,'title');$desc=seom_meta($post->ID,'description');$kw=seom_meta($post->ID,'keyword');$secondary=seom_meta($post->ID,'secondary_keywords');$canon=seom_meta($post->ID,'canonical');$robots=seom_meta($post->ID,'robots');$ogt=seom_meta($post->ID,'og_title');$ogd=seom_meta($post->ID,'og_description');$ogi=seom_meta($post->ID,'og_image',get_the_post_thumbnail_url($post->ID,'full')?:seom_default_image());$schema=seom_meta($post->ID,'schema_type','auto'); include SEOM_DIR.'admin/views/meta-box.php';}
 public static function save(int $id,WP_Post $post,bool $update):void{if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;if(wp_is_post_revision($id)||wp_is_post_autosave($id))return;if(!isset($_POST['seom_meta_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['seom_meta_nonce'])),'seom_meta_save'))return;if(!current_user_can('edit_post',$id))return;$fields=['title'=>'text','description'=>'textarea','keyword'=>'text','secondary_keywords'=>'textarea','canonical'=>'url','og_title'=>'text','og_description'=>'textarea','og_image'=>'url','robots'=>'textarea','schema_type'=>'text'];foreach($fields as $k=>$kind){if(!array_key_exists('seom_'.$k,$_POST))continue;$v=wp_unslash($_POST['seom_'.$k]);$v=$kind==='url'?esc_url_raw($v):($kind==='textarea'?sanitize_textarea_field($v):sanitize_text_field($v));if($v==='')delete_post_meta($id,'_seom_'.$k);else update_post_meta($id,'_seom_'.$k,$v);}}
 public static function columns(array $columns):array { $out=[];foreach($columns as $k=>$v){$out[$k]=$v;if($k==='title')$out['seom_score']='SEO Score';}return$out; }
 public static function column(string $column,int $post_id):void { if($column!=='seom_score')return;$a=SEO_Manager_Content_Analysis::analyze($post_id);$score=(int)($a['score']??0);$class=$score>=80?'ok':($score>=60?'warn':'bad');echo '<span class="seom-badge '.esc_attr($class).'">'.esc_html($score).'/100</span>'; }
 public static function document_title(array $parts):array{
  if(is_singular()){$id=get_queried_object_id();if(!$id)return $parts;$parts['title']=seom_title_for_post($id);unset($parts['tagline']);return $parts;}
  if(is_category()||is_tag()||is_tax()){$term=get_queried_object();if($term instanceof WP_Term){$custom=(string)get_term_meta($term->term_id,'_seom_title',true);$parts['title']=seom_title_for_archive($custom?:single_term_title('',false));unset($parts['tagline']);}return $parts;}
  if(is_post_type_archive()){$parts['title']=seom_title_for_archive(post_type_archive_title('',false));unset($parts['tagline']);return $parts;}
  if(is_author()){$parts['title']=seom_title_for_archive(get_the_author_meta('display_name',(int)get_query_var('author')));unset($parts['tagline']);return $parts;}
  if(is_date()){$label=is_day()?get_the_date():(is_month()?get_the_date('F Y'):get_the_date('Y'));$parts['title']=seom_title_for_archive($label);unset($parts['tagline']);return $parts;}
  if(is_search()){$parts['title']=seom_title_for_archive(sprintf(__('Search results for "%s"','seo-manager'),get_search_query()));unset($parts['tagline']);return $parts;}
  if(is_404()){$parts['title']=seom_title_for_archive(__('Page not found','seo-manager'));unset($parts['tagline']);return $parts;}
  if(is_home()&&!is_front_page()){$page_id=(int)get_option('page_for_posts');if($page_id){$parts['title']=seom_title_for_post($page_id);unset($parts['tagline']);}return $parts;}
  if(is_front_page()){$parts['title']=seom_title_for_archive(get_bloginfo('name'));unset($parts['tagline']);return $parts;}
  return $parts;
 }
 public static function output():void{
 if(is_admin()||wp_doing_ajax())return;
 if(is_singular()){$id=get_queried_object_id();if(!$id)return;$desc=seom_desc_for_post($id);$canon=seom_meta($id,'canonical',get_permalink($id));echo "\n".'<meta name="description" content="'.esc_attr($desc).'">' . "\n" . '<link rel="canonical" href="'.esc_url($canon).'">' . "\n";SEO_Manager_Social::output($id);SEO_Manager_Schema::output($id);return;}
 if(is_category()||is_tag()||is_tax()){$obj=get_queried_object();if($obj instanceof WP_Term){$desc=(string)get_term_meta($obj->term_id,'_seom_description',true);if($desc==='')$desc=wp_strip_all_tags((string)term_description($obj->term_id));if($desc==='')$desc=seom_default_description();$canon=(string)get_term_meta($obj->term_id,'_seom_canonical',true);if(!$canon){$link=get_term_link($obj);$canon=is_wp_error($link)?'':$link;}if($desc!=='')echo '<meta name="description" content="'.esc_attr($desc).'">' . "\n";if($canon)echo '<link rel="canonical" href="'.esc_url($canon).'">' . "\n";self::archive_social_and_schema($desc,$canon,$obj->name);}return;}
 if(is_post_type_archive()||is_author()||is_date()||is_home()||is_front_page()){$canon=self::archive_canonical();$desc=seom_default_description();$title=wp_strip_all_tags(get_the_archive_title());if($desc!=='')echo '<meta name="description" content="'.esc_attr($desc).'">' . "\n";if($canon)echo '<link rel="canonical" href="'.esc_url($canon).'">' . "\n";self::archive_social_and_schema($desc,$canon,$title?:get_bloginfo('name'));return;}
 }
 private static function archive_canonical():string{
  if(is_front_page())return home_url('/');
  if(is_home()){$page_id=(int)get_option('page_for_posts');return $page_id?(string)get_permalink($page_id):home_url('/');}
  if(is_post_type_archive()){$link=get_post_type_archive_link((string)get_query_var('post_type'));return $link?:'';}
  if(is_author())return (string)get_author_posts_url((int)get_query_var('author'));
  if(is_day())return (string)get_day_link((int)get_query_var('year'),(int)get_query_var('monthnum'),(int)get_query_var('day'));
  if(is_month())return (string)get_month_link((int)get_query_var('year'),(int)get_query_var('monthnum'));
  if(is_year())return (string)get_year_link((int)get_query_var('year'));
  return '';
 }
 private static function archive_social_and_schema(string $desc,string $canon,string $title):void{
  if((int)seom_get('enable_og',1)){$tags=[['og:title',$title],['og:type','website'],['og:site_name',get_bloginfo('name')]];if($desc!=='')$tags[]=['og:description',$desc];if($canon!=='')$tags[]=['og:url',$canon];foreach($tags as $m)echo '<meta property="'.esc_attr($m[0]).'" content="'.esc_attr($m[1]).'">' . "\n";$img=seom_default_image();if($img)echo '<meta property="og:image" content="'.esc_url($img).'">' . "\n";}
  if((int)seom_get('enable_schema',1)){$url=$canon?:home_url('/');$node=['@type'=>'CollectionPage','@id'=>$url.'#primary','url'=>$url,'name'=>$title,'isPartOf'=>['@id'=>home_url('/#website')]];if($desc!=='')$node['description']=$desc;echo '<script type="application/ld+json">'.wp_json_encode(['@context'=>'https://schema.org','@graph'=>[$node]],JSON_UNESCAPED_UNICODE).'</script>' . "\n";}
 }
 public static function robots(array $robots):array{
  $noindex=false;
  if(is_search())$noindex=(bool)seom_get('search_noindex',1);elseif(is_date())$noindex=(bool)seom_get('date_noindex',1);elseif(is_author())$noindex=(bool)seom_get('author_noindex',0);elseif(is_category())$noindex=(bool)seom_get('category_noindex',0);elseif(is_tag())$noindex=(bool)seom_get('tag_noindex',1);
  if($noindex){$robots['noindex']=true;$robots['nofollow']=false;}
  if(is_singular()){$raw=seom_meta(get_queried_object_id(),'robots','');if($raw){$arr=json_decode((string)$raw,true);if(is_array($arr)){if(isset($arr['index'])){$robots['noindex']=($arr['index']==='noindex'||$arr['index']===false);};if(isset($arr['follow'])){$robots['nofollow']=($arr['follow']==='nofollow'||$arr['follow']===false);};foreach(['noarchive','nosnippet','noimageindex','max-image-preview','max-snippet','max-video-preview'] as $k)if(array_key_exists($k,$arr))$robots[$k]=$arr[$k];}}}
  if(is_category()||is_tag()){ $term=get_queried_object(); if($term instanceof WP_Term && (int)$term->count===0 && (int)seom_get('noindex_empty_archives',1)){$robots['noindex']=true;$robots['nofollow']=false;} }
  return $robots;
 }
}
