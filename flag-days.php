<?php

/**
 * Finnish flag days, with Swedish names suited to a Finland-Swedish info display.
 *
 * Source: https://intermin.fi/sv/flagga-och-vapen/flaggdagar-och-tider
 */

date_default_timezone_set( 'Europe/Helsinki' );

if ( ! function_exists( 'flag_day_for' ) ) {

	/**
	 * Return the Swedish name of the flag day falling on the given date, or
	 * null if the date is not a flag day.
	 *
	 * @param int|null $timestamp Unix timestamp; defaults to the current time.
	 *                            Caller is responsible for setting the
	 *                            appropriate timezone (Europe/Helsinki).
	 * @return string|null
	 */
	function flag_day_for( $timestamp = null ) {

		if ( $timestamp === null ) {
			$timestamp = time();
		}

		$mmdd    = date( 'm-d', $timestamp );
		$month   = (int) date( 'n', $timestamp );
		$day     = (int) date( 'j', $timestamp );
		$weekday = (int) date( 'N', $timestamp ); // 1 = Mon ... 7 = Sun.

		// Fixed-date flag days (same calendar date every year).
		$fixed = [
			'02-05' => 'Runebergsdagen',
			'02-06' => 'Samernas nationaldag',
			'02-28' => 'Kalevaladagen, dagen för den finska kulturen',
			'03-19' => 'Minna Canths dag, dagen för jämlikhet',
			'04-09' => 'Mikael Agricolas dag, dagen för det finska språket',
			'04-27' => 'Nationella veterandagen',
			'05-01' => 'Första maj, det finska arbetets dag',
			'05-09' => 'Europadagen',
			'05-12' => 'J.V. Snellmans dag, dagen för det finska',
			'06-04' => 'Försvarets fanfest',
			'07-06' => 'Eino Leinos dag, diktningens och sommarens dag',
			'10-01' => 'Miina Sillanpää-dagen, medborgarinflytandets dag',
			'10-10' => 'Aleksis Kivis dag, den finska litteraturens dag',
			'10-24' => 'FN-dagen',
			'11-06' => 'Svenska dagen, Finlands svenska kulturdag',
			'11-20' => 'Barnkonventionens dag',
			'12-06' => 'Självständighetsdagen',
			'12-08' => 'Jean Sibeliusdagen, den finländska musikens dag',
		];

		if ( isset( $fixed[ $mmdd ] ) ) {
			return $fixed[ $mmdd ];
		}

		// Mors dag — andra söndagen i maj.
		if ( $month === 5 && $weekday === 7 && $day >= 8 && $day <= 14 ) {
			return 'Mors dag';
		}

		// De stupades dag — tredje söndagen i maj. (Flaggas på halv stång.)
		if ( $month === 5 && $weekday === 7 && $day >= 15 && $day <= 21 ) {
			return 'De stupades dag';
		}

		// Finlands flaggas dag — lördagen mellan 20 och 26 juni (midsommardagen).
		if ( $month === 6 && $weekday === 6 && $day >= 20 && $day <= 26 ) {
			return 'Finlands flaggas dag';
		}

		// Fars dag — andra söndagen i november.
		if ( $month === 11 && $weekday === 7 && $day >= 8 && $day <= 14 ) {
			return 'Fars dag';
		}

		return null;

	}

}

$today_flag_day = flag_day_for();

// test flag day output
//$testdate = strtotime( '2026-05-01' );
//$today_flag_day = flag_day_for( $testdate );

if ( $today_flag_day ) {

	echo '<p class="flag-day"><span aria-hidden="true"><img src="finland.png" alt=""></span> 
	<strong>Flaggdag:</strong> ' . htmlspecialchars( $today_flag_day, ENT_QUOTES, 'UTF-8' ) . '</p>';

}
else {

	echo '<!-- no flag day -->';

}

?>
