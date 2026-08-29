=== Chandan Digital SEO ===
Contributors: seomanager
Tags: seo, search console, sitemap, schema, redirects, 404, woocommerce, elementor
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 4.9.3
License: GPLv2 or later

Advanced SEO Manager for WordPress by Chandan Digital with Google Search Console integration, SEO analysis, redirects, 404 tools, XML sitemaps, schema and bulk operations.

= 4.9.3 =
* Fixed data loss: saving the SEO Automation screen silently wiped every SEO Tweaks setting. Both screens submit to the same handler, which rewrote all of its option keys on every save — because an unchecked checkbox and a field the form never rendered look identical when submitted, saving Automation blanked the Google/Bing/Yandex/Baidu verification IDs, the Analytics Measurement ID, the llms.txt notes, and reset every SEO Tweaks toggle (nofollow external links, open external links in a new tab, noindex empty archives, attachment redirects, strip category base, llms.txt) to off. Each form now declares its own scope and only writes the settings it actually shows.
* Fixed: saving SEO Automation redirected to the SEO Tweaks screen instead of staying on SEO Automation.
* Fixed invisible text on the Google & Indexing, Instant Indexing, Bing & IndexNow and Business Profile screens. The hero banner is marked up with both `seom-panel` and `seom-google-hero`; the later `seom-panel` rule overrode the hero's dark gradient at equal CSS specificity, leaving white heading text, body copy and the connection status pill on a white background.
* Fixed the OAuth redirect URI block rendering as pale text on a pale background — the global `code` styling outranked the dark `.seom-code-block` rule.
* Improved admin text contrast: the faint text token used for card captions and counters measured 2.86:1 against white, below the WCAG AA 4.5:1 minimum, and is now 4.6:1. Several low-opacity labels on dark backgrounds were also raised.

= 4.9.2 =
* Security fix: JSON-LD output (Article/Product/LocalBusiness/WebPage/breadcrumb/schema-template/video schema) no longer disables slash-escaping, closing a stored-XSS `</script>` breakout via post titles, author display names, descriptions and schema template variables.
* Security fix: CSV/XLSX exports (redirects, 404 log, templates) now actually run every cell through formula-injection sanitisation before writing it; the sanitiser existed but was never called, so a crafted HTTP Referer on a 404 hit could plant a spreadsheet formula that ran when an admin opened the export.
* Security fix: Bulk Editor admin screen built each row's SEO Title/Focus Keyword `<input value="...">` by string concatenation, so a value containing a double quote could break out of the attribute and inject an event handler that runs in the viewing administrator's browser; rows are now built with DOM APIs so no manual escaping is required.
* Updater fix: private GitHub repositories now actually download update packages. WordPress's own package download has no way to send the configured Authorization header, so a private-repo asset previously failed silently even though the settings screen offered a token field for it; a new `upgrader_pre_download` handler performs the authenticated download for this plugin's update only.
* Updater fix: Rollback no longer deletes the live plugin directory before confirming the backup restore succeeded; the current files are moved aside first and restored automatically if the extracted backup can't be moved into place, instead of leaving the site with no plugin directory at all.
* Updater hardening: backup ZIPs written to wp-content/upgrade/seom-backups now get an index.php stub and a deny-all .htaccess, and rollback rejects any non-.zip path.
* Fix: the Yoast/Rank Math/AIOSEO migration importer was hard-capped at the first 500 posts with no pagination, so sites with more content than that silently never finished migrating the rest.
* Fix: two JSON-LD blocks were terminated with a literal `\n` two-character string instead of a real newline.

= 4.9.1 =
* Added advanced GitHub Release based plugin update integration.
* Added native WordPress update detection and optional automatic updates.
* Added pre-update ZIP backups with configurable retention.
* Added administrator rollback controls for stored backups.
* Added update source and private GitHub token configuration.

== Key Features ==

* Google OAuth connection and Search Console property selector.
* Search Console performance data and URL Inspection.
* Search Console sitemap submission.
* Google SEO Update Monitor for official Search Central blog and documentation feeds.
* Email notifications for new Google SEO updates.
* SEO title, meta description, focus keyword and canonical controls.
* SEO score and content analysis.
* XML sitemap with post type and taxonomy support.
* Robots meta and robots.txt controls.
* JSON-LD structured data and breadcrumbs.
* Open Graph and Twitter/X cards.
* Redirect manager with 301, 302, 307 and 308.
* Privacy-friendly 404 monitoring.
* 404 bulk import/export in CSV and XLSX.
* SEO data JSON import/export.
* Bulk SEO editor.
* Image ALT scanner.
* Internal link reporting.
* SEO tweaks for external links, attachment redirects and empty archives.
* Webmaster verification tags for Google, Bing, Yandex and Baidu.
* Google Analytics Measurement ID support.
* IndexNow notification support.
* llms.txt support.
* Local SEO settings.
* WooCommerce SEO controls with Product/Offer schema, price/currency Open Graph, brand, GTIN and MPN fields.
* Schema template builder with conditional application and dynamic post variables.
* SEO Role Manager capabilities for editors and teams.
* Automated image ALT/title generation from filenames.
* Automatic YouTube/Vimeo VideoObject schema detection.
* Internal-link counts in the WordPress content list and link-opportunity reporting.

