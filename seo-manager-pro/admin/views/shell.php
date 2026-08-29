<?php if(!defined('ABSPATH'))exit;
$group_url=seom_admin_page_url($section);
$groups=[
 'appearance'=>['Search Appearance','Titles, snippets, schema and search presentation.','dashicons-search'],
 'technical'=>['Technical SEO','Sitemaps, crawl controls and safe technical tweaks.','dashicons-admin-tools'],
 'indexing'=>['Google & Indexing','Search Console, Google API, Bing and IndexNow.','dashicons-chart-line'],
 'links'=>['Links & Redirects','Redirects, 404s and internal linking.','dashicons-admin-links'],
 'content'=>['Content SEO','Content audit, image SEO and bulk editing.','dashicons-edit'],
 'tools'=>['Tools & Data','Migration, import/export and system diagnostics.','dashicons-database'],
 'business'=>['Google Business Profile','Locations, reviews, posts, performance and entity signals.','dashicons-location-alt'],
];
$g=$groups[$section]??$groups['appearance'];
?>
<div class="wrap seom-wrap">
<?php include SEOM_DIR.'admin/views/brandbar.php'; ?>
<div class="seom-app-grid">
<aside class="seom-internal-sidebar">
 <a class="seom-side-home" href="<?php echo esc_url(seom_admin_page_url('dashboard')); ?>"><span class="dashicons dashicons-dashboard"></span>Dashboard</a>
 <?php foreach($groups as $k=>$info): ?>
 <a class="seom-side-group <?php echo $k===$section?'active':''; ?>" href="<?php echo esc_url(seom_admin_page_url($k)); ?>"><span class="dashicons <?php echo esc_attr($info[2]); ?>"></span><span><?php echo esc_html($info[0]); ?></span></a>
 <?php endforeach; ?>
</aside>
<main class="seom-app-main">
 <div class="seom-section-heading"><div><div class="seom-eyebrow">CHANDAN DIGITAL SEO</div><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($g[1]); ?></p></div><a class="button" href="<?php echo esc_url(seom_admin_page_url('dashboard')); ?>">Overview</a></div>
 <div class="seom-subnav">
  <?php foreach($tabs as $tab): ?><a class="<?php echo sanitize_key($subtab??'')===$tab['key']?'active':''; ?>" href="<?php echo esc_url(seom_admin_page_url($section,$tab['key'])); ?>"><?php echo esc_html($tab['label']); ?></a><?php endforeach; ?>
 </div>
<div class="seom-content-stack">
