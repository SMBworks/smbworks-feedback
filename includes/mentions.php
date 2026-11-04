<?php
/**
 * @mentions and notification emails.
 *
 * Only feedback reviewers can be mentioned, since the email links into the inbox. A mention matches a reviewer's
 * username or nicename, or their first name or display name (spaces removed) when that is unique, so the
 * "@Mark" style from the original widget keeps working.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'smbfb_item_created', 'smbfb_on_item_created' );
add_action( 'smbfb_reply_created', 'smbfb_on_reply_created', 10, 2 );

/**
 * @return WP_User[]
 */
function smbfb_mentionable_users() {
	static $users = null;
	if ( null === $users ) {
		$users = get_users(
			array(
				'capability' => 'smbfb_manage_feedback',
				'fields'     => 'all_with_meta',
				'orderby'    => 'display_name',
			)
		);
		$users = array_values( $users );
	}
	return $users;
}

function smbfb_mention_handle( WP_User $user ) {
	return $user->user_nicename;
}

/**
 * Normalise a name for matching: lowercase, no spaces or leading @.
 */
function smbfb_mention_norm( $value ) {
	return mb_strtolower( preg_replace( '/\s+/u', '', ltrim( (string) $value, '@' ) ) );
}

/**
 * @return int[] IDs of reviewers mentioned in the text.
 */
function smbfb_parse_mentions( $text ) {
	if ( ! preg_match_all( '/(?<![\p{L}\p{N}_.@])@([\p{L}\p{N}][\p{L}\p{N}._-]*)/u', (string) $text, $matches ) ) {
		return array();
	}

	$exact = array();
	$loose = array();
	foreach ( smbfb_mentionable_users() as $user ) {
		foreach ( array( $user->user_login, $user->user_nicename ) as $value ) {
			$exact[ smbfb_mention_norm( $value ) ] = $user->ID;
		}
		foreach ( array_unique( array( smbfb_mention_norm( $user->first_name ), smbfb_mention_norm( $user->display_name ) ) ) as $value ) {
			if ( '' !== $value ) {
				$loose[ $value ][] = $user->ID;
			}
		}
	}

	$ids = array();
	foreach ( $matches[1] as $token ) {
		$token = smbfb_mention_norm( rtrim( $token, '.-_' ) );
		if ( isset( $exact[ $token ] ) ) {
			$ids[] = $exact[ $token ];
		} elseif ( isset( $loose[ $token ] ) && 1 === count( array_unique( $loose[ $token ] ) ) ) {
			$ids[] = $loose[ $token ][0];
		}
	}
	return array_values( array_unique( array_map( 'intval', $ids ) ) );
}

/**
 * Store mentions and email each newly mentioned reviewer once.
 *
 * @return int[] IDs that were emailed.
 */
function smbfb_record_mentions( $item, $reply_id, $text, $author_id, $author_name ) {
	global $wpdb;

	$sent = array();
	foreach ( smbfb_parse_mentions( $text ) as $user_id ) {
		if ( (int) $author_id === $user_id ) {
			continue;
		}
		// The unique key makes a repeat mention in the same comment a no-op.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$added = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . smbfb_table( 'mentions' ) . ' (item_id, reply_id, user_id, created_at) VALUES (%d, %d, %d, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$item->id,
				$reply_id,
				$user_id,
				smbfb_now()
			)
		);
		if ( ! $added ) {
			continue;
		}

		$user = get_userdata( $user_id );
		if ( $user && smbfb_send_mention_email( $user, $item, $reply_id, $text, $author_name ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				smbfb_table( 'mentions' ),
				array( 'notified_at' => smbfb_now() ),
				array( 'id' => (int) $wpdb->insert_id )
			);
			$sent[] = $user_id;
		}
	}
	return $sent;
}

function smbfb_blogname() {
	return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
}

function smbfb_page_label( $item ) {
	return '' !== $item->page_title ? $item->page_title : $item->page_key;
}

function smbfb_mail( $to, $subject, $lines, $reply_to = '' ) {
	$headers = array();
	if ( $reply_to && is_email( $reply_to ) ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}
	return wp_mail(
		$to,
		sprintf( '[%s] %s', smbfb_blogname(), $subject ),
		implode( "\n", $lines ),
		apply_filters( 'smbfb_mail_headers', $headers, $to, $subject )
	);
}

