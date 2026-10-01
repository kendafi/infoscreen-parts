<?php

/**
 * This file fetches bus timetables for specific lines and bus stops from the
 * HSL (Digitransit) GraphQL API and saves them to bus-stops.html.
 * https://www.hsl.fi/en/hsl/open-data
 */

// Your Digitransit developer API key - get it from https://digitransit.fi/en/developers/api-registration/
define( 'DIGITRANSIT_API_KEY_PRIMARY', 'enter your key here' );

define( 'HSL_BASE_URL', 'https://api.digitransit.fi/routing/v2/hsl/gtfs/v1' );

// How many departures to show in the final HTML list.
define( 'HSL_DEPARTURE_LIMIT', 10 );

// Where to cache the rendered HTML, and how long to consider it fresh.
define( 'HSL_CACHE_FILE', __DIR__ . '/html/bus-stops.html' );
define( 'HSL_CACHE_TTL', 5 * 60 ); // 5 minutes, in seconds.

// Look for "Accept-Language" in this file to set language.

// Bus stops to list, and which busses to mention.
// Keys are Digitransit GTFS IDs ("HSL:<number>"), not the human-readable
// stop codes printed at the stop. The H-codes are read off the API at
// fetch time; the comments are just for human reference. Look up a GTFS
// ID by querying `stops(name: "...")` against the Digitransit API.
// See bus-stops-get-id.php for a helper script that finds GTFS IDs
// based on the human-readable stop codes.
$bus_stops = [
	// H1392, Lokkalantie
	'HSL:1301126' => [
		'description' => '(mot stan)',
		'buses' => [ 20, 25, 30 ]
	],
	// H1393, Lokkalantie
	'HSL:1301125' => [
		'description' => '(mot Munkshöjden)',
		'buses' => [ 20, 30, 52 ]
	],
	// H1388, Munkkiniemen aukio
	'HSL:1301122' => [
		'description' => '(mot Tripla)',
		'buses' => [ 500, 510 ]
	],
];

date_default_timezone_set( 'Europe/Helsinki' );

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

// POST a GraphQL query to the HSL endpoint and return the decoded response.
function fetch_hsl_api_data( $query ) {

	$payload = json_encode( [ 'query' => $query ] );

	$headers = [
		'Content-Type: application/json',
		'Accept: application/json',
		'Accept-Language: sv', // Stop names, headsigns and route names in Swedish.
		'digitransit-subscription-key: ' . DIGITRANSIT_API_KEY_PRIMARY,
	];

	$context = stream_context_create( [
		'http' => [
			'method'        => 'POST',
			'header'        => implode( "\r\n", $headers ) . "\r\n",
			'content'       => $payload,
			'timeout'       => 10,
			'ignore_errors' => true,
		],
	] );

	$response = file_get_contents( HSL_BASE_URL, false, $context );

	if ( $response === false ) {
		return null;
	}

	return json_decode( $response, true );

}

// Fetch upcoming departures for one stop, filtered to the lines we care about.
function fetch_stop_departures( $gtfs_id, $allowed_buses ) {

	// Ask for more departures than we need so we still have enough left after
	// filtering to the configured bus lines.
	$query = sprintf(
		'{
			stop(id: "%s") {
				name
				code
				stoptimesWithoutPatterns(numberOfDepartures: 30) {
					serviceDay
					scheduledDeparture
					realtimeDeparture
					realtime
					headsign
					trip {
						route {
							shortName
						}
					}
				}
			}
		}',
		$gtfs_id
	);

	$response = fetch_hsl_api_data( $query );

	if ( ! isset( $response['data']['stop'] ) ) {
		return null;
	}

	$stop = $response['data']['stop'];
	$allowed_buses = array_map( 'strval', $allowed_buses );
	$departures = [];

	foreach ( $stop['stoptimesWithoutPatterns'] as $stoptime ) {

		$line = (string) $stoptime['trip']['route']['shortName'];

		if ( ! in_array( $line, $allowed_buses, true ) ) {
			continue;
		}

		$departure_seconds = $stoptime['realtime']
			? $stoptime['realtimeDeparture']
			: $stoptime['scheduledDeparture'];

		$departures[] = [
			'line'      => $line,
			'headsign'  => $stoptime['headsign'],
			'timestamp' => $stoptime['serviceDay'] + $departure_seconds,
			'realtime'  => (bool) $stoptime['realtime'],
		];

	}

	return [
		'name'       => $stop['name'],
		'code'       => $stop['code'],
		'departures' => $departures,
	];

}

// If a recent cached HTML file exists, serve it without hitting the API.
if ( file_exists( HSL_CACHE_FILE ) && ( time() - filemtime( HSL_CACHE_FILE ) ) < HSL_CACHE_TTL ) {
	readfile( HSL_CACHE_FILE );
	return;
}

// Build one section per bus stop.
$now = time();
$output_html = '';

foreach ( $bus_stops as $gtfs_id => $stop_config ) {

	$stop_data = fetch_stop_departures( $gtfs_id, $stop_config['buses'] );

	if ( ! $stop_data ) {
		continue;
	}

	// Drop past departures, sort, and trim to the configured limit.
	$departures = array_filter( $stop_data['departures'], function ( $d ) use ( $now ) {
		return $d['timestamp'] >= $now;
	} );

	usort( $departures, function ( $a, $b ) {
		return $a['timestamp'] <=> $b['timestamp'];
	} );

	$departures = array_slice( $departures, 0, HSL_DEPARTURE_LIMIT );

	$output_html .= '<div class="bus-stop">';
	$output_html .= '<h2 class="bus-stop-heading">';
	$output_html .= '<span class="bus-stop-name">' . esc_html( $stop_data['name'] ) . '</span> ';
	$output_html .= '<span class="bus-stop-code">' . esc_html( $stop_data['code'] ) . '</span> ';
	$output_html .= '<span class="bus-stop-description">' . esc_html( $stop_config['description'] ) . '</span>';
	$output_html .= '</h2>';

	$output_html .= '<ul class="bus-stop-departures">';

	if ( empty( $departures ) ) {

		$output_html .= '<li class="departure no-departures">Inga kommande avgångar.</li>';

	} else {

		foreach ( $departures as $departure ) {

			$time = date( 'H:i', $departure['timestamp'] );
			$realtime_class = $departure['realtime'] ? ' is-realtime' : '';

			$output_html .= '<li class="departure' . $realtime_class . '">';
			$output_html .= '<span class="departure-time">' . esc_html( $time ) . '</span> ';
			$output_html .= '<strong class="departure-line">' . esc_html( $departure['line'] ) . '</strong> ';
			$output_html .= '<span class="departure-headsign">' . esc_html( $departure['headsign'] ) . '</span>';
			$output_html .= '</li>';

		}

	}

	$output_html .= '</ul>';
	$output_html .= '</div> <!-- bus-stop -->';

}

if ( $output_html != '' ) {

	$output_html .= '<p><small>Bus data is from <a href="https://digitransit.fi/en/developers/" target="_blank">HSL/Digitransit</a></small></p>';

}

// If the fetch produced no HTML (e.g. API outage), keep the previous cache
// in place and serve it as a stale fallback rather than overwriting it with
// blanks. Leaving mtime untouched means the next request will retry the API.
if ( $output_html === '' ) {

	if ( file_exists( HSL_CACHE_FILE ) ) {
		readfile( HSL_CACHE_FILE );
	}

	return;

}

echo $output_html;

file_put_contents( HSL_CACHE_FILE, $output_html );

?>
