<?php
if (!current_user_can('manage_options')) wp_die('Unauthorized.');
SEO_Manager_RankMath_Parity::ensure_roles();
$roles = wp_roles()->roles;
$caps = SEO_Manager_RankMath_Parity::capability_map();
?>
<div class="seom-panel"><h2>Role Manager</h2><p>Control which SEO areas each WordPress role can access. Administrators retain full control.</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="seom_save_role_manager"><?php wp_nonce_field('seom_save_role_manager'); ?>
<table class="widefat striped"><thead><tr><th>Role</th><?php foreach($caps as $cap=>$label): ?><th><?php echo esc_html($label); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($roles as $slug=>$role): $obj=get_role($slug); if(!$obj)continue; ?><tr><th><?php echo esc_html($role['name']); ?></th><?php foreach($caps as $cap=>$label): ?><td><input type="checkbox" name="roles[<?php echo esc_attr($slug); ?>][]" value="<?php echo esc_attr($cap); ?>" <?php checked($obj->has_cap($cap)); ?>></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table><p><button class="button button-primary">Save Role Permissions</button></p></form></div>
