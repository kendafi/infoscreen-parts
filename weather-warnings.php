<?php

/**
 * Fetches FMI's currently-valid weather warnings from the CAP Atom feed and
 * renders only those whose <areaDesc> mentions a Helsinki-area keyword, so a
 * thunderstorm in Lappi doesn't get displayed next to a calm Helsinki forecast.
 * The Swedish-language feed is used because the rest of the dashboard is Swedish.
 *
 * Feed: https://alerts.fmi.fi/cap/feed/atom_sv-FI.xml
 * Licence: CC BY 4.0 (Ilmatieteen laitos / FMI). Attribution required.
 *
 * Debug by adding ?debug=true to the URL like:
 * weather-warnings.php?debug=true
 *
 */

define( 'WEATHER_WARNINGS_CACHE_FILE', __DIR__ . '/html/weather-warnings.html' );
define( 'WEATHER_WARNINGS_CACHE_TTL', 30 * 60 ); // 30 minutes, in seconds.

// Helsinki-area keywords. Matched case-insensitively as substrings against
// each warning's <areaDesc> blob. Generous list — false positives are
// preferable to silently dropping a relevant warning.
$weather_warnings_area_keywords = [
	'Nyland',
	'huvudstadsregionen',
	'södra Finland',
	'sydöstra Finland',
	'sydvästra Finland',
	'Finska viken',
	'Helsingfors',
];

// If a recent cached HTML file exists, serve it without hitting the feed.
if ( file_exists( WEATHER_WARNINGS_CACHE_FILE )
	&& ( time() - filemtime( WEATHER_WARNINGS_CACHE_FILE ) ) < WEATHER_WARNINGS_CACHE_TTL
	&& !isset( $_GET['debug'] )) {
	readfile( WEATHER_WARNINGS_CACHE_FILE );
	return;
}

$weather_warnings_context = stream_context_create( [
	'http' => [
		'timeout'       => 5,
		'ignore_errors' => true,
	],
] );

$weather_warnings_response = @file_get_contents( 'https://alerts.fmi.fi/cap/feed/atom_sv-FI.xml', false, $weather_warnings_context );

// If the fetch failed or returned unparseable XML, leave any existing cache
// in place and serve it as a stale fallback rather than blanking the column
// on a brief outage.
if ( $weather_warnings_response === false ) {

	if ( isset( $_GET['debug'] ) ) {

		echo '<p>Fetching data failed.</p>';

	}

	if ( file_exists( WEATHER_WARNINGS_CACHE_FILE ) ) {
		readfile( WEATHER_WARNINGS_CACHE_FILE );
	}

	return;

}

if ( isset( $_GET['debug'] ) ) {

	echo '<p>Fetching data was a success!</p>';

}

$weather_warnings_atom = @simplexml_load_string( $weather_warnings_response );

if ( ! $weather_warnings_atom ) {

	if ( isset( $_GET['debug'] ) ) {

		echo '<p>Loading XML failed.</p>';

	}

	if ( file_exists( WEATHER_WARNINGS_CACHE_FILE ) ) {
		readfile( WEATHER_WARNINGS_CACHE_FILE );
	}

	return;

}

if ( isset( $_GET['debug'] ) ) {

	echo '<p>Loading XML was a success!</p>';

}

$cap_namespace            = 'urn:oasis:names:tc:emergency:cap:1.2';
$weather_warnings_matched = [];

foreach ( $weather_warnings_atom->entry as $atom_entry ) {

	// The CAP <alert> is embedded inline under Atom's <content type="text/xml">.
	$cap_children = $atom_entry->content->children( $cap_namespace );

	if ( ! isset( $cap_children->alert ) ) {
		continue;
	}

	$cap_alert = $cap_children->alert;

	// Each <alert> contains multiple <info> blocks, one per language. We only
	// want the sv-FI variant: its <description>, <onset>, <expires>, the
	// `color` parameter, and the translated <areaDesc>s used for filtering.
	$sv_info = null;

	foreach ( $cap_alert->info as $cap_info ) {
		if ( (string) $cap_info->language === 'sv-FI' ) {
			$sv_info = $cap_info;
			break;
		}
	}

	if ( $sv_info === null ) {
		continue;
	}

	// Area filter — Helsinki-region keywords against the Swedish areaDescs
	// only, since the keyword list is in Swedish.
	$area_descs = [];

	foreach ( $sv_info->area as $cap_area ) {
		$area_descs[] = (string) $cap_area->areaDesc;
	}

	$area_blob = implode( ' | ', $area_descs );

	$is_local = false;

	foreach ( $weather_warnings_area_keywords as $keyword ) {
		if ( stripos( $area_blob, $keyword ) !== false ) {
			$is_local = true;
			break;
		}
	}

	if ( ! $is_local ) {
		continue;
	}

	$warning_description = (string) $sv_info->description;
	$warning_onset       = (string) $sv_info->onset;
	$warning_expires     = (string) $sv_info->expires;

	// The CAP <parameter> with valueName=color carries FMI's awareness level:
	// 'yellow', 'orange', or 'red'. ('green' is conceptual — the feed simply
	// omits warnings rather than publishing a green one.)
	$warning_color = '';

	foreach ( $sv_info->parameter as $cap_parameter ) {
		if ( (string) $cap_parameter->valueName === 'color' ) {
			$warning_color = (string) $cap_parameter->value;
			break;
		}
	}

	// Dedupe across feed entries that re-publish the same underlying warning.
	$dedupe_key = $warning_description . '|' . $warning_onset . '|' . $warning_expires;

	if ( isset( $weather_warnings_matched[ $dedupe_key ] ) ) {
		continue;
	}

	$weather_warnings_matched[ $dedupe_key ] = [
		'description' => $warning_description,
		'onset'       => $warning_onset,
		'expires'     => $warning_expires,
		'color'       => $warning_color,
	];

}

