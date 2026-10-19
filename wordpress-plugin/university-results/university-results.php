<?php
/**
 * Plugin Name:       University Results Portal
 * Description:       Administrators import and manage students and marks; students look up their own published results with a registration number and access code.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            AWS Restart Capstone
 * License:           GPL-2.0-or-later
 * Text Domain:       university-results
 */

defined( 'ABSPATH' ) || exit;

define( 'URP_VERSION', '0.1.0' );
// Bump when the table definitions in URP_Installer change; triggers dbDelta on next load.
define( 'URP_DB_VERSION', '1' );
define( 'URP_PLUGIN_FILE', __FILE__ );
define( 'URP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once URP_PLUGIN_DIR . 'includes/class-urp-installer.php';
require_once URP_PLUGIN_DIR . 'includes/class-urp-validator.php';
require_once URP_PLUGIN_DIR . 'includes/class-urp-repository.php';
require_once URP_PLUGIN_DIR . 'includes/class-urp-csv-importer.php';
require_once URP_PLUGIN_DIR . 'includes/class-urp-access-codes.php';
require_once URP_PLUGIN_DIR . 'includes/class-urp-rate-limiter.php';
require_once URP_PLUGIN_DIR . 'includes/class-urp-lookup-shortcode.php';

register_activation_hook( __FILE__, array( 'URP_Installer', 'activate' ) );
add_action( 'plugins_loaded', array( 'URP_Installer', 'maybe_upgrade' ) );

URP_Lookup_Shortcode::register();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once URP_PLUGIN_DIR . 'includes/class-urp-cli.php';
	WP_CLI::add_command( 'urp', 'URP_CLI' );
}

if ( is_admin() ) {
	require_once URP_PLUGIN_DIR . 'includes/class-urp-admin.php';
	( new URP_Admin() )->register();

	add_action(
		'admin_enqueue_scripts',
		static function ( string $hook ): void {
			if ( str_contains( $hook, 'urp-' ) ) {
				wp_enqueue_style( 'urp-admin', plugins_url( 'assets/css/admin.css', URP_PLUGIN_FILE ), array(), URP_VERSION );
			}
		}
	);
}
