<?php

/**
 * This file fetches a short-range weather forecast from the Finnish
 * Meteorological Institute (FMI) for a hardcoded coordinate and saves it
 * to weather.html.
 * https://en.ilmatieteenlaitos.fi/open-data
 */

define( 'FMI_BASE_URL', 'https://opendata.fmi.fi/wfs' );

// Forecast location, read off Google Maps. Adjust here if the tablet moves.
define( 'FMI_LATLON', '60.197472,24.879631' );

// How many hourly forecast steps to render.
define( 'FMI_FORECAST_HOURS', 12 );

// Where to cache the rendered HTML, and how long to consider it fresh.
define( 'WEATHER_CACHE_FILE', __DIR__ . '/html/weather.html' );
define( 'WEATHER_CACHE_TTL', 5 * 60 ); // 5 minutes, in seconds.

date_default_timezone_set( 'Europe/Helsinki' );

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

// FMI WeatherSymbol3 codes mapped to an emoji and a short Swedish description.
// The text is shown next to the emoji so the row stays readable even if a
// device renders the emoji as a tofu box.
// https://www.ilmatieteenlaitos.fi/latauspalvelun-pikaohje
function weather_symbol( $code ) {

	$map = [
		1  => [ 'emoji' => '☀️',  'text' => 'klart' ],
		2  => [ 'emoji' => '🌤️',  'text' => 'halvklart' ],
		3  => [ 'emoji' => '☁️',  'text' => 'mulet' ],
		21 => [ 'emoji' => '🌦️',  'text' => 'lätta regnskurar' ],
		22 => [ 'emoji' => '🌦️',  'text' => 'regnskurar' ],
		23 => [ 'emoji' => '🌧️',  'text' => 'kraftiga regnskurar' ],
		31 => [ 'emoji' => '🌧️',  'text' => 'lätt regn' ],
		32 => [ 'emoji' => '🌧️',  'text' => 'regn' ],
		33 => [ 'emoji' => '🌧️',  'text' => 'kraftigt regn' ],
		41 => [ 'emoji' => '🌨️',  'text' => 'lätta snöbyar' ],
		42 => [ 'emoji' => '🌨️',  'text' => 'snöbyar' ],
		43 => [ 'emoji' => '🌨️',  'text' => 'kraftiga snöbyar' ],
		51 => [ 'emoji' => '🌨️',  'text' => 'lätt snöfall' ],
		52 => [ 'emoji' => '🌨️',  'text' => 'snöfall' ],
		53 => [ 'emoji' => '❄️',  'text' => 'kraftigt snöfall' ],
		61 => [ 'emoji' => '⛈️',  'text' => 'åskskurar' ],
		62 => [ 'emoji' => '⛈️',  'text' => 'kraftiga åskskurar' ],
		71 => [ 'emoji' => '🌨️',  'text' => 'lätta byar av snöblandat regn' ],
		72 => [ 'emoji' => '🌨️',  'text' => 'byar av snöblandat regn' ],
		73 => [ 'emoji' => '🌨️',  'text' => 'kraftiga byar av snöblandat regn' ],
		81 => [ 'emoji' => '🌨️',  'text' => 'lätt snöblandat regn' ],
		82 => [ 'emoji' => '🌨️',  'text' => 'snöblandat regn' ],
		83 => [ 'emoji' => '🌨️',  'text' => 'kraftigt snöblandat regn' ],
		91 => [ 'emoji' => '🌫️',  'text' => 'dis' ],
		92 => [ 'emoji' => '🌫️',  'text' => 'dimma' ],
	];

	return isset( $map[ $code ] ) ? $map[ $code ] : null;

}

// Fetch and parse the FMI HARMONIE point forecast at the configured coordinates.
// Returns an array keyed by ISO timestamp, each value an associative array of
// parameter name => float value.
function fetch_fmi_forecast( $latlon, $hours ) {

	$params = [
		'service'        => 'WFS',
		'version'        => '2.0.0',
		'request'        => 'getFeature',
		'storedquery_id' => 'fmi::forecast::harmonie::surface::point::simple',
		'latlon'         => $latlon,
		'parameters'     => 'Temperature,WindSpeedMS,WeatherSymbol3,Precipitation1h',
		'timestep'       => 60,
		'endtime'        => gmdate( 'Y-m-d\TH:i:s\Z', time() + $hours * 3600 ),
	];

	$url = FMI_BASE_URL . '?' . http_build_query( $params );

	$response = @file_get_contents( $url );

	if ( $response === false ) {
		return [];
	}

	$xml = simplexml_load_string( $response );

	if ( ! $xml ) {
		return [];
	}

	$xml->registerXPathNamespace( 'BsWfs', 'http://xml.fmi.fi/schema/wfs/2.0' );
	$elements = $xml->xpath( '//BsWfs:BsWfsElement' );

	$by_time = [];

	foreach ( $elements as $el ) {

		$bs    = $el->children( 'http://xml.fmi.fi/schema/wfs/2.0' );
		$time  = (string) $bs->Time;
		$name  = (string) $bs->ParameterName;
		$value = (string) $bs->ParameterValue;

		// FMI returns NaN for missing values; treat those as absent.
		if ( $value === 'NaN' || $value === '' ) {
			continue;
		}

		$by_time[ $time ][ $name ] = (float) $value;

	}

	ksort( $by_time );

	return $by_time;

}

