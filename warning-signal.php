<?php

/**
 * Finnish Alarm Test Notification: indicates whether today is the scheduled
 * monthly alarm test day in Finland.
 * Outputs nothing on the (vastly more common) non-test days, or if the
 * upstream call fails.
 *
 * Debug by adding ?debug=warningsignal to the URL like this:
 * index.php?debug=warningsignal
 * warning-signal.php?debug=warningsignal
 *
 */

date_default_timezone_set( 'Europe/Helsinki' );

// Set this as 'en' (English), 'fi' (Finnish) or 'sv' (Swedish). Debug messages will always be in English.
$output_language = 'fi';

// Where to cache the rendered HTML. Like name-day.php, freshness is measured
// per calendar day rather than as a fixed-seconds TTL, since the answer only
// changes at midnight.
define( 'WARNING_SIGNAL_CACHE_FILE', __DIR__ . '/html/warning-signal.html' );

// If the cached HTML was written on today's calendar date, serve it without
// hitting the endpoint. An empty cache file is a valid "no alarm today"
// result and serving it costs nothing.
if ( file_exists( WARNING_SIGNAL_CACHE_FILE )
	&& date( 'Y-m-d', filemtime( WARNING_SIGNAL_CACHE_FILE ) ) === date( 'Y-m-d' )
	&& !isset( $_GET['debug'] ) ) {
	readfile( WARNING_SIGNAL_CACHE_FILE );
	return;
}

function getFinnishPublicHolidays(int $year): array {
    // Fixed-date holidays that can fall on a Monday
    $holidays = [
        "$year-01-01", // New Year's Day
        "$year-01-06", // Epiphany
        "$year-05-01", // May Day (Labour Day)
        "$year-12-06", // Finnish Independence Day
        "$year-12-24", // Christmas Eve
        "$year-12-25", // Christmas Day
        "$year-12-26", // Boxing Day
    ];

    // Easter Monday (using anonymous Gregorian algorithm)
    // Good Friday, Easter Sunday, Ascension Day, Whit Sunday, Midsummer,
    // and All Saints' Day are omitted as they can never fall on a Monday.
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
    $easter = new DateTime("$year-$month-$day");

    $holidays[] = (clone $easter)->modify('+1 day')->format('Y-m-d'); // Easter Monday

    return $holidays;
}

function getAlarmMonday(): DateTime {
    $today = new DateTime();
    $year  = (int)$today->format('Y');
    $month = (int)$today->format('n');

    // Find the first Monday of the current month
    $monday = new DateTime("first monday of $year-$month");

    // Keep moving forward by a week if the Monday falls on a Finnish public holiday
    while (in_array($monday->format('Y-m-d'), getFinnishPublicHolidays((int)$monday->format('Y')))) {
        $monday->modify('+7 days');
    }

    return $monday;
}

$alarmMonday = getAlarmMonday();
$today       = new DateTime();
$isAlarmDay  = $today->format('Y-m-d') === $alarmMonday->format('Y-m-d');

if ( isset ( $_GET['debug'] ) && $_GET['debug'] == 'warningsignal' ) {

	// Here you can set a date to test it.
	$isAlarmDay = '2026-10-05';

	echo '<p style="color:red">Debugging warningsignal!</p>';

}

$warning_signal_data = [];

$output_html = '';

if ( $isAlarmDay ) {

	$alarm_emoji = '📢';

	$alarm_text  = [
		'en' => [
			'text' => 'Today is the first Monday of the month and the alarm system is tested at 12:00! The test sound is a steady tone lasting 7 seconds.',
			'link' => 'Read more about it!',
			'url' => 'https://pelastustoimi.fi/en/home-everyday-life/emergencies/alarm-signal'
		],
		'fi' => [
			'text' => 'Tänään on kuukauden ensimmäisenä arkimaanantai ja hälytysjärjestelmän testaus klo 12! Testausääni on 7 sekuntia kestävä tasainen ääni.',
			'link' => 'Lue siitä lisää!',
			'url' => 'https://pelastustoimi.fi/koti-ja-arki/hatatilanne/vaaramerkki'
		],
		'sv' => [
			'text' => 'Idag är det första vardagsmåndagen i månaden och larmsystemet testas klockan 12:00! Testljudet är en fast ton som varar i 7 sekunder.',
			'link' => 'Läs mera om det!',
			'url' => 'https://pelastustoimi.fi/sv/hem-och-vardag/nodsituation/farosignal'
		],
	];

	$output_html .= '<p class="warning-signal">';
	$output_html .= '<span class="warning-signal-emoji" aria-hidden="true">' . htmlspecialchars( $alarm_emoji, ENT_QUOTES, 'UTF-8' ) . '</span> ';
	$output_html .= '<span class="warning-signal-text">' . htmlspecialchars( $alarm_text[ $output_language ]['text'], ENT_QUOTES, 'UTF-8' ) . '</span> ';
	$output_html .= '<span class="warning-signal-link"><a href="' . $alarm_text[ $output_language ]['url'] . '" target="_blank">' . htmlspecialchars( $alarm_text[ $output_language ]['link'], ENT_QUOTES, 'UTF-8' ) . '</a></span>';
	$output_html .= '</p>';

	if ( isset( $_GET['debug'] ) && $_GET['debug'] == 'warningsignal' ) {

		echo '<p style="color:red">There is an alarm today (' . htmlspecialchars( $isAlarmDay , ENT_QUOTES, 'UTF-8' ) . ')!</p>';

		echo $output_html;

		// We exit to avoid writing anything to the cache file which would display the above content for those not debugging.
		return;

	}

}
elseif ( isset( $_GET['debug'] ) && $_GET['debug'] == 'warningsignal' ) {

	echo '<p style="color:red">No alarm today.</p>';

}

echo $output_html;

// Write the cache even when $output_html is empty: a successful "no alarm
// today" answer is worth caching so we don't refetch on every page load.
file_put_contents( WARNING_SIGNAL_CACHE_FILE, $output_html );

?>
