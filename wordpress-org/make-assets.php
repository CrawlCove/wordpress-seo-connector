<?php
/**
 * One-off generator for wordpress.org submission graphics (icon + banner).
 * Not part of the plugin; run manually, output goes to .wporg-assets/out/.
 * php .wporg-assets/make-assets.php
 */

// A git checkout in wp-content/plugins makes this file web-reachable; it only runs from the command line.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$font       = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$navy_deep  = array( 11, 30, 48 );   // #0b1e30
$navy_mid   = array( 15, 58, 82 );   // #0f3a52
$teal       = array( 45, 182, 176 ); // #2db6b0
$foam       = array( 214, 242, 236 ); // #d6f2ec

function hexcol( $im, $rgb ) {
	return imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] );
}

function gradient_bg( $im, $w, $h, $top, $bottom ) {
	for ( $y = 0; $y < $h; $y++ ) {
		$t = $y / max( 1, $h - 1 );
		$r = (int) ( $top[0] + ( $bottom[0] - $top[0] ) * $t );
		$g = (int) ( $top[1] + ( $bottom[1] - $top[1] ) * $t );
		$b = (int) ( $top[2] + ( $bottom[2] - $top[2] ) * $t );
		$c = imagecolorallocate( $im, $r, $g, $b );
		imageline( $im, 0, $y, $w, $y, $c );
	}
}

// A simple stylised wave: a sine ribbon plus a lighter foam line above it.
function draw_wave( $im, $w, $baseline, $amp, $period, $color, $thickness ) {
	for ( $t = 0; $t < $thickness; $t++ ) {
		$prev_x = 0;
		$prev_y = null;
		for ( $x = 0; $x <= $w; $x += 2 ) {
			$y = $baseline + $t + (int) ( $amp * sin( ( $x / $period ) * 2 * M_PI ) );
			if ( null !== $prev_y ) {
				imageline( $im, $prev_x, $prev_y, $x, $y, $color );
			}
			$prev_x = $x;
			$prev_y = $y;
		}
	}
}

function centered_text( $im, $font, $size, $color, $w, $y, $text ) {
	$box = imagettfbbox( $size, 0, $font, $text );
	$tw  = $box[2] - $box[0];
	$x   = (int) ( ( $w - $tw ) / 2 );
	imagettftext( $im, $size, 0, $x, $y, $color, $font, $text );
}

function save_icon( $size, $path ) {
	global $font, $navy_deep, $navy_mid, $teal, $foam;
	$im = imagecreatetruecolor( $size, $size );
	imagesavealpha( $im, true );
	gradient_bg( $im, $size, $size, $navy_deep, $navy_mid );

	$teal_c = hexcol( $im, $teal );
	$foam_c = hexcol( $im, $foam );
	draw_wave( $im, $size, (int) ( $size * 0.62 ), (int) ( $size * 0.055 ), $size * 0.5, $teal_c, (int) ( $size * 0.05 ) );
	draw_wave( $im, $size, (int) ( $size * 0.74 ), (int) ( $size * 0.04 ), $size * 0.42, $foam_c, (int) ( $size * 0.025 ) );

	// "CC" mark, large, centered above the waves.
	$white = imagecolorallocate( $im, 255, 255, 255 );
	centered_text( $im, $font, (int) ( $size * 0.34 ), $white, $size, (int) ( $size * 0.46 ), 'CC' );

	imagepng( $im, $path );
	imagedestroy( $im );
	echo "wrote $path\n";
}

function save_banner( $w, $h, $path ) {
	global $font, $navy_deep, $navy_mid, $teal, $foam;
	$im = imagecreatetruecolor( $w, $h );
	gradient_bg( $im, $w, $h, $navy_deep, $navy_mid );

	$teal_c = hexcol( $im, $teal );
	$foam_c = hexcol( $im, $foam );
	draw_wave( $im, $w, (int) ( $h * 0.78 ), (int) ( $h * 0.05 ), $w * 0.18, $teal_c, (int) ( $h * 0.03 ) );
	draw_wave( $im, $w, (int) ( $h * 0.88 ), (int) ( $h * 0.035 ), $w * 0.14, $foam_c, (int) ( $h * 0.015 ) );

	$white = imagecolorallocate( $im, 255, 255, 255 );
	$mist  = imagecolorallocate( $im, 176, 208, 214 );

	$title_size = (int) ( $h * 0.16 );
	$sub_size   = (int) ( $h * 0.075 );
	centered_text( $im, $font, $title_size, $white, $w, (int) ( $h * 0.42 ), 'Crawl Cove Connector' );
	centered_text( $im, $font, $sub_size, $mist, $w, (int) ( $h * 0.58 ), 'Push approved SEO fixes into Yoast or Rank Math' );

	imagepng( $im, $path );
	imagedestroy( $im );
	echo "wrote $path\n";
}

$out = __DIR__ . '/out';
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0755, true );
}

save_icon( 128, "$out/icon-128x128.png" );
save_icon( 256, "$out/icon-256x256.png" );
save_banner( 772, 250, "$out/banner-772x250.png" );
save_banner( 1544, 500, "$out/banner-1544x500.png" );
