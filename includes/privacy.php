<?php
/**
 * Tools → Export/Erase Personal Data: feedback and replies left under an email address.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wp_privacy_personal_data_exporters', 'smbfb_register_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', 'smbfb_register_eraser' );

function smbfb_register_exporter( $exporters ) {
	$exporters['smbworks-feedback'] = array(
		'exporter_friendly_name' => __( 'Feedback', 'smbworks-feedback' ),
		'callback'               => 'smbfb_privacy_export',
	);
	return $exporters;
}

function smbfb_register_eraser( $erasers ) {
	$erasers['smbworks-feedback'] = array(
		'eraser_friendly_name' => __( 'Feedback', 'smbworks-feedback' ),
		'callback'             => 'smbfb_privacy_erase',
	);
	return $erasers;
}

function smbfb_privacy_export( $email, $page = 1 ) {
	global $wpdb;
	$per   = 100;
	$items = smbfb_table( 'items' );
	$reps  = smbfb_table( 'replies' );
	$data  = array();

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, comment, page_url, created_at FROM $items WHERE author_email = %s ORDER BY id LIMIT %d OFFSET %d", $email, $per, ( $page - 1 ) * $per ) );
	foreach ( $rows as $row ) {
		$data[] = array(
			'group_id'    => 'smbfb-items',
			'group_label' => __( 'Feedback', 'smbworks-feedback' ),
			'item_id'     => 'smbfb-item-' . $row->id,
			'data'        => array(
				array(
					'name'  => __( 'Page', 'smbworks-feedback' ),
					'value' => $row->page_url,
				),
				array(
					'name'  => __( 'Feedback', 'smbworks-feedback' ),
					'value' => $row->comment,
				),
				array(
					'name'  => __( 'Date', 'smbworks-feedback' ),
					'value' => $row->created_at,
				),
			),
		);
	}

	$replies = $wpdb->get_results( $wpdb->prepare( "SELECT id, item_id, body, created_at FROM $reps WHERE author_email = %s ORDER BY id LIMIT %d OFFSET %d", $email, $per, ( $page - 1 ) * $per ) );
	// phpcs:enable
	foreach ( $replies as $row ) {
		$data[] = array(
			'group_id'    => 'smbfb-replies',
			'group_label' => __( 'Feedback replies', 'smbworks-feedback' ),
			'item_id'     => 'smbfb-reply-' . $row->id,
			'data'        => array(
				array(
					'name'  => __( 'Reply', 'smbworks-feedback' ),
					'value' => $row->body,
				),
				array(
					'name'  => __( 'Date', 'smbworks-feedback' ),
					'value' => $row->created_at,
				),
			),
		);
	}

	return array(
		'data' => $data,
		'done' => count( $rows ) < $per && count( $replies ) < $per,
	);
}

/**
 * Anonymise rather than delete, so threads other people replied to stay readable.
 */
function smbfb_privacy_erase( $email ) {
	global $wpdb;
	$anon = array(
		'author_name'  => __( 'Anonymous', 'smbworks-feedback' ),
		'author_email' => '',
		'author_id'    => 0,
	);

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$removed  = (int) $wpdb->update( smbfb_table( 'items' ), $anon + array( 'ip_hash' => '' ), array( 'author_email' => $email ) );
	$removed += (int) $wpdb->update( smbfb_table( 'replies' ), $anon, array( 'author_email' => $email ) );
	// phpcs:enable

	return array(
		'items_removed'  => $removed > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}
