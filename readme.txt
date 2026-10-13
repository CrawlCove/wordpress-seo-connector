=== Crawl Cove Connector ===
Contributors: crawlcove
Tags: seo, yoast, rank math, seopress, aioseo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.10.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push approved title/description fixes from Crawl Cove into Yoast, Rank Math, SEOPress or AIOSEO, with a change log and one-click revert.

== Description ==

Crawl Cove Connector closes the loop between an SEO crawl and your CMS. Instead of exporting a spreadsheet of title and meta description problems and fixing each post by hand, the [Crawl Cove](https://crawlcove.com/?utm_source=wordpress-plugin&utm_medium=referral&utm_campaign=wporg-readme) desktop crawler sends the fixes you approved straight to your site, and this plugin applies them to whichever SEO plugin you already use.

The plugin is free. The Crawl Cove desktop app it works with is a paid crawler that starts with a free trial, no card needed: see [Crawl Cove pricing](https://crawlcove.com/pricing?utm_source=wordpress-plugin&utm_medium=referral&utm_campaign=wporg-readme).

* Works with **Yoast SEO**, **Rank Math**, **SEOPress** and **AIOSEO** (writes their native fields, so nothing is duplicated or overridden at render time).
* **You stay in control**: nothing is applied unless you approved it in the crawler, every change is logged with its previous value, and any change can be reverted with one click from Tools → Crawl Cove (or from the app). The log keeps the most recent 1,000 entries; once it is full, each new push drops the oldest (already-reverted ones first) and the Tools page says so.
* **Dry-run mode** shows exactly what would change before anything is written.
* Uses WordPress core **Application Passwords** for authentication: no extra accounts, no API keys stored by the plugin, revoke access any time from your profile.
* Per-post (and per-term) capability checks: a connected user can only change posts, pages or taxonomy terms they are allowed to edit.

The plugin is a small, auditable bridge (plain PHP, no framework, no external requests, no tracking, GPL). It exposes five REST routes under `crawlcove/v1`: status, resolve, apply, changes, revert.

== Installation ==

1. Download `crawl-cove-connector.zip` from the latest GitHub release at https://github.com/CrawlCove/wordpress-seo-connector/releases/latest, then in your WordPress admin go to Plugins → Add New → Upload Plugin, choose the zip and activate it. (Once the plugin is listed on wordpress.org you will be able to install it from Plugins → Add New by searching "Crawl Cove Connector" instead.)
2. Create an Application Password: Users → Profile → Application Passwords → "Crawl Cove".
3. In the [Crawl Cove desktop app](https://crawlcove.com/download?utm_source=wordpress-plugin&utm_medium=referral&utm_campaign=wporg-readme), open your site profile → WordPress and enter the site URL, username and application password.
4. Crawl, review the suggested fixes, push the approved ones. Review or revert them any time under Tools → Crawl Cove.

== Frequently Asked Questions ==

= Does it work without Yoast, Rank Math, SEOPress or AIOSEO? =

Not yet. The plugin writes the SEO title and meta description fields those plugins own. Support for further SEO plugins is planned; the /status endpoint reports what was detected.

= Can it change my content? =

No. It writes only the SEO title and meta description fields, only for changes you approved. Ordinary posts/pages are re-saved so your SEO plugin and any caching plugin pick up the new values immediately; the homepage and taxonomy archives use a best-effort cache purge instead, since neither has a post to re-save.

= Is this safe on a live site? =

Every write is capability-checked, validated, length-capped and logged with its previous value; reverting is one click. Authentication is core WordPress Application Passwords over HTTPS. This plugin relies on WordPress core and your host for login rate-limiting/brute-force protection rather than implementing its own; a login-throttling plugin is recommended if you don't already run one.

= Does my site need HTTPS? =

For the desktop app to log in, yes. WordPress only enables Application Passwords when the site is served over HTTPS (or WP_ENVIRONMENT_TYPE is set to local); on a plain http site, or an HTTPS site behind a proxy that does not pass the scheme through, core ignores the login and the app is told the site rejected it. Tools, then Crawl Cove, shows whether Application Passwords are on and what to change if they are not.

= What happens to the change log if I delete the plugin? =

Deleting the plugin (Plugins → Delete) removes everything it stored, including the change log, so the revert history goes with it. The titles and descriptions it applied stay in your SEO plugin as they are. Deactivating the plugin keeps the log.

== Screenshots ==

1. Tools → Crawl Cove: whether the desktop app has connected, the detected SEO plugin, setup steps, and the change log with one-click revert.

== Changelog ==

= 0.10.4 =
* Tools page and the API now say when WordPress has Application Passwords switched off (plain-http or proxied sites), instead of the desktop app reporting wrong credentials; new FAQ "Does my site need HTTPS?".
* Update URI header on the GitHub build, so an install from GitHub is never offered a wordpress.org plugin with the same slug as an update.

= 0.10.3 =
* Tools → Crawl Cove shows whether the desktop app has connected, and when and by whom it last did.

= 0.10.2 =
* The change log keeps the most recent 1,000 entries (was 200). When it is full, entries you already reverted are dropped first, and the Tools page tells you.

= 0.10.1 =
* Tools → Crawl Cove links to the Crawl Cove desktop app as its first setup step. Links to crawlcove.com carry visible `utm_*` parameters; the plugin itself still makes no requests and sends nothing.

= 0.10.0 =
* TranslatePress: translated URLs such as `/fr/some-post/` now resolve to the right post, page or term.

= 0.1.0 to 0.9.0 =
* Released on GitHub only. Highlights: adapters for Yoast SEO, Rank Math, SEOPress and AIOSEO; homepage and category/tag archive titles and descriptions (support varies by SEO plugin); Polylang per-language homepage with Yoast; WooCommerce products and categories; cache purge for common caching plugins after a change. The full history is in [CHANGELOG.md](https://github.com/CrawlCove/wordpress-seo-connector/blob/main/CHANGELOG.md).
