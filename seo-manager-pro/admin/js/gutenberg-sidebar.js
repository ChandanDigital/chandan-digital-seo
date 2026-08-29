/**
 * Chandan Digital SEO — Gutenberg sidebar.
 *
 * Registers a native PluginSidebar (right-hand panel) that scores the post
 * in real time as the author types, using the same weighted engine that
 * powers the REST endpoint (`class-content-analysis.php`). No build step is
 * required: this file runs as-is in the browser using `wp.element.createElement`.
 *
 * @package SEO_Manager
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.plugins || ! wp.data || ! wp.components || ! wp.apiFetch ) {
		return;
	}

	var el              = wp.element.createElement;
	var Fragment         = wp.element.Fragment;
	var useState         = wp.element.useState;
	var useEffect        = wp.element.useEffect;
	var useRef           = wp.element.useRef;
	var registerPlugin   = wp.plugins.registerPlugin;
	var useSelect        = wp.data.useSelect;
	var useDispatch      = wp.data.useDispatch;
	var apiFetch         = wp.apiFetch;
	var __               = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( text ) { return text; };

	// Modern WordPress aliases PluginSidebar into `@wordpress/editor`; older
	// versions still expose it only on `@wordpress/edit-post`. Support both.
	var PluginSidebar               = ( wp.editor && wp.editor.PluginSidebar ) || ( wp.editPost && wp.editPost.PluginSidebar );
	var PluginSidebarMoreMenuItem   = ( wp.editor && wp.editor.PluginSidebarMoreMenuItem ) || ( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem );

	if ( ! PluginSidebar ) {
		return;
	}

	var PanelBody       = wp.components.PanelBody;
	var TextControl      = wp.components.TextControl;
	var TextareaControl  = wp.components.TextareaControl;
	var SelectControl    = wp.components.SelectControl;
	var ToggleControl    = wp.components.ToggleControl;
	var Notice           = wp.components.Notice;
	var Button           = wp.components.Button;
	var TabPanel         = wp.components.TabPanel;

	var SETTINGS = window.SEOM_SIDEBAR || {};
	var DEBOUNCE_MS = SETTINGS.analyzeDebounce || 700;
	var SCHEMA_TYPES = SETTINGS.schemaTypes || [ 'auto', 'Article', 'WebPage' ];

	var META_KEYS = {
		title: '_seom_title',
		description: '_seom_description',
		keyword: '_seom_keyword',
		secondaryKeywords: '_seom_secondary_keywords',
		canonical: '_seom_canonical',
		ogTitle: '_seom_og_title',
		ogDescription: '_seom_og_description',
		ogImage: '_seom_og_image',
		robots: '_seom_robots',
		schemaType: '_seom_schema_type'
	};

	/* ------------------------------------------------------------------
	 * Small self-contained stylesheet, injected once.
	 * ---------------------------------------------------------------- */
	function injectStyles() {
		if ( document.getElementById( 'seomx-sidebar-style' ) ) {
			return;
		}
		var style = document.createElement( 'style' );
		style.id = 'seomx-sidebar-style';
		style.textContent =
			'.seomx-sb-score-row{display:flex;align-items:center;gap:14px;margin-bottom:14px}' +
			'.seomx-sb-ring{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex:0 0 auto}' +
			'.seomx-sb-ring-inner{width:52px;height:52px;border-radius:50%;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center}' +
			'.seomx-sb-ring-num{font-size:17px;font-weight:700;line-height:1}' +
			'.seomx-sb-ring-label{font-size:15px}' +
			'.seomx-sb-score-meta{font-size:12px;color:#6b7089}' +
			'.seomx-sb-score-meta strong{display:block;font-size:13px;color:#1c2033;margin-bottom:2px}' +
			'.seomx-sb-checklist{margin:0;padding:0;list-style:none}' +
			'.seomx-sb-checklist li{padding:6px 0;font-size:12.5px;line-height:1.5;border-bottom:1px solid #f0f0f4;display:flex;gap:8px}' +
			'.seomx-sb-checklist li:last-child{border-bottom:none}' +
			'.seomx-sb-dot{flex:0 0 auto;width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;margin-top:1px}' +
			'.seomx-sb-dot.good{background:#0f9d58}' +
			'.seomx-sb-dot.warning{background:#b5720a}' +
			'.seomx-sb-dot.bad{background:#d1373f}' +
			'.seomx-sb-serp{border:1px solid #e5e7f3;border-radius:8px;padding:12px;margin:10px 0 16px;background:#fbfbfe}' +
			'.seomx-sb-serp-title{color:#1a0dab;font-size:16px;line-height:1.3;margin-bottom:2px;overflow-wrap:anywhere}' +
			'.seomx-sb-serp-url{color:#188038;font-size:12px;margin-bottom:3px}' +
			'.seomx-sb-serp-desc{color:#4d5156;font-size:12.5px;line-height:1.4}' +
			'.seomx-sb-counter{font-size:11px;color:#9498ad;margin-top:2px}' +
			'.seomx-sb-counter.over{color:#d1373f}' +
			'.seomx-sb-stats{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-size:11.5px;color:#6b7089;margin-top:10px}' +
			'.seomx-sb-stats strong{color:#1c2033}' +
			'.seomx-sb-analyzing{font-size:11.5px;color:#9498ad;margin-bottom:8px}';
		document.head.appendChild( style );
	}

	function scoreColor( score ) {
		if ( score >= 80 ) { return '#0f9d58'; }
		if ( score >= 60 ) { return '#b5720a'; }
		return '#d1373f';
	}

	function debounce( fn, wait ) {
		var timer = null;
		var debounced = function () {
			var args = arguments;
			if ( timer ) { clearTimeout( timer ); }
			timer = setTimeout( function () { fn.apply( null, args ); }, wait );
		};
		debounced.cancel = function () { if ( timer ) { clearTimeout( timer ); } };
		return debounced;
	}

	function parseRobots( raw ) {
		var out = { noindex: false, nofollow: false };
		if ( ! raw ) { return out; }
		try {
			var parsed = JSON.parse( raw );
			if ( parsed && typeof parsed === 'object' ) {
				out.noindex  = parsed.index === 'noindex' || parsed.index === false;
				out.nofollow = parsed.follow === 'nofollow' || parsed.follow === false;
			}
		} catch ( e ) { /* leave defaults */ }
		return out;
	}

	function stringifyRobots( noindex, nofollow ) {
		return JSON.stringify( {
			index: noindex ? 'noindex' : 'index',
			follow: nofollow ? 'nofollow' : 'follow'
		} );
	}

	/* ------------------------------------------------------------------
	 * Score ring
	 * ---------------------------------------------------------------- */
	function ScoreRing( props ) {
		var score = props.score;
		var color = scoreColor( score );
		var deg   = Math.round( ( score / 100 ) * 360 );
		return el(
			'div',
			{
				className: 'seomx-sb-ring',
				style: { background: 'conic-gradient(' + color + ' ' + deg + 'deg, #e5e7f3 ' + deg + 'deg)' }
			},
			el(
				'div',
				{ className: 'seomx-sb-ring-inner' },
				el( 'span', { className: 'seomx-sb-ring-num', style: { color: color } }, String( score ) )
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Checklist grouped by category
	 * ---------------------------------------------------------------- */
	var CATEGORY_LABELS = {
		keyword: __( 'Keyword Placement', 'seo-manager' ),
		content: __( 'Content Quality', 'seo-manager' ),
		structure: __( 'Heading Structure', 'seo-manager' ),
		readability: __( 'Readability', 'seo-manager' ),
		media: __( 'Media & Links', 'seo-manager' )
	};

	function Checklist( props ) {
		var checks = props.checks || [];
		var groups = {};
		var order = [];
		checks.forEach( function ( check ) {
			if ( ! groups[ check.category ] ) {
				groups[ check.category ] = [];
				order.push( check.category );
			}
			groups[ check.category ].push( check );
		} );

		return el(
			Fragment,
			null,
			order.map( function ( category ) {
				return el(
					PanelBody,
					{ key: category, title: CATEGORY_LABELS[ category ] || category, initialOpen: 'keyword' === category },
					el(
						'ul',
						{ className: 'seomx-sb-checklist' },
						groups[ category ].map( function ( check ) {
							var mark = 'good' === check.status ? '\u2713' : ( 'warning' === check.status ? '!' : '\u2715' );
							return el(
								'li',
								{ key: check.id },
								el( 'span', { className: 'seomx-sb-dot ' + check.status }, mark ),
								el( 'span', null, check.message )
							);
						} )
					)
				);
			} )
		);
	}

	/* ------------------------------------------------------------------
	 * Main sidebar component
	 * ---------------------------------------------------------------- */
	function SeomSidebarPanel() {
		var postId   = useSelect( function ( select ) { return select( 'core/editor' ).getCurrentPostId(); }, [] );
		var postType = useSelect( function ( select ) { return select( 'core/editor' ).getCurrentPostType(); }, [] );
		var title    = useSelect( function ( select ) { return select( 'core/editor' ).getEditedPostAttribute( 'title' ); }, [] );
		var slug     = useSelect( function ( select ) { return select( 'core/editor' ).getEditedPostAttribute( 'slug' ); }, [] );
		var content  = useSelect( function ( select ) { return select( 'core/editor' ).getEditedPostContent(); }, [] );
		var meta     = useSelect( function ( select ) { return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {}; }, [] );

		var editPost = useDispatch( 'core/editor' ).editPost;

		function setMeta( key, value ) {
			var next = {};
			next[ key ] = value;
			editPost( { meta: next } );
		}

		var seoTitle    = meta[ META_KEYS.title ] || '';
		var description = meta[ META_KEYS.description ] || '';
		var keyword     = meta[ META_KEYS.keyword ] || '';
		var secondary   = meta[ META_KEYS.secondaryKeywords ] || '';
		var canonical   = meta[ META_KEYS.canonical ] || '';
		var ogTitle     = meta[ META_KEYS.ogTitle ] || '';
		var ogDesc      = meta[ META_KEYS.ogDescription ] || '';
		var ogImage     = meta[ META_KEYS.ogImage ] || '';
		var schemaType  = meta[ META_KEYS.schemaType ] || 'auto';
		var robotsState = parseRobots( meta[ META_KEYS.robots ] || '' );

		var effectiveTitle = seoTitle || title || '';
		var effectiveDesc  = description || '';

		var reportState      = useState( null );
		var report           = reportState[ 0 ];
		var setReport        = reportState[ 1 ];
		var analyzingState   = useState( false );
		var isAnalyzing      = analyzingState[ 0 ];
		var setIsAnalyzing   = analyzingState[ 1 ];
		var errorState       = useState( '' );
		var error            = errorState[ 0 ];
		var setError         = errorState[ 1 ];

		var debouncedAnalyze = useRef( null );
		if ( ! debouncedAnalyze.current ) {
			debouncedAnalyze.current = debounce( function ( payload ) {
				setIsAnalyzing( true );
				setError( '' );
				apiFetch( {
					path: '/' + SETTINGS.restNamespace + '/analyze',
					method: 'POST',
					data: payload
				} ).then( function ( response ) {
					setReport( response );
					setIsAnalyzing( false );
				} ).catch( function ( err ) {
					setIsAnalyzing( false );
					setError( ( err && err.message ) || __( 'Analysis failed.', 'seo-manager' ) );
				} );
			}, DEBOUNCE_MS );
		}

		useEffect(
			function () {
				debouncedAnalyze.current( {
					post_id: postId || 0,
					post_type: postType || SETTINGS.postType || 'post',
					title: title || '',
					slug: slug || '',
					description: effectiveDesc,
					keyword: keyword,
					content: content || ''
				} );
			},
			[ title, slug, content, effectiveDesc, keyword ]
		);

		var score        = report ? report.score : 0;
		var label        = report ? report.label : __( 'Not analyzed yet', 'seo-manager' );

		var titleLen = effectiveTitle.length;
		var descLen  = effectiveDesc.length;

		return el(
			Fragment,
			null,
			el(
				PluginSidebarMoreMenuItem,
				{ target: 'seom-sidebar', icon: 'chart-area' },
				__( 'Chandan Digital SEO', 'seo-manager' )
			),
			el(
				PluginSidebar,
				{ name: 'seom-sidebar', title: __( 'Chandan Digital SEO', 'seo-manager' ), icon: 'chart-area' },
				el(
					PanelBody,
					{ title: __( 'SEO Score', 'seo-manager' ), initialOpen: true },
					isAnalyzing ? el( 'div', { className: 'seomx-sb-analyzing' }, __( 'Analyzing…', 'seo-manager' ) ) : null,
					error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
					el(
						'div',
						{ className: 'seomx-sb-score-row' },
						el( ScoreRing, { score: score, label: label } ),
						el(
							'div',
							{ className: 'seomx-sb-score-meta' },
							el( 'strong', null, score + '/100 — ' + label ),
							report ? ( report.stats.word_count + ' ' + __( 'words', 'seo-manager' ) + ' · ' + report.headings + ' ' + __( 'headings', 'seo-manager' ) ) : __( 'Start typing to see your score.', 'seo-manager' )
						)
					),
					report ? el(
						'div',
						{ className: 'seomx-sb-stats' },
						el( 'span', null, __( 'Readability', 'seo-manager' ) + ': ', el( 'strong', null, report.stats.reading_level ) ),
						el( 'span', null, __( 'Keyword density', 'seo-manager' ) + ': ', el( 'strong', null, report.stats.keyword_density + '%' ) ),
						el( 'span', null, __( 'H1 tags', 'seo-manager' ) + ': ', el( 'strong', null, String( report.headings_detail.counts.h1 ) ) ),
						el( 'span', null, __( 'Images w/ ALT', 'seo-manager' ) + ': ', el( 'strong', null, report.media.images_with_alt + '/' + report.media.images ) )
					) : null
				),
				el(
					PanelBody,
					{ title: __( 'Content', 'seo-manager' ), initialOpen: true },
					el( TextControl, {
						label: __( 'SEO Title', 'seo-manager' ),
						value: seoTitle,
						placeholder: title || '',
						onChange: function ( value ) { setMeta( META_KEYS.title, value ); }
					} ),
					el( 'div', { className: 'seomx-sb-counter' + ( titleLen > 65 ? ' over' : '' ) }, titleLen + ' / 65 ' + __( 'characters', 'seo-manager' ) ),
					el( TextareaControl, {
						label: __( 'Meta Description', 'seo-manager' ),
						value: description,
						rows: 3,
						onChange: function ( value ) { setMeta( META_KEYS.description, value ); }
					} ),
					el( 'div', { className: 'seomx-sb-counter' + ( descLen > 160 ? ' over' : '' ) }, descLen + ' / 160 ' + __( 'characters', 'seo-manager' ) ),
					el( TextControl, {
						label: __( 'Focus Keyword', 'seo-manager' ),
						value: keyword,
						placeholder: __( 'Primary topic', 'seo-manager' ),
						onChange: function ( value ) { setMeta( META_KEYS.keyword, value ); }
					} ),
					el( TextControl, {
						label: __( 'Secondary Keywords', 'seo-manager' ),
						value: secondary,
						placeholder: __( 'keyword 2, keyword 3', 'seo-manager' ),
						onChange: function ( value ) { setMeta( META_KEYS.secondaryKeywords, value ); }
					} ),
					el( SelectControl, {
						label: __( 'Schema Type', 'seo-manager' ),
						value: schemaType,
						options: SCHEMA_TYPES.map( function ( type ) { return { label: 'auto' === type ? __( 'Automatic', 'seo-manager' ) : type, value: type }; } ),
						onChange: function ( value ) { setMeta( META_KEYS.schemaType, value ); }
					} ),
					el(
						'div',
						{ className: 'seomx-sb-serp' },
						el( 'div', { className: 'seomx-sb-serp-title' }, effectiveTitle || __( '(SEO title preview)', 'seo-manager' ) ),
						el( 'div', { className: 'seomx-sb-serp-url' }, ( SETTINGS.homeUrl || '' ) + ( slug || '' ) ),
						el( 'div', { className: 'seomx-sb-serp-desc' }, effectiveDesc || __( '(Meta description preview)', 'seo-manager' ) )
					)
				),
				el(
					PanelBody,
					{ title: __( 'Analysis Checklist', 'seo-manager' ), initialOpen: false },
					report ? el( Checklist, { checks: report.checks } ) : el( 'p', null, __( 'Analysis will appear here once you start writing.', 'seo-manager' ) )
				),
				el(
					PanelBody,
					{ title: __( 'Social Sharing', 'seo-manager' ), initialOpen: false },
					el( TextControl, {
						label: __( 'Open Graph Title', 'seo-manager' ),
						value: ogTitle,
						onChange: function ( value ) { setMeta( META_KEYS.ogTitle, value ); }
					} ),
					el( TextareaControl, {
						label: __( 'Open Graph Description', 'seo-manager' ),
						value: ogDesc,
						rows: 2,
						onChange: function ( value ) { setMeta( META_KEYS.ogDescription, value ); }
					} ),
					el( TextControl, {
						label: __( 'Social Image URL', 'seo-manager' ),
						type: 'url',
						value: ogImage,
						placeholder: SETTINGS.defaultOgImage || '',
						onChange: function ( value ) { setMeta( META_KEYS.ogImage, value ); }
					} )
				),
				el(
					PanelBody,
					{ title: __( 'Advanced', 'seo-manager' ), initialOpen: false },
					el( TextControl, {
						label: __( 'Canonical URL', 'seo-manager' ),
						type: 'url',
						value: canonical,
						onChange: function ( value ) { setMeta( META_KEYS.canonical, value ); }
					} ),
					el( ToggleControl, {
						label: __( 'Allow search engines to show this in results', 'seo-manager' ),
						checked: ! robotsState.noindex,
						onChange: function ( checked ) {
							setMeta( META_KEYS.robots, stringifyRobots( ! checked, robotsState.nofollow ) );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Allow search engines to follow links here', 'seo-manager' ),
						checked: ! robotsState.nofollow,
						onChange: function ( checked ) {
							setMeta( META_KEYS.robots, stringifyRobots( robotsState.noindex, ! checked ) );
						}
					} ),
					el( Button, {
						variant: 'secondary',
						onClick: function () {
							debouncedAnalyze.current.cancel();
							debouncedAnalyze.current( {
								post_id: postId || 0,
								post_type: postType || SETTINGS.postType || 'post',
								title: title || '',
								slug: slug || '',
								description: effectiveDesc,
								keyword: keyword,
								content: content || ''
							} );
						}
					}, __( 'Re-run Analysis', 'seo-manager' ) )
				)
			)
		);
	}

	injectStyles();

	registerPlugin( 'seom-sidebar', {
		icon: 'chart-area',
		render: SeomSidebarPanel
	} );
} )( window.wp );
