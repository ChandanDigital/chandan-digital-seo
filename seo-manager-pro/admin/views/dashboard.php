<?php if(!defined('ABSPATH'))exit; global $wpdb;
$stats=['posts'=>0,'missing'=>0,'redirects'=>0,'errors'=>0,'audit'=>0];
foreach(seom_post_types() as $pt){$obj=wp_count_posts($pt);$stats['posts']+=(int)($obj->publish??0);}
$types=seom_post_types(); $marks=$types?implode(',',array_fill(0,count($types),'%s')):"''";
$stats['missing']=$types?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID=m.post_id AND m.meta_key='_seom_title' WHERE p.post_status='publish' AND p.post_type IN ($marks) AND (m.meta_value IS NULL OR m.meta_value='')",$types)):0;
$stats['redirects']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_redirects");
$stats['errors']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_404_logs");
$stats['audit']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_audit");
$groups=[
 ['appearance','Search Appearance','General SEO, Titles & Meta, Schema and social search presentation.','dashicons-search'],
 ['technical','Technical SEO','Sitemap, robots, crawl controls and technical SEO safeguards.','dashicons-admin-tools'],
 ['indexing','Google & Indexing','Search Console, Instant Indexing, Bing and IndexNow.','dashicons-chart-line'],
 ['links','Links & Redirects','301/302/307/308 redirects, 404 monitoring and internal links.','dashicons-admin-links'],
 ['content','Content SEO','SEO analyzer, image optimization and bulk editor.','dashicons-edit'],
 ['tools','Tools & Data','Import/export, migration and system diagnostics.','dashicons-database'],
];
?>
<div class="wrap seom-wrap"><div class="seom-brandbar">
<div class="seom-brandmark">CD</div><div class="seom-brandcopy"><div class="seom-brandname">Chandan Digital</div><div class="seom-productname">Chandan Digital SEO</div><div class="seom-brandtag">Advanced SEO control centre for your WordPress site.</div></div><div class="seom-brandmeta"><span class="seom-pill">v<?php echo esc_html(SEOM_VERSION); ?></span><a class="seom-site-link" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener">Visit Site</a></div>
</div>
<div class="seom-hero"><div><div class="seom-eyebrow">SEO CONTROL CENTRE</div><h1>Website SEO Overview</h1><p>Manage search visibility, indexing, technical SEO and content optimisation from one place.</p></div><div class="seom-score-orb"><strong><?php echo esc_html(min(100,max(0,100-($stats['missing']>0?25:0)-($stats['errors']>0?15:0)-($stats['redirects']>0?0:10)))); ?></strong><span>health</span></div></div>
<div class="seom-cards"><div class="seom-card"><span>Published content</span><strong><?php echo (int)$stats['posts']; ?></strong></div><div class="seom-card"><span>Missing SEO titles</span><strong><?php echo (int)$stats['missing']; ?></strong></div><div class="seom-card"><span>Redirects</span><strong><?php echo (int)$stats['redirects']; ?></strong></div><div class="seom-card"><span>Tracked 404 URLs</span><strong><?php echo (int)$stats['errors']; ?></strong></div><div class="seom-card"><span>Audit findings</span><strong><?php echo (int)$stats['audit']; ?></strong></div><div class="seom-card"><span>Google</span><strong><?php echo SEO_Manager_Google::connected()?'ON':'OFF'; ?></strong><small><?php echo esc_html(SEO_Manager_Google::connected()?seom_get('google_email','Connected'):'Not connected'); ?></small></div></div>
<h2 class="seom-dashboard-title">SEO Modules</h2><div class="seom-module-grid seom-module-grid-2">
<?php foreach($groups as $g): ?><a class="seom-module-card seom-module-card-large" href="<?php echo esc_url(seom_admin_page_url($g[0])); ?>"><span class="seom-module-icon dashicons <?php echo esc_attr($g[3]); ?>"></span><b><?php echo esc_html($g[1]); ?></b><span><?php echo esc_html($g[2]); ?></span><em>Open section →</em></a><?php endforeach; ?>
</div>
<div class="seom-two"><div class="seom-panel"><h2>Key SEO URLs</h2><p><strong>Sitemap:</strong> <a href="<?php echo esc_url(home_url('/seo-sitemap.xml')); ?>" target="_blank" rel="noopener"><?php echo esc_html(home_url('/seo-sitemap.xml')); ?></a></p><p><strong>Robots:</strong> <a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank" rel="noopener"><?php echo esc_html(home_url('/robots.txt')); ?></a></p><p><strong>Search engine key:</strong> <?php echo SEO_Manager_Search_Engines::enabled()?'IndexNow enabled':'IndexNow not configured'; ?></p></div><div class="seom-panel"><h2>Quick Health Checks</h2><?php $checks=[['XML Sitemap',(int)seom_get('enable_sitemap',1),'Sitemap generation is enabled.'],['Schema',(int)seom_get('enable_schema',1),'JSON-LD output is enabled.'],['Open Graph',(int)seom_get('enable_og',1),'Social metadata is enabled.'],['404 cleanup',wp_next_scheduled('seom_cleanup_404')!==false,'Daily cleanup is scheduled.'],['Google monitor',(int)seom_get('google_monitor_enabled',1),'Official Google SEO update monitoring is enabled.']]; foreach($checks as $c): ?><div class="seom-check"><b><?php echo esc_html($c[0]); ?></b><span class="seom-badge <?php echo $c[1]?'ok':'warn'; ?>"><?php echo $c[1]?'OK':'CHECK'; ?></span><small><?php echo esc_html($c[2]); ?></small></div><?php endforeach; ?></div></div>
</div>
