# Contributing to Crawl Cove Connector

Thanks for helping. Bug reports, fixes and support for more SEO plugins are all welcome.

## Before you start

- Open an issue first for anything bigger than a small fix, so we can agree on the approach before you write it.
- Security problems go to [SECURITY.md](SECURITY.md), not to a public issue.
- The REST API that the Crawl Cove desktop app calls is documented in [README.md](README.md). A change to a route, a field or an error code needs the README updated in the same pull request.

## Development setup

You need PHP 8.1 or later and Composer to run the tools. The plugin itself must keep working on PHP 7.4 and WordPress 6.2.

```
composer install
composer lint        # php -l over every plugin file
composer phpcompat   # PHP 7.4 compatibility check
composer phpcs       # WordPress coding standards
composer test        # PHPUnit against lightweight WordPress stubs
```

All four must pass before a pull request is merged. GitHub Actions runs them on every pull request.

## How the code is laid out

- `includes/`: the REST routes, the service layer and `CCC_Adapter`, which maps each SEO plugin (Yoast SEO, Rank Math, SEOPress, AIOSEO) to its own fields.
- `admin/`: the Tools → Crawl Cove page.
- `tests/`: unit tests. The core classes (`CCC_Service`, `CCC_Adapter`, `CCC_Change_Log`) avoid `WP_REST_*` types so they test without WordPress.
- `tests/integration/`: shell harnesses that build a real WordPress site (SQLite, `php -S`) and push fixes over HTTP. Run one adapter with `tests/integration/run.sh --adapter=yoast` (or `rankmath`, `seopress`, `aioseo`), or everything with `tests/integration/all.sh`. They never download anything themselves, so fetch these into `tests/integration/.cache/` first:

```
cd tests/integration/.cache
curl -sL https://wordpress.org/latest.zip -o wordpress.zip
curl -sL https://downloads.wordpress.org/plugin/sqlite-database-integration.zip -o sqlite.zip
curl -sL https://downloads.wordpress.org/plugin/seo-by-rank-math.zip -o rankmath.zip
curl -sL https://downloads.wordpress.org/plugin/wordpress-seo.zip -o yoast.zip
curl -sL https://downloads.wordpress.org/plugin/wp-seopress.zip -o seopress.zip
curl -sL https://downloads.wordpress.org/plugin/all-in-one-seo-pack.zip -o aioseo.zip
curl -sL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o wp-cli.phar
```

Some suites (WooCommerce, Polylang, TranslatePress, caching) need more zips; each script names what is missing.

## Rules the code follows

The plugin runs on other people's production sites and is written to wordpress.org review standards:

- Sanitize every input, escape every output, and check capabilities (per post or term) on every write.
- No external HTTP requests, no tracking, no new dependencies in the shipped plugin.
- PHP 7.4 syntax only in plugin code.
- Every user-facing string goes through the `crawl-cove-connector` text domain. If you add or change one, regenerate `languages/crawl-cove-connector.pot` (see `tests/integration/pot-check.sh`).
- No em dashes in user-facing strings or docs (a unit test enforces it).
- New logic gets a unit test. Behaviour that the stubs cannot see, like what the SEO plugin prints on the front end, gets a check in the integration harness.

## Pull requests

Keep each pull request to one change. Add a line under `## Unreleased` in [CHANGELOG.md](CHANGELOG.md) for anything a site owner would notice. By contributing you agree that your work is released under the [GPLv2 or later](LICENSE) licence.
