# Changelog

## 0.10.8 (2026-10-21)

- **Fix: pushing a title or description no longer strips embeds from the
  post.** After every write the plugin re-saved the whole post to refresh
  caches, and WordPress re-filtered the body as the pushing user. For a user
  without the `unfiltered_html` capability (any Author, and every site
  administrator on multisite) that deleted iframes, scripts and forms an
  admin had put in the content. The plugin now writes SEO meta only and
  purges caches directly (`clean_post_cache()`, W3 Total Cache, LiteSpeed
  Cache, WP Fastest Cache). Yoast still rebuilds its indexable from its own
  meta watcher. The post's modified date no longer changes on a push. New
  `ccc_after_post_write` action.
- **Docs: the wordpress.org copy installs from the directory.** `bin/build-zip.sh
  --wporg` now also rewrites readme.txt Installation step 1 to "Plugins → Add
  Plugin, search for Crawl Cove Connector", so the listing never sends a
  directory user to the GitHub zip. The GitHub copy keeps the download step,
  and both readmes now use the current "Add Plugin" menu name.

## 0.10.7 (2026-10-20)

- **Fix: reverting an older change no longer wipes a newer one** (review
  ticket #248). Revert wrote the old value back without looking, so undoing
  an earlier push after a re-push, or after a hand edit in the SEO plugin,
  silently overwrote the newer value. `/revert` now answers 409
  `ccc_value_changed` with the current value and writes nothing, unless sent
  `force: true`. The Tools page marks overwritten entries "replaced by a
  later change" and offers "Revert anyway" when a revert is refused.
- **Fix: pushed titles and descriptions keep their SEO template variables**
  (review ticket #233). The value was run through core
  `sanitize_text_field()`, which deletes `%` plus two hex digits, so
  `%category%` was stored as `tegory%` and `%%date%%` as `%te%%`, then
  printed literally in the page title. Tags are still stripped. Verified on
  real Rank Math, Yoast, SEOPress and AIOSEO.

## 0.10.6 (2026-10-17)

- **Security: `/resolve` and `/changes` no longer disclose what the user
  cannot read** (review ticket #227). A Contributor or Author could resolve
  `?p=N` for any id and read the title and SEO values of other users'
  drafts and private posts, and of privacy requests (whose title is the
  requester's email). Those now answer `ccc_unresolvable`, like a missing
  post. `/changes` lists only entries on targets the user may edit;
  administrators still see the whole log. The app resolves only crawled
  public URLs, so nothing changes for it.
- The Application Passwords hint (Tools page, readme FAQ) now tells users of
  other security plugins to look for a setting with a similar name, instead
  of asserting they all have one; matches the crawlcove.com FAQ (seo #222).

## 0.10.5 (2026-10-15)

- When a plugin has switched Application Passwords off on an HTTPS site
  (Wordfence's default), the Tools page and the API 401 now say so and name
  Wordfence's setting, instead of blaming HTTPS and offering the proxy fix
  (review ticket #220). Same `ccc_app_passwords_unavailable` code; new FAQ.
- readme.txt and README install step 4 say the desktop app can push one fix
  at a time or every open title and description finding at once (app 1.3.8
  bulk push, ticket #217). Docs only.
- wordpress.org screenshot-1 regenerated with the Application Passwords row
  that 0.10.4 added to the Tools page; caption updated to match.

## 0.10.4 (2026-10-13)

- **The plugin now says when the site cannot accept Application Passwords at
  all** (review ticket #211). WordPress core only enables them over HTTPS or
  with `WP_ENVIRONMENT_TYPE=local`, so on a plain-http staging site, or an
  HTTPS site behind a proxy that hides the scheme, core silently ignored the
  desktop app's login and the app could only report "wrong credentials".
  Every route now answers such an anonymous request with its own error,
  `ccc_app_passwords_unavailable` (401), whose message names the cause; the
  Tools page Status table gains an "Application Passwords" row (On, or Off
  with the proxy fix and the last-resort filter); readme.txt gains a "Does my
  site need HTTPS?" FAQ; README documents the code for the app. A logged-in
  admin's own cookie session is never blocked. New `CCC_App_Passwords`
  class, 5 unit tests, and `tests/integration/app-passwords-checks.sh` (12
  checks, wired into run.sh) which lifts the harness's own
  `wp_is_application_passwords_available` filter and proves both surfaces
  against real core auth.

- **`Update URI` header** (review ticket #212). Installed from GitHub, the
  plugin had no Update URI, so core asked api.wordpress.org for updates
  against a slug nobody has claimed yet; whoever one day owns
  `crawl-cove-connector` there would have been offered to every GitHub
  install as an upgrade. The header now points at
  `https://crawlcove.com/wordpress-plugin`, which makes core skip the
  directory for this plugin. `bin/build-zip.sh --wporg` strips the line for
  the wordpress.org SVN copy (the directory serves updates only to plugins
  without one, and Plugin Check flags it); `plugin-check.sh` checks that
  copy. No update notices for GitHub installs yet: that needs an external
  request both readmes promise not to make.

- **README documents how to find the REST root.** WordPress serves the API
  at `/wp-json/`, `/index.php/wp-json/` or `/index.php?rest_route=/`
  depending on the permalink setting, and a client that hard-codes
  `/wp-json/` cannot reach a plain-permalink site (the desktop app did
  exactly that, review ticket #200). The contract now says: read the
  `Link rel="https://api.w.org/"` header on the site URL, fall back to the
  `?rest_route=` form, and treat a 2xx non-JSON body as "not the API". New
  harness script `tests/integration/rest-root-checks.sh` (13 checks, wired
  into run.sh) pins the header, the advertised root and the route under it
  against a real WordPress on both the plain (`?rest_route=`) and the pretty
  (`/wp-json/`) permalink setting, and the taxonomy script now also resolves
  a post and the static front page by their pretty URLs (what the crawler
  really sends), not only by `?p=N`. No plugin code change.

- **No em dashes in what the plugin itself says.** Six strings reworded:
  the plugin header description (the Plugins screen), the Tools page's
  "None" SEO-plugin line, and four REST error messages the desktop app
  shows (no SEO plugin, URL not on this site, static front page, Rank Math
  homepage title clear). The Polylang homepage label is now
  "Homepage (latest posts, fr)". Error codes are unchanged, so the app
  needs nothing. POT regenerated. A unit test tokenises the plugin's PHP
  and fails on an em dash in any string literal or inline HTML (comments
  exempt, the lone empty-cell placeholder allowed).

- **Readmes rewritten without em dashes, and a short wordpress.org
  changelog.** readme.txt and README.md (including the repo's H1) now follow
  the house style, with the sentences reworded rather than the dashes
  swapped for hyphens. The readme.txt changelog is a few plain lines for
  0.10.x plus one summary of the GitHub-only versions, linking here for the
  full history (167 words, was 1,324). Unit tests keep both readmes free of
  em dashes outside code blocks and that changelog under 250 words.
  CHANGELOG.md got the same rewrite (61 em dashes, version headings now
  read `## 0.10.3 (2026-10-04)`; the build and release scripts still find
  them) and the same test, and so did SECURITY-NOTES.md (69).

- **Both readmes now say the desktop app is paid.** The plugin is free, but
  the Crawl Cove crawler it works with is a paid app with a free trial, and
  neither readme said so: a reader saw "free, GPL" and found out at
  /pricing. One sentence in the Description (readme.txt) and "Why this
  plugin exists" (README.md), linking crawlcove.com/pricing. Plus an FAQ:
  deleting the plugin removes its change log, so revert history goes with
  it, while the applied titles and descriptions stay. A unit test pins
  both, and that the Stable tag matches the plugin version. No code change.

## 0.10.3 (2026-10-04)

- **Tools page shows whether the desktop app has connected.** The readmes
  promised a "connection status" but the Status table only listed the
  detected SEO plugin and the endpoint, so a wrong password or URL typo
  looked like nothing at all. There is now a "Desktop app" row: "Not
  connected yet" until an authenticated user calls a `crawlcove/v1` route,
  then "Last connected <date> by <user>". Recorded in the permission
  callback, so rejected and anonymous requests never count; stored as one
  non-autoloaded option (`ccc_last_connection`), written at most once a
  minute per user, removed on uninstall. Inbound only: no external request.
  No REST contract change.

## 0.10.2 (2026-10-02)

- **Change log keeps 1,000 entries, and evicts reverted ones first.** The
  log was capped at 200 entries and silently dropped the oldest when full.
  A title and a description on one post are two entries, so one push over
  100 posts overflowed it: the earliest changes of that same push were
  already gone, and unrevertable, when it finished, while both readmes
  promised "any change can be reverted". The cap is now 1,000 (worst case
  at the input limits is about 2MB in a non-autoloaded option that only
  /apply, /revert and the Tools page read), already-reverted entries are
  dropped before any live one, the cap is documented in both readmes, and
  the Tools page says when the log is full. No REST contract change: the
  `/changes` shape is unchanged, it can just return more entries. The
  Tools page paginates the log (100 rows a page, revert keeps your page)
  and loads each page's posts in one query, so the bigger cap costs the
  admin nothing.

## 0.10.1 (2026-10-02)

- **Tools → Crawl Cove now links to the desktop app.** The "Connect the
  Crawl Cove desktop app" steps told a site owner to open Crawl Cove
  without ever saying where to get it. That was a problem for anyone who finds the
  plugin first (the wordpress.org listing, a search, a colleague's site)
  rather than the crawler first. A new first step links to the download
  page. That link, and the crawlcove.com links in the two readmes, now
  carry plain `utm_*` query parameters so crawlcove.com can count visitors
  who arrived from an installed copy of the plugin. Nothing else changes:
  the plugin still makes no requests of its own, loads nothing remote and
  sends nothing about your site or users; the parameters are visible in
  the link and only travel if you click it. No code path other than the
  admin page's HTML is touched.

## 0.10.0 (2026-09-27)

- **Fix: TranslatePress compatibility.** On a site running TranslatePress
  (the other major free multilingual plugin besides Polylang), every URL
  under a non-default language's prefix (`/fr/some-post/`, `/fr/category/
  news/`, the `/fr/` homepage, all of it) was `ccc_unresolvable`. This
  wasn't a narrow edge case like Polylang's homepage gap; it was every
  translated URL on the whole site. Root cause, verified against
  TranslatePress's own source rather than assumed: it manages its
  language-prefixed URLs (`includes/class-url-converter.php`) with plain
  string manipulation, not a WordPress rewrite rule: there is no
  `add_rewrite_rule()` call anywhere in the plugin. `url_to_postid()` and
  `CCC_Term_Resolver`'s own rewrite-rule matching both work by matching
  against WordPress's *compiled rewrite rules*, so a prefix that isn't one
  of those rules is invisible to them, full stop.
- Architecturally, TranslatePress turned out to be the OPPOSITE problem
  shape from Polylang: Polylang creates genuinely separate content (a real
  post_id per translation) plus a real (if non-public) taxonomy whose
  rewrite rule could collide with CCC's own resolution (see 0.8.1).
  TranslatePress has no separate content or SEO storage per language at
  all; it translates the SAME post's SAME Yoast/Rank Math postmeta at
  render time via its own gettext-style string dictionary, keyed on the
  literal original string text. That makes the fix simpler than Polylang's:
  `CCC_Service::strip_translatepress_language_prefix()` recognises and
  strips a configured non-default language's URL slug (read from
  TranslatePress's own `trp_settings` option, since it ships no public `@api`
  equivalent of Polylang's `pll_home_url()`) before any resolution runs, so
  every existing post/term/homepage path handles the stripped URL exactly
  like its default-language counterpart. No REST contract change: `lang`
  stays empty throughout, correctly, because there is nothing
  per-language here for it to mean.
- Verified end-to-end against a real WordPress + Yoast + TranslatePress
  install, not source-reading alone (`tests/integration/
  translatepress-checks.sh`, 9/9): a post URL and its `/fr/`-prefixed
  counterpart resolve to the same post_id; the homepage and its `/fr/`
  counterpart both resolve to `HOME_ID`; a CCC title write is read back
  identically from either URL. Confirmed the harness genuinely catches the
  regression by reverting the fix locally first (5/9 failed, as predicted).
- One consequence documented, not fixed, because it isn't a CCC bug: since
  TranslatePress's translation lookup is keyed on the literal original
  string, ANY edit to that string (from CCC or from a site owner's own
  hand-edit in Yoast's metabox, no difference) orphans whatever
  translation already existed until a human re-translates it in
  TranslatePress's own editor. Noted in SECURITY-NOTES.md so it isn't
  rediscovered as "a bug" later.
- Security: the `lang` field shipped in 0.9.0 was never added to
  `security-checks.sh`'s type/injection/unicode/null-byte/oversized-string
  fuzzing matrix every other field gets. Added 12 checks to
  `polylang-yoast-home-checks.sh` (the one harness with Polylang+Yoast
  live). No bug found: `is_string()` + a strict `in_array()` whitelist
  already rejected everything; now a permanent regression check.

- Packaging (30 Sept, before this version was tagged): `bin/build-zip.sh`
  builds the installable `crawl-cove-connector.zip` from the same
  production file set `tests/integration/plugin-check.sh` checks (one
  exclude list now, shared), verifies the archive unpacks under
  `crawl-cove-connector/` with no tests/vendor inside, and refuses to build
  if the plugin header, `CCC_VERSION`, readme `Stable tag` and this
  changelog disagree. A tag push (`v*`) now creates the GitHub Release with
  that zip attached (`.github/workflows/release.yml`). Installation step 1
  in readme.txt/README.md names the download; the "a few hundred lines"
  size claim is gone (the plugin is about two thousand lines of PHP now).

## 0.9.0 (2026-09-26)

- Real per-language "your latest posts" homepage title/description support
  for multilingual sites, closing the feature gap 0.8.1's safety fix
  deliberately left open. `/resolve` now reports a
  non-default Polylang language's homepage URL (e.g. `/fr/` under
  directory URL mode) as `post_id: 0` with a new `lang: "fr"` field,
  instead of `ccc_unresolvable`; send that same `lang` back on `/apply`/
  `/revert` to push a fix to it. Fully additive: omitting `lang` (or
  sending the site's own default language code, which is treated
  identically) targets the plain/default-language homepage exactly as the
  existing `post_id: 0` contract always has.
- **Yoast SEO only.** Verified against Polylang's own source (not
  guessed): its `src/integrations/` directory ships a dedicated
  compatibility module *only* for Yoast (`wpseo/wpseo.php`, registering
  `title-home-wpseo`/`metadesc-home-wpseo` with Polylang's own
  string-translation system, `PLL_Translate_Option` → `PLL_MO`). There is
  no equivalent module for Rank Math, SEOPress or AIOSEO, because none of
  them has a per-language slot to write into at all: each one's homepage
  title/description is one value shared across every language, full stop.
  A `lang`-qualified change sent to any of those three now returns
  `ccc_language_unsupported`: reported, not silently applied as an
  unwanted site-wide change.
- The write itself uses the exact mechanism Polylang's own admin "Strings
  translation" screen uses to save a translation (`PLL_MO::
  import_from_db()`/`add_entry()`/`export_to_db()`). That is not a documented
  `@api` function (there isn't one for writing), but the plugin's one real
  internal implementation of "set this string's translation for language
  X", the same one `PLL_Translate_Option` itself relies on for the
  default-language save path already shipped in 0.4.0. Verified end-to-end
  against a real Yoast + Polylang install, across separate HTTP requests
  (write via one REST call, read back via a later one): a French-only
  write leaves the English original, and any OTHER language's own
  translation, untouched; reverting restores Yoast's real fallback
  behaviour (an untranslated language falls back to the default-language
  value, exactly like real gettext, never to blank); a subsequent
  default-language title change correctly reattaches the existing French
  translation to the new original string (Polylang's own behaviour,
  unchanged by this feature). New `tests/integration/
  polylang-yoast-home-checks.sh` (13/13); `tests/integration/
  polylang-checks.sh` updated (an adapter-independent `/resolve` now
  reports `lang` for ANY adapter, with `editable` correctly gating the
  ones that can't act on it). 13 new unit tests (99 total), full gate
  green.

## 0.8.1 (2026-09-26)

- Security/correctness fix: `CCC_Term_Resolver::resolve_pretty_permalink()`
  matched a URL against `$wp_rewrite`'s compiled rules and accepted ANY
  resulting taxonomy-archive query (`is_tax`/`is_category`/`is_tag`), with
  no filter on whether that taxonomy was actually public, unlike its
  sibling `resolve_plain_query_vars()`, which only ever iterates
  `get_taxonomies(['public' => true])`. Found and verified against a real
  Polylang install (not guessed): Polylang registers its own internal
  "language" taxonomy as `public => false` / `publicly_queryable => true`
  (so its language-switcher rewrite rule for the `lang` query var still
  works), and that rule matches a bare `/fr/`, which is exactly what a French
  "your latest posts" homepage URL looks like under Polylang's default
  directory URL mode. Before this fix, resolving `/fr/` silently returned
  Polylang's own "French" language TERM instead of the homepage or
  `ccc_unresolvable`. CCC would report a title/description fix as
  successfully applied, write it into the SEO plugin's term meta for that
  internal language term, and the real French homepage's rendered title
  would never change: a silent no-op reported as a success. Fixed in two
  places, `resolve_pretty_permalink()` (URL discovery) and
  `CCC_Service::validate_change()` (a direct `{"post_id": -N}` apply/revert
  call must be held to the same rule, not just discovery via URL), and both
  now require `get_taxonomy($term->taxonomy)->public` before accepting a
  term as a valid target. New `tests/integration/polylang-checks.sh`
  (standalone, like woocommerce/multisite-checks.sh) pins the fix against
  a real Polylang install with directory-mode URLs and proves no
  regression on ordinary translated content or language-prefixed archives
  of REAL (public) taxonomies. +1 unit test (86 total). Scope note: this
  fixes the safety bug (never misresolve to internal plumbing); it does
  not add real per-language homepage/archive targeting for Polylang.
  Rank Math itself has no Polylang integration, so its homepage title stays
  one shared value across every language regardless of CCC. A
  non-default-language "your latest posts" homepage is `ccc_unresolvable`,
  not mis-resolved.

## 0.8.0 (2026-09-26)

- Fix: a homepage ("your latest posts" mode) or taxonomy-archive apply/
  revert never signalled a page-caching plugin that the page had changed.
  Both targets write through `update_option()`/term meta directly; no
  post row exists for `wp_update_post()` to re-save, so `clean_post_cache`
  (the action a real post's apply already fires, and the one caching
  plugins hook) never ran. Verified against WP Super Cache's real source
  (`wp-cache-phase2.php`): `wp_cache_post_edit()`/`wp_cache_post_change()`
  both bail immediately on `$post_id === 0` and are registered only on
  `clean_post_cache`, never on any term-edit hook, so a WP Super Cache
  site kept serving the old cached homepage/archive HTML indefinitely
  after a desktop-app push. `CCC_Service::invalidate_caches_for()` now
  runs a best-effort full-site purge for WP Super Cache, W3 Total Cache and
  WP Rocket (via their own public functions, guarded by `function_exists()`)
  plus WP Fastest Cache's and LiteSpeed Cache's own documented
  `wpfc_clear_all_cache`/`litespeed_purge_all` action hooks, and always
  fires a new plugin-agnostic `ccc_after_uncached_write` action for
  anything else.
- Fix: `CCC_Change_Log::revert()` never called `wp_update_post()` at all,
  for ANY target, not even an ordinary post. A reverted post's Yoast
  indexable went stale and no caching plugin's `clean_post_cache` hook
  fired, the exact gap `apply()` already closed for a fresh write. Revert
  now runs through the same `CCC_Service::invalidate_caches_for()` path
  apply() uses, for every target type.
- New `tests/integration/caching-checks.sh`: a real WordPress + real Rank
  Math harness with an mu-plugin probe that logs `clean_post_cache`
  firings and WP Super Cache's own `wp_cache_clear_cache()` (confirmed
  against its real source rather than guessed), plus LiteSpeed's
  `litespeed_purge_all` and the new `ccc_after_uncached_write` action.
  12/12 checks green with the fix; confirmed the harness actually catches
  the bug by reverting the fix locally first: 10/12 failed as predicted
  (only the pre-existing "apply on a real post" path passed).
- 85 unit tests (+1), full gate green (phpcs, phpcompat, 4-adapter
  integration matrix, Plugin Check). See SECURITY-NOTES.md for the full
  writeup.

## 0.7.0 (2026-09-24)

- SEOPress taxonomy term title/description support, extending 0.6.0's
  per-term target (Yoast, Rank Math) to a third adapter. Verified against
  real SEOPress source: unlike Yoast's shared-option storage, per-term SEO
  data is plain term meta under the *exact same key names* as its
  post-level fields (`_seopress_titles_title`/`_seopress_titles_desc`),
  confirmed at `src/Services/Metas/Title/Specifications/
  TaxonomySpecification.php` (read) and `inc/admin/metaboxes/
  admin-term-metaboxes.php` (write, plain `update_term_meta()`/
  `delete_term_meta()`), so no shared-array read-modify-write risk, same
  simple pattern as Rank Math's term storage, so both now share one code
  path in `CCC_Adapter`.
- AIOSEO's term unsupported status is now confirmed on the merits, not
  left unresearched: its own source (`app/Common/Main/BulkActions.php`)
  states outright that per-term SEO analysis columns "live on the Pro
  aioseo_terms table" and the free-tier REST term controller's meta-field
  registration is a deliberate no-op ("Term SEO meta requires the Pro Term
  model"). The free plugin this connector supports has no term SEO storage
  to write to at all.
- 84 unit tests (+2), taxonomy integration checks extended to all
  supporting adapters, full gate green, Plugin Check clean.

## 0.6.0 (2026-09-24)

- Taxonomy term title/description support: fix a single category, tag or
  custom-taxonomy archive's title/description without touching any other
  term in that taxonomy (the taxonomy-wide default TEMPLATE was explicitly
  scoped out, because a template fix would let one crawled-URL
  fix silently rewrite every other term's title). New negative post_id
  sentinel (`post_id = -$term_id`) across `/resolve`, `/apply`, `/revert`,
  the same "reuse the existing int slot" trick `HOME_ID` (0) already uses
  for the homepage: no new REST fields, no change-log schema change.
  `CCC_Term_Resolver` (new) resolves a term archive URL two ways: query-var
  matching for "Plain" permalinks (`?cat=N`, `?category_name=slug`,
  `?tag=slug`, and any other public taxonomy's own registered query_var),
  and rewrite-rule matching for pretty permalinks, adapted from WordPress
  core's own `url_to_postid()` (checks `is_tax`/`is_category`/`is_tag`
  instead of `is_singular`). Capability is `edit_term` (WordPress core's own
  meta capability, maps to `manage_categories` for category/post_tag).
  Yoast via `WPSEO_Taxonomy_Meta::get_term_meta()`/`set_values()`; Rank Math
  via real term meta (`rank_math_title`/`rank_math_description`, same key
  names as posts, via core's `get_term_meta()`/`update_term_meta()`).
  SEOPress and AIOSEO report `ccc_term_unsupported` per-item rather than
  guessing at their storage.
- Fix, found by the real-WordPress integration harness rather than unit
  stubs: `url_to_postid()` has its own quirk on a site with a static front
  page: *any* query string at the site root (not just the homepage's own)
  collapses to the front page's post id, because its "is what's left the
  home URL?" check runs before rewrite-rule matching. `?cat=2` was
  resolving to the homepage instead of the category. Fixed by resolving
  query-string term targets BEFORE calling `url_to_postid()`, not after, since a
  named taxonomy query var is a far more precise signal than that coarse
  check.
- Fix, also caught by the integration harness: Yoast's own
  `WPSEO_Taxonomy_Meta::set_value()` is not a true single-field patch for
  most fields. Writing just a term's title (or just its description) was
  silently resetting the OTHER of the two back to '', and would do the same
  to any other Yoast term setting a site owner had set by hand (focus
  keyword, Open Graph/Twitter overrides, cornerstone flag). Fixed by
  reading the term's full current Yoast settings first and writing them all
  back with only the intended field changed.

## 0.5.0 (2026-09-23)

- SEOPress homepage title/description support, extending 0.4.0's
  `post_id: 0` homepage target to a third adapter. Storage:
  `seopress_titles_option_name` option's `seopress_titles_home_site_title` /
  `seopress_titles_home_site_desc` keys, written through the same
  read-whole-array-then-`update_option()` pattern SEOPress's own setup
  wizard uses (`inc/admin/wizard/admin-wizard.php`). Confirmed safe to clear
  to '': `LatestPostsSpecification::isSatisfyBy()` in SEOPress's own title/
  description generator explicitly stops applying when the value is empty,
  falling through to the next specification rather than rendering blank:
  same safety class as Yoast, no `can_clear_home_title()` exception needed.
- AIOSEO investigated properly this session and confirmed genuinely
  unsupported, not just unresearched: `Meta\Title::getHomePageTitle()` and
  `Meta\Description::getHomePageDescription()` (app/Common/Meta/) fall back,
  for a "your latest posts" site, to
  `searchAppearance.global.siteTitle`/`.metaDescription`, the *same*
  site-wide template that fills the `#site_title`/`#tagline` variables used
  in every other page's title/description template. AIOSEO has no dedicated
  per-homepage field to write to; doing so would silently change title
  generation across the whole site, not just `/`. `ccc_home_unsupported`
  stays correct for AIOSEO, now for a verified reason.
- 61 unit tests (+2), full 4-adapter integration suite (including
  `tests/integration/homepage-checks.sh`) green, Plugin Check clean.

## 0.4.0 (2026-09-23)

- Homepage title/description support for sites with **no static front page**
  (Settings → Reading → "Your latest posts"). Previously only a static front
  page worked, because it's just a normal page and CCC already resolved/wrote
  it like any other post; a "latest posts" homepage has no post to hold an
  override, so Yoast and Rank Math each keep it in their own settings:
  `wpseo_titles` (`title-home-wpseo` / `metadesc-home-wpseo`) and
  `rank-math-options-titles` (`homepage_title` / `homepage_description`)
  respectively, both confirmed against real plugin source and both written
  through the plugin's own safe read-modify-write helper (`WPSEO_Options::
  save_option()` for Yoast; read-whole-array-then-`update_option()` for Rank
  Math), never a bare option overwrite, since either would silently wipe every
  other setting sharing that option array.
- New REST target: `/resolve`, `/apply` and `/revert` now accept `post_id: 0`
  meaning "the homepage" (`CCC_Service::HOME_ID`). `/resolve` reports it
  automatically for the site root when there's no static front page.
  Permission is `manage_options`, not `edit_post` (there's no post to check
  `edit_post` against). SEOPress and AIOSEO report `ccc_home_unsupported`
  rather than silently dropping the change; sending `post_id: 0` on a site
  that *does* have a static front page reports `ccc_no_homepage_target`.
- Found and fixed while implementing, before it ever wrote anything wrong:
  (1) a homepage write must never trigger the post-apply `wp_update_post()`
  "touch", because `wp_update_post( [ 'ID' => 0 ] )` is WordPress core's signal to
  **insert a new post**, not a no-op, which would have created a stray empty
  post on every homepage change; (2) the initial homepage-URL matcher ignored
  query strings, so a "Plain" permalink post at the site root
  (`/?p=5`) was misidentified as the homepage; fixed by requiring an empty
  query string; (3) Rank Math's homepage title has no template fallback when
  cleared (unlike Yoast's homepage fields and Rank Math's own homepage
  description, which do fall back safely). Verified against real Rank Math
  source, confirmed on a live install, and now refused with
  `ccc_home_title_clear_unsupported` instead of shipping a blank
  browser-tab title to a stranger's site.
- `tests/integration/homepage-checks.sh` (new, run by `run.sh` for every
  adapter): switches a real WordPress site to "your latest posts" mode,
  proves the resolve/apply/revert round-trip against the SEO plugin's own
  stored option (not just CCC's own read-back), the `manage_options`
  capability gate, the Rank Math clear-title guard, and the
  `ccc_home_unsupported` path for SEOPress/AIOSEO. 59 unit tests (was 37,
  +22), full 4-adapter integration suite still green.

## 0.3.0 (2026-09-22)

- AIOSEO adapter. Structurally different from the other three: AIOSEO 4.x
  stores title/description in a custom `wp_aioseo_posts` table (40+
  columns, several JSON-encoded), not postmeta. Verified against real
  AIOSEO 4.9 source before writing anything: the REST-controller wrapper
  (`PostSeoService`) is explicitly `@internal Not a public extension
  surface`, but `\AIOSEO\Plugin\Common\Models\Post::savePost()` (their own
  internal callers' write path, `@since 4.0.3`, not `@internal`) is
  patch-style: it only touches the keys you pass, filling in every other
  column's default when the row doesn't exist yet. Confirmed empty-string
  correctly falls back to AIOSEO's default title template (their renderer
  uses PHP's `empty()`, true for both `''` and `null`). Added a permanent
  integration-harness regression check
  (`tests/integration/aioseo-checks.sh`) that seeds a post with unrelated
  AIOSEO fields (social titles) via AIOSEO's own API, applies a title/
  description change through CCC's real REST route, and asserts the
  unrelated fields survive untouched. This is the one claim from reading
  the source that needed proving against a real write, not just reading.
- `tests/integration/run.sh` now builds an AIOSEO site too
  (`--adapter=aioseo`); 68/68 checks green (26 route + 42 security), no
  regressions on rankmath/yoast/seopress.

## 0.2.0 (2026-09-22)

- SEOPress adapter (`_seopress_titles_title` / `_seopress_titles_desc`),
  verified against SEOPress 10.2 source: same postmeta keys its own admin
  metabox saves to, empty value deletes the meta key exactly like Yoast and
  Rank Math. Detection via `SEOPRESS_VERSION`.
- `CCC_Adapter::label()` for the admin page's plugin-name display (was an
  inline Yoast/Rank Math ternary, now scales to any adapter).
- wordpress.org submission pack verified: `wp plugin check` via a new
  `tests/integration/plugin-check.sh` (production file set only, because the
  integration harness's whole-repo symlink makes Plugin Check hang on
  vendor/'s dev tooling): 0 errors, 0 warnings.
- Investigated AIOSEO (All in One SEO) for a third adapter: its title/
  description live in a custom `wp_aioseo_posts` table with 40+ JSON-encoded
  columns, and the only in-plugin write path is explicitly marked
  `@internal Not a public extension surface`. Needs its own integration
  harness before shipping, not a quick postmeta-style addition, so left for a
  dedicated session.

## 0.1.1 (2026-09-22)

- Fix: `resolve_url()` now verifies `get_post()` before returning a resolved
  id. WordPress core's `url_to_postid()` pattern-matches `?p=N` out of the
  query string and returns `N` even when no such post exists, so `/resolve`
  was reporting a phantom post as successfully resolved.
- Real-WordPress integration test harness (`tests/integration/`): SQLite
  drop-in, Rank Math and Yoast, all five REST routes end-to-end.
- Security pass: auth sweep, subscriber/author/editor capability matrix,
  `/apply` + `/resolve` payload fuzzing. No vulnerabilities found
  (`SECURITY-NOTES.md`).
- PHPCS clean against WordPress-Extra + WordPress-Docs (`phpcs.xml.dist`).
- POT file for translators (`languages/crawl-cove-connector.pot`).
- wordpress.org submission pack: icon/banner/screenshot assets
  (`wordpress-org/`).

## 0.1.0 (2026-09-21)

First version.

- Yoast SEO and Rank Math adapters (writes their native title/description post meta; empty string removes the override).
- REST API under `crawlcove/v1`: `/status`, `/resolve`, `/apply` (with `dry_run`), `/changes`, `/revert`.
- Authentication via WordPress core Application Passwords; `edit_posts` required on every route, `edit_post` re-checked per post.
- Change log (capped at 200 entries) with previous values; revert from the API or from Tools → Crawl Cove.
- Touched posts are re-saved after apply so Yoast's indexables pick the change up immediately (filter `ccc_touch_post_after_apply` to disable).
- Unit tests for the adapter, change log and service layers.
