# Security pass, 22 Sept 2026

Run against a real WordPress install (SQLite drop-in, no MySQL), both Rank
Math and Yoast SEO, over live HTTP with Application Password auth. Reproduce
with `tests/integration/run.sh --adapter=rankmath|yoast`. It runs
`checks.sh` (route behaviour, 22 checks) then `security-checks.sh` (this
pass, 42 checks). Both adapters: 64/64 green.

## Scope

- Every route (`status`, `resolve`, `apply`, `changes`, `revert`) confirmed
  to require authentication: no route is reachable without valid Application
  Password credentials, and a wrong password is rejected too.
- Full capability matrix: subscriber (no `edit_posts`) blocked at all five
  routes with 403; author (`edit_posts`, not `edit_others_posts`) can act on
  their own posts but is `ccc_forbidden` on another user's post; editor
  (`edit_others_posts`) can act on any post. All of it matches WordPress core's
  own capability model, with nothing bespoke in the plugin to get wrong.
- `/apply` and `/resolve` fuzzed: wrong types (string/null/object/array where
  a string or array is expected), nested objects, non-numeric `post_id`,
  10k-character strings, exact length-cap boundaries (512/1024/50/100),
  `<script>` tags, a SQL-injection-shaped string, an embedded null byte, and
  deliberately truncated/malformed JSON. Every case returns a clean 4xx or a
  per-item `ok:false`; no PHP warning, stack trace, or file path ever
  appears in a response body.
- `/revert`: missing/non-numeric/negative/nonexistent `change_id` all handled
  without a crash (400 or 404, never 500).

## Findings

1. **Fixed in code** (see `includes/class-ccc-service.php`): WordPress
   core's `url_to_postid()` pattern-matches `?p=N` straight out of a URL's
   query string and returns `N` even when no such post exists. `resolve_url()`
   trusted that blindly, so a stale or guessed numeric URL was reported as a
   successfully resolved (but blank, uneditable) post instead of "not found".
   Now verifies `get_post()` before returning; covered by a new unit test in
   `tests/ServiceTest.php` plus the integration check
   "resolve: phantom numeric id (url_to_postid quirk) is unresolvable".

2. **Observed, no code change needed**: WordPress's REST argument layer does
   not 400 a scalar against a route arg declared `type => 'array'`. It
   silently `(array)`-casts it to a one-element array instead of rejecting
   the request. Both `/resolve`'s `urls` and `/apply`'s `changes` rely on
   this only as a first line of defense; the plugin's own `is_array()` /
   per-item validation in `CCC_Service` is what actually catches the
   malformed shape, and it does so safely in every case tested. Worth
   knowing if either route's args are ever refactored: don't assume the REST
   layer enforces the declared `type` for you.