// If a recent cached HTML file exists, serve it without hitting the API.
if ( file_exists( WEATHER_CACHE_FILE ) && ( time() - filemtime( WEATHER_CACHE_FILE ) ) < WEATHER_CACHE_TTL ) {
	readfile( WEATHER_CACHE_FILE );
	return;
}

$forecast = fetch_fmi_forecast( FMI_LATLON, FMI_FORECAST_HOURS );

$output_html = '';

if ( ! empty( $forecast ) ) {

	$output_html .= '<h2>Väderprognos</h2>';

	// Codes whose emoji contains rain (🌦️ 🌧️ ⛈️), pure sun only (☀️ — no
	// clouds), or snow (🌨️ ❄️). Used to decide which large indicator emojis
	// to show below the title.
	$rain_codes = [ 21, 22, 23, 31, 32, 33, 61, 62 ];
	$sun_codes  = [ 1 ];
	$snow_codes = [ 41, 42, 43, 51, 52, 53, 71, 72, 73, 81, 82, 83 ];

	$categories_found = [];
	$list_html        = '<ul class="weather-forecast">';

	foreach ( $forecast as $iso_time => $values ) {

		// FMI returns UTC; date() formats it in the locally-set Helsinki zone.
		$ts         = strtotime( $iso_time );
		$time_label = date( 'H:i', $ts );

		$temperature = isset( $values['Temperature'] )
			? round( $values['Temperature'] ) . '°C'
			: '';

		$wind = isset( $values['WindSpeedMS'] )
			? round( $values['WindSpeedMS'] ) . ' m/s'
			: '';

		$symbol = isset( $values['WeatherSymbol3'] )
			? weather_symbol( (int) $values['WeatherSymbol3'] )
			: null;

		// Suppress "0.0 mm" noise — only show precipitation when notable.
		$precip = ( isset( $values['Precipitation1h'] ) && $values['Precipitation1h'] >= 0.1 )
			? number_format( $values['Precipitation1h'], 1 ) . ' mm'
			: '';

		$list_html .= '<li class="weather-hour">';
		$list_html .= '<span class="weather-time">' . esc_html( $time_label ) . '</span> ';
		$list_html .= '<span class="weather-temperature">' . esc_html( $temperature ) . '</span> ';

		if ( $symbol ) {

			$code = (int) $values['WeatherSymbol3'];

			if ( in_array( $code, $rain_codes, true ) ) {
				$categories_found['rain'] = true;
			} elseif ( in_array( $code, $sun_codes, true ) ) {
				$categories_found['sun'] = true;
			} elseif ( in_array( $code, $snow_codes, true ) ) {
				$categories_found['snow'] = true;
			}

			$list_html .= '<span class="weather-symbol">';
			$list_html .= '<span class="weather-symbol-emoji" aria-hidden="true">' . esc_html( $symbol['emoji'] ) . '</span> ';
			$list_html .= '<span class="weather-symbol-text">' . esc_html( $symbol['text'] ) . '</span>';
			$list_html .= '</span> ';
		}

		$list_html .= '<span class="weather-wind">' . esc_html( $wind ) . '</span>';

		if ( $precip !== '' ) {
			$list_html .= ' <span class="weather-precipitation">' . esc_html( $precip ) . '</span>';
		}

		$list_html .= '</li>';

	}

	$list_html .= '</ul>';

	// Big indicator emojis between the title and the per-hour list. Order is
	// fixed (rain, sun, snow) so the layout doesn't shuffle hour-by-hour.
	if ( ! empty( $categories_found ) ) {

		$output_html .= '<div class="weather-big-symbols" aria-hidden="true">';

		if ( isset( $categories_found['rain'] ) ) {
			$output_html .= '<span class="weather-big-symbol">🌂</span>';
		}

		if ( isset( $categories_found['sun'] ) ) {
			$output_html .= '<span class="weather-big-symbol">😎</span>';
		}

		if ( isset( $categories_found['snow'] ) ) {
			$output_html .= '<span class="weather-big-symbol">☃️</span>';
		}

		$output_html .= '</div>';

	}

	$output_html .= $list_html;

}

// If the fetch produced no HTML (e.g. API outage), keep the previous cache
// in place and serve it as a stale fallback rather than overwriting it with
// blanks. Leaving mtime untouched means the next request will retry the API.
if ( $output_html === '' ) {

	if ( file_exists( WEATHER_CACHE_FILE ) ) {
		readfile( WEATHER_CACHE_FILE );
	}

	return;

}

echo $output_html;

file_put_contents( WEATHER_CACHE_FILE, $output_html );

?>
