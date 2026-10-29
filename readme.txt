=== Crawl Cove Connector ===
Contributors: crawlcove
Tags: seo, yoast, rank math, seopress, aioseo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.11.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push approved SEO fixes from Crawl Cove to Yoast, Rank Math, SEOPress or AIOSEO: titles, links, alt text and more, with one-click revert.

== Description ==

Crawl Cove Connector closes the loop between an SEO crawl and your CMS. Instead of exporting a spreadsheet of title and meta description problems and fixing each post by hand, the [Crawl Cove](https://crawlcove.com/?utm_source=wordpress-plugin&utm_medium=referral&utm_campaign=wporg-readme) desktop crawler sends the fixes you approved straight to your site, and this plugin applies them to whichever SEO plugin you already use.

The plugin is free. The Crawl Cove desktop app it works with is a paid crawler that starts with a free trial, no card needed: see [Crawl Cove pricing](https://crawlcove.com/pricing?utm_source=wordpress-plugin&utm_medium=referral&utm_campaign=wporg-readme).

* Works with **Yoast SEO**, **Rank Math**, **SEOPress** and **AIOSEO** (writes their native fields, so nothing is duplicated or overridden at render time).
* **You stay in control**: nothing is applied unless you approved it in the crawler, every change is logged with its previous value, and any change can be reverted with one click from Tools → Crawl Cove (or from the app). The log keeps the most recent 1,000 entries; once it is full, each new push drops the oldest (already-reverted ones first) and the Tools page says so.
* **Dry-run mode** shows exactly what would change before anything is written.
* Uses WordPress core **Application Passwords** for authentication: no extra accounts, no API keys stored by the plugin, revoke access any time from your profile.
* Per-post (and per-term) capability checks: a connected user can only change posts, pages or taxonomy terms they are allowed to edit.
* **More than titles**: canonical URLs, index/noindex and Open Graph/Twitter fields through your SEO plugin; links that point at a redirect repointed to the final URL (or unlinked) across posts, Elementor layouts and menus; image alt text; and four hardening switches for administrators (XML-RPC off, WordPress version hidden, security headers, no upload folder listing).

The plugin is a small, auditable bridge (plain PHP, no framework, no external requests, no tracking, GPL). It exposes eight REST routes under `crawlcove/v1`: status, resolve, apply, replace-urls, image-alt, site-settings, changes, revert.

== Installation ==

1. Download `crawl-cove-connector.zip` from the latest GitHub release at https://github.com/CrawlCove/wordpress-seo-connector/releases/latest, then in your WordPress admin go to Plugins → Add Plugin → Upload Plugin, choose the zip and activate it. (Once the plugin is listed on wordpress.org you will be able to install it from Plugins → Add Plugin by searching "Crawl Cove Connector" instead.)
2. Create an Application Password: Users → Profile → Application Passwords → "Crawl Cove".
3. In the [Crawl Cove desktop app](https://crawlcove.com/download?utm_source=wordpress-plugin&utm_medium=referral&utm_campaign=wporg-readme), open the client, then Connections, and fill in the WordPress card: site URL, username and application password.
4. Crawl, review the suggested fixes, and push the approved ones: one at a time, or every open title and description finding at once from the Findings list. Review or revert them any time under Tools → Crawl Cove.

== Frequently Asked Questions ==

= Does it work without Yoast, Rank Math, SEOPress or AIOSEO? =

Partly. Titles, descriptions, canonical URLs, index/noindex and social fields are written into the fields those plugins own, so they need one of them. Link fixes, image alt text and the site hardening switches work on any site. The /status endpoint reports what was detected.

= Can it change my content? =

Only where you approved a fix that lives in the content, and only that part. A title, description, canonical, robots or social push writes only those SEO fields: the post body and its modified date are left exactly as they were. A link fix changes only the matching link (the exact URL, never other text) and an alt text fix only the matching image's alt attribute; the rest of the page is untouched and never goes back through the editor, so embeds stay. Every change can be reverted to the exact previous content. Each changed post or page has its cache purged so the new values show straight away; the homepage and taxonomy archives get a best-effort full cache purge instead.

= Is this safe on a live site? =

Every write is capability-checked, validated, length-capped and logged with its previous value; reverting is one click. Authentication is core WordPress Application Passwords over HTTPS. This plugin relies on WordPress core and your host for login rate-limiting/brute-force protection rather than implementing its own; a login-throttling plugin is recommended if you don't already run one.

= Does my site need HTTPS? =

For the desktop app to log in, yes. WordPress only enables Application Passwords when the site is served over HTTPS (or WP_ENVIRONMENT_TYPE is set to local); on a plain http site, or an HTTPS site behind a proxy that does not pass the scheme through, core ignores the login and the app is told the site rejected it. Tools, then Crawl Cove, shows whether Application Passwords are on and what to change if they are not.

= My site is on HTTPS but Application Passwords are off. Why? =

A security plugin has switched them off. Wordfence does this by default: untick Firewall, All Firewall Options, "Disable WordPress application passwords", then save. On another security plugin, look for a setting with a similar name. Tools, then Crawl Cove, tells you which of the two causes applies to your site.

= What happens to the change log if I delete the plugin? =

Deleting the plugin (Plugins → Delete) removes everything it stored, including the change log, so the revert history goes with it. The fixes it applied stay on your site as they are. The site hardening switches stop applying, and the upload-folder line it added to .htaccess is removed. Deactivating the plugin keeps the log.

== Screenshots ==

1. Tools → Crawl Cove: the detected SEO plugin, whether Application Passwords are on, whether the desktop app has connected, setup steps, and the change log with one-click revert.

== Changelog ==

= 0.11.0 =
* New: fix links on one page or across the site (repoint, unlink or drop nofollow), set image alt text, set canonical, robots and social fields, and switch on four site hardening settings. Every change is logged and revertable.
* On Yoast SEO, a pushed category or tag title now shows on the archive page.
* Deleting the plugin removes its data from every site of a network, and the uploads .htaccess block it added.

= 0.1.0 to 0.10.8 =
* Released on GitHub only. Highlights: a push writes only the SEO fields and leaves the post body and modified date alone; Revert refuses to overwrite a newer value unless you choose Revert anyway; Contributors and Authors see only their own posts and changes; adapters for Yoast SEO, Rank Math, SEOPress and AIOSEO; homepage and category/tag archive titles and descriptions (support varies by SEO plugin); Polylang per-language homepage with Yoast; TranslatePress URLs; WooCommerce products and categories; cache purge for common caching plugins after a change; a 1,000-entry change log; Tools page status for Application Passwords (including when a security plugin switches them off) and the desktop app connection. The full history is in [CHANGELOG.md](https://github.com/CrawlCove/wordpress-seo-connector/blob/main/CHANGELOG.md).
