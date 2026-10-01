<?php

/**
 * Computes today's sunrise and sunset times for a hardcoded coordinate and
 * renders them as a small block, intended to sit just above the Väderprognos
 * title. Uses PHP's built-in date_sun_info() — no network call — so this can
 * never fail from upstream outage. The cache file exists only to match the
 * "include and forget" pattern of the other renderers; the underlying
 * computation is deterministic given (date, latlon).
 */

// Forecast location. Kept in sync with FMI_LATLON in weather.php so the sun
// times match the weather column's geography.
define( 'SUN_LATITUDE',  60.197472 );
define( 'SUN_LONGITUDE', 24.879631 );

// Where to cache the rendered HTML. Freshness is per calendar day since the
// rise/set times only change at midnight.
define( 'SUN_CACHE_FILE', __DIR__ . '/html/sun.html' );

date_default_timezone_set( 'Europe/Helsinki' );

// If the cached HTML was written on today's calendar date, serve it.
if ( file_exists( SUN_CACHE_FILE )
	&& date( 'Y-m-d', filemtime( SUN_CACHE_FILE ) ) === date( 'Y-m-d' ) ) {
	readfile( SUN_CACHE_FILE );
	return;
}

$sun_info = date_sun_info( time(), SUN_LATITUDE, SUN_LONGITUDE );

// date_sun_info() returns booleans (not timestamps) on polar day/night when
// the sun never crosses the horizon. Guard against that so the output stays
// well-formed at high latitudes.
$sunrise = ( isset( $sun_info['sunrise'] ) && is_int( $sun_info['sunrise'] ) )
	? date( 'H:i', $sun_info['sunrise'] )
	: '';

$sunset = ( isset( $sun_info['sunset'] ) && is_int( $sun_info['sunset'] ) )
	? date( 'H:i', $sun_info['sunset'] )
	: '';

$output_html = '';

if ( $sunrise !== '' && $sunset !== '' ) {
	$output_html .= '<p class="sun-times">Solen ';
	$output_html .= '<span class="sun-rise"><span aria-hidden="true">🌅</span> ' . htmlspecialchars( $sunrise, ENT_QUOTES, 'UTF-8' ) . '</span> ';
	$output_html .= '<span class="sun-set"><span aria-hidden="true">🌇</span> ' . htmlspecialchars( $sunset, ENT_QUOTES, 'UTF-8' ) . '</span>';
	$output_html .= '</p>';
}

echo $output_html;

file_put_contents( SUN_CACHE_FILE, $output_html );

?>
