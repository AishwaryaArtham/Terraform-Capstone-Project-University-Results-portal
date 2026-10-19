<?php
/**
 * Reads and validates student/result CSV files.
 *
 * Plain PHP (unit-tested without WordPress). It never writes to the database:
 * callers show the preview, then save the valid rows through URP_Repository.
 * Uploaded files are read straight from PHP's temporary upload location and
 * are not stored, so imports don't depend on the server's uploads folder.
 */

defined( 'ABSPATH' ) || exit;

final class URP_CSV_Importer {

	const MAX_ROWS  = 5000;
	const MAX_BYTES = 1048576; // 1 MB

	const COLUMNS = array(
		'students' => array( 'reg_no', 'full_name', 'programme' ),
		'results'  => array( 'reg_no', 'term', 'subject_code', 'subject_name', 'marks', 'max_marks' ),
	);

	const OPTIONAL = array( 'programme', 'max_marks' );

	/**
	 * Parses and validates a CSV file.
	 *
	 * @param string   $type            'students' or 'results'.
	 * @param callable $reg_no_exists   fn(string[] $reg_nos): string[] returning the reg_nos that
	 *                                  exist as students. Only used for 'results'.
	 * @return array{
	 *   file_errors: string[],
	 *   rows: array<int,array{line:int,data:array<string,string>,errors:string[]}>,
	 *   valid: int,
	 *   invalid: int
	 * }
	 */
	public static function prepare( string $path, string $type, ?callable $reg_no_exists = null ): array {
		$report = array(
			'file_errors' => array(),
			'rows'        => array(),
			'valid'       => 0,
			'invalid'     => 0,
		);

		if ( ! isset( self::COLUMNS[ $type ] ) ) {
			$report['file_errors'][] = 'Unknown import type.';
			return $report;
		}

		$parsed = self::read( $path, $type );
		if ( $parsed['file_errors'] ) {
			$report['file_errors'] = $parsed['file_errors'];
			return $report;
		}

		$known = array();
		if ( 'results' === $type && $reg_no_exists ) {
			$reg_nos = array_map( static fn( $row ) => URP_Validator::normalize_reg_no( $row['data']['reg_no'] ), $parsed['rows'] );
			$known   = array_flip( $reg_no_exists( $reg_nos ) );
		}

		$seen = array();
		foreach ( $parsed['rows'] as $row ) {
			$data = self::normalize( $row['data'], $type );

			if ( 'students' === $type ) {
				$errors = URP_Validator::validate_student( $data );
				$key    = $data['reg_no'];
			} else {
				$errors = URP_Validator::validate_result( $data );
				$key    = $data['reg_no'] . '|' . $data['term'] . '|' . $data['subject_code'];
				if ( ! $errors && $reg_no_exists && ! isset( $known[ $data['reg_no'] ] ) ) {
					$errors[] = 'No student has this registration number. Import or add the student first.';
				}
			}

			if ( ! $errors && isset( $seen[ $key ] ) ) {
				$errors[] = sprintf( 'Duplicate of line %d in this file.', $seen[ $key ] );
			}
			$seen[ $key ] = $seen[ $key ] ?? $row['line'];

			$report['rows'][] = array(
				'line'   => $row['line'],
				'data'   => $data,
				'errors' => $errors,
			);
			$errors ? $report['invalid']++ : $report['valid']++;
		}

		if ( ! $report['rows'] ) {
			$report['file_errors'][] = 'The file has a header row but no data rows.';
		}

		return $report;
	}

	/**
	 * Makes a value safe to open in Excel/Sheets: a leading = + - @ would
	 * otherwise run as a formula (CSV injection).
	 */
	public static function safe_cell( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * @return array{file_errors: string[], rows: array<int,array{line:int,data:array<string,string>}>}
	 */
	private static function read( string $path, string $type ): array {
		$out = array(
			'file_errors' => array(),
			'rows'        => array(),
		);

		if ( ! is_readable( $path ) ) {
			$out['file_errors'][] = 'The file could not be read.';
			return $out;
		}
		if ( filesize( $path ) > self::MAX_BYTES ) {
			$out['file_errors'][] = 'The file is larger than 1 MB. Split it into smaller files.';
			return $out;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$header = fgetcsv( $handle, null, ',', '"', '' );
		if ( ! $header || array( null ) === $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out['file_errors'][] = 'The file is empty.';
			return $out;
		}

		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] ); // Excel's UTF-8 BOM
		$header    = array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), $header );

		$missing = array_diff( self::COLUMNS[ $type ], self::OPTIONAL, $header );
		if ( $missing ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out['file_errors'][] = sprintf(
				'Missing required column(s): %s. Expected header: %s',
				implode( ', ', $missing ),
				implode( ',', self::COLUMNS[ $type ] )
			);
			return $out;
		}

		$line = 1;
		while ( false !== ( $cells = fgetcsv( $handle, null, ',', '"', '' ) ) ) {
			++$line;
			if ( array( null ) === $cells || '' === trim( implode( '', $cells ) ) ) {
				continue; // blank line
			}
			if ( count( $out['rows'] ) >= self::MAX_ROWS ) {
				$out['file_errors'][] = sprintf( 'The file has more than %d data rows. Split it into smaller files.', self::MAX_ROWS );
				break;
			}
			$data = array();
			foreach ( $header as $i => $column ) {
				$data[ $column ] = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
			}
			$out['rows'][] = array(
				'line' => $line,
				'data' => $data,
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( $out['file_errors'] ) {
			$out['rows'] = array();
		}
		return $out;
	}

	/** Keeps only the known columns, trimmed, with codes upper-cased. */
	private static function normalize( array $data, string $type ): array {
		$clean = array();
		foreach ( self::COLUMNS[ $type ] as $column ) {
			$clean[ $column ] = self::strip_controls( trim( $data[ $column ] ?? '' ) );
		}
		$clean['reg_no'] = URP_Validator::normalize_reg_no( $clean['reg_no'] );
		if ( 'results' === $type ) {
			$clean['subject_code'] = URP_Validator::normalize_subject_code( $clean['subject_code'] );
			if ( '' === $clean['max_marks'] ) {
				$clean['max_marks'] = '100';
			}
		}
		return $clean;
	}

	private static function strip_controls( string $value ): string {
		return (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
	}
}