function smbfb_send_mention_email( WP_User $user, $item, $reply_id, $text, $author_name ) {
	return smbfb_mail(
		$user->user_email,
		/* translators: 1: person who wrote the comment, 2: page title */
		sprintf( __( '%1$s mentioned you in feedback on “%2$s”', 'smbworks-feedback' ), $author_name, smbfb_page_label( $item ) ),
		array(
			/* translators: %s: person who wrote the comment */
			sprintf( __( '%s wrote:', 'smbworks-feedback' ), $author_name ),
			'',
			$text,
			'',
			/* translators: %s: link to the item in wp-admin */
			sprintf( __( 'Read and reply: %s', 'smbworks-feedback' ), smbfb_item_admin_url( $item->id, $reply_id ) ),
			/* translators: %s: link to the page the feedback is about */
			sprintf( __( 'Page: %s', 'smbworks-feedback' ), $item->page_url ),
		)
	);
}

function smbfb_on_item_created( $item_id ) {
	$item = smbfb_get_item( $item_id );
	if ( ! $item ) {
		return;
	}

	$mentioned = smbfb_record_mentions( $item, 0, $item->comment, $item->author_id, $item->author_name );

	// The notify list skips anyone who just got a mention email for the same comment.
	$skip = array_map( static fn( $id ) => strtolower( get_userdata( $id )->user_email ), $mentioned );
	$to   = array_diff( array_map( 'strtolower', array_filter( array_map( 'trim', explode( ',', (string) smbfb_setting( 'notify_emails' ) ) ) ) ), $skip );
	if ( ! $to ) {
		return;
	}

	$types = smbfb_types();
	smbfb_mail(
		array_values( $to ),
		/* translators: 1: feedback type, 2: page title */
		sprintf( __( 'New feedback (%1$s) on “%2$s”', 'smbworks-feedback' ), $types[ $item->type ] ?? $item->type, smbfb_page_label( $item ) ),
		array(
			/* translators: 1: name, 2: email */
			sprintf( __( 'From: %1$s <%2$s>', 'smbworks-feedback' ), $item->author_name, $item->author_email ),
			'',
			$item->comment,
			'',
			/* translators: %s: link to the item in wp-admin */
			sprintf( __( 'Read and reply: %s', 'smbworks-feedback' ), smbfb_item_admin_url( $item->id ) ),
			/* translators: %s: link to the page the feedback is about */
			sprintf( __( 'Page: %s', 'smbworks-feedback' ), $item->page_url ),
		),
		$item->author_email
	);
}

/**
 * Mentions in the reply, then (if enabled) a note to whoever left the feedback, so guests hear back too.
 */
function smbfb_on_reply_created( $reply_id, $reply ) {
	$item = smbfb_get_item( $reply['item_id'] );
	if ( ! $item ) {
		return;
	}

	$mentioned = smbfb_record_mentions( $item, $reply_id, $reply['body'], $reply['author_id'], $reply['author_name'] );

	if ( ! smbfb_setting( 'notify_author' ) || ! is_email( $item->author_email ) ) {
		return;
	}
	if ( strtolower( $item->author_email ) === strtolower( $reply['author_email'] ) || ( $item->author_id && in_array( (int) $item->author_id, $mentioned, true ) ) ) {
		return;
	}

	// Guests can't open wp-admin, so they get the page link and the reply text.
	$link = $item->author_id && smbfb_can_manage( $item->author_id ) ? smbfb_item_admin_url( $item->id, $reply_id ) : $item->page_url;

	smbfb_mail(
		$item->author_email,
		/* translators: %s: page title */
		sprintf( __( 'New reply to your feedback on “%s”', 'smbworks-feedback' ), smbfb_page_label( $item ) ),
		array(
			/* translators: %s: person who replied */
			sprintf( __( '%s replied:', 'smbworks-feedback' ), $reply['author_name'] ),
			'',
			$reply['body'],
			'',
			__( 'Your feedback:', 'smbworks-feedback' ),
			'',
			$item->comment,
			'',
			$link,
		)
	);
}
