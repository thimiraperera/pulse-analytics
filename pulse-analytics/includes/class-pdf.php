<?php
/**
 * Small PDF writer used for the summary report.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes A4 documents with text in Helvetica, filled shapes, lines and JPEG images.
 *
 * Positions are in points from the top left corner of the page. Text is written in
 * the Windows-1252 character set of the standard PDF fonts, so characters outside it
 * (for example Sinhala or Tamil) come out as "?". Use representable() to check text
 * first and show something else instead.
 */
class Pdf {

	const WIDTH  = 595.28;
	const HEIGHT = 841.89;

	/**
	 * Helvetica character widths for codes 32 to 255 (thousandths of the font size).
	 */
	const WIDTHS_REGULAR = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500';

	/**
	 * Helvetica-Bold character widths for codes 32 to 255.
	 */
	const WIDTHS_BOLD = '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556';

	/**
	 * Content stream of each page.
	 *
	 * @var string[]
	 */
	private $pages = array();

	/**
	 * Embedded images: name => data, width, height.
	 *
	 * @var array
	 */
	private $images = array();

	/**
	 * Parsed width tables.
	 *
	 * @var array
	 */
	private static $widths = array();

	/**
	 * Starts a new page.
	 */
	public function add_page() {
		$this->pages[] = '';
	}

	/**
	 * Number of pages so far.
	 *
	 * @return int
	 */
	public function page_count() {
		return count( $this->pages );
	}

	/**
	 * Adds a drawing command to a page (the current one by default).
	 *
	 * @param string   $command PDF operators.
	 * @param int|null $page    Page index.
	 */
	private function out( $command, $page = null ) {
		$this->pages[ null === $page ? count( $this->pages ) - 1 : $page ] .= $command . "\n";
	}

	/**
	 * Writes a line of text. $y is the baseline.
	 *
	 * @param float  $x     Left edge (or right edge / centre, see align).
	 * @param float  $y     Baseline from the top of the page.
	 * @param string $text  UTF-8 text.
	 * @param array  $style size, bold, color, align (left, right, center) and page.
	 * @return float Width of the text.
	 */
	public function text( $x, $y, $text, array $style = array() ) {
		$style   = array_merge(
			array(
				'size'  => 10,
				'bold'  => false,
				'color' => '#1d2327',
				'align' => 'left',
				'page'  => null,
			),
			$style
		);
		$encoded = self::encode( $text );
		$width   = $this->measure( $encoded, $style['size'], $style['bold'] );
		if ( 'right' === $style['align'] ) {
			$x -= $width;
		} elseif ( 'center' === $style['align'] ) {
			$x -= $width / 2;
		}
		$this->out(
			sprintf( 'BT /%s %.2F Tf %s rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET', $style['bold'] ? 'F2' : 'F1', $style['size'], self::rgb( $style['color'] ), $x, self::HEIGHT - $y, self::escape( $encoded ) ),
			$style['page']
		);
		return $width;
	}

	/**
	 * Draws a rectangle.
	 *
	 * @param float  $x      Left.
	 * @param float  $y      Top.
	 * @param float  $w      Width.
	 * @param float  $h      Height.
	 * @param string $fill   Fill colour, or '' for none.
	 * @param string $stroke Border colour, or '' for none.
	 */
	public function rect( $x, $y, $w, $h, $fill, $stroke = '' ) {
		if ( '' === $fill && '' === $stroke ) {
			return;
		}
		$op = '' !== $fill && '' !== $stroke ? 'B' : ( '' !== $fill ? 'f' : 'S' );
		$this->out( sprintf( '%s%s0.6 w %.2F %.2F %.2F %.2F re %s', '' !== $fill ? self::rgb( $fill ) . ' rg ' : '', '' !== $stroke ? self::rgb( $stroke ) . ' RG ' : '', $x, self::HEIGHT - $y - $h, $w, $h, $op ) );
	}

	/**
	 * Draws a straight line.
	 *
	 * @param float  $x1    Start x.
	 * @param float  $y1    Start y.
	 * @param float  $x2    End x.
	 * @param float  $y2    End y.
	 * @param string $color Colour.
	 * @param float  $width Line width.
	 * @param bool   $dashed Dashed line.
	 */
	public function line( $x1, $y1, $x2, $y2, $color, $width = 0.5, $dashed = false ) {
		$this->out( sprintf( '%s RG %.2F w %s %.2F %.2F m %.2F %.2F l S [] 0 d', self::rgb( $color ), $width, $dashed ? '[3 2] 0 d' : '[] 0 d', $x1, self::HEIGHT - $y1, $x2, self::HEIGHT - $y2 ) );
	}