$output_html = '';

if ( ! empty( $weather_warnings_matched ) ) {

	if ( isset( $_GET['debug'] ) ) {

		echo '<p>We have some warnings matching our keywords!</p>';

	}

	// Swedish weekday abbreviations, keyed by date('N') (1=Mon..7=Sun), used
	// to format the warning's onset/expires time range FMI-style.
	$weather_warnings_weekdays = [
		1 => 'måndag',
		2 => 'tisdag',
		3 => 'onsdag',
		4 => 'torsdag',
		5 => 'fredag',
		6 => 'lördag',
		7 => 'söndag',
	];

	// FMI awareness levels in display order, with their Swedish tooltip text.
	$weather_warnings_levels = [
		'green'  => 'Ej fara',
		'yellow' => 'Möjligtvis farlig',
		'orange' => 'Farlig',
		'red'    => 'Mycket farlig',
	];

	$weather_warnings_timezone = new DateTimeZone( 'Europe/Helsinki' );

	$output_html .= '<div class="weather-warnings">';
	$output_html .= '<h3 class="weather-warnings-heading">Vädervarningar</h3>';

	foreach ( $weather_warnings_matched as $warning ) {

		// Level swatch row — the matching color gets warning-level-active.
		$output_html .= '<div class="weather-warning">Nivå: ';
		$output_html .= '<span class="warning-levels">';

		foreach ( $weather_warnings_levels as $level_color => $level_label ) {

			$level_class = 'warning-level-' . $level_color;

			if ( $level_color === $warning['color'] ) {
				$level_class .= ' warning-level-active';
			}

			$output_html .= '<span class="' . $level_class . '" title="' . htmlspecialchars( $level_label, ENT_QUOTES, 'UTF-8' ) . '"></span>';

		}

		$output_html .= '</span>';

		// Time range: "to 12.00 – 21.00" when same day, "to 22.00 – fr 06.00"
		// across midnight. Onset/expires are ISO-8601 with timezone.
		$onset_dt   = new DateTime( $warning['onset'] );
		$expires_dt = new DateTime( $warning['expires'] );
		$onset_dt->setTimezone( $weather_warnings_timezone );
		$expires_dt->setTimezone( $weather_warnings_timezone );

		$onset_weekday = $weather_warnings_weekdays[ (int) $onset_dt->format( 'N' ) ];

		if ( $onset_dt->format( 'Y-m-d' ) === $expires_dt->format( 'Y-m-d' ) ) {
			$time_range = $onset_weekday . ' ' . $onset_dt->format( 'H.i' ) . ' – ' . $expires_dt->format( 'H.i' );
		} else {
			$expires_weekday = $weather_warnings_weekdays[ (int) $expires_dt->format( 'N' ) ];
			$time_range      = $onset_weekday . ' ' . $onset_dt->format( 'H.i' ) . ' – ' . $expires_weekday . ' ' . $expires_dt->format( 'H.i' );
		}

		$output_html .= '<p class="weather-warning-when">' . htmlspecialchars( $time_range, ENT_QUOTES, 'UTF-8' ) . '</p>';
		$output_html .= '<p class="weather-warning-text">' . htmlspecialchars( $warning['description'], ENT_QUOTES, 'UTF-8' ) . '</p>';
		$output_html .= '</div>';

	}

	$output_html .= '</div>';

}
elseif ( isset( $_GET['debug'] ) ) {

	echo '<p>There were no warnings matching our keywords.</p>';

}

echo $output_html;

// Write the cache even when no warnings match: a successful "all clear"
// fetch is worth caching so we don't refetch a 500 KB feed on every page load.
file_put_contents( WEATHER_WARNINGS_CACHE_FILE, $output_html );

?>
