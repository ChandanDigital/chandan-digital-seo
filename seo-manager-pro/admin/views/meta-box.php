<?php
/**
 * Classic Editor / mobile fallback SEO panel.
 *
 * Included by SEO_Manager_Meta::render() with these variables already in
 * scope: $post (WP_Post), $title, $desc, $kw, $secondary, $canon, $robots,
 * $ogt, $ogd, $ogi, $schema. Field `name` attributes are preserved exactly
 * as SEO_Manager_Meta::save() expects them, so classic form submits keep
 * working unchanged.
 *
 * On block-editor screens the native Gutenberg PluginSidebar (see
 * admin/js/gutenberg-sidebar.js) is the primary experience; this panel is
 * still rendered (so nothing here needs duplicate wiring) but is hidden via
 * a server-side check so people aren't shown the same controls twice on
 * desktop. It reappears automatically for the Classic Editor and for any
 * screen where the block editor isn't active.
 *
 * @package SEO_Manager
 * @var WP_Post $post
 * @var string  $title
 * @var string  $desc
 * @var string  $kw
 * @var string  $secondary
 * @var string  $canon
 * @var string  $robots
 * @var string  $ogt
 * @var string  $ogd
 * @var string  $ogi
 * @var string  $schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$seomx_is_block_editor = function_exists( 'use_block_editor_for_post' ) && $post instanceof WP_Post && use_block_editor_for_post( $post );

$seomx_robots_decoded = array();
if ( '' !== (string) $robots ) {
	$seomx_decoded = json_decode( (string) $robots, true );
	if ( is_array( $seomx_decoded ) ) {
		$seomx_robots_decoded = $seomx_decoded;
	}
}
$seomx_noindex  = isset( $seomx_robots_decoded['index'] ) && ( 'noindex' === $seomx_robots_decoded['index'] || false === $seomx_robots_decoded['index'] );
$seomx_nofollow = isset( $seomx_robots_decoded['follow'] ) && ( 'nofollow' === $seomx_robots_decoded['follow'] || false === $seomx_robots_decoded['follow'] );

$seomx_schema_types = array( 'auto', 'Article', 'NewsArticle', 'BlogPosting', 'WebPage', 'FAQPage', 'HowTo', 'Product', 'Recipe', 'Event', 'Course', 'JobPosting', 'SoftwareApplication' );
?>
<div class="seomx-panel"
	id="seomx-panel-<?php echo (int) $post->ID; ?>"
	data-rest-url="<?php echo esc_url( rest_url( 'seom/v1/analyze' ) ); ?>"
	data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
	data-ajax-nonce="<?php echo esc_attr( wp_create_nonce( 'seom_admin' ) ); ?>"
	data-post-id="<?php echo (int) $post->ID; ?>"
	data-post-type="<?php echo esc_attr( $post->post_type ); ?>"
	data-post-slug="<?php echo esc_attr( $post->post_name ); ?>"
	<?php echo $seomx_is_block_editor ? 'style="display:none" data-hidden-reason="block-editor-active"' : ''; ?>>

	<style>
		#seomx-panel-<?php echo (int) $post->ID; ?>{--seomx-primary:var(--seom-primary,#4338ca);--seomx-border:var(--seom-border,#e5e7f3);--seomx-text:var(--seom-text,#1c2033);--seomx-muted:var(--seom-text-muted,#6b7089);--seomx-bg:var(--seom-bg,#f7f7fb);--seomx-good:#0f9d58;--seomx-warn:#b5720a;--seomx-bad:#d1373f;font-size:13px;color:var(--seomx-text)}
		#seomx-panel-<?php echo (int) $post->ID; ?> *{box-sizing:border-box}
		.seomx-tabs{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid var(--seomx-border);margin-bottom:16px}
		.seomx-tab{background:none;border:none;padding:9px 14px;font-size:12.8px;font-weight:600;color:var(--seomx-muted);cursor:pointer;border-bottom:2px solid transparent;border-radius:6px 6px 0 0}
		.seomx-tab:hover{color:var(--seomx-primary);background:var(--seomx-bg)}
		.seomx-tab.is-active{color:var(--seomx-primary);border-bottom-color:var(--seomx-primary)}
		.seomx-tabpanel{display:none}
		.seomx-tabpanel.is-active{display:block}
		.seomx-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 18px;margin-bottom:16px}
		.seomx-field{margin-bottom:2px}
		.seomx-field.full{grid-column:1/-1}
		.seomx-field label{display:block;font-weight:700;font-size:12.5px;margin-bottom:6px;color:var(--seomx-text)}
		.seomx-field input[type=text],.seomx-field input[type=url],.seomx-field textarea,.seomx-field select{width:100%;padding:9px 11px;border:1px solid var(--seomx-border);border-radius:8px;font-size:13px;background:#fff;color:var(--seomx-text)}
		.seomx-field input:focus,.seomx-field textarea:focus,.seomx-field select:focus{outline:none;border-color:var(--seomx-primary);box-shadow:0 0 0 3px rgba(67,56,202,.14)}
		.seomx-counter{font-size:11.2px;color:var(--seomx-muted);margin-top:5px}
		.seomx-counter.is-over{color:var(--seomx-bad);font-weight:700}
		.seomx-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 13px;border:1px solid var(--seomx-border);border-radius:8px;margin-bottom:10px;background:var(--seomx-bg)}
		.seomx-toggle-row span{font-size:12.5px;font-weight:600}
		.seomx-switch{position:relative;display:inline-block;width:38px;height:22px;flex:0 0 auto}
		.seomx-switch input{opacity:0;width:0;height:0}
		.seomx-switch-track{position:absolute;inset:0;background:#c7c9dc;border-radius:22px;cursor:pointer;transition:background .15s}
		.seomx-switch-track:before{content:"";position:absolute;height:16px;width:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .15s}
		.seomx-switch input:checked + .seomx-switch-track{background:var(--seomx-primary)}
		.seomx-switch input:checked + .seomx-switch-track:before{transform:translateX(16px)}
		.seomx-serp{border:1px solid var(--seomx-border);border-radius:10px;padding:14px 16px;margin-top:4px;background:#fbfbfe}
		.seomx-serp-label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--seomx-muted);font-weight:800;margin-bottom:8px}
		.seomx-serp-title{font-size:17px;color:#1a0dab;line-height:1.3;overflow-wrap:anywhere}
		.seomx-serp-url{font-size:12.5px;color:#188038;margin:3px 0}
		.seomx-serp-desc{font-size:13px;line-height:1.5;color:#4d5156}
		.seomx-run-btn{margin-bottom:14px}
		.seomx-score-row{display:flex;align-items:center;gap:16px;margin-bottom:16px}
		.seomx-score-ring{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
		.seomx-score-ring-inner{width:58px;height:58px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;font-size:19px;font-weight:800}
		.seomx-score-meta strong{display:block;font-size:14px;margin-bottom:3px}
		.seomx-score-meta{font-size:12px;color:var(--seomx-muted)}
		.seomx-analyzing{font-size:12px;color:var(--seomx-muted);margin-bottom:10px}
		.seomx-cat{border:1px solid var(--seomx-border);border-radius:10px;margin-bottom:10px;overflow:hidden}
		.seomx-cat-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:9px 13px;background:var(--seomx-bg);color:var(--seomx-muted)}
		.seomx-check-list{list-style:none;margin:0;padding:6px 4px}
		.seomx-check-list li{display:flex;gap:9px;padding:6px 9px;font-size:12.5px;line-height:1.5}
		.seomx-dot{flex:0 0 auto;width:17px;height:17px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff;margin-top:1px}
		.seomx-dot.good{background:var(--seomx-good)}
		.seomx-dot.warning{background:var(--seomx-warn)}
		.seomx-dot.bad{background:var(--seomx-bad)}
		.seomx-muted{color:var(--seomx-muted)}
		@media (max-width:782px){
			.seomx-grid{grid-template-columns:1fr}
			.seomx-field input[type=text],.seomx-field input[type=url],.seomx-field textarea,.seomx-field select{padding:11px 12px;font-size:15px}
			.seomx-tab{padding:11px 12px;font-size:13px}
			.seomx-score-ring{width:64px;height:64px}
			.seomx-score-ring-inner{width:50px;height:50px;font-size:16px}
		}
	</style>

	<div class="seomx-tabs" role="tablist">
		<button type="button" class="seomx-tab is-active" data-tab="content"><?php esc_html_e( 'Content', 'seo-manager' ); ?></button>
		<button type="button" class="seomx-tab" data-tab="social"><?php esc_html_e( 'Social', 'seo-manager' ); ?></button>
		<button type="button" class="seomx-tab" data-tab="advanced"><?php esc_html_e( 'Advanced', 'seo-manager' ); ?></button>
		<button type="button" class="seomx-tab" data-tab="analysis"><?php esc_html_e( 'Analysis', 'seo-manager' ); ?></button>
	</div>

	<div class="seomx-tabpanel is-active" data-tabpanel="content">
		<div class="seomx-grid">
			<div class="seomx-field full">
				<label for="seomx-title-<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'SEO Title', 'seo-manager' ); ?></label>
				<input type="text" class="seomx-input-title" id="seomx-title-<?php echo (int) $post->ID; ?>" name="seom_title" value="<?php echo esc_attr( $title ); ?>" maxlength="180">
				<div class="seomx-counter" data-counter-for="title">0 / 65</div>
			</div>
			<div class="seomx-field full">
				<label for="seomx-desc-<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'Meta Description', 'seo-manager' ); ?></label>
				<textarea class="seomx-input-desc" id="seomx-desc-<?php echo (int) $post->ID; ?>" name="seom_description" rows="3" maxlength="320"><?php echo esc_textarea( $desc ); ?></textarea>
				<div class="seomx-counter" data-counter-for="desc">0 / 160</div>
			</div>
			<div class="seomx-field">
				<label for="seomx-keyword-<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'Focus Keyword', 'seo-manager' ); ?></label>
				<input type="text" class="seomx-input-keyword" id="seomx-keyword-<?php echo (int) $post->ID; ?>" name="seom_keyword" value="<?php echo esc_attr( $kw ); ?>" placeholder="<?php esc_attr_e( 'Primary topic', 'seo-manager' ); ?>">
			</div>
			<div class="seomx-field">
				<label for="seomx-secondary-<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'Secondary Keywords', 'seo-manager' ); ?></label>
				<input type="text" id="seomx-secondary-<?php echo (int) $post->ID; ?>" name="seom_secondary_keywords" value="<?php echo esc_attr( $secondary ); ?>" placeholder="<?php esc_attr_e( 'keyword 2, keyword 3', 'seo-manager' ); ?>">
			</div>
			<div class="seomx-field full">
				<label for="seomx-schema-<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'Schema Type', 'seo-manager' ); ?></label>
				<select id="seomx-schema-<?php echo (int) $post->ID; ?>" name="seom_schema_type">
					<?php foreach ( $seomx_schema_types as $seomx_type ) : ?>
						<option value="<?php echo esc_attr( $seomx_type ); ?>" <?php selected( $schema, $seomx_type ); ?>>
							<?php echo esc_html( 'auto' === $seomx_type ? __( 'Automatic', 'seo-manager' ) : $seomx_type ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<div class="seomx-serp">
			<div class="seomx-serp-label"><?php esc_html_e( 'Search Preview', 'seo-manager' ); ?></div>
			<div class="seomx-serp-title" data-serp="title"><?php echo esc_html( $title ?: get_the_title( $post ) ); ?></div>
			<div class="seomx-serp-url"><?php echo esc_html( get_permalink( $post ) ); ?></div>
			<div class="seomx-serp-desc" data-serp="desc"><?php echo esc_html( $desc ); ?></div>
		</div>
	</div>

	<div class="seomx-tabpanel" data-tabpanel="social">
		<div class="seomx-grid">
			<div class="seomx-field full">
				<label for="seomx-ogtitle-<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'Open Graph Title', 'seo-manager' ); ?></label>
				<input type="text" id="seomx-ogtitle-<?php echo (int) $post->ID; ?>" name="seom_og_title" value="<?php echo esc_attr( $ogt ); ?>">
			</div>
			<div class="seomx-field full">
				<label for="seomx-ogdesc-<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'Open Graph Description', 'seo-manager' ); ?></label>
				<textarea id="seomx-ogdesc-<?php echo (int) $post->ID; ?>" name="seom_og_description" rows="2"><?php echo esc_textarea( $ogd ); ?></textarea>
			</div>
			<div class="seomx-field full">
				<label for="seomx-ogimage-<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'Social Image URL', 'seo-manager' ); ?></label>
				<input type="url" id="seomx-ogimage-<?php echo (int) $post->ID; ?>" name="seom_og_image" value="<?php echo esc_attr( $ogi ); ?>">
			</div>
		</div>
	</div>

	<div class="seomx-tabpanel" data-tabpanel="advanced">
		<div class="seomx-grid">
			<div class="seomx-field full">
				<label for="seomx-canonical-<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'Canonical URL', 'seo-manager' ); ?></label>
				<input type="url" id="seomx-canonical-<?php echo (int) $post->ID; ?>" name="seom_canonical" value="<?php echo esc_attr( $canon ); ?>">
			</div>
		</div>
		<div class="seomx-toggle-row">
			<span><?php esc_html_e( 'Allow search engines to show this in results', 'seo-manager' ); ?></span>
			<label class="seomx-switch">
				<input type="checkbox" class="seomx-robots-index" <?php checked( ! $seomx_noindex ); ?>>
				<span class="seomx-switch-track"></span>
			</label>
		</div>
		<div class="seomx-toggle-row">
			<span><?php esc_html_e( 'Allow search engines to follow links here', 'seo-manager' ); ?></span>
			<label class="seomx-switch">
				<input type="checkbox" class="seomx-robots-follow" <?php checked( ! $seomx_nofollow ); ?>>
				<span class="seomx-switch-track"></span>
			</label>
		</div>
		<input type="hidden" class="seomx-robots-hidden" name="seom_robots" value="<?php echo esc_attr( $robots ); ?>">
	</div>

	<div class="seomx-tabpanel" data-tabpanel="analysis">
		<button type="button" class="button button-primary seomx-run-btn"><?php esc_html_e( 'Run SEO Analysis', 'seo-manager' ); ?></button>
		<div class="seomx-analysis-body">
			<p class="seomx-muted"><?php esc_html_e( 'Click "Run SEO Analysis" to score this content against your focus keyword, headings, readability and links.', 'seo-manager' ); ?></p>
		</div>
	</div>
</div>

<script>
( function () {
	var panel = document.getElementById( 'seomx-panel-<?php echo (int) $post->ID; ?>' );
	if ( ! panel || panel.dataset.seomxBound ) {
		return;
	}
	panel.dataset.seomxBound = '1';

	/* ---- Tabs ---- */
	var tabs = panel.querySelectorAll( '.seomx-tab' );
	for ( var t = 0; t < tabs.length; t++ ) {
		tabs[ t ].addEventListener( 'click', function () {
			var target = this.getAttribute( 'data-tab' );
			var allTabs = panel.querySelectorAll( '.seomx-tab' );
			var allPanels = panel.querySelectorAll( '.seomx-tabpanel' );
			for ( var i = 0; i < allTabs.length; i++ ) { allTabs[ i ].classList.remove( 'is-active' ); }
			for ( var j = 0; j < allPanels.length; j++ ) { allPanels[ j ].classList.remove( 'is-active' ); }
			this.classList.add( 'is-active' );
			panel.querySelector( '[data-tabpanel="' + target + '"]' ).classList.add( 'is-active' );
		} );
	}

	/* ---- Live counters + SERP preview ---- */
	var titleInput = panel.querySelector( '.seomx-input-title' );
	var descInput  = panel.querySelector( '.seomx-input-desc' );
	var serpTitle  = panel.querySelector( '[data-serp="title"]' );
	var serpDesc   = panel.querySelector( '[data-serp="desc"]' );
	var titleCounter = panel.querySelector( '[data-counter-for="title"]' );
	var descCounter  = panel.querySelector( '[data-counter-for="desc"]' );
	var wpTitleField = document.getElementById( 'title' );

	function refreshPreview() {
		var titleVal = titleInput.value || ( wpTitleField ? wpTitleField.value : '' );
		var descVal  = descInput.value || '';
		if ( serpTitle ) { serpTitle.textContent = titleVal; }
		if ( serpDesc ) { serpDesc.textContent = descVal; }
		if ( titleCounter ) {
			titleCounter.textContent = titleInput.value.length + ' / 65';
			titleCounter.classList.toggle( 'is-over', titleInput.value.length > 65 );
		}
		if ( descCounter ) {
			descCounter.textContent = descInput.value.length + ' / 160';
			descCounter.classList.toggle( 'is-over', descInput.value.length > 160 );
		}
	}
	titleInput.addEventListener( 'input', refreshPreview );
	descInput.addEventListener( 'input', refreshPreview );
	refreshPreview();

	/* ---- Robots toggle sync ---- */
	var robotsIndex  = panel.querySelector( '.seomx-robots-index' );
	var robotsFollow = panel.querySelector( '.seomx-robots-follow' );
	var robotsHidden = panel.querySelector( '.seomx-robots-hidden' );
	function syncRobots() {
		robotsHidden.value = JSON.stringify( {
			index: robotsIndex.checked ? 'index' : 'noindex',
			follow: robotsFollow.checked ? 'follow' : 'nofollow'
		} );
	}
	robotsIndex.addEventListener( 'change', syncRobots );
	robotsFollow.addEventListener( 'change', syncRobots );

	/* ---- Live content (Visual or Text editor) ---- */
	function currentContent() {
		if ( typeof tinymce !== 'undefined' ) {
			var editor = tinymce.get( 'content' );
			if ( editor && ! editor.isHidden() ) {
				return editor.getContent();
			}
		}
		var textarea = document.getElementById( 'content' );
		return textarea ? textarea.value : '';
	}

	/* ---- Analysis ---- */
	var runBtn      = panel.querySelector( '.seomx-run-btn' );
	var analysisBody = panel.querySelector( '.seomx-analysis-body' );
	var CATEGORY_LABELS = {
		keyword: '<?php echo esc_js( __( 'Keyword Placement', 'seo-manager' ) ); ?>',
		content: '<?php echo esc_js( __( 'Content Quality', 'seo-manager' ) ); ?>',
		structure: '<?php echo esc_js( __( 'Heading Structure', 'seo-manager' ) ); ?>',
		readability: '<?php echo esc_js( __( 'Readability', 'seo-manager' ) ); ?>',
		media: '<?php echo esc_js( __( 'Media & Links', 'seo-manager' ) ); ?>'
	};

	function esc( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text || '';
		return div.innerHTML;
	}

	function scoreColor( score ) {
		if ( score >= 80 ) { return '#0f9d58'; }
		if ( score >= 60 ) { return '#b5720a'; }
		return '#d1373f';
	}

	function renderReport( data ) {
		if ( ! data || typeof data.score === 'undefined' ) {
			analysisBody.innerHTML = '<p class="seomx-muted"><?php echo esc_js( __( 'Analysis failed. Please try again.', 'seo-manager' ) ); ?></p>';
			return;
		}
		var color = scoreColor( data.score );
		var deg   = Math.round( ( data.score / 100 ) * 360 );
		var html  = '<div class="seomx-score-row">';
		html += '<div class="seomx-score-ring" style="background:conic-gradient(' + color + ' ' + deg + 'deg, #e5e7f3 ' + deg + 'deg)">';
		html += '<div class="seomx-score-ring-inner" style="color:' + color + '">' + data.score + '</div></div>';
		html += '<div class="seomx-score-meta"><strong>' + data.score + '/100 — ' + esc( data.label ) + '</strong>';
		html += data.words + ' <?php echo esc_js( __( 'words', 'seo-manager' ) ); ?> · ' + data.headings + ' <?php echo esc_js( __( 'headings', 'seo-manager' ) ); ?> · ' + data.links + ' <?php echo esc_js( __( 'links', 'seo-manager' ) ); ?></div>';
		html += '</div>';

		var groups = {};
		var order = [];
		( data.checks || [] ).forEach( function ( check ) {
			if ( ! groups[ check.category ] ) { groups[ check.category ] = []; order.push( check.category ); }
			groups[ check.category ].push( check );
		} );

		order.forEach( function ( category ) {
			html += '<div class="seomx-cat"><div class="seomx-cat-title">' + ( CATEGORY_LABELS[ category ] || category ) + '</div>';
			html += '<ul class="seomx-check-list">';
			groups[ category ].forEach( function ( check ) {
				var mark = check.status === 'good' ? '\u2713' : ( check.status === 'warning' ? '!' : '\u2715' );
				html += '<li><span class="seomx-dot ' + check.status + '">' + mark + '</span><span>' + esc( check.message ) + '</span></li>';
			} );
			html += '</ul></div>';
		} );

		analysisBody.innerHTML = html;
	}

	function runAnalysis() {
		runBtn.disabled = true;
		runBtn.textContent = '<?php echo esc_js( __( 'Analyzing…', 'seo-manager' ) ); ?>';
		analysisBody.innerHTML = '<p class="seomx-analyzing"><?php echo esc_js( __( 'Analyzing…', 'seo-manager' ) ); ?></p>';

		var payload = {
			post_id: parseInt( panel.dataset.postId, 10 ) || 0,
			post_type: panel.dataset.postType,
			title: titleInput.value,
			slug: panel.dataset.postSlug,
			description: descInput.value,
			keyword: panel.querySelector( '.seomx-input-keyword' ).value,
			content: currentContent()
		};

		fetch( panel.dataset.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': panel.dataset.restNonce },
			body: JSON.stringify( payload )
		} ).then( function ( response ) {
			if ( ! response.ok ) { throw new Error( 'rest-failed' ); }
			return response.json();
		} ).then( function ( data ) {
			renderReport( data );
		} ).catch( function () {
			var body = new URLSearchParams();
			body.set( 'action', 'seom_analyze' );
			body.set( 'nonce', panel.dataset.ajaxNonce );
			Object.keys( payload ).forEach( function ( key ) { body.set( key, payload[ key ] || '' ); } );
			fetch( panel.dataset.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( r ) {
					if ( r && r.success ) { renderReport( r.data ); }
					else { analysisBody.innerHTML = '<p class="seomx-muted"><?php echo esc_js( __( 'Analysis failed. Please try again.', 'seo-manager' ) ); ?></p>'; }
				} )
				.catch( function () {
					analysisBody.innerHTML = '<p class="seomx-muted"><?php echo esc_js( __( 'Analysis failed. Please try again.', 'seo-manager' ) ); ?></p>';
				} );
		} ).finally( function () {
			runBtn.disabled = false;
			runBtn.textContent = '<?php echo esc_js( __( 'Run SEO Analysis', 'seo-manager' ) ); ?>';
		} );
	}

	runBtn.addEventListener( 'click', runAnalysis );
} )();
</script>
