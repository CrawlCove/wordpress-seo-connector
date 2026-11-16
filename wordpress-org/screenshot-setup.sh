#!/usr/bin/env bash
#
# Seeds the harness site (tests/integration/run.sh --adapter=rankmath first) with the
# demo data for screenshot-1.png, through the real REST routes. Needs the site served:
#   (cd tests/integration/.site && php -S 127.0.0.1:8987 -t .) &
# then: node wordpress-org/screenshot-capture.js wordpress-org/screenshot-1.png
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE="$ROOT/tests/integration/.site"
OUT="$(mktemp -d)"
W=(php "$ROOT/tests/integration/.cache/wp-cli.phar" --path="$SITE" --quiet)
U=http://127.0.0.1:8987
cat > "$SITE/wp-content/mu-plugins/zz-screenshot-clean.php" <<'PHP'
<?php
// Screenshot only: hide the harness tells (SQLite label, update counts).
add_filter( 'wp_get_update_data', function ( $d ) {
	$d['counts'] = array_map( function () { return 0; }, $d['counts'] );
	$d['title']  = '';
	return $d;
} );
add_action( 'admin_bar_menu', function ( $bar ) {
	$bar->remove_node( 'sqlite-db-integration' );
	$bar->remove_node( 'updates' );
}, 1000 );
PHP
"${W[@]}" option update blogname "Crawl Cove Connector Demo"
ids=$("${W[@]}" post list --post_type=any --post_status=any --format=ids)
[ -n "$ids" ] && "${W[@]}" post delete $ids --force
ids=$("${W[@]}" post list --post_type=attachment --post_status=any --format=ids)
[ -n "$ids" ] && "${W[@]}" post delete $ids --force
for o in crawlcove_change_log crawlcove_change_seq crawlcove_last_connection crawlcove_site_settings; do "${W[@]}" option delete $o || true; done
php -r '$i=imagecreatetruecolor(600,400);imagefill($i,0,0,imagecolorallocate($i,180,190,200));imagefilledellipse($i,300,180,120,120,imagecolorallocate($i,120,130,140));imagepng($i,$argv[1]);' "$OUT/leaky-kitchen-tap.png"
aid=$("${W[@]}" media import $OUT/leaky-kitchen-tap.png --porcelain)
[ -n "$aid" ] || { echo "image import failed" >&2; exit 1; }
src=$("${W[@]}" eval "echo wp_get_attachment_url($aid);")
p1=$("${W[@]}" post create --post_type=post --post_status=publish --post_title="Best Local Plumbers in Bristol" --post_content="<p>Our pick of plumbers who answer the phone.</p>" --porcelain)
p2=$("${W[@]}" post create --post_type=post --post_status=publish --post_title="How to Fix a Leaky Tap" --post_content="<p>Most drips are a worn washer. See our <a href=\"$U/old-washer-guide/\" rel=\"nofollow\">washer guide</a>.</p><p><img src=\"$src\" alt=\"\" width=\"600\" height=\"400\" class=\"wp-image-$aid\"></p>" --porcelain)
pw=$("${W[@]}" user application-password create admin "Crawl Cove" --porcelain)
u2=$("${W[@]}" eval "echo get_permalink($p2);")
R() { curl -sS -u "admin:$pw" -H 'Content-Type: application/json' "$U/index.php?rest_route=/crawlcove/v1/$1" -d "$2"; echo; sleep 1; }
R apply "{\"changes\":[{\"post_id\":$p1,\"title\":\"Best Local Plumbers in Bristol | Fast, Licensed Call-Outs\",\"description\":\"Compare rated local plumbers in Bristol. Same-day emergency call-outs, upfront pricing, fully licensed.\"}]}"
R apply "{\"changes\":[{\"post_id\":$p2,\"title\":\"How to Fix a Leaky Tap in 10 Minutes (No Plumber Needed)\",\"og_title\":\"Fix a Leaky Tap in 10 Minutes\"}]}"
R replace-urls "{\"changes\":[{\"url\":\"$u2\",\"from\":\"$U/old-washer-guide/\",\"to\":\"$U/tap-washer-guide/\",\"rel_strip\":\"nofollow\",\"scope\":\"page\"}]}"
R site-settings '{"settings":{"hide_generator":true}}'
R image-alt "{\"changes\":[{\"url\":\"$u2\",\"src\":\"$src\",\"alt\":\"Dripping kitchen tap with a worn washer\"}]}"
"${W[@]}" user application-password delete admin --all
