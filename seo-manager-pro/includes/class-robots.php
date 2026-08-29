<?php
if (!defined('ABSPATH')) exit;
final class SEO_Manager_Robots {public static function init():void{add_filter('robots_txt',[__CLASS__,'filter'],999,2);}public static function filter(string $output,bool $public):string{if(!$public)return $output;$custom=trim((string)seom_get('robots_custom',''));if($custom)$output=$custom."\n";$output.='Sitemap: '.esc_url(home_url('/seo-sitemap.xml'))."\n";return $output;}}
