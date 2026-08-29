<?php
if (!defined('ABSPATH')) exit;

/**
 * Controller for all XML sitemap output: the sitemap index, per post-type
 * and per-taxonomy sub-sitemaps, the Google News sitemap and the video
 * sitemap. Owns its own rewrite rules/query vars, a transient cache layer
 * (24h TTL, instantly invalidated on content or settings changes), and the
 * <lastmod>/<image:image> enrichment used by the sub-sitemaps.
 */
final class SEO_Manager_Sitemap {

    /** How long a generated sitemap fragment is cached for. */
    private const CACHE_TTL = DAY_IN_SECONDS;

    /** Google News only accepts articles published within the last 2 days. */
    private const NEWS_WINDOW = 2 * DAY_IN_SECONDS;

    /** URLs per sub-sitemap page, matching Google's per-file guidance. */
    private const PER_PAGE = 1000;

    /** Cap on <image:image> entries emitted per URL. */
    private const MAX_IMAGES = 20;

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_rewrites']);
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_action('template_redirect', [__CLASS__, 'serve'], 0);

        // Cache invalidation: any content change or explicit settings save
        // bumps the cache "version", which instantly orphans every previously
        // cached fragment without needing to enumerate/delete transients.
        add_action('save_post', [__CLASS__, 'on_post_change']);
        add_action('deleted_post', [__CLASS__, 'purge_cache'], 10, 0);
        add_action('trashed_post', [__CLASS__, 'purge_cache'], 10, 0);
        add_action('untrashed_post', [__CLASS__, 'purge_cache'], 10, 0);
    }

    /* ---------------------------------------------------------------
     * Rewrite rules / query vars
     * ------------------------------------------------------------- */

    public static function register_rewrites(): void {
        // /seo-sitemap.xml, /sitemap.xml and /sitemap_index.xml are aliases
        // for the same sitemap index.
        add_rewrite_rule('^(?:seo-sitemap|sitemap|sitemap_index)\.xml$', 'index.php?seom_sitemap=index', 'top');
        // Paginated per post-type / per-taxonomy sub-sitemaps, e.g.
        // /seo-sitemap-post-1.xml, /seo-sitemap-category-1.xml.
        add_rewrite_rule('^seo-sitemap-([a-z0-9_-]+)-([0-9]+)\.xml$', 'index.php?seom_sitemap=$matches[1]&seom_sitemap_page=$matches[2]', 'top');
        add_rewrite_rule('^seo-news\.xml$', 'index.php?seom_sitemap=news', 'top');
        add_rewrite_rule('^seo-video\.xml$', 'index.php?seom_sitemap=video', 'top');
        add_rewrite_tag('%seom_sitemap%', '([^&]+)');
        add_rewrite_tag('%seom_sitemap_page%', '([0-9]+)');
    }

    public static function query_vars(array $vars): array {
        $vars[] = 'seom_sitemap';
        $vars[] = 'seom_sitemap_page';
        return $vars;
    }

    /** Called from settings-save handlers, safe any time after 'init'. */
    public static function flush_rules(): void {
        flush_rewrite_rules(false);
    }

    /* ---------------------------------------------------------------
     * Request handling
     * ------------------------------------------------------------- */

    public static function serve(): void {
        $route = self::detect_route();
        if (!$route) return;

        self::serve_route($route);
        exit;
    }

    private static function serve_route(array $route): void {
        nocache_headers();
        status_header(200);
        header('Content-Type: application/xml; charset=' . get_bloginfo('charset'));

        if ($route['type'] === 'index' || $route['type'] === 'map') {
            if (!(int) seom_get('enable_sitemap', 1)) self::deny('XML sitemap is disabled.');
        }

        if ($route['type'] === 'index') { self::index(); return; }

        if ($route['type'] === 'news') {
            if (!(int) seom_get('news_sitemap', 0)) self::deny('News sitemap is disabled.');
            self::news();
            return;
        }

        if ($route['type'] === 'video') {
            if (!(int) seom_get('video_sitemap', 0)) self::deny('Video sitemap is disabled.');
            self::video();
            return;
        }

        self::type_map($route['slug'], $route['page']);
    }

    private static function detect_route(): ?array {
        $type = (string) get_query_var('seom_sitemap', '');
        if ($type !== '') {
            $page = max(1, (int) get_query_var('seom_sitemap_page', 1));
            if ($type === 'index') return ['type' => 'index'];
            if ($type === 'news') return ['type' => 'news'];
            if ($type === 'video') return ['type' => 'video'];
            return ['type' => 'map', 'slug' => sanitize_key($type), 'page' => $page];
        }

        // Safety net only: covers the brief window before a pending
        // flush_rewrite_rules() has taken effect (e.g. right after the
        // toggles above are saved on a host with a slow object cache).
        return self::detect_route_fallback();
    }

    private static function detect_route_fallback(): ?array {
        $path = (string) parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $home_path = '/' . trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home_path !== '/' && strpos($path, rtrim($home_path, '/') . '/') === 0) {
            $path = substr($path, strlen(rtrim($home_path, '/')));
        }
        $clean = untrailingslashit('/' . ltrim($path, '/'));

        if (in_array($clean, ['/seo-sitemap.xml', '/sitemap.xml', '/sitemap_index.xml'], true)) return ['type' => 'index'];
        if ($clean === '/seo-news.xml') return ['type' => 'news'];
        if ($clean === '/seo-video.xml') return ['type' => 'video'];
        if (preg_match('#^/seo-sitemap-([a-z0-9_-]+)-([0-9]+)\.xml$#i', $clean, $m)) {
            return ['type' => 'map', 'slug' => sanitize_key($m[1]), 'page' => max(1, absint($m[2]))];
        }
        return null;
    }

    private static function deny(string $message, int $code = 404): void {
        status_header($code);
        header('Content-Type: text/plain; charset=' . get_bloginfo('charset'));
        echo $message;
        exit;
    }

    private static function esc(string $value): string {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, get_bloginfo('charset'), false);
    }

    /* ---------------------------------------------------------------
     * Transient cache layer
     * ------------------------------------------------------------- */

    private static function cache_version(): int {
        return (int) seom_get('sitemap_cache_version', 1);
    }

    private static function cache_key(string $type, int $page = 0): string {
        return 'seom_sm_' . md5($type . '|' . $page . '|' . self::cache_version());
    }

    /** Bumping the version instantly orphans every cached fragment. */
    public static function purge_cache(): void {
        seom_update('sitemap_cache_version', self::cache_version() + 1);
    }

    public static function on_post_change($post_id): void {
        $post_id = (int) $post_id;
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        self::purge_cache();
    }

    private static function cached_output(string $key, callable $generator): void {
        $cached = get_transient($key);
        if ($cached !== false) { echo $cached; return; }
        ob_start();
        $generator();
        $output = (string) ob_get_clean();
        set_transient($key, $output, self::CACHE_TTL);
        echo $output;
    }

    /* ---------------------------------------------------------------
     * Sitemap index
     * ------------------------------------------------------------- */

    private static function index(): void {
        self::cached_output(self::cache_key('index'), function () {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach (self::sitemap_items() as $item) {
                echo '<sitemap><loc>' . self::esc($item['url']) . '</loc>';
                if ($item['lastmod']) echo '<lastmod>' . self::esc($item['lastmod']) . '</lastmod>';
                echo '</sitemap>';
            }
            if ((int) seom_get('news_sitemap', 0)) echo '<sitemap><loc>' . self::esc(home_url('/seo-news.xml')) . '</loc></sitemap>';
            if ((int) seom_get('video_sitemap', 0)) echo '<sitemap><loc>' . self::esc(home_url('/seo-video.xml')) . '</loc></sitemap>';
            echo '</sitemapindex>';
        });
    }

    /** Post-type and taxonomy sub-sitemaps, split at PER_PAGE URLs each. */
    private static function sitemap_items(): array {
        $items = [];
        $site_lastmod = self::iso_date(get_lastpostmodified('gmt'));

        foreach (seom_post_types() as $pt) {
            $count_obj = wp_count_posts($pt);
            $count = isset($count_obj->publish) ? (int) $count_obj->publish : 0;
            if ($count < 1) continue;
            $pages = (int) ceil($count / self::PER_PAGE);
            $lastmod = self::iso_date(get_lastpostmodified('gmt', $pt)) ?: $site_lastmod;
            for ($i = 1; $i <= $pages; $i++) {
                $items[] = ['url' => home_url('/seo-sitemap-' . sanitize_key($pt) . '-' . $i . '.xml'), 'lastmod' => $lastmod];
            }
        }

        foreach (get_taxonomies(['public' => true], 'objects') as $tax) {
            if (in_array($tax->name, ['post_format'], true)) continue;
            $count = wp_count_terms(['taxonomy' => $tax->name, 'hide_empty' => true]);
            if (is_wp_error($count) || (int) $count < 1) continue;
            $pages = max(1, (int) ceil((int) $count / self::PER_PAGE));
            for ($i = 1; $i <= $pages; $i++) {
                // Per-term lastmod would need an aggregate query per taxonomy;
                // the site-wide last-modified date is used here as a fair
                // approximation and kept cheap to compute for the index.
                $items[] = ['url' => home_url('/seo-sitemap-' . sanitize_key($tax->name) . '-' . $i . '.xml'), 'lastmod' => $site_lastmod];
            }
        }

        return $items;
    }

    /* ---------------------------------------------------------------
     * Per post-type / per-taxonomy sub-sitemap
     * ------------------------------------------------------------- */

    private static function type_map(string $slug, int $page): void {
        $is_tax = taxonomy_exists($slug);
        $is_pt = !$is_tax && post_type_exists($slug);
        if (!$is_tax && !$is_pt) { self::deny('Sitemap not found.'); return; }

        self::cached_output(self::cache_key('map_' . $slug, $page), function () use ($slug, $page, $is_tax) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';

            if ($is_tax) {
                echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
                $terms = get_terms(['taxonomy' => $slug, 'hide_empty' => true, 'number' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE]);
                if (!is_wp_error($terms) && $terms) {
                    $lastmods = self::term_lastmod_map(wp_list_pluck($terms, 'term_taxonomy_id'));
                    foreach ($terms as $term) {
                        $link = get_term_link($term);
                        if (is_wp_error($link)) continue;
                        echo '<url><loc>' . self::esc($link) . '</loc>';
                        $lm = $lastmods[(int) $term->term_taxonomy_id] ?? '';
                        if ($lm) echo '<lastmod>' . self::esc($lm) . '</lastmod>';
                        echo '</url>';
                    }
                }
                echo '</urlset>';
                return;
            }

            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
            $ids = get_posts(['post_type' => $slug, 'post_status' => 'publish', 'posts_per_page' => self::PER_PAGE, 'paged' => $page, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC']);
            foreach ($ids as $id) {
                $url = get_permalink($id);
                if (!$url) continue;
                echo '<url><loc>' . self::esc($url) . '</loc><lastmod>' . self::esc((string) get_post_modified_time('c', true, $id)) . '</lastmod>';
                $post = get_post($id);
                if ($post) {
                    foreach (self::extract_images($post) as $image) {
                        echo '<image:image><image:loc>' . self::esc($image['url']) . '</image:loc><image:title>' . self::esc($image['title']) . '</image:title></image:image>';
                    }
                }
                echo '</url>';
            }
            echo '</urlset>';
        });
    }

    /** Featured image + inline content images, de-duplicated and capped. */
    private static function extract_images(WP_Post $post): array {
        $images = [];

        $thumb_id = get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $src = wp_get_attachment_image_url($thumb_id, 'full');
            if ($src) $images[] = ['url' => $src, 'title' => wp_trim_words(get_the_title($post->ID), 12, '')];
        }

        if (preg_match_all('#<img[^>]+src=["\']([^"\']+)["\'][^>]*>#i', (string) $post->post_content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $src = $m[1];
                if ($src === '' || stripos($src, 'data:') === 0) continue;
                $alt = '';
                if (preg_match('#alt=["\']([^"\']*)["\']#i', $m[0], $am)) $alt = trim($am[1]);
                $images[] = ['url' => $src, 'title' => $alt !== '' ? wp_trim_words($alt, 12, '') : wp_trim_words(get_the_title($post->ID), 12, '')];
            }
        }

        $seen = []; $unique = [];
        foreach ($images as $image) {
            if (isset($seen[$image['url']])) continue;
            $seen[$image['url']] = true;
            $unique[] = $image;
            if (count($unique) >= self::MAX_IMAGES) break;
        }
        return $unique;
    }

    /** Batched MAX(post_modified_gmt) per term, for the term IDs on this page only. */
    private static function term_lastmod_map(array $term_taxonomy_ids): array {
        global $wpdb;
        $ids = array_map('absint', $term_taxonomy_ids);
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT tr.term_taxonomy_id, MAX(p.post_modified_gmt) AS lastmod
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                WHERE tr.term_taxonomy_id IN ($placeholders) AND p.post_status = 'publish'
                GROUP BY tr.term_taxonomy_id";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $ids));
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->term_taxonomy_id] = self::iso_date((string) $row->lastmod);
        }
        return $map;
    }

    private static function iso_date($mysql_gmt): string {
        if (!$mysql_gmt) return '';
        $ts = strtotime($mysql_gmt . ' UTC');
        return $ts ? gmdate('c', $ts) : '';
    }

    /* ---------------------------------------------------------------
     * Google News sitemap
     * ------------------------------------------------------------- */

    private static function news(): void {
        self::cached_output(self::cache_key('news'), function () {
            $publisher = trim((string) seom_get('news_publisher', get_bloginfo('name')));
            $lang = preg_replace('/[^a-zA-Z-]/', '', (string) seom_get('news_language', get_bloginfo('language')));
            $lang = $lang ?: 'en';
            $since = gmdate('Y-m-d H:i:s', time() - self::NEWS_WINDOW);

            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">';
            $posts = get_posts([
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'date_query' => [['after' => $since, 'column' => 'post_date_gmt', 'inclusive' => true]],
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            foreach ($posts as $p) {
                echo '<url><loc>' . self::esc((string) get_permalink($p->ID)) . '</loc><news:news>'
                   . '<news:publication><news:name>' . self::esc($publisher) . '</news:name><news:language>' . self::esc($lang) . '</news:language></news:publication>'
                   . '<news:publication_date>' . self::esc((string) get_post_time('c', true, $p->ID)) . '</news:publication_date>'
                   . '<news:title>' . self::esc(get_the_title($p->ID)) . '</news:title></news:news></url>';
            }
            echo '</urlset>';
        });
    }

    /* ---------------------------------------------------------------
     * Video sitemap
     * ------------------------------------------------------------- */

    private static function video(): void {
        self::cached_output(self::cache_key('video'), function () {
            $posts = get_posts(['post_type' => seom_post_types(), 'post_status' => 'publish', 'posts_per_page' => 200]);
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">';
            foreach ($posts as $p) {
                $content = (string) $p->post_content; $watch = '';
                if (preg_match('#https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $content, $m)) {
                    $watch = 'https://www.youtube.com/watch?v=' . $m[1];
                } elseif (preg_match('#https?://(?:www\.)?vimeo\.com/(\d+)#i', $content, $m)) {
                    $watch = 'https://vimeo.com/' . $m[1];
                }
                if (!$watch) continue;
                $thumb = get_the_post_thumbnail_url($p->ID, 'full');
                if (!$thumb) continue;
                echo '<url><loc>' . self::esc((string) get_permalink($p->ID)) . '</loc><video:video>'
                   . '<video:thumbnail_loc>' . self::esc($thumb) . '</video:thumbnail_loc>'
                   . '<video:title>' . self::esc(wp_trim_words(get_the_title($p->ID), 10, '')) . '</video:title>'
                   . '<video:description>' . self::esc(wp_trim_words(wp_strip_all_tags($p->post_content), 30, '')) . '</video:description>'
                   . '<video:player_loc>' . self::esc($watch) . '</video:player_loc></video:video></url>';
            }
            echo '</urlset>';
        });
    }
}