3. **Confirmed safe, not a finding**: `sanitize_text_field()` (used on every
   title/description value) strips `<script>` and other tags before
   storage, and the admin page (`admin/class-ccc-admin.php`) escapes every
   logged value with `esc_html()` on output: defense in depth on both ends,
   and no stored-XSS path found.
   Update 19 Oct 2026 (review #233): `/apply` now uses
   `CCC_Service::sanitize_meta_text()`, which is core's sanitizer minus the
   percent-octet removal that was deleting SEO template variables. Tag
   stripping and the lone-`<` entity step are unchanged, so the conclusion
   above still holds. An encoded octet left in the stored text is inert: it
   is plain text and escaped on output.

4. **Confirmed safe, not a finding**: capability checks (`current_user_can`)
   correctly deny `edit_post` for a non-existent post id, so even the
   url_to_postid quirk in (1) could never have led to an unauthorized write:
   `/apply` with a phantom post id returned `ccc_forbidden`, not a crash or
   a write. (1) was a read-side correctness bug in `/resolve`'s response,
   not a write-side authorization gap.

## Addendum, 24 Sept 2026 (taxonomy term support, v0.6.0)

Same harness, extended with `tests/integration/taxonomy-checks.sh` (16
checks) covering the new negative-`post_id` term target across all four
adapters, plain and pretty permalinks, and the `edit_term`/`manage_categories`
capability boundary (author blocked, editor allowed: WordPress core's own
model again, nothing bespoke). Two more real-WordPress-only findings, same
class as finding 1 above (a core quirk / a plugin helper's own behaviour
trusted too literally, not a vulnerability in this plugin's own code):

5. **Fixed in code**: on a site with a static front page, `url_to_postid()`
   collapses *any* query string at the site root (not just the homepage's
   own) to the front page's post id, so `?cat=2` was resolving to the
   homepage, not the category. `resolve_url()` now resolves query-string
   term targets (`CCC_Term_Resolver::resolve_plain_query_vars()`) *before*
   calling `url_to_postid()`, not after.
6. **Fixed in code**: Yoast's own `WPSEO_Taxonomy_Meta::set_value()` is not
   a true single-field patch. Writing just a term's title (or just its
   description) was silently resetting the other back to `''`, and would do
   the same to any other Yoast term setting a site owner had set by hand
   (focus keyword, Open Graph/Twitter overrides, cornerstone flag). Not an
   auth/injection issue, but a real silent-data-loss bug a wordpress.org
   reviewer or a site owner could reasonably treat as a security-adjacent
   correctness defect. Fixed by reading the term's full current settings
   first (`get_term_meta()` with no `$meta` arg) and writing them all back
   with only the intended field changed.

## Addendum, 26 Sept 2026 (WooCommerce + Multisite capability verification)

Two new standalone harnesses (`tests/integration/woocommerce-checks.sh`,
`tests/integration/multisite-checks.sh`), same "prove it against a real
install, don't trust the code read" discipline as the addenda above. No
vulnerabilities found; one capability-model behavior worth recording here
because it's exactly the kind of thing a site owner could mistake for a
CCC bug (or a security hole in the other direction: an Editor who thinks
they can't touch products, when actually they simply lack the capability
core already withholds):

7. **Not a CCC issue, confirmed on the merits**: WooCommerce grants
   `edit_products`/`edit_product_terms` (and related capabilities) ONLY to
   the Shop Manager and Administrator roles, verified against
   `WC_Install::create_roles()` and `WC_Post_Types::register_taxonomies()`
   source, not assumed. CCC's `edit_post`/`edit_term` capability checks defer
   entirely to WordPress core's meta-capability system, so an Editor who can
   push fixes to ordinary posts/pages correctly gets `ccc_forbidden` on a
   WooCommerce product or product_cat term. This is WooCommerce's own
   authorization model working as intended, not a gap in CCC. Documented in
   README.md.
8. **Multisite scoping confirmed, not assumed**: activating CCC + Rank Math
   on one subsite of a network and hitting the network root's REST API
   returns a genuine `rest_no_route` 404 (route doesn't exist there at all),
   not a `ccc_forbidden`/`rest_forbidden`. That confirms `register_rest_route()`
   is correctly per-site (fires on each site's own `rest_api_init`) with no
   network-wide leakage, and that a write via the subsite's REST endpoint
   lands in that subsite's own postmeta table, never the network's shared
   tables.

## Addendum, 26 Sept 2026 (caching-plugin interaction: a real bug, now fixed)

Investigated on the hypothesis that a caching plugin's own per-page purge
hook might not fire for every CCC write path. Not a security issue, but a
correctness one with the same "silent, invisible failure" shape worth this
file's discipline. Confirmed real, not assumed: downloaded WP Super Cache's
actual current-stable source from wordpress.org and read its cache-purge
functions directly (`wp-cache-phase2.php`) rather than guessing at its
behavior.

9. **Real bug, now fixed**: `wp_cache_post_edit()`/`wp_cache_post_change()`
   (WP Super Cache's own functions, hooked to core's `clean_post_cache`
   action) both `return` immediately when `$post_id === 0`, and neither is
   registered on any term-edit action at all. CCC's homepage (`post_id 0`)
   and taxonomy-archive (negative term-id sentinel) writes went through
   `update_option()`/term meta directly, with no `wp_update_post()` call to
   fire `clean_post_cache` in the first place, so a site running WP Super
   Cache (or any similarly-built caching plugin) kept serving a stale
   cached homepage or category/tag archive page after a desktop-app push,
   for as long as that page's cache lived. `CCC_Service::invalidate_caches_for()`
   now runs a best-effort full-site purge (WP Super Cache, W3 Total Cache,
   WP Rocket via `function_exists()`-guarded calls to their own public
   functions, plus WP Fastest Cache's and LiteSpeed Cache's own documented
   action hooks `wpfc_clear_all_cache`/`litespeed_purge_all`, neither of
   which is a callable function despite the naming convention suggesting
   otherwise; confirmed against WP Fastest Cache's real downloaded source,
   which registers it with `add_action()`, not `function_exists()`-checkable
   at all) for these two target types, and always fires a new
   `ccc_after_uncached_write` action for anything not listed. Initially
   wrote `wpfc_clear_all_cache` as a `function_exists()` guard by pattern-
   matching the others without checking its real source first; caught and
   fixed before commit by downloading WP Fastest Cache and grepping it,
   the same discipline as the rest of this file.
10. **Second real bug found investigating the first**: `CCC_Change_Log::
    revert()` never called `wp_update_post()` at all, for ANY target,
    including an ordinary post. A reverted post's Yoast indexable went
    stale and no caching plugin learned the page changed, the exact defect
    class `apply()` already had a fix for (re-saving the post) that
    `revert()` simply never inherited. Both fixes share one code path now
    (`CCC_Service::invalidate_caches_for()`, called from both `apply()` and
    `revert()`), so they can't drift apart again silently.

Verified against a real WordPress + real Rank Math install, not just unit
stubs: `tests/integration/caching-checks.sh` uses an mu-plugin probe that
logs real firings of `clean_post_cache` and a stand-in for WP Super Cache's
own `wp_cache_clear_cache()` (name and signature taken from its real
source, not guessed). Confirmed the harness genuinely catches the bug, not
just exercises the happy path: reverted the fix locally and re-ran it first:
10 of 12 checks failed exactly as predicted, then re-ran with the fix
restored for a clean 12/12.

## Addendum, 26 Sept 2026 (Gutenberg/block-editor concurrent-edit check: investigated, no bug)

Tested a specific hypothesis the previous session's handoff flagged as
worth checking: does having a post open in the block editor risk a stale
sidebar value silently overwriting a CCC push? Real difference found
between adapters first, via a live Gutenberg session (Playwright,
`wp.data.select('core/editor').getCurrentPost().meta`): **Yoast SEO
registers `_yoast_wpseo_title`/`_yoast_wpseo_metadesc` (the exact same
postmeta keys CCC itself writes) as Gutenberg REST meta fields, visible
in the editor's own state. Rank Math does not** (its SEO panel doesn't
appear in `post.meta` at all, so this class of risk doesn't apply to it).

That raised a real question for Yoast: if an editor tab loads the OLD
title into its meta store, CCC pushes a NEW title via REST while that tab
sits open, and the user then saves the post for an unrelated reason (e.g.
fixing a typo in the body), does Gutenberg's `savePost()` resend its
stale copy of `meta` and clobber CCC's fresh write? **Tested against a
real WordPress + real Yoast install, not assumed: no.** Editing post
content (never touching the Yoast SEO panel) and calling
`wp.data.dispatch('core/editor').savePost()` produced a PUT that changed
`post_content` but left `_yoast_wpseo_title` in the database exactly as
CCC had just set it, confirmed by reading the postmeta directly after
the save completed, not just by reading network traffic. Gutenberg only
includes an attribute (including the whole `meta` object) in its save
diff when that attribute was explicitly dispatched via `editPost()` in
that browser session; merely having loaded a value into the store isn't
enough. The only real collision left is the ordinary case of two edits to
the SAME field in the same window, no different from any two concurrent
editors of one WordPress field, not a CCC-specific bug, and not pursued
further. No code change needed.

## Addendum, 26 Sept 2026 (multilingual-plugin URL resolution: a real bug, now fixed, v0.8.1)

Investigated Polylang (the largest free WordPress multilingual plugin,
700,000+ installs) as an untested compatibility surface, following the same
"prove it against a real install, don't trust a code read" discipline as the
WooCommerce/multisite passes. Downloaded Polylang's real current-stable
source from wordpress.org rather than guessing at its internals.

11. **Real bug, now fixed**: `CCC_Term_Resolver::resolve_pretty_permalink()`
    accepted ANY rewrite-rule match that resolved to a taxonomy-archive
    query (`is_tax`/`is_category`/`is_tag`), with no check on whether that
    taxonomy was actually `public`, unlike its own sibling method
    `resolve_plain_query_vars()`, which only ever iterates
    `get_taxonomies(['public' => true])`. Confirmed against Polylang's real
    source (`src/translated-post.php`): Polylang registers its own internal
    "language" taxonomy (used for its language switcher) as
    `public => false` / `publicly_queryable => true` / `query_var => 'lang'`:
    publicly queryable so its own rewrite rule works, but explicitly not
    public because it isn't real content. Under Polylang's default
    directory URL mode (default language at the root, others under `/xx/`),
    that rewrite rule matches a bare `/fr/`, exactly the shape of a French
    "your latest posts" homepage URL. Verified live against a real
    WordPress + real Polylang + real Rank Math install: resolving `/fr/`
    returned Polylang's own "French" language TERM (a negative-sentinel
    target CCC treats as fully editable) instead of the homepage or
    `ccc_unresolvable`. Applying a title through it reported `ok: true` and
    genuinely wrote into Rank Math's term meta for that internal term.
    **The desktop app would tell a user their homepage fix succeeded, and
    the real page would never change.** Silent-failure-reported-as-success
    is the worst class of bug for a plugin whose whole premise is "crawl,
    fix, push live, verify."
12. **Same gap, second entry point**: `CCC_Service::validate_change()`
    accepted any existing term id for a negative `post_id`, with no
    publicness check either, so even after fixing URL resolution, a
    caller passing `{"post_id": -5}` directly (bypassing `/resolve`
    entirely) could still reach the same internal term. Both entry points
    now require `get_taxonomy($term->taxonomy)->public` before accepting a
    term as a valid target, closing the gap at both the discovery and the
    write layer, not just one.

Verified against a real WordPress + real Rank Math + real Polylang install,
not unit stubs alone: `tests/integration/polylang-checks.sh` (new,
standalone, like woocommerce/multisite-checks.sh) proves the fix directly
(a French homepage URL and a direct term-id apply attempt both now fail
cleanly) and proves no regression on the things that must keep working:
ordinary translated posts stay genuinely isolated per language, and a real
(public) category taxonomy's own language-prefixed archive URL
(`/fr/category/news/`) still resolves correctly. Full 4-adapter matrix
(`run.sh`) plus woocommerce/multisite/caching-checks.sh and plugin-check.sh
all re-verified clean after the fix; +1 unit test (86 total).

Deliberately out of scope: this fixes the safety bug (never misresolve to
internal plumbing), not real per-language homepage/archive SEO targeting for
multilingual sites. Rank Math itself has no Polylang integration at all
(confirmed: only Yoast has a dedicated compat layer in Polylang's own
source, wrapping its homepage title/description strings for Polylang's own
translation system), so its homepage title stays one shared value across
every language regardless of anything CCC does. A non-default-language
"your latest posts" homepage is honestly `ccc_unresolvable` for now, not
silently wrong. Real multilingual-aware homepage targeting would be a
larger, separate feature, not rushed into this fix (it shipped in v0.9.0,
see below).

## Addendum, 26 Sept 2026 (Yoast homepage title + Polylang string translation: investigated, no bug)

Follow-up question raised by the addendum above: on a Yoast + Polylang site,
does pushing a CCC homepage (`post_id: 0`) title/description write through
`WPSEO_Options::save_option()` corrupt or silently overwrite a non-default
language's already-translated homepage title? Polylang has a real,
dedicated compat layer for exactly this (`src/integrations/wpseo/wpseo.php`
registers `wpseo_titles`'s `title-home-wpseo`/`metadesc-home-wpseo` for its
own string-translation system via `PLL_Translate_Option`), so this wasn't
assumed safe just because the Rank Math case (no Polylang integration at
all) is.

Tested against a real WordPress + real Yoast + real Polylang install, not
just a read of `PLL_Translate_Option`'s source: configured an English
homepage title/description via Yoast directly, registered a French
translation for both strings the same way Polylang's own Strings
Translation admin screen would (via `PLL_MO::add_entry()`/`export_to_db()`
against the French language term's `_pll_strings_translations` term meta),
then pushed a NEW English title/description through CCC's real `/apply`
REST route (admin user, `manage_options`, the actual capability gate for
`HOME_ID`). Result: **no corruption.** The raw `wpseo_titles` option now
holds the new English strings (correct: that is CCC's write). Polylang's own
`pre_update_option_wpseo_titles`/`update_option_wpseo_titles` filters (the
"step 1 filter out the update, step 2 reattach the old translation to the
new original string" mechanism `PLL_Translate_Option::pre_update_option()`/
`update_option()` implement) fired exactly as designed: the French term's
`_pll_strings_translations` term meta still maps to the *same* French
translation strings, now re-keyed to the new English original rather than
lost. A site owner's French Yoast homepage title survives a CCC push to the
English one untouched. That is safe by construction, not by luck: this is
literally the scenario `PLL_Translate_Option` exists to handle (any
plugin/admin action that updates the option's raw value), CCC isn't doing
anything unusual to it. No code change needed.

Scope note, unchanged from the addendum above: this confirms CCC pushing to
the DEFAULT language's homepage is safe on a Yoast+Polylang site. It does
not mean a non-default language's homepage is independently *reachable* by
CCC. `resolve_url()` still can't discover `/fr/` as a target at all (fixed
in v0.8.1 to fail cleanly as `ccc_unresolvable` rather than misresolve); a
real French-homepage-specific fix would need Polylang-aware URL resolution
that did not exist at the time: a feature gap, not a safety concern (closed in
v0.9.0, next addendum).

## Addendum, 26 Sept 2026 (real per-language homepage support, v0.9.0: new feature, security-reviewed)

The addendum above confirmed the DEFAULT language's homepage write was safe
on a Yoast+Polylang site, but left the actual feature gap open: a
non-default language's own homepage (`/fr/`) was `ccc_unresolvable`, not a
target CCC could reach. This session closed that gap for Yoast (the only
adapter with real per-language homepage storage, confirmed against
Polylang's own source: `src/integrations/` has a dedicated compat module
only for Yoast, none for Rank Math/SEOPress/AIOSEO).

**New capability surface, security-relevant bits:**

- New optional `lang` field on `/apply`/`/revert` change items. Validated
  before anything is written: must be a string, must name one of the
  site's own currently-active Polylang languages
  (`in_array($lang, pll_languages_list(), true)`, not a bare "is this a
  plausible-looking language code" regex), and is only accepted alongside
  `post_id: 0` (`ccc_language_requires_home` otherwise), so this can't be
  smuggled onto an ordinary post/term write to do anything unexpected
  there.
- Capability check is unchanged: `manage_options`, gated exactly like the
  existing default-language homepage target, checked BEFORE the
  language-specific adapter-support gate. A user who can't manage the
  homepage at all can't discover adapter support by probing `lang` values
  either (same ordering `can_edit_target()` already enforced).
- The write (`PLL_MO::import_from_db()`/`add_entry()`/`export_to_db()`)
  targets a `PLL_Language` object obtained via `PLL()->model->get_language(
  $lang)`, itself only reachable after the `in_array()` active-language
  check above passes, so an attacker-controlled `lang` string is never
  passed to Polylang's API directly without that allowlist check first
  (fuzzing-relevant: unicode/oversized/null-byte `lang` strings all fail
  the `in_array()` check the same as any other unrecognised code, same
  class of guard `security-checks.sh` already exercises for `post_id`).
- `resolve_url()`'s new Polylang-language-home matching
  (`resolve_polylang_home_language()`) runs LAST, only after every existing
  post/term resolution path has already missed. It cannot shadow or
  pre-empt a real post/term match, only fill the specific gap that used to
  fall through to `ccc_unresolvable`.
- Resolution itself (`/resolve`) is deliberately adapter-independent: it
  reports `lang` for a matched URL regardless of which SEO plugin is
  active, with `editable: false` for one that can't act on it. This is not
  an information leak beyond what `/resolve` already exposes for every
  other target (current title/description text, which SEO plugin is
  active): whether a site runs Polylang, and what its active language
  codes are, is already public information any visitor can see from the
  site's own rendered `<html lang>` attribute and URL structure.
- Verified this session (not assumed) that a `lang`-qualified write cannot
  cross into another language's translation or the default-language
  value: `tests/integration/polylang-yoast-home-checks.sh` proves a French
  write leaves the raw (default-language) `wpseo_titles` option
  byte-for-byte untouched, and that reverting a French write restores
  Yoast's real fallback rendering (the default-language value, per real
  gettext semantics, confirmed against a real install as what an
  untranslated string actually renders, not a guess) rather than
  leaving anything blank or duplicated across languages.

No new external requests, no new stored PII, no change to the
authentication model. Full write-up (design rationale, REST contract,
adapter-support matrix) in README.md's "Multilingual sites" section and
CHANGELOG.md's 0.9.0 entry.

## Addendum, 27 Sept 2026 (TranslatePress URL resolution: a real bug, now fixed, v0.10.0)

Researched whether TranslatePress (the other major free multilingual
plugin, alongside Polylang) needed the same kind of safety/feature work as
Polylang got in the two addenda above. Not assumed either way: verified
against its own downloaded source and then against a real install.

- **Architecturally the opposite problem from Polylang.** Polylang creates
  genuinely separate content (a real `post_id` per translation) plus a real
  taxonomy whose rewrite rule once collided with CCC's own resolution (see
  the 26 Sept addendum above). TranslatePress has no separate post or SEO
  storage per language at all. It translates the SAME post's SAME Yoast/
  Rank Math/SEOPress/AIOSEO postmeta at render time, by swapping the
  rendered *string* via its own gettext-style dictionary tables
  (`includes/queries/class-gettext-table-creation.php`), keyed on the
  literal original string text.
- **Real bug found, not a homepage-only edge case.** Confirmed against
  TranslatePress's own source that it manages its language-prefixed URLs
  (e.g. `/fr/some-post/`) with plain string manipulation
  (`includes/class-url-converter.php`); there is no `add_rewrite_rule()`
  call anywhere in the plugin. `url_to_postid()` and `CCC_Term_Resolver`'s
  own matching both work by matching WordPress's *compiled rewrite rules*,
  so a prefix that was never registered as one is invisible to them. Proved
  against a real WordPress + Yoast + TranslatePress install
  (`tests/integration/translatepress-checks.sh`) before assuming this from
  source alone: EVERY URL under the non-default language's prefix
  (ordinary posts, the homepage, term archives) was `ccc_unresolvable`.
  For a plugin whose whole point is "crawl a URL, push a fix, verify it
  went live," that's a real coverage gap on a very popular free plugin, not
  a narrow one.
- **Fix is simpler than Polylang's**, because there's no separate content to
  target: `CCC_Service::strip_translatepress_language_prefix()` reads
  TranslatePress's own `trp_settings` option (`default-language`,
  `url-slugs`; it ships no public `@api` equivalent of Polylang's
  `pll_home_url()`) and strips a recognised non-default language's slug
  from the URL *before* any resolution logic runs. Every existing post/
  term/homepage path then handles the stripped URL exactly like its
  default-language counterpart. No new REST field: `lang` stays `''`
  throughout correctly, because there is genuinely nothing per-language
  here for it to mean.
- Verified end-to-end, not just from source: `translatepress-checks.sh`
  (9/9): a post URL and its `/fr/`-prefixed counterpart resolve to the
  same `post_id`; the homepage and its `/fr/` counterpart both resolve to
  `HOME_ID`; a CCC title write reads back identically from either URL.
  Confirmed the harness genuinely catches the regression by reverting the
  fix locally first (5/9 failed, as predicted, matching this project's own
  practice for every other integration harness).
- **One consequence documented, not fixed, because it isn't a CCC bug.**
  TranslatePress's translation lookup is keyed on the literal original
  string, so any edit to that string (from CCC, or from a site owner's
  own hand-edit in the SEO plugin's metabox, no difference) orphans
  whatever translation already existed until a human re-translates it in
  TranslatePress's own editor. This is TranslatePress's own designed
  behaviour for any edit source, not something this plugin introduces or
  could avoid.
- The pre-existing `lang` field's own fuzzing matrix (type confusion,
  whitelist-bypass, injection/oversized/null-byte values; see the
  previous addendum) was extended this session too:
  `polylang-yoast-home-checks.sh` gained 12 checks covering it, since it
  shipped in 0.9.0 but was never added to that matrix. No bug found:
  `is_string()` + a strict `in_array()` whitelist already rejected
  everything. It is now a permanent regression check.

No new external requests, no new stored PII, no change to the
authentication model. Full write-up in README.md's "Multilingual sites"
section and CHANGELOG.md's 0.10.0 entry.

## Not covered here (separate backlog items)

- PHPCS / WordPress-Coding-Standards pass.
- CSRF: not applicable. The REST API here is authenticated by Application
  Passwords (Basic Auth), which WordPress core exempts from the cookie-nonce
  CSRF check by design (same model core itself uses for the REST API).
- Rate limiting / brute-force protection on Application Password auth: this
  plugin doesn't implement its own; it relies on whatever the host/core
  provides. Documented in `readme.txt`'s FAQ (26 Sept 2026) rather than left
  as an undocumented gap for a wordpress.org reviewer to ask about.

## Addendum, 17 Oct 2026 (read disclosure through /resolve and /changes: a real bug, now fixed)

Reported by the review agent (ticket #227) on the harness site. `url_to_postid()`
takes `?p=N` straight from the query string and `resolve_url()` only checked
that the post existed, so any `edit_posts` user could enumerate ids and read
titles, permalinks and SEO values of other users' drafts and private posts,
and of `user_request` posts (title = the GDPR requester's email). Core's own
`GET /wp/v2/posts/<id>` refuses the same reads. `GET /changes` returned the
whole log, including from/to values and the login behind every change.

Fix: `resolve_url()` now also requires `read_post` and `is_post_type_viewable()`
and answers `ccc_unresolvable` otherwise, so the reply is no oracle for which
ids exist. `/changes` serves `CCC_Change_Log::visible()`: the full log for
`manage_options`, otherwise only entries where `can_edit_target()` holds.
Writes were never affected (`/apply` and `/revert` already checked
`can_edit_target()`).

Verified: `security-checks.sh` gained 13 checks (author vs editor's private
post, draft and a real `wp_create_user_request()` row; editor's own private
post still resolves; /changes as author omits the editor's entries, as editor
includes the author's). All 8 leak checks FAIL against the 0.10.5 code and
pass with the fix: AIOSEO 148/148, Rank Math 156/156, Yoast 154/154. Unit
suite 127 -> 132.


## Addendum, 21 Oct 2026 (a push re-filtered the post body: a real data-loss bug, now fixed)

Found by hand on the harness, no report. After every real write to a post,
`CCC_Service::invalidate_caches_for()` called `wp_update_post( array( 'ID' =>
$id ) )` to rebuild Yoast's indexable and fire `clean_post_cache`. That
re-saves every field through `content_save_pre`, and core adds
`wp_filter_post_kses` there for a user without `unfiltered_html`: any Author,
every site administrator on multisite, and everyone on a site with
`DISALLOW_UNFILTERED_HTML`. An Author's title push to their own post deleted
the iframe, `<script>` tags and form an admin had added; on multisite a
subsite admin's push deleted the super admin's YouTube embed. Not a privilege
escalation (it removes markup, never adds it), but silent loss of content the
plugin promises never to touch, plus a modified-date bump and a `save_post`
for every other plugin on each push.

Fix: no re-save. A post write now calls `clean_post_cache()` and the per-post
purge APIs of W3 Total Cache, LiteSpeed Cache and WP Fastest Cache (each read
from the plugin's current source; the last three only purged on the re-save's
`save_post`/`transition_post_status`). Yoast's `Indexable_Post_Meta_Watcher`
rebuilds the indexable from the `_yoast_wpseo_*` meta write at shutdown, so
the re-save was never needed for that.

Verified: new `content-integrity-checks.sh` (wired into run.sh) fails 4 checks
on the 0.10.7 code (body stripped, modified date moved, on apply and revert)
and passes with the fix on all four adapters; on Yoast it also pins the
indexable title after apply and after revert. `multisite-checks.sh` gained a
subsite-admin embed check (fails on 0.10.7, passes now). `caching-checks.sh`
pins the LiteSpeed and WPFC per-post hooks and that `save_post` never fires.
Harness: Yoast 178, Rank Math 177, SEOPress 175, AIOSEO 170, caching 16,
multisite 6, all green. Unit suite 141, with no `wp_update_post()` call
allowed.

Same session, two smaller findings (Unreleased, next weekly release):

- `/apply` with a raw `post_id` checked only `edit_post`, which an Editor
  passes on a `user_request` (privacy request), so it wrote SEO meta onto one.
  No read leak (the reply carries only the SEO values). It now refuses any
  type `is_post_type_viewable()` rejects, as `/resolve` does. Pinned in
  `security-checks.sh` (fails on the old code) and `ServiceTest`.
- `uninstall.php` ran on the main site only, so on multisite every subsite's
  change log (logins, old and new titles) survived deleting the plugin. It
  now walks every site; `multisite-checks.sh` runs the real uninstall.
- A per-language (Yoast + Polylang) homepage entry reverted after the site
  switched to Rank Math or SEOPress went through their language-blind writer:
  a forced revert put the French title over the default homepage title
  (reproduced: Rank Math's `%sitename% %page% %sep% %sitedesc%` became
  "Nouveau Titre"). With Polylang off, the Yoast writer returned silently and
  the entry was marked reverted. Revert now refuses both with
  `ccc_language_unavailable`, force or not; pinned in
  `polylang-yoast-home-checks.sh` (fails on the old code) and `ServiceTest`.

## Addendum, 22 Oct 2026 (/replace-urls: the first route that writes post content)

Threat model and what pins each answer:

- **Writing the body without kses.** The route writes `post_content` straight
  to the row, because `wp_update_post()` would strip an admin's embeds when
  an Author pushes (the 0.10.8 lesson). That is only safe because the bytes
  it can introduce are fixed: `to` must be an absolute http(s) URL equal to
  its own `esc_url_raw()` and free of quotes, angle brackets, backslashes,
  backticks and whitespace, so it cannot close an attribute, a tag, a JSON
  string or a block comment (`--` is escaped in block comments as WordPress
  does). In CSS `url()` a URL with `(`, `)`, `;` or `,` is skipped, not
  written, since it could end the value and inject CSS; same for a comma in
  `srcset`. Unlink only deletes `<a>` and `</a>` tags. Unit tests pin each
  refusal; replace-urls-checks.sh pins the Author's iframe surviving.
- **Reach.** Page scope needs `edit_post` on the page. Site scope filters
  every candidate by `edit_post` (menus: `edit_theme_options`), so an Author
  reaches only their own posts and the reply names no other post (pinned).
  A replace entry is listed by `/changes` and revertable only for a user who
  may edit every place it touched (pinned: Author cannot see an admin's
  site-wide change, Editor without menu rights cannot revert it).
- **Exactness.** Only whole URL values in URL contexts change; `/old/` never
  touches `/old/two/` or plain text (pinned on the front end). Revert
  restores the exact bytes from stored splices only when each location's
  hash still matches; otherwise 409, never a guess. A mutation that dropped
  the hash check corrupted the post on revert in the harness, which is why
  the check exists.
- **Cost.** At most 50 items per request and 200 places per site-wide item,
  with a pre-filter cap of 1,000 candidates before any row is read.