	/**
	 * Draws a line through points, optionally filling the area down to a baseline.
	 *
	 * @param array      $points   Pairs of x, y.
	 * @param string     $color    Line colour.
	 * @param float      $width    Line width.
	 * @param bool       $dashed   Dashed line.
	 * @param string     $fill     Area colour, or ''.
	 * @param float|null $baseline y of the area's bottom edge.
	 */
	public function polyline( array $points, $color, $width = 1, $dashed = false, $fill = '', $baseline = null ) {
		if ( count( $points ) < 2 ) {
			return;
		}
		if ( '' !== $fill && null !== $baseline ) {
			$area = sprintf( '%.2F %.2F m', $points[0][0], self::HEIGHT - $baseline );
			foreach ( $points as $p ) {
				$area .= sprintf( ' %.2F %.2F l', $p[0], self::HEIGHT - $p[1] );
			}
			$last  = end( $points );
			$area .= sprintf( ' %.2F %.2F l h f', $last[0], self::HEIGHT - $baseline );
			$this->out( self::rgb( $fill ) . ' rg ' . $area );
		}
		$path = '';
		foreach ( $points as $i => $p ) {
			$path .= sprintf( '%.2F %.2F %s ', $p[0], self::HEIGHT - $p[1], 0 === $i ? 'm' : 'l' );
		}
		$this->out( sprintf( '%s RG %.2F w 1 J 1 j %s %sS [] 0 d', self::rgb( $color ), $width, $dashed ? '[3 2] 0 d' : '[] 0 d', $path ) );
	}

