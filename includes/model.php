<?php
/**
 * Reads and writes for feedback items and replies. Callers check permissions; these only validate data.
 */

defined( 'ABSPATH' ) || exit;

function smbfb_statuses() {
	return array(
		'open'     => __( 'Open', 'smbworks-feedback' ),
		'resolved' => __( 'Resolved', 'smbworks-feedback' ),
		'archived' => __( 'Archived', 'smbworks-feedback' ),
	);
}

function smbfb_now() {
	return current_time( 'mysql', true );
}

/**
 * Hosts feedback may point at. Defaults to the site's own, so the widget can't be used to file links to elsewhere.
 */
function smbfb_allowed_hosts() {
	$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) );
	return array_unique( array_filter( apply_filters( 'smbfb_allowed_hosts', $hosts ) ) );
}

/**
 * The page a URL belongs to, for grouping: path only, no query string or fragment, trailing slash kept consistent.
 *
 * @return string Empty when the URL isn't on an allowed host.
 */
function smbfb_page_key( $url ) {
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) || ! in_array( strtolower( $parts['host'] ), array_map( 'strtolower', smbfb_allowed_hosts() ), true ) ) {
		return '';
	}
	$path = '/' . ltrim( $parts['path'] ?? '', '/' );
	$path = '/' === $path ? $path : user_trailingslashit( untrailingslashit( $path ) );
	return substr( strtolower( rawurldecode( $path ) ), 0, 191 );
}

/**
 * @param array $data comment, type, page_url, page_title, author_id, author_name, author_email, ip_hash, context.
 * @return int|WP_Error New item ID.
 */
