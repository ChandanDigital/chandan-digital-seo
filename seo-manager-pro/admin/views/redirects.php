<?php if(!defined('ABSPATH'))exit;
$notice = sanitize_key($_GET['redirect_status'] ?? '');
$imported = absint($_GET['imported'] ?? 0); $updated = absint($_GET['updated'] ?? 0); $skipped = absint($_GET['skipped'] ?? 0);
$base = seom_admin_page_url('links','redirects');
$export_csv = wp_nonce_url(admin_url('admin-post.php?action=seom_redirect_export&format=csv'),'seom_redirect_export');
$export_xlsx = wp_nonce_url(admin_url('admin-post.php?action=seom_redirect_export&format=xlsx'),'seom_redirect_export');
$template_csv = wp_nonce_url(admin_url('admin-post.php?action=seom_redirect_template&format=csv'),'seom_redirect_template');
$xlsx_available = class_exists('ZipArchive');
$template_xlsx = wp_nonce_url(admin_url('admin-post.php?action=seom_redirect_template&format=xlsx'),'seom_redirect_template');
$fallback = sanitize_key((string)seom_get('redirect_fallback','404'));
$default_type = (int)seom_get('redirect_default_type',301);
?>
<?php if($notice==='imported'): ?>
<div class="notice notice-success is-dismissible"><p><strong>Redirect import completed.</strong> Added: <?php echo (int)$imported; ?>, Updated: <?php echo (int)$updated; ?>, Skipped: <?php echo (int)$skipped; ?>.</p></div>
<?php elseif($notice==='saved'): ?><div class="notice notice-success is-dismissible"><p>Redirect rule saved successfully.</p></div>
<?php elseif($notice==='deleted'): ?><div class="notice notice-success is-dismissible"><p>Redirect deleted.</p></div>
<?php elseif($notice==='updated'): ?><div class="notice notice-success is-dismissible"><p>Redirect status updated.</p></div>
<?php elseif($notice==='settings_saved'): ?><div class="notice notice-success is-dismissible"><p>Redirect settings saved.</p></div>
<?php elseif($notice && $notice!=='imported'): ?>
<div class="notice notice-error is-dismissible"><p>Redirect import could not be completed. Check the file format and required columns.</p></div>
<?php endif; ?>

<div class="seom-panel">
    <div class="seom-actions"><div><h2 style="margin-bottom:4px">Redirect Manager</h2><p style="margin-top:0;color:#646970">Create, manage, test and bulk-import your URL redirects.</p></div></div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="seom-form-grid">
        <input type="hidden" name="action" value="seom_add_redirect"><?php wp_nonce_field('seom_add_redirect'); ?>
        <label>Source URL<input name="source" placeholder="/old-page/" required><small>Use a relative path such as <code>/old-page/</code>.</small></label>
        <label>Destination URL<input type="text" inputmode="url" name="destination" placeholder="https://example.com/new-page/" required><small>Use the full destination URL.</small></label>
        <label>Type<select name="type"><option value="301" <?php selected($default_type,301); ?>>301 Permanent</option><option value="302" <?php selected($default_type,302); ?>>302 Temporary</option><option value="307" <?php selected($default_type,307); ?>>307 Temporary</option><option value="308" <?php selected($default_type,308); ?>>308 Permanent</option></select></label>
        <div style="align-self:end"><button class="button button-primary">Add / Update Redirect</button></div>
    </form>
</div>