	/**
	 * Registers a JPEG image.
	 *
	 * @param string $data JPEG bytes.
	 * @return string Image name, or '' when the data is not a usable JPEG.
	 */
	public function add_jpeg( $data ) {
		$info = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $data ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] || ( isset( $info['channels'] ) && 3 !== (int) $info['channels'] ) ) {
			return '';
		}
		$name                  = 'Im' . ( count( $this->images ) + 1 );
		$this->images[ $name ] = array(
			'data' => $data,
			'w'    => (int) $info[0],
			'h'    => (int) $info[1],
		);
		return $name;
	}

	/**
	 * Size of a registered image in pixels.
	 *
	 * @param string $name Image name.
	 * @return array{0:int,1:int}
	 */
	public function image_size( $name ) {
		return isset( $this->images[ $name ] ) ? array( $this->images[ $name ]['w'], $this->images[ $name ]['h'] ) : array( 0, 0 );
	}

	/**
	 * Places a registered image.
	 *
	 * @param string $name Image name.
	 * @param float  $x    Left.
	 * @param float  $y    Top.
	 * @param float  $w    Width.
	 * @param float  $h    Height.
	 */
	public function image( $name, $x, $y, $w, $h ) {
		if ( isset( $this->images[ $name ] ) ) {
			$this->out( sprintf( 'q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $w, $h, $x, self::HEIGHT - $y - $h, $name ) );
		}
	}

	/**
	 * Width of UTF-8 text in points.
	 *
	 * @param string $text Text.
	 * @param float  $size Font size.
	 * @param bool   $bold Bold.
	 * @return float
	 */
	public function width( $text, $size = 10, $bold = false ) {
		return $this->measure( self::encode( $text ), $size, $bold );
	}

	/**
	 * Shortens text with "..." so that it fits a width.
	 *
	 * @param string $text Text.
	 * @param float  $max  Available width.
	 * @param float  $size Font size.
	 * @param bool   $bold Bold.
	 * @return string
	 */
	public function fit( $text, $max, $size = 10, $bold = false ) {
		$text = (string) $text;
		if ( $this->width( $text, $size, $bold ) <= $max ) {
			return $text;
		}
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		while ( $chars && $this->width( implode( '', $chars ) . '...', $size, $bold ) > $max ) {
			array_pop( $chars );
		}
		return rtrim( implode( '', $chars ) ) . '...';
	}

	/**
	 * Width of already encoded text.
	 *
	 * @param string $encoded Windows-1252 text.
	 * @param float  $size    Font size.
	 * @param bool   $bold    Bold.
	 * @return float
	 */
	private function measure( $encoded, $size, $bold ) {
		$key = $bold ? 'bold' : 'regular';
		if ( ! isset( self::$widths[ $key ] ) ) {
			self::$widths[ $key ] = array_map( 'intval', explode( ',', $bold ? self::WIDTHS_BOLD : self::WIDTHS_REGULAR ) );
		}
		$total = 0;
		$len   = strlen( $encoded );
		for ( $i = 0; $i < $len; $i++ ) {
			$code   = ord( $encoded[ $i ] );
			$total += $code >= 32 ? self::$widths[ $key ][ $code - 32 ] : 0;
		}
		return $total * $size / 1000;
	}

	/**
	 * Whether text can be written without losing characters.
	 *
	 * @param string $text UTF-8 text.
	 * @return bool
	 */
	public static function representable( $text ) {
		$text = (string) $text;
		return substr_count( self::encode( $text ), '?' ) === substr_count( self::clean( $text ), '?' );
	}

	/**
	 * Converts UTF-8 text to Windows-1252; characters it does not have become "?".
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	public static function encode( $text ) {
		$text = self::clean( $text );
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$out = @mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} elseif ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			$out = preg_replace( '/[^\x20-\x7E]/', '?', $text );
		}
		return is_string( $out ) ? $out : '';
	}

	/**
	 * Decodes entities and replaces special spaces and line breaks.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clean( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		return str_replace( array( "\u{00A0}", "\u{202F}", "\u{2009}", "\r", "\n", "\t" ), ' ', $text );
	}

	/**
	 * Escapes text for a PDF string.
	 *
	 * @param string $text Encoded text.
	 * @return string
	 */
	private static function escape( $text ) {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
	}

	/**
	 * "#rrggbb" to PDF colour components.
	 *
	 * @param string $hex Colour.
	 * @return string
	 */
	private static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$hex = str_pad( substr( preg_replace( '/[^0-9a-f]/i', '', $hex ), 0, 6 ), 6, '0' );
		return sprintf( '%.3F %.3F %.3F', hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 );
	}

	/**
	 * The finished document.
	 *
	 * @param string $title Document title (any language).
	 * @return string PDF bytes.
	 */
	public function output( $title ) {
		if ( ! $this->pages ) {
			$this->add_page();
		}
		$objects  = array();
		$first    = 5 + count( $this->images );
		$kids     = array();
		$xobjects = '';
		foreach ( array_keys( $this->pages ) as $i ) {
			$kids[] = ( $first + $i * 2 ) . ' 0 R';
		}

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $this->pages ) . ' >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$id         = 5;
		foreach ( $this->images as $name => $image ) {
			$objects[ $id ] = '<< /Type /XObject /Subtype /Image /Width ' . $image['w'] . ' /Height ' . $image['h'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $image['data'] ) . " >>\nstream\n" . $image['data'] . "\nendstream";
			$xobjects      .= '/' . $name . ' ' . $id . ' 0 R ';
			++$id;
		}
		$resources = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ( '' !== $xobjects ? ' /XObject << ' . $xobjects . '>>' : '' ) . ' >>';
		$compress  = function_exists( 'gzcompress' );
		foreach ( $this->pages as $i => $content ) {
			$page_id                 = $first + $i * 2;
			$data                    = $compress ? gzcompress( $content ) : $content;
			$objects[ $page_id ]     = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '] /Resources ' . $resources . ' /Contents ' . ( $page_id + 1 ) . ' 0 R >>';
			$objects[ $page_id + 1 ] = '<< ' . ( $compress ? '/Filter /FlateDecode ' : '' ) . '/Length ' . strlen( $data ) . " >>\nstream\n" . $data . "\nendstream";
		}
		$info_id             = $first + count( $this->pages ) * 2;
		$objects[ $info_id ] = '<< /Title ' . self::unicode_string( $title ) . ' /Producer (Pulse Analytics) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		ksort( $objects );
		foreach ( $objects as $object_id => $body ) {
			$offsets[ $object_id ] = strlen( $pdf );
			$pdf                  .= $object_id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$size = $info_id + 1;
		$pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
		for ( $i = 1; $i < $size; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<< /Size " . $size . ' /Root 1 0 R /Info ' . $info_id . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}

	/**
	 * A PDF text string in UTF-16, for metadata in any language.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	private static function unicode_string( $text ) {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			return '(' . self::escape( self::encode( $text ) ) . ')';
		}
		return '<FEFF' . strtoupper( bin2hex( mb_convert_encoding( (string) $text, 'UTF-16BE', 'UTF-8' ) ) ) . '>';
	}
}
