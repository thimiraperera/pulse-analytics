<?php
/**
 * Dependency-free reader for MaxMind DB (.mmdb) files, used for DB-IP Lite and MaxMind GeoLite2.
 * Implements the MaxMind DB File Format Specification 2.0.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads records from a MaxMind DB file without loading it into memory.
 */
class MMDB_Reader {

	const METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";

	/**
	 * File handle.
	 *
	 * @var resource|null
	 */
	private $fh;

	/**
	 * Decoded metadata map.
	 *
	 * @var array
	 */
	private $metadata = array();

	/**
	 * Search tree parameters.
	 *
	 * @var int
	 */
	private $node_count = 0;
	private $record_size = 0;
	private $ip_version = 6;
	private $node_byte_size = 0;
	private $search_tree_size = 0;

	/**
	 * Base offset used when decoding (data section start or metadata start).
	 *
	 * @var int
	 */
	private $pointer_base = 0;

	/**
	 * Cached node where IPv4 addresses start in an IPv6 tree.
	 *
	 * @var int|null
	 */
	private $ipv4_start = null;

	/**
	 * Opens a database.
	 *
	 * @param string $file Path to the .mmdb file.
	 * @throws \RuntimeException When the file is missing or invalid.
	 */
	public function __construct( $file ) {
		if ( ! is_readable( $file ) ) {
			throw new \RuntimeException( 'Database file is not readable.' );
		}
		$this->fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->fh ) {
			throw new \RuntimeException( 'Database file could not be opened.' );
		}

		$size  = filesize( $file );
		$chunk = (int) min( $size, 131072 );
		fseek( $this->fh, $size - $chunk );
		$tail = fread( $this->fh, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$pos  = strrpos( (string) $tail, self::METADATA_MARKER );
		if ( false === $pos ) {
			throw new \RuntimeException( 'Not a MaxMind DB file (metadata marker missing).' );
		}
		$metadata_start = $size - $chunk + $pos + strlen( self::METADATA_MARKER );

		$this->pointer_base = $metadata_start;
		list( $metadata )   = $this->decode( $metadata_start );
		if ( ! is_array( $metadata ) || empty( $metadata['node_count'] ) || empty( $metadata['record_size'] ) ) {
			throw new \RuntimeException( 'Invalid MaxMind DB metadata.' );
		}

		$this->metadata         = $metadata;
		$this->node_count       = (int) $metadata['node_count'];
		$this->record_size      = (int) $metadata['record_size'];
		$this->ip_version       = isset( $metadata['ip_version'] ) ? (int) $metadata['ip_version'] : 6;
		$this->node_byte_size   = (int) ( $this->record_size / 4 );
		$this->search_tree_size = $this->node_count * $this->node_byte_size;
		$this->pointer_base     = $this->search_tree_size + 16;

		if ( ! in_array( $this->record_size, array( 24, 28, 32 ), true ) ) {
			throw new \RuntimeException( 'Unsupported record size.' );
		}
	}

