<div class="seom-panel"><h2>SEO Automation</h2><p>Automate common SEO housekeeping without changing your content.</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="seom_save_advanced"><input type="hidden" name="seom_scope" value="automation"><?php wp_nonce_field('seom_save_advanced'); ?>
<div class="seom-toggle-list">
<label class="seom-toggle-row"><span><strong>Automatic Image ALT text</strong><small>Generate ALT text from the image filename when ALT text is empty.</small></span><input type="checkbox" name="image_auto_alt" value="1" <?php checked((int)seom_get('image_auto_alt',0),1); ?>></label>
<label class="seom-toggle-row"><span><strong>Automatic Image Title</strong><small>Generate the attachment title from the filename when the attachment has no title.</small></span><input type="checkbox" name="image_auto_title" value="1" <?php checked((int)seom_get('image_auto_title',0),1); ?>></label>
<label class="seom-toggle-row"><span><strong>Automatic Video Schema</strong><small>Detect YouTube and Vimeo embeds and add VideoObject JSON-LD.</small></span><input type="checkbox" name="video_auto_schema" value="1" <?php checked((int)seom_get('video_auto_schema',0),1); ?>></label>
</div><p><button class="button button-primary">Save Automation Settings</button></p></form></div>