== Google Connection ==

Create an OAuth 2.0 Web application in Google Cloud. Add the exact redirect URI shown in Chandan Digital SEO -> Google & Integrations.

The plugin requests the Google Search Console scope required for Search Console data and sitemap management.

== 404 Import Format ==

CSV or XLSX files should include a URL column. Optional columns include Referrer, Hits, First Seen and Last Seen.

== Privacy ==

The 404 monitor hashes IP addresses instead of storing raw IP addresses. OAuth tokens and the Google client secret use authenticated WordPress salts for local encryption when OpenSSL is available.


== 4.4.0 ==
* Fixed plugin admin navigation so each Chandan Digital SEO submenu opens its intended screen instead of falling back to the dashboard.
* Added dedicated submenu callbacks for Google, Instant Indexing, Search Engines, Redirections, 404 Monitor, SEO Analyzer, Schema, Links, Images, Bulk Editor, Import/Export, Tools and SEO Tweaks.
* Hardened sitemap request handling and flushes rewrite rules during version upgrades.
* Kept the plugin compatible with PHP 7.4+.

== 4.3.0 ==
* Added bulk redirect import/export in CSV and XLSX.
* Added downloadable CSV/XLSX redirect templates.
* Added import upsert logic with validation and import result counts.
* Added safer spreadsheet export handling for formula-like cell values.

= 4.8.0 =
* Fixed: Redirect "Enabled" checkbox on the Add Redirect form was ignored and every new redirect saved as enabled regardless of the checkbox state.
* Fixed: Redirects table's unique index on the source column could exceed the MySQL/MariaDB key-length limit on utf8mb4 installs; now uses a safe 191-character prefix.
* Fixed: The "Default Meta Description" setting was saved but never actually used as a fallback for pages/posts with no content and no custom description.
* Fixed: The "Logo URL" field on the General SEO settings page was saved but never read; it now feeds the Organization/LocalBusiness schema logo when no separate Knowledge Panel logo is set.
* Fixed: llms.txt output ran every post link onto a single line due to an escaping bug.
* Fixed: the Breadcrumb Schema toggle had no settings-page control; added the missing checkbox.
* Fixed/Improved: SEO title, meta description, canonical URL, Open Graph tags and JSON-LD schema now also apply to the homepage post index, category/tag/custom taxonomy archives (including their saved custom title), author archives, date archives, post type archives, and use the term's custom SEO title where one is set — previously these only worked on single posts/pages.
* Synced plugin version numbers across the main file and readme.

== 4.0.0 ==
* Added Google Indexing API integration with service account JSON upload.
* Added manual URL update/delete submission, request status checks and history.
* Added automatic publish/update and trash notifications with strict JobPosting/BroadcastEvent eligibility mode.
* Added local daily request budget and retry queue.

= 4.3.0 =
* Added IndexNow multi-engine notifications and key hosting.
* Added optional Bing URL Submission API integration.
* Added WordPress admin left-side submenu navigation for SEO sections.
* Fixed root-path redirects such as `/` and hardening for custom destinations.
* Added direct sitemap route handling and aliases for `/seo-sitemap.xml`, `/sitemap.xml`, and `/sitemap_index.xml`.


== Uninstall ==
Deleting the plugin from WordPress Plugins now runs the plugin cleanup automatically. The uninstall file removes plugin tables, options, SEO metadata, taxonomy metadata and scheduled hooks. This action only runs when an administrator explicitly chooses Delete in WordPress.


=== Google Business Profile integration ===
* OAuth connection using the business.manage scope.
* Business Profile account and location sync.
* Supported profile field editing (name, website and primary phone).
* Google review listing, reply and reply deletion.
* Google Posts creation and recent post listing.
* Business Profile performance metrics retrieval.
* Location manager invitation/removal.
* Knowledge Panel & entity readiness dashboard plus optional Knowledge Graph entity lookup.

Note: Google does not provide a public API to directly edit or force a Knowledge Panel. Q&A API access was discontinued by Google in November 2025, so the plugin provides guidance/direct access rather than attempting unsupported Q&A API calls.