	/**
	 * Closes the file.
	 */
	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->fh = null;
		}
	}

	/**
	 * Database metadata (database_type, build_epoch, languages, ...).
	 *
	 * @return array
	 */
	public function metadata() {
		return $this->metadata;
	}

	/**
	 * Looks up an IP address.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return array|null Record or null when not found.
	 */
	public function get( $ip ) {
		$packed = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return null;
		}
		$bit_count = strlen( $packed ) * 8;
		if ( 4 === $this->ip_version && 128 === $bit_count ) {
			return null;
		}

		$node = 32 === $bit_count ? $this->ipv4_start_node() : 0;
		for ( $i = 0; $i < $bit_count && $node < $this->node_count; $i++ ) {
			$bit  = ( ord( $packed[ $i >> 3 ] ) >> ( 7 - ( $i & 7 ) ) ) & 1;
			$node = $this->read_node( $node, $bit );
		}

		if ( $node <= $this->node_count ) {
			return null;
		}

		$offset = $node - $this->node_count + $this->search_tree_size;
		list( $record ) = $this->decode( $offset );
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Node where the IPv4 subtree starts (::/96 in an IPv6 tree).
	 *
	 * @return int
	 */
	private function ipv4_start_node() {
		if ( 4 === $this->ip_version ) {
			return 0;
		}
		if ( null === $this->ipv4_start ) {
			$node = 0;
			for ( $i = 0; $i < 96 && $node < $this->node_count; $i++ ) {
				$node = $this->read_node( $node, 0 );
			}
			$this->ipv4_start = $node;
		}
		return $this->ipv4_start;
	}

	/**
	 * Reads the left (0) or right (1) record of a node.
	 *
	 * @param int $node  Node number.
	 * @param int $index 0 or 1.
	 * @return int
	 */
	private function read_node( $node, $index ) {
		$base = $node * $this->node_byte_size;
		switch ( $this->record_size ) {
			case 24:
				$bytes = $this->read( $base + $index * 3, 3 );
				return unpack( 'N', "\x00" . $bytes )[1];
			case 28:
				if ( 0 === $index ) {
					$bytes = $this->read( $base, 4 );
					return ( ( ord( $bytes[3] ) & 0xF0 ) << 20 ) | unpack( 'N', "\x00" . substr( $bytes, 0, 3 ) )[1];
				}
				$bytes = $this->read( $base + 3, 4 );
				return ( ( ord( $bytes[0] ) & 0x0F ) << 24 ) | unpack( 'N', "\x00" . substr( $bytes, 1, 3 ) )[1];
			default:
				$bytes = $this->read( $base + $index * 4, 4 );
				return unpack( 'N', $bytes )[1];
		}
	}

	/**
	 * Reads raw bytes.
	 *
	 * @param int $offset Absolute file offset.
	 * @param int $length Number of bytes.
	 * @return string
	 * @throws \RuntimeException On short reads.
	 */
	private function read( $offset, $length ) {
		if ( 0 === $length ) {
			return '';
		}
		fseek( $this->fh, $offset );
		$data = fread( $this->fh, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( false === $data || strlen( $data ) !== $length ) {
			throw new \RuntimeException( 'Unexpected end of MaxMind DB file.' );
		}
		return $data;
	}

	/**
	 * Decodes a data field.
	 *
	 * @param int $offset Absolute file offset.
	 * @return array{0:mixed,1:int} Value and the offset after it.
	 * @throws \RuntimeException On invalid data.
	 */
	private function decode( $offset ) {
		$ctrl = ord( $this->read( $offset, 1 ) );
		++$offset;
		$type = $ctrl >> 5;

		if ( 1 === $type ) {
			$pointer_size = ( ( $ctrl >> 3 ) & 0x3 ) + 1;
			$buf          = $this->read( $offset, $pointer_size );
			$offset      += $pointer_size;
			switch ( $pointer_size ) {
				case 1:
					$pointer = unpack( 'n', chr( $ctrl & 0x7 ) . $buf )[1];
					break;
				case 2:
					$pointer = unpack( 'N', "\x00" . chr( $ctrl & 0x7 ) . $buf )[1] + 2048;
					break;
				case 3:
					$pointer = unpack( 'N', chr( $ctrl & 0x7 ) . $buf )[1] + 526336;
					break;
				default:
					$pointer = unpack( 'N', $buf )[1];
			}
			list( $value ) = $this->decode( $this->pointer_base + $pointer );
			return array( $value, $offset );
		}

		if ( 0 === $type ) {
			$type = 7 + ord( $this->read( $offset, 1 ) );
			++$offset;
		}

		$size = $ctrl & 0x1f;
		if ( $size >= 29 ) {
			$extra = $size - 28;
			$bytes = $this->read( $offset, $extra );
			$offset += $extra;
			if ( 29 === $size ) {
				$size = 29 + ord( $bytes );
			} elseif ( 30 === $size ) {
				$size = 285 + unpack( 'n', $bytes )[1];
			} else {
				$size = 65821 + unpack( 'N', "\x00" . $bytes )[1];
			}
		}

		switch ( $type ) {
			case 2: // UTF-8 string.
				return array( $this->read( $offset, $size ), $offset + $size );
			case 3: // Double.
				return array( unpack( 'E', $this->read( $offset, 8 ) )[1], $offset + 8 );
			case 4: // Bytes.
				return array( $this->read( $offset, $size ), $offset + $size );
			case 5: // uint16.
			case 6: // uint32.
			case 9: // uint64.
			case 10: // uint128.
				return array( $this->decode_uint( $this->read( $offset, $size ) ), $offset + $size );
			case 7: // Map.
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $key, $offset )   = $this->decode( $offset );
					list( $value, $offset ) = $this->decode( $offset );
					$map[ (string) $key ]   = $value;
				}
				return array( $map, $offset );
			case 8: // int32.
				$int = unpack( 'N', str_pad( $this->read( $offset, $size ), 4, "\x00", STR_PAD_LEFT ) )[1];
				if ( 4 === $size && $int >= 0x80000000 ) {
					$int -= 0x100000000;
				}
				return array( $int, $offset + $size );
			case 11: // Array.
				$array = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $value, $offset ) = $this->decode( $offset );
					$array[]                = $value;
				}
				return array( $array, $offset );
			case 14: // Boolean.
				return array( 0 !== $size, $offset );
			case 15: // Float.
				return array( unpack( 'G', $this->read( $offset, 4 ) )[1], $offset + 4 );
			case 12: // Data cache container.
			case 13: // End marker.
				return array( null, $offset );
		}
		throw new \RuntimeException( 'Unknown MaxMind DB data type ' . (int) $type . '.' );
	}

	/**
	 * Big-endian unsigned integer of any length (large values become floats/strings).
	 *
	 * @param string $bytes Raw bytes.
	 * @return int|float|string
	 */
	private function decode_uint( $bytes ) {
		$length = strlen( $bytes );
		if ( 0 === $length ) {
			return 0;
		}
		if ( $length <= 7 || ( 8 === $length && ord( $bytes[0] ) < 0x80 && PHP_INT_SIZE >= 8 ) ) {
			$int = 0;
			for ( $i = 0; $i < $length; $i++ ) {
				$int = ( $int << 8 ) | ord( $bytes[ $i ] );
			}
			return $int;
		}
		return bin2hex( $bytes );
	}
}
