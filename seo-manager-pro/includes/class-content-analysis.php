<?php
/**
 * Content analysis + on-page SEO scoring engine.
 *
 * Responsibilities:
 *  - Real-time, weighted 0-100 SEO scoring (keyword placement, content depth,
 *    heading anatomy, readability, media and links).
 *  - REST API (`seom/v1/analyze`, `seom/v1/analyze-term`) that powers the
 *    Gutenberg PluginSidebar and the Classic Editor / taxonomy fallback panel,
 *    scoring the *unsaved, in-editor* content in real time.
 *  - Legacy `wp_ajax_seom_analyze` action kept for backward compatibility.
 *  - Native `register_post_meta()` / `register_term_meta()` wiring so every
 *    field the sidebar exposes is validated, sanitized, and persisted through
 *    core's own metadata pipeline (`update_post_meta()` / `update_term_meta()`).
 *  - A lightweight "Focus Keyword + SEO Analysis" panel appended to every
 *    public taxonomy's Add/Edit screens, alongside the fields already
 *    rendered by SEO_Manager_Taxonomy.
 *
 * @package SEO_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SEO_Manager_Content_Analysis {

	/**
	 * Scoring weights. Every branch below awards a whole or partial share of
	 * its weight; the sum of all weights is exactly 100 so the final score
	 * never needs anything more than a straight addition + clamp.
	 */
	private const WEIGHTS = array(
		'title_keyword'       => 12,
		'slug_keyword'        => 8,
		'description_keyword' => 8,
		'intro_keyword'       => 7,
		'word_count'          => 10,
		'keyword_density'     => 10,
		'paragraph_length'    => 5,
		'sentence_length'     => 5,
		'h1_single'           => 7,
		'h2_present'          => 6,
		'keyword_in_heading'  => 7,
		'readability'         => 10,
		'image_alt'           => 3,
		'links_present'       => 2,
	);

	/** Guards against double-registering the same taxonomy save hook. */
	private static bool $taxonomy_hooks_registered = false;

	/** Guards the taxonomy panel's inline <style> from being echoed twice. */
	private static bool $term_panel_style_printed = false;

	/* -----------------------------------------------------------------
	 * Bootstrap
	 * ------------------------------------------------------------- */

	public static function init(): void {
		// Legacy AJAX — kept so the Classic/mobile fallback panel and any
		// third-party code that still calls `seom_analyze` keeps working.
		add_action( 'wp_ajax_seom_analyze', array( __CLASS__, 'ajax_analyze_post' ) );
		add_action( 'wp_ajax_seom_analyze_term', array( __CLASS__, 'ajax_analyze_term' ) );

		// REST API — real-time analysis of unsaved editor state.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Native meta registration: lets the Gutenberg sidebar read/write
		// fields through core's own validated + sanitized meta pipeline.
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );

		// Gutenberg sidebar — fires for every post type using the block editor.
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );

		// Append a Focus Keyword + Analysis panel to every public taxonomy's
		// Add/Edit screens (categories, tags, and any custom taxonomy).
		add_action( 'admin_init', array( __CLASS__, 'hook_taxonomy_panels' ) );
	}

	/* -----------------------------------------------------------------
	 * Native meta registration (Faultless Integration & Schema Validation)
	 * ------------------------------------------------------------- */

	/**
	 * Map of meta suffix => sanitize "kind". `text` uses sanitize_text_field(),
	 * `html` uses wp_kses_post() (kept minimal/safe for description-type
	 * fields but future-proofs anyone who needs inline formatting), and
	 * `url` uses esc_url_raw().
	 */
	private static function post_meta_field_map(): array {
		return array(
			'title'              => 'text',
			'description'        => 'html',
			'keyword'            => 'text',
			'secondary_keywords' => 'text',
			'canonical'          => 'url',
			'og_title'           => 'text',
			'og_description'     => 'html',
			'og_image'           => 'url',
			'robots'             => 'text',
			'schema_type'        => 'text',
		);
	}

	private static function term_meta_field_map(): array {
		return array(
			'title'       => 'text',
			'description' => 'html',
			'keyword'     => 'text',
			'canonical'   => 'url',
			'robots'      => 'text',
		);
	}

	private static function sanitize_by_kind( $value, string $kind ): string {
		$value = wp_unslash( (string) $value );
		switch ( $kind ) {
			case 'url':
				return esc_url_raw( $value );
			case 'html':
				return wp_kses_post( $value );
			case 'text':
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Registers every SEO meta field with `show_in_rest`, a strict
	 * sanitize_callback, and an auth_callback tied to the correct
	 * edit capability. This is what allows the Gutenberg sidebar to save
	 * fields natively (WordPress core stores them via update_post_meta()
	 * automatically once the REST request validates).
	 */
	public static function register_meta(): void {
		foreach ( seom_post_types() as $post_type ) {
			foreach ( self::post_meta_field_map() as $key => $kind ) {
				register_post_meta(
					$post_type,
					'_seom_' . $key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => static function ( $value ) use ( $kind ) {
							return self::sanitize_by_kind( $value, $kind );
						},
						'auth_callback'     => static function () use ( $post_type ) {
							$object = get_post_type_object( $post_type );
							return $object && current_user_can( $object->cap->edit_posts );
						},
					)
				);
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			foreach ( self::term_meta_field_map() as $key => $kind ) {
				register_term_meta(
					$taxonomy,
					'_seom_' . $key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => static function ( $value ) use ( $kind ) {
							return self::sanitize_by_kind( $value, $kind );
						},
						'auth_callback'     => static function () use ( $taxonomy ) {
							$object = get_taxonomy( $taxonomy );
							return $object && current_user_can( $object->cap->edit_terms );
						},
					)
				);
			}
		}
	}

	/* -----------------------------------------------------------------
	 * REST API
	 * ------------------------------------------------------------- */

	public static function register_routes(): void {
		register_rest_route(
			'seom/v1',
			'/analyze',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_analyze_post' ),
				'permission_callback' => array( __CLASS__, 'rest_permission_post' ),
				'args'                => array(
					'post_id'     => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'post_type'   => array(
						'type'              => 'string',
						'default'           => 'post',
						'sanitize_callback' => 'sanitize_key',
					),
					'title'       => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'keyword'     => array( 'type' => 'string' ),
					'content'     => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			'seom/v1',
			'/analyze-term',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_analyze_term' ),
				'permission_callback' => array( __CLASS__, 'rest_permission_term' ),
				'args'                => array(
					'term_id'     => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'taxonomy'    => array(
						'type'              => 'string',
						'default'           => 'category',
						'sanitize_callback' => 'sanitize_key',
					),
					'title'       => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'keyword'     => array( 'type' => 'string' ),
					'content'     => array( 'type' => 'string' ),
				),
			)
		);
	}

	public static function rest_permission_post( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( $post_id > 0 ) {
			return current_user_can( 'edit_post', $post_id );
		}
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'post' ) );
		$object    = get_post_type_object( $post_type );
		return $object ? current_user_can( $object->cap->edit_posts ) : current_user_can( 'edit_posts' );
	}

	public static function rest_permission_term( WP_REST_Request $request ) {
		$term_id = absint( $request->get_param( 'term_id' ) );
		if ( $term_id > 0 ) {
			return current_user_can( 'edit_term', $term_id );
		}
		$taxonomy = sanitize_key( (string) ( $request->get_param( 'taxonomy' ) ?: 'category' ) );
		$object   = get_taxonomy( $taxonomy );
		return $object ? current_user_can( $object->cap->edit_terms ) : current_user_can( 'manage_categories' );
	}

	public static function rest_analyze_post( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$report  = self::analyze_post( $post_id, self::collect_overrides_from_request( $request ) );
		if ( empty( $report ) ) {
			return new WP_Error( 'seom_not_found', __( 'Content not found.', 'seo-manager' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $report );
	}

	public static function rest_analyze_term( WP_REST_Request $request ) {
		$term_id = absint( $request->get_param( 'term_id' ) );
		$report  = self::analyze_term( $term_id, self::collect_overrides_from_request( $request ) );
		if ( empty( $report ) ) {
			return new WP_Error( 'seom_not_found', __( 'Term not found.', 'seo-manager' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $report );
	}

	private static function collect_overrides_from_request( WP_REST_Request $request ): array {
		$overrides = array();
		foreach ( array( 'title', 'slug', 'description', 'keyword' ) as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$overrides[ $field ] = sanitize_text_field( (string) $value );
			}
		}
		$content = $request->get_param( 'content' );
		if ( null !== $content ) {
			// Structural tags (h1-h6, p, img, a, ul/li ...) must survive this
			// pass — the analyzer parses them — while scripts/styles/iframes
			// are stripped, exactly what wp_kses_post() is designed for.
			$overrides['content'] = wp_kses_post( (string) $content );
		}
		if ( array_key_exists( 'slug', $overrides ) ) {
			$overrides['slug'] = sanitize_title( $overrides['slug'] );
		}
		return $overrides;
	}

	/* -----------------------------------------------------------------
	 * Legacy AJAX (backward compatible with the existing `seom_analyze`
	 * action and admin-ajax.php driven screens).
	 * ------------------------------------------------------------- */

	public static function ajax_analyze_post(): void {
		check_ajax_referer( 'seom_admin', 'nonce' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$can     = $post_id > 0 ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
		if ( ! $can ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-manager' ) ) );
		}
		$report = self::analyze_post( $post_id, self::collect_overrides_from_post() );
		if ( empty( $report ) ) {
			wp_send_json_error( array( 'message' => __( 'Content not found.', 'seo-manager' ) ) );
		}
		wp_send_json_success( $report );
	}

	public static function ajax_analyze_term(): void {
		check_ajax_referer( 'seom_admin', 'nonce' );
		$term_id = absint( $_POST['term_id'] ?? 0 );
		$can     = $term_id > 0 ? current_user_can( 'edit_term', $term_id ) : current_user_can( 'manage_categories' );
		if ( ! $can ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-manager' ) ) );
		}
		$report = self::analyze_term( $term_id, self::collect_overrides_from_post() );
		if ( empty( $report ) ) {
			wp_send_json_error( array( 'message' => __( 'Term not found.', 'seo-manager' ) ) );
		}
		wp_send_json_success( $report );
	}

	private static function collect_overrides_from_post(): array {
		$overrides = array();
		foreach ( array( 'title', 'slug', 'description', 'keyword' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$overrides[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}
		if ( isset( $_POST['content'] ) ) {
			$overrides['content'] = wp_kses_post( wp_unslash( (string) $_POST['content'] ) );
		}
		if ( array_key_exists( 'slug', $overrides ) ) {
			$overrides['slug'] = sanitize_title( $overrides['slug'] );
		}
		return $overrides;
	}

	/* -----------------------------------------------------------------
	 * Gutenberg sidebar asset loading
	 * ------------------------------------------------------------- */

	public static function enqueue_block_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		$post_type = $screen->post_type ?: 'post';
		if ( ! in_array( $post_type, seom_post_types(), true ) ) {
			return;
		}

		$deps = array(
			'wp-plugins',
			'wp-editor',
			'wp-edit-post',
			'wp-element',
			'wp-components',
			'wp-data',
			'wp-core-data',
			'wp-i18n',
			'wp-compose',
			'wp-api-fetch',
			'wp-url',
			'wp-hooks',
		);

		wp_enqueue_script( 'seom-gutenberg-sidebar', SEOM_URL . 'admin/js/gutenberg-sidebar.js', $deps, SEOM_VERSION, true );
		wp_set_script_translations( 'seom-gutenberg-sidebar', 'seo-manager' );

		global $post;
		$post_id = $post instanceof WP_Post ? $post->ID : 0;

		wp_localize_script(
			'seom-gutenberg-sidebar',
			'SEOM_SIDEBAR',
			array(
				'restNamespace'   => 'seom/v1',
				'postId'          => $post_id,
				'postType'        => $post_type,
				'homeUrl'         => home_url( '/' ),
				'defaultOgImage'  => seom_default_image(),
				'schemaTypes'     => array( 'auto', 'Article', 'NewsArticle', 'BlogPosting', 'WebPage', 'FAQPage', 'HowTo', 'Product', 'Recipe', 'Event', 'Course', 'JobPosting', 'SoftwareApplication' ),
				'analyzeDebounce' => 700,
			)
		);
	}

	/* -----------------------------------------------------------------
	 * Taxonomy panel (Focus Keyword + Analysis) for category/tag/CPT
	 * taxonomy Add & Edit screens. Additive: it hooks the same core
	 * *_add_form_fields / *_edit_form_fields actions SEO_Manager_Taxonomy
	 * already uses, so nothing there needs to change.
	 * ------------------------------------------------------------- */

	public static function hook_taxonomy_panels(): void {
		if ( self::$taxonomy_hooks_registered ) {
			return;
		}
		self::$taxonomy_hooks_registered = true;

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( __CLASS__, 'render_term_panel_new' ) );
			add_action( $taxonomy . '_edit_form_fields', array( __CLASS__, 'render_term_panel_edit' ), 20, 1 );
		}

		// created_term / edited_term fire for every taxonomy already —
		// register the keyword-save handler once, not per taxonomy.
		add_action( 'created_term', array( __CLASS__, 'save_term_keyword' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'save_term_keyword' ), 10, 3 );
	}

	public static function save_term_keyword( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! isset( $_POST['seom_term_keyword'] ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$value = sanitize_text_field( wp_unslash( (string) $_POST['seom_term_keyword'] ) );
		if ( '' === $value ) {
			delete_term_meta( $term_id, '_seom_keyword' );
		} else {
			update_term_meta( $term_id, '_seom_keyword', $value );
		}
	}

	public static function render_term_panel_new( string $taxonomy ): void {
		self::print_term_panel_style();
		echo '<div class="form-field seomx-term-field">';
		echo '<label for="seomx-term-keyword">' . esc_html__( 'Focus Keyword', 'seo-manager' ) . '</label>';
		echo '<input type="text" name="seom_term_keyword" id="seomx-term-keyword" value="" placeholder="' . esc_attr__( 'Primary topic for this term', 'seo-manager' ) . '">';
		echo '<p>' . esc_html__( 'Used for the live SEO analysis below once this term is created.', 'seo-manager' ) . '</p>';
		echo '</div>';
		self::print_term_analysis_markup( 0, $taxonomy, '', 'add' );
	}

	public static function render_term_panel_edit( WP_Term $term ): void {
		self::print_term_panel_style();
		$keyword = (string) get_term_meta( $term->term_id, '_seom_keyword', true );
		echo '<tr class="form-field seomx-term-field"><th scope="row"><label for="seomx-term-keyword">' . esc_html__( 'Focus Keyword', 'seo-manager' ) . '</label></th><td>';
		echo '<input type="text" name="seom_term_keyword" id="seomx-term-keyword" value="' . esc_attr( $keyword ) . '" placeholder="' . esc_attr__( 'Primary topic for this term', 'seo-manager' ) . '">';
		echo '</td></tr>';
		echo '<tr class="form-field seomx-term-field"><th scope="row">' . esc_html__( 'SEO Analysis', 'seo-manager' ) . '</th><td>';
		self::print_term_analysis_markup( $term->term_id, $term->taxonomy, $keyword, 'edit' );
		echo '</td></tr>';
	}

	private static function print_term_panel_style(): void {
		if ( self::$term_panel_style_printed ) {
			return;
		}
		self::$term_panel_style_printed = true;
		?>
		<style>
			.seomx-term-field input[type="text"]{max-width:400px}
			.seomx-term-analysis{margin-top:10px;max-width:600px}
			.seomx-term-analysis .seomx-run-btn{margin-bottom:10px}
			.seomx-term-analysis .seomx-score-line{font-weight:700;font-size:14px;margin-bottom:8px}
			.seomx-term-analysis .seomx-check-list{margin:0;padding:0;list-style:none}
			.seomx-term-analysis .seomx-check-list li{padding:4px 0 4px 22px;position:relative;font-size:12.5px;line-height:1.5}
			.seomx-term-analysis .seomx-check-list li:before{position:absolute;left:0;top:4px;font-weight:700}
			.seomx-term-analysis .seomx-check-list li.good:before{content:"\2713";color:#0f9d58}
			.seomx-term-analysis .seomx-check-list li.warning:before{content:"!";color:#b5720a}
			.seomx-term-analysis .seomx-check-list li.bad:before{content:"\2715";color:#d1373f}
		</style>
		<?php
	}

	/**
	 * Echoes the "Run SEO Analysis" button + result container for a term,
	 * and a small self-contained script wiring it to the REST endpoint.
	 * $context is 'add' or 'edit' and controls which core field IDs the
	 * script reads the live Name/Slug/Description values from.
	 */
	private static function print_term_analysis_markup( int $term_id, string $taxonomy, string $keyword, string $context ): void {
		$rest_url   = esc_url( rest_url( 'seom/v1/analyze-term' ) );
		$rest_nonce = esc_attr( wp_create_nonce( 'wp_rest' ) );
		$ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );
		$ajax_nonce = esc_attr( wp_create_nonce( 'seom_admin' ) );

		$name_field = 'add' === $context ? 'tag-name' : 'name';
		$slug_field = 'add' === $context ? 'tag-slug' : 'slug';
		$desc_field = 'add' === $context ? 'tag-description' : 'description';

		echo '<div class="seomx-term-analysis" data-term-id="' . (int) $term_id . '" data-taxonomy="' . esc_attr( $taxonomy ) . '" ';
		echo 'data-rest-url="' . $rest_url . '" data-rest-nonce="' . $rest_nonce . '" data-ajax-url="' . $ajax_url . '" data-ajax-nonce="' . $ajax_nonce . '" ';
		echo 'data-name-field="' . esc_attr( $name_field ) . '" data-slug-field="' . esc_attr( $slug_field ) . '" data-desc-field="' . esc_attr( $desc_field ) . '">';
		echo '<button type="button" class="button seomx-run-btn">' . esc_html__( 'Run SEO Analysis', 'seo-manager' ) . '</button>';
		echo '<div class="seomx-result"><p class="description">' . esc_html__( 'Click "Run SEO Analysis" to score this term.', 'seo-manager' ) . '</p></div>';
		echo '</div>';
		?>
		<script>
		( function () {
			var panels = document.querySelectorAll( '.seomx-term-analysis' );
			for ( var i = 0; i < panels.length; i++ ) {
				( function ( panel ) {
					var btn = panel.querySelector( '.seomx-run-btn' );
					if ( ! btn || btn.dataset.seomxBound ) {
						return;
					}
					btn.dataset.seomxBound = '1';
					btn.addEventListener( 'click', function () {
						runTermAnalysis( panel );
					} );
				} )( panels[ i ] );
			}

			function fieldValue( id ) {
				var el = document.getElementById( id );
				return el ? el.value : '';
			}

			function esc( text ) {
				var div = document.createElement( 'div' );
				div.textContent = text || '';
				return div.innerHTML;
			}

			function renderResult( el, data ) {
				if ( ! data ) {
					el.innerHTML = '<p class="description"><?php echo esc_js( __( 'Analysis failed.', 'seo-manager' ) ); ?></p>';
					return;
				}
				var html = '<div class="seomx-score-line">' + '<?php echo esc_js( __( 'SEO Score', 'seo-manager' ) ); ?>' + ': ' + data.score + '/100 (' + esc( data.label ) + ')</div>';
				html += '<ul class="seomx-check-list">';
				( data.checks || [] ).forEach( function ( check ) {
					html += '<li class="' + check.status + '">' + esc( check.message ) + '</li>';
				} );
				html += '</ul>';
				el.innerHTML = html;
			}

			function runTermAnalysis( panel ) {
				var btn      = panel.querySelector( '.seomx-run-btn' );
				var resultEl = panel.querySelector( '.seomx-result' );
				var payload  = {
					term_id: parseInt( panel.dataset.termId, 10 ) || 0,
					taxonomy: panel.dataset.taxonomy,
					title: fieldValue( panel.dataset.nameField ),
					slug: fieldValue( panel.dataset.slugField ),
					description: fieldValue( panel.dataset.descField ),
					keyword: fieldValue( 'seomx-term-keyword' ),
					content: fieldValue( panel.dataset.descField )
				};
				btn.disabled = true;
				btn.textContent = '<?php echo esc_js( __( 'Analyzing…', 'seo-manager' ) ); ?>';

				fetch( panel.dataset.restUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': panel.dataset.restNonce },
					body: JSON.stringify( payload )
				} ).then( function ( response ) {
					if ( ! response.ok ) { throw new Error( 'rest-failed' ); }
					return response.json();
				} ).then( function ( data ) {
					renderResult( resultEl, data );
				} ).catch( function () {
					var body = new URLSearchParams();
					body.set( 'action', 'seom_analyze_term' );
					body.set( 'nonce', panel.dataset.ajaxNonce );
					Object.keys( payload ).forEach( function ( key ) { body.set( key, payload[ key ] || '' ); } );
					fetch( panel.dataset.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( r ) {
							if ( r && r.success ) { renderResult( resultEl, r.data ); }
							else { resultEl.innerHTML = '<p class="description"><?php echo esc_js( __( 'Analysis failed.', 'seo-manager' ) ); ?></p>'; }
						} )
						.catch( function () {
							resultEl.innerHTML = '<p class="description"><?php echo esc_js( __( 'Analysis failed.', 'seo-manager' ) ); ?></p>';
						} );
				} ).finally( function () {
					btn.disabled = false;
					btn.textContent = '<?php echo esc_js( __( 'Run SEO Analysis', 'seo-manager' ) ); ?>';
				} );
			}
		} )();
		</script>
		<?php
	}

	/* -----------------------------------------------------------------
	 * Public analysis entry points
	 * ------------------------------------------------------------- */

	/**
	 * Backward-compatible entry point. Existing callers
	 * (SEO_Manager_Audit::run(), SEO_Manager_Meta::column()) call this with
	 * a single post ID and rely on the flat 'score', 'h1', 'images',
	 * 'images_with_alt' and 'links' keys — all still present below.
	 */
	public static function analyze( int $id, string $type = 'post', array $overrides = array() ): array {
		if ( 'term' === $type ) {
			return self::analyze_term( $id, $overrides );
		}
		return self::analyze_post( $id, $overrides );
	}

	public static function analyze_post( int $id, array $overrides = array() ): array {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( $id > 0 && ! $post ) {
			return array();
		}

		$title       = array_key_exists( 'title', $overrides ) ? $overrides['title'] : ( $post ? seom_title_for_post( $id ) : '' );
		$slug        = array_key_exists( 'slug', $overrides ) ? $overrides['slug'] : ( $post ? (string) $post->post_name : '' );
		$description = array_key_exists( 'description', $overrides ) ? $overrides['description'] : ( $post ? seom_desc_for_post( $id ) : '' );
		$keyword     = array_key_exists( 'keyword', $overrides ) ? $overrides['keyword'] : ( $post ? (string) seom_meta( $id, 'keyword' ) : '' );
		$content     = array_key_exists( 'content', $overrides ) ? $overrides['content'] : ( $post ? (string) $post->post_content : '' );

		$report               = self::build_report( compact( 'title', 'slug', 'description', 'keyword', 'content' ) );
		$report['post_id']    = $id;
		$report['post_type']  = $post ? $post->post_type : ( $overrides['post_type'] ?? 'post' );
		return $report;
	}

	public static function analyze_term( int $term_id, array $overrides = array() ): array {
		$term = $term_id > 0 ? get_term( $term_id ) : null;
		if ( $term_id > 0 && ( ! $term || is_wp_error( $term ) ) ) {
			return array();
		}

		$stored_description = $term ? (string) get_term_meta( $term_id, '_seom_description', true ) : '';
		if ( '' === $stored_description && $term ) {
			$stored_description = (string) term_description( $term_id );
		}

		$title       = array_key_exists( 'title', $overrides ) ? $overrides['title'] : ( $term ? (string) get_term_meta( $term_id, '_seom_title', true ) : '' );
		$slug        = array_key_exists( 'slug', $overrides ) ? $overrides['slug'] : ( $term ? (string) $term->slug : '' );
		$description = array_key_exists( 'description', $overrides ) ? $overrides['description'] : $stored_description;
		$keyword     = array_key_exists( 'keyword', $overrides ) ? $overrides['keyword'] : ( $term ? (string) get_term_meta( $term_id, '_seom_keyword', true ) : '' );
		$content     = array_key_exists( 'content', $overrides ) ? $overrides['content'] : $stored_description;

		$report             = self::build_report( compact( 'title', 'slug', 'description', 'keyword', 'content' ) );
		$report['term_id']  = $term_id;
		$report['taxonomy'] = $term ? $term->taxonomy : ( $overrides['taxonomy'] ?? 'category' );
		return $report;
	}

	/* -----------------------------------------------------------------
	 * Scoring engine
	 * ------------------------------------------------------------- */

	private static function build_report( array $ctx ): array {
		$title        = trim( (string) ( $ctx['title'] ?? '' ) );
		$slug         = trim( (string) ( $ctx['slug'] ?? '' ) );
		$description  = trim( (string) ( $ctx['description'] ?? '' ) );
		$keyword      = trim( (string) ( $ctx['keyword'] ?? '' ) );
		$content_html = (string) ( $ctx['content'] ?? '' );
		$has_keyword  = '' !== $keyword;

		$plain      = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $content_html ) ) ) );
		$words      = self::tokenize_words( $plain );
		$word_count = count( $words );

		$sentence_count = max( 1, preg_match_all( '/[.!?]+(?=\s|$)/u', $plain ) );
		$flesch         = self::flesch_score( $words, $sentence_count );

		$paragraphs      = self::extract_paragraphs( $content_html );
		$paragraph_count = count( $paragraphs );
		$long_paragraphs = 0;
		foreach ( $paragraphs as $paragraph ) {
			if ( count( self::tokenize_words( $paragraph ) ) > 150 ) {
				++$long_paragraphs;
			}
		}

		$headings      = self::extract_headings( $content_html );
		$h1_count      = count( $headings['h1'] );
		$h2_count      = count( $headings['h2'] );
		$heading_total = 0;
		foreach ( $headings as $list ) {
			$heading_total += count( $list );
		}

		$images          = self::extract_images( $content_html );
		$image_count     = count( $images );
		$images_with_alt = 0;
		foreach ( $images as $image ) {
			if ( $image['has_alt'] ) {
				++$images_with_alt;
			}
		}

		$links = self::extract_links( $content_html );

		$low_text       = mb_strtolower( $plain );
		$low_kw         = mb_strtolower( $keyword );
		$kw_occurrences = 0;
		if ( $has_keyword && $word_count > 0 ) {
			$pattern        = '/(?<![\p{L}\p{N}])' . preg_quote( $low_kw, '/' ) . '(?![\p{L}\p{N}])/u';
			$kw_occurrences = (int) preg_match_all( $pattern, $low_text );
		}
		$density = $word_count > 0 ? round( ( $kw_occurrences / $word_count ) * 100, 2 ) : 0.0;

		$intro      = $paragraphs[0] ?? implode( ' ', array_slice( $words, 0, 60 ) );
		$slug_words = trim( str_replace( array( '-', '_' ), ' ', $slug ) );

		$heading_pool       = mb_strtolower( implode( ' ', array_merge( $headings['h2'], $headings['h3'] ) ) );
		$keyword_in_heading = $has_keyword && '' !== $heading_pool && false !== mb_stripos( $heading_pool, $low_kw );

		$checks = array();

		/* ---- Keyword placement (35 pts): Title, Permalink, Meta Description, Introduction ---- */
		$checks[] = self::score_keyword_in_text(
			'title_keyword',
			'keyword',
			$title,
			$keyword,
			self::WEIGHTS['title_keyword'],
			__( 'Focus keyword found in the SEO title.', 'seo-manager' ),
			__( 'Focus keyword is missing from the SEO title.', 'seo-manager' ),
			__( 'Add an SEO title to check keyword placement.', 'seo-manager' )
		);
		$checks[] = self::score_keyword_in_text(
			'slug_keyword',
			'keyword',
			$slug_words,
			$keyword,
			self::WEIGHTS['slug_keyword'],
			__( 'Focus keyword found in the URL slug.', 'seo-manager' ),
			__( 'Focus keyword is missing from the URL slug.', 'seo-manager' ),
			__( 'Set a URL slug to check keyword placement.', 'seo-manager' )
		);
		$checks[] = self::score_keyword_in_text(
			'description_keyword',
			'keyword',
			$description,
			$keyword,
			self::WEIGHTS['description_keyword'],
			__( 'Focus keyword found in the meta description.', 'seo-manager' ),
			__( 'Focus keyword is missing from the meta description.', 'seo-manager' ),
			__( 'Add a meta description to check keyword placement.', 'seo-manager' )
		);
		$checks[] = self::score_keyword_in_text(
			'intro_keyword',
			'keyword',
			$intro,
			$keyword,
			self::WEIGHTS['intro_keyword'],
			__( 'Focus keyword found in the opening paragraph.', 'seo-manager' ),
			__( 'Focus keyword does not appear in the first paragraph.', 'seo-manager' ),
			__( 'Write an introduction to check keyword placement.', 'seo-manager' )
		);

		/* ---- Content quality (30 pts) ---- */
		$w = self::WEIGHTS['word_count'];
		if ( $word_count >= 300 ) {
			$checks[] = self::check( 'word_count', 'content', 'good', sprintf( __( 'Content length is good (%d words).', 'seo-manager' ), $word_count ), $w, $w );
		} elseif ( $word_count >= 150 ) {
			$points   = (int) round( $w * ( $word_count / 300 ) );
			$checks[] = self::check( 'word_count', 'content', 'warning', sprintf( __( 'Content is a little thin (%d words). Aim for 300 or more.', 'seo-manager' ), $word_count ), $points, $w );
		} else {
			$checks[] = self::check( 'word_count', 'content', 'bad', sprintf( __( 'Content is very short (%d words).', 'seo-manager' ), $word_count ), 0, $w );
		}

		$w = self::WEIGHTS['keyword_density'];
		if ( ! $has_keyword ) {
			$checks[] = self::check( 'keyword_density', 'content', 'warning', __( 'Add a focus keyword to check keyword density.', 'seo-manager' ), 0, $w );
		} elseif ( 0 === $kw_occurrences ) {
			$checks[] = self::check( 'keyword_density', 'content', 'bad', __( 'Focus keyword does not appear in the content body.', 'seo-manager' ), 0, $w );
		} elseif ( $density > 2.5 ) {
			$checks[] = self::check( 'keyword_density', 'content', 'bad', sprintf( __( 'Keyword density is %s%%, which looks like keyword stuffing. Keep it under 2.5%%.', 'seo-manager' ), $density ), 0, $w );
		} elseif ( $density < 0.5 ) {
			$points   = (int) round( $w * ( $density / 0.5 ) );
			$checks[] = self::check( 'keyword_density', 'content', 'warning', sprintf( __( 'Keyword density is only %s%%. Use the focus keyword a little more.', 'seo-manager' ), $density ), $points, $w );
		} else {
			$checks[] = self::check( 'keyword_density', 'content', 'good', sprintf( __( 'Keyword density is %s%%, within the healthy 0.5–2.5%% range.', 'seo-manager' ), $density ), $w, $w );
		}

		$w = self::WEIGHTS['paragraph_length'];
		if ( 0 === $paragraph_count ) {
			$checks[] = self::check( 'paragraph_length', 'content', 'warning', __( 'No paragraph blocks were found to check.', 'seo-manager' ), (int) round( $w / 2 ), $w );
		} elseif ( 0 === $long_paragraphs ) {
			$checks[] = self::check( 'paragraph_length', 'content', 'good', __( 'All paragraphs are a comfortable length.', 'seo-manager' ), $w, $w );
		} else {
			$message  = sprintf(
				/* translators: %d: number of long paragraphs. */
				_n( '%d paragraph runs longer than 150 words. Break it up for mobile readers.', '%d paragraphs run longer than 150 words. Break them up for mobile readers.', $long_paragraphs, 'seo-manager' ),
				$long_paragraphs
			);
			$checks[] = self::check( 'paragraph_length', 'content', 'warning', $message, 0, $w );
		}

		$w   = self::WEIGHTS['sentence_length'];
		$asl = $flesch['avg_sentence_length'];
		if ( 0 === $word_count ) {
			$checks[] = self::check( 'sentence_length', 'readability', 'warning', __( 'Add content to check sentence length.', 'seo-manager' ), 0, $w );
		} elseif ( $asl <= 20 ) {
			$checks[] = self::check( 'sentence_length', 'readability', 'good', sprintf( __( 'Average sentence length is %s words, easy to follow.', 'seo-manager' ), $asl ), $w, $w );
		} elseif ( $asl <= 25 ) {
			$points   = (int) round( $w * 0.4 );
			$checks[] = self::check( 'sentence_length', 'readability', 'warning', sprintf( __( 'Average sentence length is %s words. Try shorter sentences.', 'seo-manager' ), $asl ), $points, $w );
		} else {
			$checks[] = self::check( 'sentence_length', 'readability', 'bad', sprintf( __( 'Average sentence length is %s words, which is hard to read.', 'seo-manager' ), $asl ), 0, $w );
		}

		/* ---- Heading anatomy (20 pts) ---- */
		$w = self::WEIGHTS['h1_single'];
		if ( 1 === $h1_count ) {
			$checks[] = self::check( 'h1_single', 'structure', 'good', __( 'Exactly one H1 heading was found.', 'seo-manager' ), $w, $w );
		} elseif ( 0 === $h1_count ) {
			$checks[] = self::check( 'h1_single', 'structure', 'bad', __( 'No H1 heading found. Add one clear H1.', 'seo-manager' ), 0, $w );
		} else {
			$checks[] = self::check( 'h1_single', 'structure', 'bad', sprintf( __( '%d H1 headings found. Use only one H1 per page.', 'seo-manager' ), $h1_count ), 0, $w );
		}

		$w = self::WEIGHTS['h2_present'];
		if ( $h2_count > 0 ) {
			$checks[] = self::check( 'h2_present', 'structure', 'good', sprintf( __( '%d H2 subheading(s) help structure the content.', 'seo-manager' ), $h2_count ), $w, $w );
		} else {
			$checks[] = self::check( 'h2_present', 'structure', 'bad', __( 'Add at least one H2 subheading.', 'seo-manager' ), 0, $w );
		}

		$w = self::WEIGHTS['keyword_in_heading'];
		if ( ! $has_keyword ) {
			$checks[] = self::check( 'keyword_in_heading', 'structure', 'warning', __( 'Add a focus keyword to check subheadings.', 'seo-manager' ), 0, $w );
		} elseif ( $keyword_in_heading ) {
			$checks[] = self::check( 'keyword_in_heading', 'structure', 'good', __( 'Focus keyword appears in a subheading.', 'seo-manager' ), $w, $w );
		} else {
			$checks[] = self::check( 'keyword_in_heading', 'structure', 'bad', __( 'Focus keyword does not appear in any H2 or H3.', 'seo-manager' ), 0, $w );
		}

		/* ---- Readability (10 pts) ---- */
		$w = self::WEIGHTS['readability'];
		if ( 0 === $word_count ) {
			$checks[] = self::check( 'readability', 'readability', 'warning', __( 'Add content to calculate readability.', 'seo-manager' ), 0, $w );
		} elseif ( $flesch['score'] >= 60 ) {
			$checks[] = self::check( 'readability', 'readability', 'good', sprintf( __( 'Readability score is %1$d (%2$s).', 'seo-manager' ), $flesch['score'], $flesch['level'] ), $w, $w );
		} elseif ( $flesch['score'] >= 50 ) {
			$points   = (int) round( $w * 0.6 );
			$checks[] = self::check( 'readability', 'readability', 'warning', sprintf( __( 'Readability score is %1$d (%2$s). Simplify a little.', 'seo-manager' ), $flesch['score'], $flesch['level'] ), $points, $w );
		} else {
			$checks[] = self::check( 'readability', 'readability', 'bad', sprintf( __( 'Readability score is %1$d (%2$s). Use shorter words and sentences.', 'seo-manager' ), $flesch['score'], $flesch['level'] ), 0, $w );
		}

		/* ---- Media & links (5 pts) ---- */
		$w = self::WEIGHTS['image_alt'];
		if ( 0 === $image_count ) {
			$checks[] = self::check( 'image_alt', 'media', 'good', __( 'No images to check.', 'seo-manager' ), $w, $w );
		} elseif ( $images_with_alt === $image_count ) {
			$checks[] = self::check( 'image_alt', 'media', 'good', sprintf( __( 'All %d image(s) have ALT text.', 'seo-manager' ), $image_count ), $w, $w );
		} else {
			$points   = (int) round( $w * ( $images_with_alt / max( 1, $image_count ) ) );
			$checks[] = self::check( 'image_alt', 'media', 'warning', sprintf( __( '%1$d of %2$d images are missing ALT text.', 'seo-manager' ), $image_count - $images_with_alt, $image_count ), $points, $w );
		}

		$w = self::WEIGHTS['links_present'];
		if ( $links['total'] > 0 ) {
			$checks[] = self::check( 'links_present', 'media', 'good', sprintf( __( '%1$d internal and %2$d external link(s) found.', 'seo-manager' ), $links['internal'], $links['external'] ), $w, $w );
		} else {
			$checks[] = self::check( 'links_present', 'media', 'bad', __( 'Add at least one internal or external link.', 'seo-manager' ), 0, $w );
		}

		// Real, explicit scoring loop — sums the points every check actually earned.
		$score = 0;
		foreach ( $checks as $check ) {
			$score += $check['points'];
		}
		$score = (int) max( 0, min( 100, $score ) );

		$issues = array();
		$passed = array();
		foreach ( $checks as $check ) {
			if ( 'good' === $check['status'] ) {
				$passed[] = $check['message'];
			} else {
				$issues[] = $check['message'];
			}
		}

		return array(
			// Backward-compatible flat keys (relied on by SEO_Manager_Audit
			// and SEO_Manager_Meta) — do not rename or remove these.
			'score'               => $score,
			'label'               => seom_score_label( $score ),
			'words'               => $word_count,
			'readability'         => $flesch['score'],
			'headings'            => $heading_total,
			'h1'                  => $h1_count,
			'images'              => $image_count,
			'images_with_alt'     => $images_with_alt,
			'links'               => $links['total'],
			'keyword_occurrences' => $kw_occurrences,
			'issues'              => array_values( array_unique( $issues ) ),
			'passed'              => array_values( array_unique( $passed ) ),
			'title_length'        => mb_strlen( $title ),
			'description_length'  => mb_strlen( $description ),

			// Rich, structured data for the Gutenberg sidebar / fallback panel.
			'checks'              => $checks,
			'stats'               => array(
				'word_count'             => $word_count,
				'sentence_count'         => $sentence_count,
				'paragraph_count'        => $paragraph_count,
				'long_paragraphs'        => $long_paragraphs,
				'avg_sentence_length'    => $flesch['avg_sentence_length'],
				'avg_syllables_per_word' => $flesch['avg_syllables_per_word'],
				'flesch_score'           => $flesch['score'],
				'reading_level'          => $flesch['level'],
				'keyword_density'        => $density,
				'keyword_occurrences'    => $kw_occurrences,
			),
			'headings_detail'     => array(
				'counts'             => array(
					'h1' => $h1_count,
					'h2' => $h2_count,
					'h3' => count( $headings['h3'] ),
					'h4' => count( $headings['h4'] ),
					'h5' => count( $headings['h5'] ),
					'h6' => count( $headings['h6'] ),
				),
				'total'              => $heading_total,
				'h1_status'          => 1 === $h1_count ? 'ok' : ( 0 === $h1_count ? 'missing' : 'multiple' ),
				'keyword_in_heading' => $keyword_in_heading,
			),
			'media'               => array(
				'images'             => $image_count,
				'images_with_alt'    => $images_with_alt,
				'images_missing_alt' => $image_count - $images_with_alt,
			),
			'links_detail'        => $links,
			'meta'                => array(
				'title'       => $title,
				'slug'        => $slug,
				'description' => $description,
				'keyword'     => $keyword,
			),
		);
	}

	private static function score_keyword_in_text( string $id, string $category, string $haystack, string $keyword, int $weight, string $good_message, string $bad_message, string $empty_message ): array {
		$haystack = trim( $haystack );
		$keyword  = trim( $keyword );
		if ( '' === $keyword || '' === $haystack ) {
			return self::check( $id, $category, 'warning', $empty_message, 0, $weight );
		}
		$found = false !== mb_stripos( $haystack, $keyword );
		return self::check( $id, $category, $found ? 'good' : 'bad', $found ? $good_message : $bad_message, $found ? $weight : 0, $weight );
	}

	private static function check( string $id, string $category, string $status, string $message, int $points, int $max_points ): array {
		return array(
			'id'         => $id,
			'category'   => $category,
			'status'     => $status,
			'message'    => $message,
			'points'     => max( 0, $points ),
			'max_points' => $max_points,
		);
	}

	/* -----------------------------------------------------------------
	 * Text parsing helpers
	 * ------------------------------------------------------------- */

	private static function tokenize_words( string $text ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}
		return preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	}

	private static function count_syllables( string $word ): int {
		$word = mb_strtolower( preg_replace( '/[^\pL]/u', '', $word ) );
		if ( '' === $word ) {
			return 0;
		}
		return max( 1, preg_match_all( '/[aeiouy]+/u', $word ) );
	}

	private static function flesch_score( array $words, int $sentence_count ): array {
		$word_count = count( $words );
		if ( 0 === $word_count || 0 === $sentence_count ) {
			return array(
				'score'                  => 0,
				'level'                  => __( 'N/A', 'seo-manager' ),
				'avg_sentence_length'    => 0.0,
				'avg_syllables_per_word' => 0.0,
			);
		}
		$syllables = 0;
		foreach ( $words as $word ) {
			$syllables += self::count_syllables( $word );
		}
		$avg_sentence_length    = $word_count / $sentence_count;
		$avg_syllables_per_word = $syllables / $word_count;
		$raw_score              = 206.835 - ( 1.015 * $avg_sentence_length ) - ( 84.6 * $avg_syllables_per_word );
		$score                  = (int) max( 0, min( 100, round( $raw_score ) ) );

		return array(
			'score'                  => $score,
			'level'                  => self::flesch_level( $score ),
			'avg_sentence_length'    => round( $avg_sentence_length, 1 ),
			'avg_syllables_per_word' => round( $avg_syllables_per_word, 2 ),
		);
	}

	private static function flesch_level( int $score ): string {
		if ( $score >= 90 ) {
			return __( 'Very Easy', 'seo-manager' );
		}
		if ( $score >= 80 ) {
			return __( 'Easy', 'seo-manager' );
		}
		if ( $score >= 70 ) {
			return __( 'Fairly Easy', 'seo-manager' );
		}
		if ( $score >= 60 ) {
			return __( 'Standard', 'seo-manager' );
		}
		if ( $score >= 50 ) {
			return __( 'Fairly Difficult', 'seo-manager' );
		}
		if ( $score >= 30 ) {
			return __( 'Difficult', 'seo-manager' );
		}
		return __( 'Very Confusing', 'seo-manager' );
	}

	private static function extract_headings( string $html ): array {
		$out = array(
			'h1' => array(),
			'h2' => array(),
			'h3' => array(),
			'h4' => array(),
			'h5' => array(),
			'h6' => array(),
		);
		if ( preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$level = 'h' . $match[1];
				$text  = trim( wp_strip_all_tags( $match[2] ) );
				if ( '' !== $text ) {
					$out[ $level ][] = $text;
				}
			}
		}
		return $out;
	}

	private static function extract_paragraphs( string $html ): array {
		$out = array();
		if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches ) ) {
			foreach ( $matches[1] as $inner ) {
				$text = trim( wp_strip_all_tags( $inner ) );
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}
		return $out;
	}

	private static function extract_images( string $html ): array {
		$out = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $matches ) ) {
			foreach ( $matches[0] as $tag ) {
				$alt   = preg_match( '/\balt=["\']([^"\']*)["\']/i', $tag, $alt_match ) ? trim( $alt_match[1] ) : '';
				$src   = preg_match( '/\bsrc=["\']([^"\']*)["\']/i', $tag, $src_match ) ? trim( $src_match[1] ) : '';
				$out[] = array(
					'src'     => $src,
					'alt'     => $alt,
					'has_alt' => '' !== $alt,
				);
			}
		}
		return $out;
	}

	private static function extract_links( string $html ): array {
		$out       = array(
			'internal' => 0,
			'external' => 0,
			'total'    => 0,
			'nofollow' => 0,
		);
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( preg_match_all( '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$tag  = $match[0];
				$href = $match[1];
				if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'javascript:' ) ) {
					continue;
				}
				++$out['total'];
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $host || $host === $home_host ) {
					++$out['internal'];
				} else {
					++$out['external'];
				}
				if ( preg_match( '/\brel=["\'][^"\']*nofollow[^"\']*["\']/i', $tag ) ) {
					++$out['nofollow'];
				}
			}
		}
		return $out;
	}
}
