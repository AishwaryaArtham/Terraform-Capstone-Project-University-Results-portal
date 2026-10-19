<?php
/**
 * WP-CLI commands for scripted setup, testing and server administration.
 *
 *     wp urp status
 *     wp urp import students /path/students.csv [--dry-run]
 *     wp urp import results  /path/results.csv  [--dry-run]
 *     wp urp codes [--all] [--reg-no=<reg_no>] > codes.csv
 *     wp urp publish 2026-S1
 *     wp urp unpublish 2026-S1
 */

defined( 'ABSPATH' ) || exit;

final class URP_CLI {

	/**
	 * Shows record counts and which terms are published.
	 */
	public function status(): void {
		$published = URP_Repository::published_terms();
		WP_CLI::log( sprintf( 'Schema version: %s', get_option( URP_Installer::DB_VERSION_OPTION ) ) );
		WP_CLI::log( sprintf( 'Students: %d (%d without access code)', URP_Repository::count_students(), count( URP_Repository::students_for_codes( true ) ) ) );
		WP_CLI::log( sprintf( 'Results:  %d', URP_Repository::count_results() ) );
		foreach ( URP_Repository::terms() as $row ) {
			WP_CLI::log( sprintf( '  %-12s %4d results  %s', $row['term'], $row['result_count'], in_array( $row['term'], $published, true ) ? 'PUBLISHED' : 'not published' ) );
		}
	}

	/**
	 * Imports students or results from a CSV file. Use - to read from STDIN.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : students or results
	 *
	 * <file>
	 * : Path to the CSV file, or - for STDIN.
	 *
	 * [--dry-run]
	 * : Validate and report without saving.
	 */
	public function import( array $args, array $assoc_args ): void {
		list( $type, $file ) = $args;
		$temp                = null;

		if ( '-' === $file ) {
			$temp = wp_tempnam( 'urp-import' );
			file_put_contents( $temp, stream_get_contents( STDIN ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$file = $temp;
		}

		$report = URP_CSV_Importer::prepare(
			$file,
			$type,
			static fn( array $reg_nos ) => array_keys( URP_Repository::student_ids_by_reg_no( $reg_nos ) )
		);
		if ( $temp ) {
			wp_delete_file( $temp );
		}

		foreach ( $report['file_errors'] as $error ) {
			WP_CLI::error( $error, false );
		}
		if ( $report['file_errors'] ) {
			WP_CLI::halt( 1 );
		}

		foreach ( $report['rows'] as $row ) {
			if ( $row['errors'] ) {
				WP_CLI::warning( sprintf( 'Line %d skipped: %s', $row['line'], implode( ' ', $row['errors'] ) ) );
			}
		}

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			WP_CLI::success( sprintf( 'Dry run: %d valid, %d invalid. Nothing saved.', $report['valid'], $report['invalid'] ) );
			return;
		}

		$counts = array( 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0 );
		$ids    = 'results' === $type ? URP_Repository::student_ids_by_reg_no( array_column( array_column( $report['rows'], 'data' ), 'reg_no' ) ) : array();
		foreach ( $report['rows'] as $row ) {
			if ( $row['errors'] ) {
				continue;
			}
			$d = $row['data'];
			++$counts[
				'students' === $type
					? URP_Repository::save_student( $d['reg_no'], $d['full_name'], $d['programme'] )
					: URP_Repository::save_result( $ids[ $d['reg_no'] ], $d['term'], $d['subject_code'], $d['subject_name'], $d['marks'], $d['max_marks'] )
			];
		}

		WP_CLI::success(
			sprintf(
				'%s: %d created, %d updated, %d unchanged, %d skipped, %d failed.',
				ucfirst( $type ),
				$counts['created'],
				$counts['updated'],
				$counts['unchanged'],
				$report['invalid'],
				$counts['error']
			)
		);
		if ( $report['invalid'] || $counts['error'] ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Issues access codes and prints them as CSV on STDOUT. Redirect to a file
	 * and keep it private. By default only students without a code get one.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Issue new codes for every student (old codes stop working).
	 *
	 * [--reg-no=<reg_no>]
	 * : Issue a new code for just this student.
	 */
	public function codes( array $args, array $assoc_args ): void {
		$reg_no = $assoc_args['reg-no'] ?? '';
		if ( '' !== $reg_no ) {
			$student = URP_Repository::find_student_by_reg_no( URP_Validator::normalize_reg_no( $reg_no ) );
			if ( ! $student ) {
				WP_CLI::error( 'No student has that registration number.' );
			}
			$students = array( $student );
		} else {
			$students = URP_Repository::students_for_codes( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) );
		}

		if ( ! $students ) {
			WP_CLI::warning( 'Every student already has an access code. Use --all or --reg-no to reissue.' );
			return;
		}

		$out = fopen( 'php://stdout', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'reg_no', 'full_name', 'access_code' ), ',', '"', '' );
		foreach ( $students as $student ) {
			$code = URP_Access_Codes::generate();
			URP_Repository::set_access_code_hash( (int) $student['id'], URP_Access_Codes::hash( $code ) );
			fputcsv( $out, array( URP_CSV_Importer::safe_cell( $student['reg_no'] ), URP_CSV_Importer::safe_cell( $student['full_name'] ), $code ), ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Makes a term's results visible to students.
	 *
	 * <term>
	 * : Term, e.g. 2026-S1
	 */
	public function publish( array $args ): void {
		$this->set_published( $args[0], true );
	}

	/**
	 * Hides a term's results from students.
	 *
	 * <term>
	 * : Term, e.g. 2026-S1
	 */
	public function unpublish( array $args ): void {
		$this->set_published( $args[0], false );
	}

	private function set_published( string $term, bool $published ): void {
		if ( ! URP_Validator::is_valid_term( $term ) ) {
			WP_CLI::error( 'Invalid term.' );
		}
		URP_Repository::set_term_published( $term, $published );
		WP_CLI::success( sprintf( 'Term %s %s.', $term, $published ? 'published' : 'unpublished' ) );
	}
}
