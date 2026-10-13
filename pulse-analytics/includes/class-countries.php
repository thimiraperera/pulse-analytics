<?php
/**
 * Country and continent reference data (ISO 3166-1 alpha-2).
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Country names and continents.
 */
class Countries {

	/**
	 * Continent codes and names.
	 *
	 * @return array<string,string>
	 */
	public static function continents() {
		return array(
			'AF' => __( 'Africa', 'pulse-analytics' ),
			'AN' => __( 'Antarctica', 'pulse-analytics' ),
			'AS' => __( 'Asia', 'pulse-analytics' ),
			'EU' => __( 'Europe', 'pulse-analytics' ),
			'NA' => __( 'North America', 'pulse-analytics' ),
			'OC' => __( 'Oceania', 'pulse-analytics' ),
			'SA' => __( 'South America', 'pulse-analytics' ),
		);
	}

	/**
	 * All countries: code => array( name, continent ).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function all() {
		static $list = null;
		if ( null !== $list ) {
			return $list;
		}
		$raw  = 'AD|Andorra|EU;AE|United Arab Emirates|AS;AF|Afghanistan|AS;AG|Antigua and Barbuda|NA;AI|Anguilla|NA;AL|Albania|EU;AM|Armenia|AS;AO|Angola|AF;AQ|Antarctica|AN;AR|Argentina|SA;AS|American Samoa|OC;AT|Austria|EU;AU|Australia|OC;AW|Aruba|NA;AX|Åland Islands|EU;AZ|Azerbaijan|AS;'
			. 'BA|Bosnia and Herzegovina|EU;BB|Barbados|NA;BD|Bangladesh|AS;BE|Belgium|EU;BF|Burkina Faso|AF;BG|Bulgaria|EU;BH|Bahrain|AS;BI|Burundi|AF;BJ|Benin|AF;BL|Saint Barthélemy|NA;BM|Bermuda|NA;BN|Brunei|AS;BO|Bolivia|SA;BQ|Caribbean Netherlands|NA;BR|Brazil|SA;BS|Bahamas|NA;BT|Bhutan|AS;BV|Bouvet Island|AN;BW|Botswana|AF;BY|Belarus|EU;BZ|Belize|NA;'
			. 'CA|Canada|NA;CC|Cocos (Keeling) Islands|AS;CD|DR Congo|AF;CF|Central African Republic|AF;CG|Congo|AF;CH|Switzerland|EU;CI|Côte d\'Ivoire|AF;CK|Cook Islands|OC;CL|Chile|SA;CM|Cameroon|AF;CN|China|AS;CO|Colombia|SA;CR|Costa Rica|NA;CU|Cuba|NA;CV|Cape Verde|AF;CW|Curaçao|NA;CX|Christmas Island|AS;CY|Cyprus|EU;CZ|Czechia|EU;'
			. 'DE|Germany|EU;DJ|Djibouti|AF;DK|Denmark|EU;DM|Dominica|NA;DO|Dominican Republic|NA;DZ|Algeria|AF;EC|Ecuador|SA;EE|Estonia|EU;EG|Egypt|AF;EH|Western Sahara|AF;ER|Eritrea|AF;ES|Spain|EU;ET|Ethiopia|AF;FI|Finland|EU;FJ|Fiji|OC;FK|Falkland Islands|SA;FM|Micronesia|OC;FO|Faroe Islands|EU;FR|France|EU;'
			. 'GA|Gabon|AF;GB|United Kingdom|EU;GD|Grenada|NA;GE|Georgia|AS;GF|French Guiana|SA;GG|Guernsey|EU;GH|Ghana|AF;GI|Gibraltar|EU;GL|Greenland|NA;GM|Gambia|AF;GN|Guinea|AF;GP|Guadeloupe|NA;GQ|Equatorial Guinea|AF;GR|Greece|EU;GS|South Georgia and the South Sandwich Islands|AN;GT|Guatemala|NA;GU|Guam|OC;GW|Guinea-Bissau|AF;GY|Guyana|SA;'
			. 'HK|Hong Kong|AS;HM|Heard Island and McDonald Islands|AN;HN|Honduras|NA;HR|Croatia|EU;HT|Haiti|NA;HU|Hungary|EU;ID|Indonesia|AS;IE|Ireland|EU;IL|Israel|AS;IM|Isle of Man|EU;IN|India|AS;IO|British Indian Ocean Territory|AS;IQ|Iraq|AS;IR|Iran|AS;IS|Iceland|EU;IT|Italy|EU;JE|Jersey|EU;JM|Jamaica|NA;JO|Jordan|AS;JP|Japan|AS;'
			. 'KE|Kenya|AF;KG|Kyrgyzstan|AS;KH|Cambodia|AS;KI|Kiribati|OC;KM|Comoros|AF;KN|Saint Kitts and Nevis|NA;KP|North Korea|AS;KR|South Korea|AS;KW|Kuwait|AS;KY|Cayman Islands|NA;KZ|Kazakhstan|AS;LA|Laos|AS;LB|Lebanon|AS;LC|Saint Lucia|NA;LI|Liechtenstein|EU;LK|Sri Lanka|AS;LR|Liberia|AF;LS|Lesotho|AF;LT|Lithuania|EU;LU|Luxembourg|EU;LV|Latvia|EU;LY|Libya|AF;'
			. 'MA|Morocco|AF;MC|Monaco|EU;MD|Moldova|EU;ME|Montenegro|EU;MF|Saint Martin|NA;MG|Madagascar|AF;MH|Marshall Islands|OC;MK|North Macedonia|EU;ML|Mali|AF;MM|Myanmar|AS;MN|Mongolia|AS;MO|Macao|AS;MP|Northern Mariana Islands|OC;MQ|Martinique|NA;MR|Mauritania|AF;MS|Montserrat|NA;MT|Malta|EU;MU|Mauritius|AF;MV|Maldives|AS;MW|Malawi|AF;MX|Mexico|NA;MY|Malaysia|AS;MZ|Mozambique|AF;'
			. 'NA|Namibia|AF;NC|New Caledonia|OC;NE|Niger|AF;NF|Norfolk Island|OC;NG|Nigeria|AF;NI|Nicaragua|NA;NL|Netherlands|EU;NO|Norway|EU;NP|Nepal|AS;NR|Nauru|OC;NU|Niue|OC;NZ|New Zealand|OC;OM|Oman|AS;PA|Panama|NA;PE|Peru|SA;PF|French Polynesia|OC;PG|Papua New Guinea|OC;PH|Philippines|AS;PK|Pakistan|AS;PL|Poland|EU;PM|Saint Pierre and Miquelon|NA;PN|Pitcairn Islands|OC;PR|Puerto Rico|NA;PS|Palestine|AS;PT|Portugal|EU;PW|Palau|OC;PY|Paraguay|SA;'
			. 'QA|Qatar|AS;RE|Réunion|AF;RO|Romania|EU;RS|Serbia|EU;RU|Russia|EU;RW|Rwanda|AF;SA|Saudi Arabia|AS;SB|Solomon Islands|OC;SC|Seychelles|AF;SD|Sudan|AF;SE|Sweden|EU;SG|Singapore|AS;SH|Saint Helena|AF;SI|Slovenia|EU;SJ|Svalbard and Jan Mayen|EU;SK|Slovakia|EU;SL|Sierra Leone|AF;SM|San Marino|EU;SN|Senegal|AF;SO|Somalia|AF;SR|Suriname|SA;SS|South Sudan|AF;ST|São Tomé and Príncipe|AF;SV|El Salvador|NA;SX|Sint Maarten|NA;SY|Syria|AS;SZ|Eswatini|AF;'
			. 'TC|Turks and Caicos Islands|NA;TD|Chad|AF;TF|French Southern Territories|AN;TG|Togo|AF;TH|Thailand|AS;TJ|Tajikistan|AS;TK|Tokelau|OC;TL|Timor-Leste|AS;TM|Turkmenistan|AS;TN|Tunisia|AF;TO|Tonga|OC;TR|Türkiye|AS;TT|Trinidad and Tobago|NA;TV|Tuvalu|OC;TW|Taiwan|AS;TZ|Tanzania|AF;UA|Ukraine|EU;UG|Uganda|AF;UM|U.S. Outlying Islands|OC;US|United States|NA;UY|Uruguay|SA;UZ|Uzbekistan|AS;'
			. 'VA|Vatican City|EU;VC|Saint Vincent and the Grenadines|NA;VE|Venezuela|SA;VG|British Virgin Islands|NA;VI|U.S. Virgin Islands|NA;VN|Vietnam|AS;VU|Vanuatu|OC;WF|Wallis and Futuna|OC;WS|Samoa|OC;XK|Kosovo|EU;YE|Yemen|AS;YT|Mayotte|AF;ZA|South Africa|AF;ZM|Zambia|AF;ZW|Zimbabwe|AF;T1|Tor network|';
		$list = array();
		foreach ( explode( ';', rtrim( $raw, ';' ) ) as $entry ) {
			$parts             = explode( '|', $entry );
			$list[ $parts[0] ] = array( $parts[1], isset( $parts[2] ) ? $parts[2] : '' );
		}
		return $list;
	}

	/**
	 * Country name for a code.
	 *
	 * @param string $code ISO code.
	 * @return string
	 */
	public static function name( $code ) {
		$all = self::all();
		if ( '' === (string) $code ) {
			return __( 'Unknown', 'pulse-analytics' );
		}
		return isset( $all[ $code ] ) ? $all[ $code ][0] : (string) $code;
	}

	/**
	 * Continent code for a country.
	 *
	 * @param string $code ISO code.
	 * @return string
	 */
	public static function continent( $code ) {
		$all = self::all();
		return isset( $all[ $code ] ) ? $all[ $code ][1] : '';
	}

	/**
	 * Country codes on a continent.
	 *
	 * @param string $continent Continent code.
	 * @return string[]
	 */
	public static function in_continent( $continent ) {
		$codes = array();
		foreach ( self::all() as $code => $data ) {
			if ( $data[1] === $continent ) {
				$codes[] = $code;
			}
		}
		return $codes;
	}
}
