<?php
/**
 * Validation and grading rules.
 *
 * Plain PHP with no WordPress calls, so it can be unit-tested without WordPress.
 * Every method that accepts user input returns a list of human-readable errors
 * (empty list = valid) and never throws.
 */

defined( 'ABSPATH' ) || exit;

final class URP_Validator {

	const PASS_PERCENT = 40.0;

	/** Lower bound percentage => grade, checked from the top down. */
	const GRADE_BANDS = array(
		'A+' => 90.0,
		'A'  => 80.0,
		'B'  => 70.0,
		'C'  => 60.0,
		'D'  => 50.0,
		'E'  => 40.0,
	);

	const MAX_NAME_LENGTH = 191;

	public static function normalize_reg_no( string $value ): string {
		return strtoupper( trim( $value ) );
	}

	public static function normalize_subject_code( string $value ): string {
		return strtoupper( trim( $value ) );
	}

	/** e.g. UNI2026001 or CS-2026-014: letters, digits and dashes, 3–32 chars. */
	public static function is_valid_reg_no( string $value ): bool {
		return 1 === preg_match( '/^[A-Z0-9][A-Z0-9-]{2,31}$/', $value );
	}

	/** e.g. 2026-S1: letters, digits, dash and underscore, 1–32 chars. */
	public static function is_valid_term( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/', $value );
	}

	/** e.g. CS101: letters, digits and dashes, 2–32 chars. */
	public static function is_valid_subject_code( string $value ): bool {
		return 1 === preg_match( '/^[A-Z0-9][A-Z0-9-]{1,31}$/', $value );
	}

	/**
	 * @return string[] Errors; empty when marks and max marks are acceptable.
	 */
	public static function validate_marks( string $marks, string $max_marks ): array {
		$marks     = trim( $marks );
		$max_marks = trim( $max_marks );
		$errors    = array();

		if ( ! self::is_decimal( $marks ) ) {
			$errors[] = 'Marks must be a number, e.g. 72 or 72.5.';
		}
		if ( ! self::is_decimal( $max_marks ) || (float) $max_marks <= 0 || (float) $max_marks > 1000 ) {
			$errors[] = 'Max marks must be a number greater than 0 and at most 1000.';
		}
		if ( $errors ) {
			return $errors;
		}
		if ( (float) $marks > (float) $max_marks ) {
			$errors[] = 'Marks cannot be greater than max marks.';
		}
		return $errors;
	}

	/**
	 * @param array<string,string> $row Keys: reg_no, full_name, programme.
	 * @return string[]
	 */
	public static function validate_student( array $row ): array {
		$errors = array();
		$reg_no = self::normalize_reg_no( $row['reg_no'] ?? '' );
		$name   = trim( $row['full_name'] ?? '' );

		if ( ! self::is_valid_reg_no( $reg_no ) ) {
			$errors[] = 'Registration number must be 3–32 letters, digits or dashes.';
		}
		if ( '' === $name ) {
			$errors[] = 'Full name is required.';
		} elseif ( mb_strlen( $name ) > self::MAX_NAME_LENGTH ) {
			$errors[] = 'Full name is too long.';
		}
		if ( mb_strlen( trim( $row['programme'] ?? '' ) ) > self::MAX_NAME_LENGTH ) {
			$errors[] = 'Programme is too long.';
		}
		return $errors;
	}

	/**
	 * @param array<string,string> $row Keys: reg_no, term, subject_code, subject_name, marks, max_marks.
	 * @return string[]
	 */
	public static function validate_result( array $row ): array {
		$errors = array();

		if ( ! self::is_valid_reg_no( self::normalize_reg_no( $row['reg_no'] ?? '' ) ) ) {
			$errors[] = 'Registration number must be 3–32 letters, digits or dashes.';
		}
		if ( ! self::is_valid_term( trim( $row['term'] ?? '' ) ) ) {
			$errors[] = 'Term must be 1–32 letters, digits, dashes or underscores, e.g. 2026-S1.';
		}
		if ( ! self::is_valid_subject_code( self::normalize_subject_code( $row['subject_code'] ?? '' ) ) ) {
			$errors[] = 'Subject code must be 2–32 letters, digits or dashes.';
		}
		$subject_name = trim( $row['subject_name'] ?? '' );
		if ( '' === $subject_name ) {
			$errors[] = 'Subject name is required.';
		} elseif ( mb_strlen( $subject_name ) > self::MAX_NAME_LENGTH ) {
			$errors[] = 'Subject name is too long.';
		}

		$max_marks = trim( $row['max_marks'] ?? '' );
		return array_merge( $errors, self::validate_marks( $row['marks'] ?? '', '' === $max_marks ? '100' : $max_marks ) );
	}

	public static function percentage( float $marks, float $max_marks ): float {
		return $max_marks > 0 ? round( $marks / $max_marks * 100, 2 ) : 0.0;
	}

	public static function grade( float $percentage ): string {
		foreach ( self::GRADE_BANDS as $grade => $minimum ) {
			if ( $percentage >= $minimum ) {
				return $grade;
			}
		}
		return 'F';
	}

	public static function is_pass( float $percentage ): bool {
		return $percentage >= self::PASS_PERCENT;
	}

	private static function is_decimal( string $value ): bool {
		return 1 === preg_match( '/^\d{1,4}(\.\d{1,2})?$/', $value );
	}
}
