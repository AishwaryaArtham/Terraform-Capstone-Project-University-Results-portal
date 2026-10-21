<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase {

	public function test_reg_no_is_normalized_to_upper_case_without_spaces(): void {
		$this->assertSame( 'UNI2026001', URP_Validator::normalize_reg_no( '  uni2026001 ' ) );
	}

	#[DataProvider( 'reg_numbers' )]
	public function test_reg_no_format( string $reg_no, bool $valid ): void {
		$this->assertSame( $valid, URP_Validator::is_valid_reg_no( $reg_no ) );
	}

	public static function reg_numbers(): array {
		return array(
			'plain'          => array( 'UNI2026001', true ),
			'with dashes'    => array( 'CS-2026-014', true ),
			'too short'      => array( 'U1', false ),
			'leading dash'   => array( '-UNI2026', false ),
			'space inside'   => array( 'UNI 2026', false ),
			'sql characters' => array( "UNI' OR 1=1", false ),
			'too long'       => array( str_repeat( 'A', 33 ), false ),
		);
	}

	#[DataProvider( 'grades' )]
	public function test_grade_bands( float $percentage, string $grade ): void {
		$this->assertSame( $grade, URP_Validator::grade( $percentage ) );
	}

	public static function grades(): array {
		return array(
			array( 100.0, 'A+' ),
			array( 90.0, 'A+' ),
			array( 89.99, 'A' ),
			array( 70.0, 'B' ),
			array( 60.0, 'C' ),
			array( 50.0, 'D' ),
			array( 40.0, 'E' ),
			array( 39.99, 'F' ),
			array( 0.0, 'F' ),
		);
	}

	public function test_pass_mark_is_forty_percent(): void {
		$this->assertTrue( URP_Validator::is_pass( 40.0 ) );
		$this->assertFalse( URP_Validator::is_pass( 39.99 ) );
	}

	public function test_percentage_uses_max_marks(): void {
		$this->assertSame( 75.0, URP_Validator::percentage( 37.5, 50 ) );
		$this->assertSame( 0.0, URP_Validator::percentage( 10, 0 ) );
	}

	#[DataProvider( 'marks' )]
	public function test_marks_validation( string $marks, string $max, bool $valid ): void {
		$this->assertSame( $valid, array() === URP_Validator::validate_marks( $marks, $max ) );
	}

	public static function marks(): array {
		return array(
			'whole number'     => array( '72', '100', true ),
			'decimal'          => array( '72.5', '100', true ),
			'zero'             => array( '0', '100', true ),
			'full marks'       => array( '50', '50', true ),
			'above max'        => array( '101', '100', false ),
			'negative'         => array( '-1', '100', false ),
			'text'             => array( 'abc', '100', false ),
			'empty'            => array( '', '100', false ),
			'three decimals'   => array( '72.555', '100', false ),
			'zero max'         => array( '0', '0', false ),
			'max above limit'  => array( '10', '1001', false ),
		);
	}

	public function test_valid_student_row_has_no_errors(): void {
		$this->assertSame(
			array(),
			URP_Validator::validate_student( array( 'reg_no' => 'uni2026001', 'full_name' => 'Asha Rao', 'programme' => 'BSc' ) )
		);
	}

	public function test_student_row_requires_name_and_valid_reg_no(): void {
		$errors = URP_Validator::validate_student( array( 'reg_no' => '!!', 'full_name' => ' ' ) );
		$this->assertCount( 2, $errors );
	}

	public function test_valid_result_row_defaults_max_marks_to_100(): void {
		$row = array(
			'reg_no'       => 'UNI2026001',
			'term'         => '2026-S1',
			'subject_code' => 'cs101',
			'subject_name' => 'Programming',
			'marks'        => '88',
			'max_marks'    => '',
		);
		$this->assertSame( array(), URP_Validator::validate_result( $row ) );

		$row['marks'] = '101';
		$this->assertSame( array( 'Marks cannot be greater than max marks.' ), URP_Validator::validate_result( $row ) );
	}

	public function test_result_row_reports_every_problem(): void {
		$errors = URP_Validator::validate_result( array( 'term' => 'bad term!', 'marks' => 'x' ) );
		$this->assertCount( 5, $errors ); // reg_no, term, subject code, subject name, marks
	}
}