function smbfb_create_item( $data ) {
	global $wpdb;

	$page_key = smbfb_page_key( $data['page_url'] );
	if ( '' === $page_key ) {
		return new WP_Error( 'smbfb_bad_url', __( 'Feedback must be about a page on this site.', 'smbworks-feedback' ), array( 'status' => 400 ) );
	}

	$now = smbfb_now();
	$row = array(
		'status'           => 'open',
		'type'             => $data['type'],
		'comment'          => $data['comment'],
		'page_url'         => $data['page_url'],
		'page_key'         => $page_key,
		'page_title'       => mb_substr( $data['page_title'], 0, 255 ),
		'post_id'          => url_to_postid( $data['page_url'] ),
		'author_id'        => (int) $data['author_id'],
		'author_name'      => mb_substr( $data['author_name'], 0, 100 ),
		'author_email'     => mb_substr( $data['author_email'], 0, 100 ),
		'ip_hash'          => $data['ip_hash'] ?? '',
		'context'          => wp_json_encode( $data['context'] ?? array() ),
		'created_at'       => $now,
		'updated_at'       => $now,
		'last_activity_at' => $now,
	);

	if ( ! $wpdb->insert( smbfb_table( 'items' ), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return new WP_Error( 'smbfb_db', __( 'Could not save the feedback.', 'smbworks-feedback' ), array( 'status' => 500 ) );
	}

	$id = (int) $wpdb->insert_id;
	do_action( 'smbfb_item_created', $id, $row );
	return $id;
}

function smbfb_get_item( $id ) {
	global $wpdb;
	$table = smbfb_table( 'items' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
}

function smbfb_get_reply( $id ) {
	global $wpdb;
	$table = smbfb_table( 'replies' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
}

/**
 * Replies in posting order; the client nests them by parent_id.
 */
function smbfb_get_replies( $item_id ) {
	global $wpdb;
	$table = smbfb_table( 'replies' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE item_id = %d ORDER BY created_at ASC, id ASC", $item_id ) );
}

/**
 * @return int|WP_Error New reply ID.
 */
function smbfb_add_reply( $item_id, $parent_id, $body, WP_User $user ) {
	global $wpdb;

	if ( $parent_id ) {
		$parent = smbfb_get_reply( $parent_id );
		if ( ! $parent || (int) $parent->item_id !== (int) $item_id ) {
			return new WP_Error( 'smbfb_bad_parent', __( 'That reply belongs to another item.', 'smbworks-feedback' ), array( 'status' => 400 ) );
		}
	}

	$now = smbfb_now();
	$row = array(
		'item_id'      => (int) $item_id,
		'parent_id'    => (int) $parent_id,
		'author_id'    => $user->ID,
		'author_name'  => mb_substr( $user->display_name, 0, 100 ),
		'author_email' => mb_substr( $user->user_email, 0, 100 ),
		'body'         => $body,
		'created_at'   => $now,
	);

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	if ( ! $wpdb->insert( smbfb_table( 'replies' ), $row ) ) {
		return new WP_Error( 'smbfb_db', __( 'Could not save the reply.', 'smbworks-feedback' ), array( 'status' => 500 ) );
	}
	$id    = (int) $wpdb->insert_id;
	$items = smbfb_table( 'items' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "UPDATE $items SET reply_count = reply_count + 1, updated_at = %s, last_activity_at = %s WHERE id = %d", $now, $now, $item_id ) );
	// phpcs:enable

	do_action( 'smbfb_reply_created', $id, $row );
	return $id;
}

function smbfb_set_status( $item_id, $status, $user_id ) {
	global $wpdb;

	if ( ! array_key_exists( $status, smbfb_statuses() ) ) {
		return new WP_Error( 'smbfb_bad_status', __( 'Unknown status.', 'smbworks-feedback' ), array( 'status' => 400 ) );
	}

	$item = smbfb_get_item( $item_id );
	$now  = smbfb_now();
	$data = array(
		'status'     => $status,
		'updated_at' => $now,
	);

	if ( 'open' === $status ) {
		$data['resolved_by'] = 0;
		$data['resolved_at'] = null;
	} elseif ( 'open' === $item->status ) {
		// Archiving straight from open still records who closed it.
		$data['resolved_by'] = (int) $user_id;
		$data['resolved_at'] = $now;
	}

	$wpdb->update( smbfb_table( 'items' ), $data, array( 'id' => (int) $item_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	do_action( 'smbfb_status_changed', (int) $item_id, $status, $item->status, (int) $user_id );
	return true;
}

/**
 * WHERE clause shared by the list and its counts.
 *
 * @param array $args page_key, type, mentioned (user ID), search.
 * @return array{0: string, 1: array} SQL fragment and its placeholders' values.
 */
function smbfb_where( $args ) {
	global $wpdb;
	$sql    = '1=1';
	$values = array();

	if ( ! empty( $args['page_key'] ) ) {
		$sql     .= ' AND i.page_key = %s';
		$values[] = $args['page_key'];
	}
	if ( ! empty( $args['type'] ) ) {
		$sql     .= ' AND i.type = %s';
		$values[] = $args['type'];
	}
	if ( ! empty( $args['mentioned'] ) ) {
		$sql     .= ' AND i.id IN ( SELECT item_id FROM ' . smbfb_table( 'mentions' ) . ' WHERE user_id = %d )';
		$values[] = (int) $args['mentioned'];
	}
	if ( ! empty( $args['search'] ) ) {
		$like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$sql   .= ' AND ( i.comment LIKE %s OR i.page_title LIKE %s OR i.author_name LIKE %s OR i.author_email LIKE %s OR i.id IN ( SELECT item_id FROM ' . smbfb_table( 'replies' ) . ' WHERE body LIKE %s ) )';
		$values = array_merge( $values, array( $like, $like, $like, $like, $like ) );
	}
	return array( $sql, $values );
}

/**
 * Run prepare() only when there is something to bind; it complains about queries without placeholders.
 */
function smbfb_prepare( $sql, $values ) {
	global $wpdb;
	return $values ? $wpdb->prepare( $sql, $values ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * @param array $args status, page_key, type, mentioned, search, page, per_page.
 * @return array{items: array, total: int}
 */
function smbfb_query_items( $args ) {
	global $wpdb;
	list( $where, $values ) = smbfb_where( $args );

	if ( ! empty( $args['status'] ) ) {
		$where   .= ' AND i.status = %s';
		$values[] = $args['status'];
	}

	$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 30 ) ) );
	$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
	$table    = smbfb_table( 'items' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
	$total = (int) $wpdb->get_var( smbfb_prepare( "SELECT COUNT(*) FROM $table i WHERE $where", $values ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table i WHERE $where ORDER BY i.last_activity_at DESC, i.id DESC LIMIT %d OFFSET %d",
			array_merge( $values, array( $per_page, $offset ) )
		)
	);
	// phpcs:enable

	return array(
		'items' => $rows,
		'total' => $total,
	);
}

/**
 * Item counts per status under the other filters, for the status tabs.
 */
function smbfb_status_counts( $args ) {
	global $wpdb;
	list( $where, $values ) = smbfb_where( $args );
	$table                  = smbfb_table( 'items' );

	$counts = array_fill_keys( array_keys( smbfb_statuses() ), 0 );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( smbfb_prepare( "SELECT i.status, COUNT(*) AS n FROM $table i WHERE $where GROUP BY i.status", $values ) );
	foreach ( $rows as $row ) {
		$counts[ $row->status ] = (int) $row->n;
	}
	return $counts;
}

/**
 * Pages that have feedback, with the latest title seen for each, for the page filter.
 */
function smbfb_pages() {
	global $wpdb;
	$table = smbfb_table( 'items' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		"SELECT page_key, SUBSTRING_INDEX( GROUP_CONCAT( page_title ORDER BY id DESC SEPARATOR '\n' ), '\n', 1 ) AS title, COUNT(*) AS n, SUM( status = 'open' ) AS open
		FROM $table GROUP BY page_key ORDER BY page_key"
	);
	// phpcs:enable
	return array_map(
		static fn( $row ) => array(
			'key'   => $row->page_key,
			'title' => $row->title,
			'count' => (int) $row->n,
			'open'  => (int) $row->open,
		),
		$rows
	);
}

/**
 * Types for the inbox filter: the configured ones, then any still on items after being removed from the settings.
 */
function smbfb_filter_types() {
	global $wpdb;
	$types = smbfb_types();
	$table = smbfb_table( 'items' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $wpdb->get_col( "SELECT DISTINCT type FROM $table WHERE type <> '' ORDER BY type" ) as $key ) {
		$types[ $key ] = $types[ $key ] ?? smbfb_type_label( $key );
	}
	return $types;
}

function smbfb_open_count() {
	global $wpdb;
	$table = smbfb_table( 'items' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'open'" );
}

function smbfb_date( $mysql_gmt ) {
	return $mysql_gmt ? mysql_to_rfc3339( $mysql_gmt ) . 'Z' : null;
}

function smbfb_person( $id, $name, $email ) {
	return array(
		'id'     => (int) $id,
		'name'   => $name,
		'email'  => $email,
		'avatar' => get_avatar_url( $id ? (int) $id : $email, array( 'size' => 64 ) ),
		'guest'  => ! $id,
	);
}

/**
 * Item as the dashboard sees it.
 */
function smbfb_format_item( $row ) {
	$resolver = $row->resolved_by ? get_userdata( $row->resolved_by ) : null;
	$context  = json_decode( (string) $row->context, true );

	return array(
		'id'               => (int) $row->id,
		'status'           => $row->status,
		'type'             => $row->type,
		'type_label'       => smbfb_type_label( $row->type ),
		'comment'          => $row->comment,
		'page_url'         => $row->page_url,
		'page_key'         => $row->page_key,
		'page_title'       => $row->page_title,
		'post_id'          => (int) $row->post_id,
		'edit_url'         => $row->post_id && current_user_can( 'edit_post', $row->post_id ) ? get_edit_post_link( $row->post_id, 'raw' ) : '',
		'author'           => smbfb_person( $row->author_id, $row->author_name, $row->author_email ),
		'context'          => is_array( $context ) ? $context : array(),
		'reply_count'      => (int) $row->reply_count,
		'resolved_by'      => $resolver ? $resolver->display_name : '',
		'resolved_at'      => smbfb_date( $row->resolved_at ),
		'created_at'       => smbfb_date( $row->created_at ),
		'last_activity_at' => smbfb_date( $row->last_activity_at ),
	);
}

function smbfb_format_reply( $row ) {
	return array(
		'id'         => (int) $row->id,
		'parent_id'  => (int) $row->parent_id,
		'body'       => $row->body,
		'author'     => smbfb_person( $row->author_id, $row->author_name, $row->author_email ),
		'created_at' => smbfb_date( $row->created_at ),
	);
}

/**
 * Link to an item in the inbox.
 */
function smbfb_item_admin_url( $item_id, $reply_id = 0 ) {
	$url = admin_url( 'admin.php?page=smbworks-feedback' ) . '#item=' . (int) $item_id;
	return $reply_id ? $url . '&reply=' . (int) $reply_id : $url;
}
