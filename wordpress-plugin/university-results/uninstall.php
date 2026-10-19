<?php
/**
 * Runs when the plugin is deleted from wp-admin.
 *
 * Student data is KEPT by default so an accidental delete cannot wipe results.
 * To really remove everything, add this to wp-config.php before deleting:
 *     define( 'URP_DELETE_DATA_ON_UNINSTALL', true );
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( wp_roles()->role_objects as $role ) {
	$role->remove_cap( 'manage_urp_results' );
}

if ( ! defined( 'URP_DELETE_DATA_ON_UNINSTALL' ) || true !== URP_DELETE_DATA_ON_UNINSTALL ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}urp_results" );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}urp_students" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'urp_db_version' );
delete_option( 'urp_published_terms' );
