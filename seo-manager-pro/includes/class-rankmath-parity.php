<?php
if (!defined('ABSPATH')) exit;

/**
 * Chandan Digital SEO: original implementations of several commonly requested
 * SEO-suite capabilities. This class intentionally does not reuse third-party code.
 */
final class SEO_Manager_RankMath_Parity {
    const SCHEMA_OPTION = 'seom_schema_templates';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_post_seom_save_woocommerce', [__CLASS__, 'save_woocommerce']);
        add_action('admin_post_seom_save_schema_templates', [__CLASS__, 'save_schema_templates']);
        add_action('admin_post_seom_save_role_manager', [__CLASS__, 'save_role_manager']);
        add_action('add_meta_boxes', [__CLASS__, 'woocommerce_box']);
        add_action('save_post', [__CLASS__, 'save_product_meta'], 20, 3);
        add_action('save_post', [__CLASS__, 'auto_image_meta'], 25, 3);
        add_action('add_attachment', [__CLASS__, 'auto_image_attachment'], 25);
        add_filter('wp_robots', [__CLASS__, 'woocommerce_robots'], 30);
        add_action('wp_head', [__CLASS__, 'woocommerce_head'], 12);
        add_action('wp_head', [__CLASS__, 'video_schema'], 13);
        add_filter('manage_posts_columns', [__CLASS__, 'post_columns']);
        add_action('manage_posts_custom_column', [__CLASS__, 'post_column'], 20, 2);
        add_filter('manage_pages_columns', [__CLASS__, 'post_columns']);
        add_action('manage_pages_custom_column', [__CLASS__, 'post_column'], 20, 2);
    }

    public static function register(): void {
        register_setting('seom_role_manager', 'seom_role_manager', [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_roles'],
            'default' => [],
        ]);
    }

    public static function menu(): void {}

    public static function capability_map(): array {
        return [
            'seom_manage_settings' => 'Manage SEO settings',
            'seom_edit_seo'       => 'Edit SEO metadata',
            'seom_manage_schema'  => 'Manage schema',
            'seom_manage_redirects' => 'Manage redirects and 404s',
            'seom_manage_indexing' => 'Manage indexing integrations',
            'seom_manage_analytics' => 'View SEO analytics',
        ];
    }

    public static function ensure_roles(): void {
        $caps = array_keys(self::capability_map());
        $admin = get_role('administrator');
        if ($admin) foreach ($caps as $cap) $admin->add_cap($cap);
        $editor = get_role('editor');
        if ($editor) {
            foreach (['seom_edit_seo', 'seom_manage_schema', 'seom_manage_analytics'] as $cap) $editor->add_cap($cap);
        }
    }

    public static function save_role_manager(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_save_role_manager')) wp_die('Unauthorized.');
        $roles = isset($_POST['roles']) && is_array($_POST['roles']) ? wp_unslash($_POST['roles']) : [];
        $allowed_caps = array_keys(self::capability_map());
        foreach ($roles as $role_key => $caps) {
            $role = get_role(sanitize_key($role_key));
            if (!$role) continue;
            foreach ($allowed_caps as $cap) {
                if (in_array($cap, (array) $caps, true)) $role->add_cap($cap);
                else $role->remove_cap($cap);
            }
        }
        self::ensure_roles();
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-tools-group','subtab'=>'roles','roles_saved'=>1], admin_url('admin.php'))); exit;
    }

    public static function sanitize_roles($value): array {
        return is_array($value) ? $value : [];
    }

    private static function product_enabled(): bool {
        return class_exists('WooCommerce') || function_exists('wc_get_product');
    }

    public static function woocommerce_box(): void {
        if (!self::product_enabled()) return;
        add_meta_box('seom_wc_box', 'Chandan Digital SEO · Product SEO', [__CLASS__, 'render_product_box'], 'product', 'side', 'high');
    }

    public static function render_product_box(WP_Post $post): void {
        wp_nonce_field('seom_product_save', 'seom_product_nonce');
        $fields = [
            'brand' => 'Brand',
            'gtin' => 'GTIN / ISBN',
            'mpn' => 'MPN',
            'availability' => 'Availability',
            'condition' => 'Condition',
        ];
        echo '<div class="seom-wc-fields">';
        foreach ($fields as $key => $label) {
            $value = get_post_meta($post->ID, '_seom_wc_'.$key, true);
            echo '<p><label><strong>'.esc_html($label).'</strong><input class="widefat" name="seom_wc_'.esc_attr($key).'" value="'.esc_attr($value).'" /></label></p>';
        }
        $noindex = (int) get_post_meta($post->ID, '_seom_wc_noindex', true);
        echo '<p><label><input type="checkbox" name="seom_wc_noindex" value="1" '.checked($noindex,1,false).' /> Noindex this product</label></p>';
        echo '<p class="description">Product Schema and Open Graph tags use WooCommerce product data automatically.</p></div>';
    }

    public static function save_product_meta(int $post_id, WP_Post $post, bool $update): void {
        if (!$update || $post->post_type !== 'product') return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!isset($_POST['seom_product_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['seom_product_nonce'])), 'seom_product_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        foreach (['brand','gtin','mpn','availability','condition'] as $key) {
            $value = sanitize_text_field(wp_unslash($_POST['seom_wc_'.$key] ?? ''));
            if ($value === '') delete_post_meta($post_id, '_seom_wc_'.$key); else update_post_meta($post_id, '_seom_wc_'.$key, $value);
        }
        if (!empty($_POST['seom_wc_noindex'])) update_post_meta($post_id, '_seom_wc_noindex', 1); else delete_post_meta($post_id, '_seom_wc_noindex');
    }

    public static function woocommerce_robots(array $robots): array {
        if (!is_singular('product')) return $robots;
        if ((int) get_post_meta(get_queried_object_id(), '_seom_wc_noindex', true) === 1) {
            $robots['noindex'] = true;
            $robots['nofollow'] = false;
        }
        if (function_exists('wc_get_product')) {
            $product = wc_get_product(get_queried_object_id());
            if ((int)seom_get('wc_noindex_hidden',1) === 1 && $product && method_exists($product, 'get_catalog_visibility') && $product->get_catalog_visibility() === 'hidden') {
                $robots['noindex'] = true;
            }
        }
        return $robots;
    }

    public static function woocommerce_head(): void {
        if (is_admin() || !self::product_enabled() || !is_singular('product')) return;
        if ((int)seom_get('wc_schema',1) !== 1 && (int)seom_get('wc_og',1) !== 1) return;
        $id = get_queried_object_id();
        $product = wc_get_product($id);
        if (!$product) return;
        $brand = trim((string)get_post_meta($id, '_seom_wc_brand', true));
        $gtin = trim((string)get_post_meta($id, '_seom_wc_gtin', true));
        $mpn = trim((string)get_post_meta($id, '_seom_wc_mpn', true));
        $availability = trim((string)get_post_meta($id, '_seom_wc_availability', true));
        if (!$availability) $availability = $product->is_in_stock() ? 'InStock' : 'OutOfStock';
        $condition = trim((string)get_post_meta($id, '_seom_wc_condition', true));
        if (!$condition) $condition = 'NewCondition';
        $offers = [
            '@type' => 'Offer',
            'url' => get_permalink($id),
            'priceCurrency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : get_option('woocommerce_currency','USD'),
            'availability' => 'https://schema.org/'.preg_replace('/[^A-Za-z]/','',$availability),
            'itemCondition' => 'https://schema.org/'.preg_replace('/[^A-Za-z]/','',$condition),
        ];
        if ($product->get_price() !== '') $offers['price'] = (string)$product->get_price();
        $data = [
            '@type' => 'Product',
            '@id' => get_permalink($id).'#product',
            'name' => get_the_title($id),
            'url' => get_permalink($id),
            'description' => seom_desc_for_post($id),
            'sku' => (string)$product->get_sku(),
            'offers' => $offers,
        ];
        $image = get_the_post_thumbnail_url($id, 'full'); if ($image) $data['image'] = [$image];
        if ($brand) $data['brand'] = ['@type'=>'Brand','name'=>$brand];
        if ($gtin) $data['gtin'] = preg_replace('/\s+/', '', $gtin);
        if ($mpn) $data['mpn'] = $mpn;
        if ((int)seom_get('wc_schema',1) === 1) echo '<script type="application/ld+json">'.wp_json_encode(['@context'=>'https://schema.org'] + $data, JSON_UNESCAPED_UNICODE).'</script>';
        if ((int)seom_get('enable_og',1) && (int)seom_get('wc_og',1) === 1) {
            $tags = [
                ['og:type','product'], ['og:title', seom_title_for_post($id)], ['og:url', get_permalink($id)], ['product:price:amount', (string)$product->get_price()], ['product:price:currency', $offers['priceCurrency']]
            ];
            foreach ($tags as $tag) echo '<meta property="'.esc_attr($tag[0]).'" content="'.esc_attr($tag[1]).'">\n';
        }
    }

    public static function auto_image_meta(int $post_id, WP_Post $post, bool $update): void {
        if (!$update || $post->post_type !== 'attachment' || strpos((string)$post->post_mime_type, 'image/') !== 0) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if ((int)seom_get('image_auto_alt',0) !== 1) return;
        $alt = trim((string)get_post_meta($post_id, '_wp_attachment_image_alt', true));
        if ($alt === '') {
            $base = pathinfo((string)$post->guid, PATHINFO_FILENAME);
            $base = preg_replace('/[-_]+/', ' ', $base);
            $base = trim(preg_replace('/\s+/', ' ', $base));
            if ($base !== '') update_post_meta($post_id, '_wp_attachment_image_alt', sanitize_text_field(ucwords($base)));
        }
        if ((int)seom_get('image_auto_title',0) === 1 && trim($post->post_title) === '') {
            $base = pathinfo((string)$post->guid, PATHINFO_FILENAME);
            $base = ucwords(trim(preg_replace('/[-_]+/', ' ', $base)));
            if ($base !== '') wp_update_post(['ID'=>$post_id,'post_title'=>sanitize_text_field($base)]);
        }
    }


    public static function auto_image_attachment(int $post_id): void {
        $post = get_post($post_id);
        if ($post instanceof WP_Post) self::auto_image_meta($post_id, $post, true);
    }

    public static function post_columns(array $columns): array {
        if (isset($columns['seom_link_count'])) return $columns;
        $out = [];
        foreach ($columns as $key=>$label) {
            $out[$key] = $label;
            if ($key === 'title') $out['seom_link_count'] = 'Internal Links';
        }
        return $out;
    }

    public static function post_column(string $column, int $post_id): void {
        if ($column !== 'seom_link_count') return;
        $content = (string)get_post_field('post_content', $post_id);
        preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $content, $m);
        $home = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $internal = 0;
        foreach ((array)($m[1] ?? []) as $href) {
            $host = wp_parse_url($href, PHP_URL_HOST);
            if (!$host || strcasecmp((string)$host, (string)$home) === 0) $internal++;
        }
        echo '<span title="Internal links detected in content">'.esc_html((string)$internal).'</span>';
    }


    public static function save_woocommerce(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_save_woocommerce')) wp_die('Unauthorized.');
        foreach (['wc_schema','wc_og','wc_noindex_hidden'] as $key) seom_update($key, isset($_POST[$key]) ? 1 : 0);
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-commerce','subtab'=>'settings','wc_saved'=>1], admin_url('admin.php'))); exit;
    }

    public static function save_schema_templates(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_save_schema_templates')) wp_die('Unauthorized.');
        $raw = isset($_POST['templates']) && is_array($_POST['templates']) ? wp_unslash($_POST['templates']) : [];
        $clean = [];
        foreach ($raw as $idx => $row) {
            if (!is_array($row)) continue;
            $name = sanitize_text_field($row['name'] ?? '');
            $type = sanitize_key($row['type'] ?? 'Article');
            $condition = sanitize_text_field($row['condition'] ?? '');
            $json = trim((string)($row['json'] ?? ''));
            if (!$name || !$json) continue;
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) continue;
            $clean[] = ['id'=>sanitize_key($row['id'] ?? wp_generate_uuid4()), 'name'=>$name, 'type'=>$type, 'condition'=>$condition, 'json'=>$decoded];
        }
        update_option(self::SCHEMA_OPTION, $clean, false);
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-appearance','subtab'=>'schema','schema_saved'=>1], admin_url('admin.php'))); exit;
    }

    public static function render_schema_templates(): void {
        $templates = get_option(self::SCHEMA_OPTION, []);
        if (!is_array($templates)) $templates = [];
        echo '<div class="seom-panel"><h2>Schema Templates</h2><p>Create reusable JSON-LD templates and apply them by post type, taxonomy, or URL fragment. The template JSON must be valid JSON.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="seom_save_schema_templates">'; wp_nonce_field('seom_save_schema_templates');
        echo '<div id="seom-schema-template-list">';
        $rows = $templates ?: [['id'=>'new','name'=>'Article template','type'=>'Article','condition'=>'post','json'=>['@context'=>'https://schema.org','@type'=>'Article','headline'=>'{{title}}','url'=>'{{url}}','description'=>'{{description}}']]];
        foreach ($rows as $i=>$row) {
            $json = wp_json_encode($row['json'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            echo '<div class="seom-card seom-template-row"><p><strong>Template '.esc_html((string)($i+1)).'</strong></p>';
            echo '<div class="seom-form-grid"><label>Name<input name="templates['.$i.'][name]" value="'.esc_attr($row['name']).'"></label><label>Schema Type<input name="templates['.$i.'][type]" value="'.esc_attr($row['type']).'"></label><label>Condition<input name="templates['.$i.'][condition]" value="'.esc_attr($row['condition']).'" placeholder="post, page, product or URL fragment"></label><label>ID<input name="templates['.$i.'][id]" value="'.esc_attr($row['id']).'"></label><label class="seom-full-label">JSON-LD<textarea name="templates['.$i.'][json]" rows="10">'.esc_textarea($json).'</textarea></label></div></div>';
        }
        echo '</div><p><button class="button button-primary">Save Schema Templates</button></p></form></div>';
    }

    public static function output_templates(int $post_id): void {
        $templates = get_option(self::SCHEMA_OPTION, []);
        if (!is_array($templates)) return;
        $post_type = get_post_type($post_id);
        $permalink = get_permalink($post_id);
        foreach ($templates as $tpl) {
            $condition = trim((string)($tpl['condition'] ?? ''));
            $match = $condition === '' || $condition === $post_type || ($permalink && strpos($permalink, $condition) !== false);
            if (!$match || !is_array($tpl['json'] ?? null)) continue;
            $data = self::replace_vars($tpl['json'], $post_id);
            if (!isset($data['@context'])) $data['@context'] = 'https://schema.org';
            echo '<script type="application/ld+json">'.wp_json_encode($data, JSON_UNESCAPED_UNICODE).'</script>' . "\n";
        }
    }

    private static function replace_vars($value, int $post_id) {
        if (is_array($value)) { $out=[]; foreach($value as $k=>$v) $out[$k]=self::replace_vars($v,$post_id); return $out; }
        if (!is_string($value)) return $value;
        return strtr($value, ['{{title}}'=>get_the_title($post_id),'{{url}}'=>(string)get_permalink($post_id),'{{description}}'=>seom_desc_for_post($post_id),'{{site_name}}'=>get_bloginfo('name'),'{{date_published}}'=>get_the_date('c',$post_id),'{{date_modified}}'=>get_the_modified_date('c',$post_id)]);
    }

    public static function video_schema(): void {
        if (is_admin() || !is_singular() || !is_main_query()) return;
        if ((int)seom_get('video_auto_schema',0) !== 1) return;
        $post_id = get_queried_object_id();
        $content = (string)get_post_field('post_content', $post_id);
        if (stripos($content, '<iframe') === false) return;
        preg_match_all('/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);
        foreach ((array)($matches[1] ?? []) as $src) {
            $host = strtolower((string)wp_parse_url($src, PHP_URL_HOST));
            if (strpos($host, 'youtube.') === false && strpos($host, 'youtu.be') === false && strpos($host, 'vimeo.') === false) continue;
            $thumb = get_the_post_thumbnail_url($post_id, 'full') ?: seom_default_image();
            $video = ['@context'=>'https://schema.org','@type'=>'VideoObject','name'=>get_the_title($post_id),'description'=>seom_desc_for_post($post_id),'uploadDate'=>get_the_date('c',$post_id),'embedUrl'=>esc_url_raw($src)];
            if ($thumb) $video['thumbnailUrl'] = [$thumb];
            echo '<script type="application/ld+json">'.wp_json_encode($video, JSON_UNESCAPED_UNICODE).'</script>' . "\n";
            break;
        }
    }
}
