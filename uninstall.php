<?php
/**
 * Removes the reviewer capability always; removes tables and settings only when Settings → "Delete all feedback" is ticked.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$smbfb_sites = is_multisite() ? get_sites( array( 'fields' => 'ids' ) ) : array( get_current_blog_id() );

foreach ( $smbfb_sites as $smbfb_site ) {
	if ( is_multisite() ) {
		switch_to_blog( $smbfb_site );
	}

	foreach ( wp_roles()->role_objects as $smbfb_role ) {
		$smbfb_role->remove_cap( 'smbfb_manage_feedback' );
	}

	$smbfb_settings = get_option( 'smbfb_settings', array() );
	if ( ! empty( $smbfb_settings['delete_on_uninstall'] ) ) {
		foreach ( array( 'mentions', 'replies', 'items' ) as $smbfb_table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'smbfb_' . $smbfb_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( 'smbfb_settings' );
		delete_option( 'smbfb_db_version' );
	}

	if ( is_multisite() ) {
		restore_current_blog();
	}
}
