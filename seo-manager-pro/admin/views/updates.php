<?php if (!defined('ABSPATH')) exit; ?>
<div class="seom-panel">
<h2>Advanced Plugin Updates</h2>
<p>Connect this plugin to a GitHub Releases repository and let WordPress handle update installation. The updater supports release detection, native WordPress update notices, optional automatic updates, pre-update backups and rollback.</p>
<?php if (isset($_GET['updated'])): ?><div class="notice notice-success inline"><p>Update settings saved.</p></div><?php endif; ?>
<?php if (isset($_GET['check'])): ?><div class="notice notice-<?php echo $_GET['check']==='update'?'warning':'success'; ?> inline"><p><?php echo $_GET['check']==='update'?'A newer release is available in WordPress Plugins → Updates.':($_GET['check']==='latest'?'You already have the latest configured release.':esc_html(sanitize_key(wp_unslash($_GET['check'])))); ?></p></div><?php endif; ?>
<?php if (isset($_GET['rollback'])): ?><div class="notice notice-success inline"><p>Rollback completed. Reactivate the plugin only if WordPress asks you to.</p></div><?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="seom-form-grid">
<input type="hidden" name="action" value="seom_update_save"><?php wp_nonce_field('seom_update_save'); ?>
<label>GitHub Owner / Organisation<input name="owner" value="<?php echo esc_attr($s['owner']); ?>" placeholder="your-github-owner"></label>
<label>GitHub Repository<input name="repo" value="<?php echo esc_attr($s['repo']); ?>" placeholder="chandan-digital-seo"></label>
<label>Release Asset Name <span class="description">Optional</span><input name="asset" value="<?php echo esc_attr($s['asset']); ?>" placeholder="chandan-digital-seo.zip"></label>
<label>Branch for context<input name="branch" value="<?php echo esc_attr($s['branch']); ?>" placeholder="main"></label>
<label>GitHub Token <span class="description">Optional for private repositories</span><input type="password" name="token" value="" placeholder="Leave blank to keep existing token"></label>
<label>Backup retention<input type="number" min="1" max="20" name="backup_retention" value="<?php echo esc_attr($s['backup_retention']); ?>"></label>
<label class="seom-full"><input type="checkbox" name="auto_update" value="1" <?php checked((int)$s['auto_update'],1); ?>> Enable WordPress automatic updates for Chandan Digital SEO</label>
<div class="seom-full"><button class="button button-primary">Save Update Settings</button>
<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=seom_update_check'),'seom_update_check')); ?>">Check for Updates Now</a></div>
</form></div>
<div class="seom-panel"><h3>Update Status</h3><table class="widefat striped"><tbody>
<tr><th>Current version</th><td><?php echo esc_html(SEOM_VERSION); ?></td></tr>
<tr><th>Configured source</th><td><?php echo $s['owner']&&$s['repo']?esc_html($s['owner'].'/'.$s['repo']):'<em>Not configured</em>'; ?></td></tr>
<tr><th>Latest release</th><td><?php echo is_array($release)&&!empty($release['tag_name'])?esc_html($release['tag_name']):'<em>Not checked</em>'; ?></td></tr>
<tr><th>Last successful plugin update</th><td><?php echo !empty($last['time'])?esc_html(wp_date(get_option('date_format').' '.get_option('time_format'),(int)$last['time'])):'<em>None recorded</em>'; ?></td></tr>
<tr><th>Last backup</th><td><?php echo !empty($backup['path'])?esc_html(wp_basename($backup['path'])):(isset($backup['message'])?esc_html($backup['message']):'<em>None</em>'); ?></td></tr>
</tbody></table></div>
<div class="seom-panel"><h3>Available Rollback Backups</h3>
<?php if (!$backups): ?><p>No rollback backup exists yet. The updater creates one before a plugin update when ZipArchive is available.</p><?php else: ?><table class="widefat striped"><thead><tr><th>Backup</th><th>Action</th></tr></thead><tbody><?php foreach($backups as $b): ?><tr><td><?php echo esc_html(wp_basename($b)); ?></td><td><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=seom_rollback&backup='.rawurlencode($b)),'seom_rollback')); ?>" onclick="return confirm('Restore this backup? The current plugin files will be replaced.');">Rollback</a></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
