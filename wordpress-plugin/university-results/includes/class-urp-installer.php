<?php
/**
 * Creates/upgrades the plugin's database tables and grants the admin capability.
 *
 * Safe to run repeatedly: dbDelta only applies differences, so this also runs
 * when a new EC2 instance boots against an existing RDS database.
 */

defined( 'ABSPATH' ) || exit;

final class URP_Installer {

	const CAPABILITY        = 'manage_urp_results';
	const DB_VERSION_OPTION = 'urp_db_version';

	public static function activate(): void {
		self::install();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== URP_DB_VERSION ) {
			self::install();
		}
	}

	public static function students_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'urp_students';
	}

	public static function results_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'urp_results';
	}

	private static function install(): void {
		self::create_tables();

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAPABILITY );
		}

		update_option( self::DB_VERSION_OPTION, URP_DB_VERSION );
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$students = self::students_table();
		$results  = self::results_table();

		// dbDelta is strict: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$students} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  reg_no varchar(32) NOT NULL,
  full_name varchar(191) NOT NULL,
  programme varchar(191) NOT NULL DEFAULT '',
  access_code_hash varchar(255) NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY reg_no (reg_no)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$results} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  student_id bigint(20) unsigned NOT NULL,
  term varchar(32) NOT NULL,
  subject_code varchar(32) NOT NULL,
  subject_name varchar(191) NOT NULL,
  marks decimal(6,2) NOT NULL,
  max_marks decimal(6,2) NOT NULL DEFAULT '100.00',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY student_term_subject (student_id,term,subject_code),
  KEY term (term)
) {$charset};"
		);
	}
}
