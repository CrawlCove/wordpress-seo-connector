# Compatibility

What Crawl Cove Connector is tested against, and how. Every row below is a
real WordPress site built by `tests/integration/` (SQLite, `php -S`), with the
real plugin installed, pushed to over the REST API and read back. Nothing here
is assumed from a plugin's documentation.

Last full run: 19 Nov 2026, connector main after 1.0.0.

## SEO plugins

| Setup | Version tested | Suite | Checks | Result |
|---|---|---|---|---|
| Yoast SEO | 28.9 | `run.sh --adapter=yoast` | 231 | pass |
| Rank Math | 1.0.282 | `run.sh --adapter=rankmath` | 231 | pass |
| SEOPress | 10.4 | `run.sh --adapter=seopress` | 229 | pass |
| All in One SEO (AIOSEO) | 5.0.5 | `run.sh --adapter=aioseo` | 219 | pass |
| No SEO plugin | | `replace-urls`, `image-alt`, `site-settings`, `indexnow` | 195 | pass |

With no SEO plugin, `/status` lists only the fix kinds that still work (links,
image alt text, site settings, IndexNow), and title or description pushes are
refused with a reason. The Tools page says the same.

## Other plugins and setups

| Setup | Version tested | Suite | Checks | Result |
|---|---|---|---|---|
| WordPress Multisite (subdirectory) | | `multisite-checks.sh` | 7 | pass |
| WooCommerce products and categories | 11.1.2 | `woocommerce-checks.sh` | 11 | pass |
| Polylang (Rank Math, Yoast homepage) | 3.8.9 | `polylang-checks.sh`, `polylang-yoast-home-checks.sh` | 39 | pass |
| TranslatePress | 3.3.6 | `translatepress-checks.sh` | 9 | pass |
| Elementor layouts (links, alt text) | 4.4.1 | `replace-urls-checks.sh`, `image-alt-checks.sh` | 102 | pass |
| WP Super Cache purge | 3.1.3 | `caching-checks.sh` | 16 | pass |
| Upgrade from 1.0.0 (`ccc_` to `crawlcove_` options) | | `migrate-checks.sh` | 15 | pass |

## WordPress

| WordPress | What ran | Result |
|---|---|---|
| 7.1.5 (current) | everything above | pass |
| 6.2 (`Requires at least`) | `site-settings`, `indexnow`, `migrate` (current SEO plugins no longer install on 6.2) | pass |

## PHP

| PHP | How | Result |
|---|---|---|
| 7.4 (`Requires PHP`) to 8.2, 8.4 | the full suite in CI, `.github/workflows/compat.yml` | see the latest [compat run](https://github.com/CrawlCove/wordpress-seo-connector/actions/workflows/compat.yml) |
| 8.3 | the full suite, locally and in CI | pass (1,202 checks) |
| 8.5 | the full suite, locally and in CI | pass (1,202 checks) |

Every push also runs PHPCompatibility for PHP 7.4 and later (`composer
phpcompat`), so syntax or functions newer than 7.4 fail the build before any
site is built.

## Standards

- WordPress Plugin Check (the wordpress.org review tool), experimental checks
  included: no errors, no warnings, on the exact file set that ships.
- PHP_CodeSniffer with WordPress-Extra and WordPress-Docs: clean.

## Run it yourself

Fetch the downloads listed in [CONTRIBUTING.md](../CONTRIBUTING.md) into
`tests/integration/.cache/`, then `bash tests/integration/all.sh` (10 to 15
minutes). To use another PHP, put its binary first on `PATH` as `php`. The
harness servers go through `tests/integration/router.php`, because PHP's
built-in server before 8.5 answers its own 404 for a missing path with a file
extension instead of handing it to WordPress.
