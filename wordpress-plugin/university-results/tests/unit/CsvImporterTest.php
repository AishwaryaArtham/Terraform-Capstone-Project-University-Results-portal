<?php

use PHPUnit\Framework\TestCase;

final class CsvImporterTest extends TestCase {

	/** @var string[] */
	private array $files = array();

	protected function tearDown(): void {
		array_map( 'unlink', $this->files );
	}

	private function csv( string $contents ): string {
		$path          = tempnam( sys_get_temp_dir(), 'urp' );
		$this->files[] = $path;
		file_put_contents( $path, $contents );
		return $path;
	}

	public function test_valid_students_file(): void {
		$report = URP_CSV_Importer::prepare(
			$this->csv( "reg_no,full_name,programme\nuni2026001, Asha Rao ,BSc\nUNI2026002,Ben Ito,\n" ),
			'students'
		);

		$this->assertSame( array(), $report['file_errors'] );
		$this->assertSame( 2, $report['valid'] );
		$this->assertSame( 0, $report['invalid'] );
		$this->assertSame(
			array( 'reg_no' => 'UNI2026001', 'full_name' => 'Asha Rao', 'programme' => 'BSc' ),
			$report['rows'][0]['data']
		);
	}

	public function test_header_is_case_insensitive_and_ignores_excel_bom_and_column_order(): void {
		$report = URP_CSV_Importer::prepare( $this->csv( "\xEF\xBB\xBFFull_Name, REG_NO\nAsha Rao,UNI2026001\n" ), 'students' );
		$this->assertSame( 1, $report['valid'] );
		$this->assertSame( 'UNI2026001', $report['rows'][0]['data']['reg_no'] );
	}

	public function test_missing_required_column_rejects_whole_file(): void {
		$report = URP_CSV_Importer::prepare( $this->csv( "reg_no,name\nUNI2026001,Asha\n" ), 'students' );
		$this->assertStringContainsString( 'full_name', $report['file_errors'][0] );
		$this->assertSame( array(), $report['rows'] );
	}

	public function test_empty_and_header_only_files(): void {
		$this->assertSame( array( 'The file is empty.' ), URP_CSV_Importer::prepare( $this->csv( '' ), 'students' )['file_errors'] );
		$this->assertSame(
			array( 'The file has a header row but no data rows.' ),
			URP_CSV_Importer::prepare( $this->csv( "reg_no,full_name\n\n" ), 'students' )['file_errors']
		);
	}

	public function test_invalid_rows_are_reported_with_line_numbers_and_valid_rows_kept(): void {
		$report = URP_CSV_Importer::prepare(
			$this->csv( "reg_no,full_name\nUNI2026001,Asha\n!!,\nUNI2026001,Asha again\n" ),
			'students'
		);

		$this->assertSame( 1, $report['valid'] );
		$this->assertSame( 2, $report['invalid'] );
		$this->assertSame( 3, $report['rows'][1]['line'] );
		$this->assertCount( 2, $report['rows'][1]['errors'] );
		$this->assertSame( array( 'Duplicate of line 2 in this file.' ), $report['rows'][2]['errors'] );
	}

	public function test_results_require_known_students_and_default_max_marks(): void {
		$path   = $this->csv(
			"reg_no,term,subject_code,subject_name,marks,max_marks\n" .
			"UNI2026001,2026-S1,cs101,Programming,88,\n" .
			"UNI9999999,2026-S1,CS101,Programming,70,100\n" .
			"UNI2026001,2026-S1,CS102,Databases,120,100\n"
		);
		$report = URP_CSV_Importer::prepare( $path, 'results', static fn( array $reg_nos ) => array_intersect( $reg_nos, array( 'UNI2026001' ) ) );

		$this->assertSame( 1, $report['valid'] );
		$this->assertSame( '100', $report['rows'][0]['data']['max_marks'] );
		$this->assertSame( 'CS101', $report['rows'][0]['data']['subject_code'] );
		$this->assertStringContainsString( 'No student', $report['rows'][1]['errors'][0] );
		$this->assertSame( array( 'Marks cannot be greater than max marks.' ), $report['rows'][2]['errors'] );
	}

	public function test_quoted_values_with_commas(): void {
		$report = URP_CSV_Importer::prepare( $this->csv( "reg_no,full_name,programme\nUNI2026001,\"Rao, Asha\",\"BSc, Hons\"\n" ), 'students' );
		$this->assertSame( 'Rao, Asha', $report['rows'][0]['data']['full_name'] );
	}

	public function test_safe_cell_neutralises_spreadsheet_formulas(): void {
		$this->assertSame( "'=HYPERLINK(\"x\")", URP_CSV_Importer::safe_cell( '=HYPERLINK("x")' ) );
		$this->assertSame( "'-1", URP_CSV_Importer::safe_cell( '-1' ) );
		$this->assertSame( 'ABCDE-12345', URP_CSV_Importer::safe_cell( 'ABCDE-12345' ) );
	}
}
