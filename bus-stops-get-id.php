<?php

/**
 * Standalone helper: looks up the Digitransit GTFS ID (e.g. "HSL:1301126") for
 * a human-readable HSL stop code (e.g. "H1392", as printed on the bus stop
 * pole). The GTFS ID is what bus-stops.php uses as a key in $bus_stops.
 *
 * Get the human readable stop code from the physical bus stop or via the HSL app.
 *
 * Edit $bus_stop_human_readable_id below, then either load this file in a
 * browser or run it from the command line:
 *
 *   php bus-stops-get-id.php
 *
 * The script fetches every HSL stop via the Digitransit GraphQL API and
 * filters by `code` in PHP. The v2 schema has no stopByCode query, so
 * downloading the whole list and filtering locally is the simplest path.
 */

// #########################################################################

// Change these two!

// Your key - get it from https://digitransit.fi/en/developers/api-registration/
$digitransit_key = 'enter your key here';

// The stop code printed on the bus stop pole (H1392, H1388, E4144, Ki0412, …).
$bus_stop_human_readable_id = 'H1392';

// #########################################################################

// Same endpoint and key as bus-stops.php — keep in sync if you rotate them.
$hsl_base_url    = 'https://api.digitransit.fi/routing/v2/hsl/gtfs/v1';

// Render the output as plain text in a browser; CLI ignores the header.
if ( PHP_SAPI !== 'cli' ) {
	header( 'Content-Type: text/plain; charset=utf-8' );
}

$payload = json_encode( [ 'query' => '{ stops(name: "") { gtfsId code name } }' ] );

$context = stream_context_create( [
	'http' => [
		'method'        => 'POST',
		'header'        => implode( "\r\n", [
			'Content-Type: application/json',
			'Accept: application/json',
			'Accept-Language: sv',
			'digitransit-subscription-key: ' . $digitransit_key,
		] ) . "\r\n",
		'content'       => $payload,
		'timeout'       => 30,
		'ignore_errors' => true,
	],
] );

$response = @file_get_contents( $hsl_base_url, false, $context );

if ( $response === false ) {
	exit( "API request failed.\n" );
}

$data = json_decode( $response, true );

if ( ! isset( $data['data']['stops'] ) ) {
	exit( "Unexpected API response.\n" );
}

$matches = [];

foreach ( $data['data']['stops'] as $stop ) {
	if ( $stop['code'] === $bus_stop_human_readable_id ) {
		$matches[] = $stop;
	}
}

if ( empty( $matches ) ) {
	exit( "No stop found with code '{$bus_stop_human_readable_id}'.\n" );
}

echo 'Found ' . count( $matches ) . " stop(s) with code '{$bus_stop_human_readable_id}':\n\n";

foreach ( $matches as $match ) {
	echo "GTFS ID:  {$match['gtfsId']}\n";
	echo "Name:     {$match['name']}\n";
	echo "Code:     {$match['code']}\n";
	echo "\n";
}
