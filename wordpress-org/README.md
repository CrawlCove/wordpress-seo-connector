Submission-pack graphics for the wordpress.org plugin directory listing,
not shipped with the plugin itself, not loaded by any PHP here. These exist
because the actual upload target (SVN `assets/`) is separate from this git
repo and gated behind a human-only wordpress.org account.

- `icon-128x128.png`, `icon-256x256.png`: plugin icon.
- `banner-772x250.png`, `banner-1544x500.png` (retina): directory header banner.
- `screenshot-1.png`: Tools → Crawl Cove admin page with sample data,
  captured against the real-WP integration harness (see readme.txt's
  `== Screenshots ==` section for the caption that must stay in step).
  Recipe (14 Oct 2026): `tests/integration/run.sh --adapter=rankmath`, then
  on `.site`: blogname "Crawl Cove Connector Demo", delete the harness posts
  and the `ccc_change_log`/`ccc_change_seq`/`ccc_last_connection` options,
  create two demo posts, push three fixes through the real `/apply` with an
  admin application password, serve with `php -S 127.0.0.1:8987`, log in as
  admin/admin with Playwright and capture `tools.php?page=crawl-cove-connector`
  at a 1280x900 viewport. Regenerate whenever the Tools page changes.
- `make-assets.php`: regenerates the icon/banner PNGs (PHP GD, run directly:
  `php wordpress-org/make-assets.php`, writes back into this folder).

At submission time these get copied into the SVN `assets/` directory
(trunk/tags stay separate). That step is part of the wp-release approval,
not this repo.

SVN trunk/tags must be built with `bash bin/build-zip.sh --wporg`
(or `--stage-to DIR --wporg`), never from the GitHub release zip: the only
difference is that the `Update URI:` header line is removed. The directory
serves updates only to plugins that do not point their updates elsewhere,
and Plugin Check flags the header; the GitHub copy keeps it so a GitHub
install can never be offered whatever plugin might one day own this slug
on wordpress.org (review ticket #212).
