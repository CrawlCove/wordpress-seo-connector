Submission-pack graphics for the wordpress.org plugin directory listing,
not shipped with the plugin itself, not loaded by any PHP here. These exist
because the actual upload target (SVN `assets/`) is separate from this git
repo and gated behind a human-only wordpress.org account.

- `icon-128x128.png`, `icon-256x256.png`: plugin icon.
- `banner-772x250.png`, `banner-1544x500.png` (retina): directory header banner.
- `screenshot-1.png`: Tools → Crawl Cove admin page with sample data,
  captured against the real-WP integration harness (see readme.txt's
  `== Screenshots ==` section for the caption that must stay in step).
  Recipe (scripted 16 Nov 2026): `tests/integration/run.sh --adapter=rankmath`,
  serve the site (`cd tests/integration/.site && php -S 127.0.0.1:8987 -t .`),
  then `bash wordpress-org/screenshot-setup.sh` (resets the demo posts, image,
  change log and site settings, pushes seven changes through the real REST
  routes, and drops a screenshot-only mu-plugin that hides the SQLite admin-bar
  label and update counts) and
  `PLAYWRIGHT=<node_modules/playwright> node wordpress-org/screenshot-capture.js wordpress-org/screenshot-1.png`
  (1280-wide, full page). Last recaptured 16 Nov 2026 (review #377: Free plan
  setup copy, no harness tells), again 19 Nov 2026 (dates in the site format,
  date and time on two lines). Regenerate whenever the Tools page changes.
- `screenshot-2.png`: the desktop app's Push to WordPress panel after a
  meta description push (app ticket #391, 17 Nov 2026). It is an app-side
  capture, so the app agent regenerates it; the site shown is a harness copy
  renamed to harbourbakery.co.uk, which does not resolve publicly.
- `make-assets.php`: regenerates the icon/banner PNGs (PHP GD, run directly:
  `php wordpress-org/make-assets.php`, writes back into this folder).

These PNGs are the SVN `assets/` directory. After the first submission is
approved, every pushed `v*` tag publishes trunk, `tags/<version>` and these
assets through `bin/deploy-wporg.sh` (the `wporg` job in release.yml, which
skips until the repo secrets SVN_USERNAME and SVN_PASSWORD exist). A tag is
only pushed after a wp-release approval. To try the script without touching
wordpress.org, point it at a scratch repo:
`svnadmin create /tmp/r && SVN_URL=file:///tmp/r bash bin/deploy-wporg.sh v<version>`.

SVN trunk/tags must be built with `bash bin/build-zip.sh --wporg`
(or `--stage-to DIR --wporg`), never from the GitHub release zip: the only
difference is that readme.txt Installation step 1 says to search the
directory instead of downloading the GitHub zip (keep step 1's opening words
as they are, or the build refuses). Neither copy carries an `Update URI:`
header, and the build refuses one: core leaves a plugin whose Update URI is
not wordpress.org out of the directory update check, so GitHub installs
would never be offered a directory release (review inbox #392). Until the
slug was ours the GitHub copy carried one (review ticket #212).
