<?php
/**
 * All database access for the plugin lives here.
 *
 * Every query that includes user input goes through $wpdb->prepare(); table
 * names come from URP_Installer, never from input. Times are stored in UTC.
 */

defined( 'ABSPATH' ) || exit;

final class URP_Repository {

	const PUBLISHED_TERMS_OPTION = 'urp_published_terms';

	/* ---------------------------------------------------------------- Students */

	public static function find_student( int $id ): ?array {
		global $wpdb;
		$table = URP_Installer::students_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) ?: null;
	}

	public static function find_student_by_reg_no( string $reg_no ): ?array {
		global $wpdb;
		$table = URP_Installer::students_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE reg_no = %s", $reg_no ), ARRAY_A ) ?: null;
	}

	/**
	 * @return array{0: array<int,array>, 1: int} Rows for the page and the total match count.
	 */
	public static function search_students( string $search, int $page, int $per_page ): array {
		global $wpdb;
		$table   = URP_Installer::students_table();
		$results = URP_Installer::results_table();
		$where   = '1=1';
		$args    = array();

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where = '(s.reg_no LIKE %s OR s.full_name LIKE %s)';
			$args  = array( $like, $like );
		}

		$total = (int) $wpdb->get_var(
			$args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} s WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$table} s"
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, (SELECT COUNT(*) FROM {$results} r WHERE r.student_id = s.id) AS result_count
				 FROM {$table} s WHERE {$where} ORDER BY s.reg_no LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, max( 0, $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);

		return array( $rows, $total );
	}

	/**
	 * Inserts a student, or updates name/programme when the reg_no already exists.
	 * The access code is never touched here.
	 *
	 * @return string 'created', 'updated' or 'unchanged'.
	 */
	public static function save_student( string $reg_no, string $full_name, string $programme ): string {
		global $wpdb;
		$table = URP_Installer::students_table();
		$now   = current_time( 'mysql', true );

		// updated_at is assigned first so it compares against the old values.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (reg_no, full_name, programme, created_at, updated_at)
				 VALUES (%s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE
				   updated_at = IF(full_name <=> VALUES(full_name) AND programme <=> VALUES(programme), updated_at, VALUES(updated_at)),
				   full_name = VALUES(full_name),
				   programme = VALUES(programme)",
				$reg_no,
				$full_name,
				$programme,
				$now,
				$now
			)
		);

		return self::upsert_outcome( $affected );
	}

	public static function update_student( int $id, string $full_name, string $programme ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			URP_Installer::students_table(),
			array(
				'full_name'  => $full_name,
				'programme'  => $programme,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Deletes the student and all of their results. */
	public static function delete_student( int $id ): void {
		global $wpdb;
		$wpdb->delete( URP_Installer::results_table(), array( 'student_id' => $id ), array( '%d' ) );
		$wpdb->delete( URP_Installer::students_table(), array( 'id' => $id ), array( '%d' ) );
	}

	public static function set_access_code_hash( int $id, ?string $hash ): void {
    global $wpdb;

    $result = $wpdb->update(
        URP_Installer::students_table(),
        array(
            'access_code_hash' => $hash,
            'updated_at'       => current_time( 'mysql', true ),
        ),
        array( 'id' => $id ),
        array( '%s', '%s' ),
        array( '%d' )
    );

    if ( false === $result ) {
        throw new RuntimeException(
            'Failed to update student access code hash.'
        );
    }
}

	/** @return array<int,array{id:string,reg_no:string,full_name:string}> */
	public static function students_for_codes( bool $only_missing ): array {
		global $wpdb;
		$table = URP_Installer::students_table();
		$where = $only_missing ? "WHERE access_code_hash IS NULL OR access_code_hash = ''" : '';
		return $wpdb->get_results( "SELECT id, reg_no, full_name FROM {$table} {$where} ORDER BY reg_no", ARRAY_A );
	}

	/**
	 * @param string[] $reg_nos
	 * @return array<string,int> reg_no => student id, for those that exist.
	 */
	public static function student_ids_by_reg_no( array $reg_nos ): array {
		global $wpdb;
		$reg_nos = array_values( array_unique( $reg_nos ) );
		if ( ! $reg_nos ) {
			return array();
		}
		$table        = URP_Installer::students_table();
		$placeholders = implode( ',', array_fill( 0, count( $reg_nos ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, reg_no FROM {$table} WHERE reg_no IN ({$placeholders})", $reg_nos ),
			ARRAY_A
		);
		return array_map( 'intval', array_column( $rows, 'id', 'reg_no' ) );
	}

	/* ----------------------------------------------------------------- Results */

	public static function find_result( int $id ): ?array {
		global $wpdb;
		$results  = URP_Installer::results_table();
		$students = URP_Installer::students_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.*, s.reg_no, s.full_name FROM {$results} r JOIN {$students} s ON s.id = r.student_id WHERE r.id = %d",
				$id
			),
			ARRAY_A
		) ?: null;
	}

	/**
	 * @return array{0: array<int,array>, 1: int}
	 */
	public static function list_results( string $reg_no, string $term, int $page, int $per_page ): array {
		global $wpdb;
		$results  = URP_Installer::results_table();
		$students = URP_Installer::students_table();
		$where    = array( '1=1' );
		$args     = array();

		if ( '' !== $reg_no ) {
			$where[] = 's.reg_no = %s';
			$args[]  = $reg_no;
		}
		if ( '' !== $term ) {
			$where[] = 'r.term = %s';
			$args[]  = $term;
		}
		$where = implode( ' AND ', $where );
		$from  = "FROM {$results} r JOIN {$students} s ON s.id = r.student_id WHERE {$where}";

		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) {$from}", $args ) : "SELECT COUNT(*) {$from}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, s.reg_no, s.full_name {$from} ORDER BY r.term DESC, s.reg_no, r.subject_code LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, max( 0, $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);

		return array( $rows, $total );
	}

	/**
	 * Inserts a result, or updates it when the student already has that subject in that term.
	 *
	 * @return string 'created', 'updated' or 'unchanged'.
	 */
	public static function save_result( int $student_id, string $term, string $subject_code, string $subject_name, string $marks, string $max_marks ): string {
		global $wpdb;
		$table = URP_Installer::results_table();
		$now   = current_time( 'mysql', true );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (student_id, term, subject_code, subject_name, marks, max_marks, created_at, updated_at)
				 VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE
				   updated_at = IF(subject_name <=> VALUES(subject_name) AND marks <=> VALUES(marks) AND max_marks <=> VALUES(max_marks), updated_at, VALUES(updated_at)),
				   subject_name = VALUES(subject_name),
				   marks = VALUES(marks),
				   max_marks = VALUES(max_marks)",
				$student_id,
				$term,
				$subject_code,
				$subject_name,
				$marks,
				$max_marks,
				$now,
				$now
			)
		);

		return self::upsert_outcome( $affected );
	}

	public static function update_result( int $id, string $subject_name, string $marks, string $max_marks ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			URP_Installer::results_table(),
			array(
				'subject_name' => $subject_name,
				'marks'        => $marks,
				'max_marks'    => $max_marks,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function delete_result( int $id ): void {
		global $wpdb;
		$wpdb->delete( URP_Installer::results_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Results for one student, limited to the given terms.
	 *
	 * @param string[] $terms
	 */
	public static function results_for_student( int $student_id, array $terms ): array {
		global $wpdb;
		if ( ! $terms ) {
			return array();
		}
		$table        = URP_Installer::results_table();
		$placeholders = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term, subject_code, subject_name, marks, max_marks FROM {$table}
				 WHERE student_id = %d AND term IN ({$placeholders}) ORDER BY term DESC, subject_code",
				array_merge( array( $student_id ), array_values( $terms ) )
			),
			ARRAY_A
		);
	}

	/** @return array<int,array{term:string,result_count:string,student_count:string}> */
	public static function terms(): array {
		global $wpdb;
		$table = URP_Installer::results_table();
		return $wpdb->get_results(
			"SELECT term, COUNT(*) AS result_count, COUNT(DISTINCT student_id) AS student_count FROM {$table} GROUP BY term ORDER BY term DESC",
			ARRAY_A
		);
	}

	/** @return string[] Every programme used by at least one student, A–Z. */
	public static function programmes(): array {
		global $wpdb;
		$table = URP_Installer::students_table();
		return $wpdb->get_col( "SELECT DISTINCT programme FROM {$table} WHERE programme <> '' ORDER BY programme" );
	}

	/**
	 * Subjects already used in each term, for the admin dropdowns.
	 *
	 * @return array<string,array<int,array{code:string,name:string}>> term => subjects sorted by code
	 */
	public static function subjects_by_term(): array {
		global $wpdb;
		$table = URP_Installer::results_table();
		$rows  = $wpdb->get_results(
			"SELECT term, subject_code, MAX(subject_name) AS subject_name FROM {$table} GROUP BY term, subject_code ORDER BY term DESC, subject_code",
			ARRAY_A
		);
		$terms = array();
		foreach ( $rows as $row ) {
			$terms[ $row['term'] ][] = array(
				'code' => $row['subject_code'],
				'name' => $row['subject_name'],
			);
		}
		return $terms;
	}

	public static function count_students(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . URP_Installer::students_table() );
	}

	public static function count_results(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . URP_Installer::results_table() );
	}

	/* --------------------------------------------------------------- Publishing */

	/** @return string[] Terms whose results students are allowed to see. */
	public static function published_terms(): array {
		$terms = get_option( self::PUBLISHED_TERMS_OPTION, array() );
		return is_array( $terms ) ? array_values( $terms ) : array();
	}

	public static function set_term_published( string $term, bool $published ): void {
		$terms = array_diff( self::published_terms(), array( $term ) );
		if ( $published ) {
			$terms[] = $term;
		}
		sort( $terms );
		update_option( self::PUBLISHED_TERMS_OPTION, array_values( $terms ), false );
	}

	/** MySQL reports 1 affected row for an insert, 2 for an update, 0 for no change. */
	private static function upsert_outcome( $affected ): string {
		if ( false === $affected ) {
			return 'error';
		}
		return array( 0 => 'unchanged', 1 => 'created' )[ (int) $affected ] ?? 'updated';
	}
}