<div class="seom-panel">
    <div class="seom-actions"><div><h2 style="margin:0">Redirection Settings</h2><p style="margin:5px 0 0;color:#646970">Control debugging, automatic post redirects and 404 fallback behaviour.</p></div></div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="seom_save_redirect_settings"><?php wp_nonce_field('seom_save_redirect_settings'); ?>
        <div class="seom-settings-list">
            <label class="seom-toggle-row"><span><strong>Debug Redirections</strong><small>Show a debug console to administrators instead of executing the redirect. Visitors continue to receive the normal redirect.</small></span><input type="checkbox" name="redirect_debug" value="1" <?php checked((int)seom_get('redirect_debug',0),1); ?>></label>
            <label class="seom-toggle-row"><span><strong>Auto Post Redirect</strong><small>Create a 301 redirect automatically when a published post, page or public custom post type changes its slug.</small></span><input type="checkbox" name="redirect_auto_post" value="1" <?php checked((int)seom_get('redirect_auto_post',0),1); ?>></label>
            <label class="seom-toggle-row"><span><strong>Preserve Query Parameters</strong><small>Keep UTM and other query parameters when the destination does not already define its own query string.</small></span><input type="checkbox" name="redirect_preserve_query" value="1" <?php checked((int)seom_get('redirect_preserve_query',1),1); ?>></label>
        </div>
        <div class="seom-two" style="margin-top:10px">
            <div class="seom-option-box"><strong>Fallback Behavior</strong><p class="description">Choose what happens when a frontend 404 has no matching redirect.</p>
                <div class="seom-segmented">
                    <label><input type="radio" name="redirect_fallback" value="404" <?php checked($fallback,'404'); ?>><span>Default 404</span></label>
                    <label><input type="radio" name="redirect_fallback" value="homepage" <?php checked($fallback,'homepage'); ?>><span>Redirect to Homepage</span></label>
                    <label><input type="radio" name="redirect_fallback" value="custom" <?php checked($fallback,'custom'); ?>><span>Custom Redirection</span></label>
                </div>
                <input style="margin-top:12px;width:100%" type="text" inputmode="url" name="redirect_fallback_url" value="<?php echo esc_attr(seom_get('redirect_fallback_url','')); ?>" placeholder="/  or  https://example.com/fallback/"><small>Used only when <strong>Custom Redirection</strong> is selected. You may enter <code>/</code> to use the homepage.</small>
            </div>
            <div class="seom-option-box"><strong>Default Redirection Type</strong><p class="description">This applies to fallback redirects and is also the default in the redirect form.</p>
                <select name="redirect_default_type" style="width:100%"><option value="301" <?php selected($default_type,301); ?>>301 Permanent Move</option><option value="302" <?php selected($default_type,302); ?>>302 Temporary Redirect</option><option value="307" <?php selected($default_type,307); ?>>307 Temporary Redirect</option><option value="308" <?php selected($default_type,308); ?>>308 Permanent Redirect</option></select>
                <p class="description" style="margin-top:12px">For changed URLs, Auto Post Redirect always creates a 301.</p>
            </div>
        </div>
        <p style="margin-top:14px"><button class="button button-primary">Save Redirect Settings</button></p>
    </form>
</div>

<div class="seom-panel">
    <div class="seom-actions"><div><h2 style="margin:0">Bulk Redirect Import / Export</h2><p style="margin:5px 0 0;color:#646970">Import up to 50,000 redirects from CSV or XLSX. Existing source URLs update automatically.</p></div></div>
    <div class="seom-two" style="margin-top:14px">
        <div>
            <h3>Import redirects</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="seom_redirect_import"><?php wp_nonce_field('seom_redirect_import'); ?>
                <input type="file" name="seom_redirect_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <p class="description">Required columns: <code>Source</code>, <code>Destination</code>. Optional: <code>Type</code>, <code>Enabled</code>.</p>
                <button class="button button-primary">Import CSV / XLSX</button>
            </form>
            <div class="seom-tool-row" style="margin-top:10px"><a class="button" href="<?php echo esc_url($template_csv); ?>">Download CSV Template</a><?php if($xlsx_available): ?><a class="button" href="<?php echo esc_url($template_xlsx); ?>">Download XLSX Template</a><?php endif; ?></div>
        </div>
        <div>
            <h3>Export redirects</h3><p class="description">Export the current redirect rules, status and hit counts.</p>
            <div class="seom-tool-row"><a class="button" href="<?php echo esc_url($export_csv); ?>">Export CSV</a><?php if($xlsx_available): ?><a class="button" href="<?php echo esc_url($export_xlsx); ?>">Export XLSX</a><?php else: ?><span class="seom-badge warn">XLSX needs PHP ZipArchive</span><?php endif; ?></div>
            <div class="seom-import-format"><strong>File format</strong><br>Source | Destination | Type | Enabled<br><small>Enabled accepts 1/0, true/false, active/inactive or enabled/disabled.</small></div>
        </div>
    </div>
</div>

<div class="seom-panel">
    <div class="seom-actions"><h2 style="margin:0">Redirects</h2><span class="seom-badge ok">Bulk-ready</span></div>
    <div style="overflow:auto"><table class="widefat striped"><thead><tr><th>Source</th><th>Destination</th><th>Type</th><th>Hits</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php global $wpdb; $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}seom_redirects ORDER BY id DESC LIMIT 500"); if(!$rows): ?><tr><td colspan="6">No redirects found.</td></tr><?php else: foreach($rows as $r): ?>
<tr><td><code><?php echo esc_html($r->source); ?></code></td><td><a href="<?php echo esc_url($r->destination); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($r->destination); ?></a></td><td><?php echo (int)$r->type; ?></td><td><?php echo (int)$r->hits; ?></td><td><span class="seom-badge <?php echo $r->enabled?'ok':'bad'; ?>"><?php echo $r->enabled?'Active':'Disabled'; ?></span></td><td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"><input type="hidden" name="action" value="seom_toggle_redirect"><input type="hidden" name="id" value="<?php echo (int)$r->id; ?>"><?php wp_nonce_field('seom_toggle_redirect'); ?><button class="button button-small">Toggle</button></form> <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('Delete this redirect?');"><input type="hidden" name="action" value="seom_delete_redirect"><input type="hidden" name="id" value="<?php echo (int)$r->id; ?>"><?php wp_nonce_field('seom_delete_redirect'); ?><button class="button button-small">Delete</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
</div>
