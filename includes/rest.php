<?php
/**
 * REST API: smbworks-feedback/v1.
 *
 * POST /items is the widget and is open to whoever the widget is shown to. Everything else needs
 * smbfb_manage_feedback. Logged-in requests use the standard REST cookie nonce (X-WP-Nonce).
 */

defined( 'ABSPATH' ) || exit;

define( 'SMBFB_REST_NS', 'smbworks-feedback/v1' );

add_action( 'rest_api_init', 'smbfb_rest_routes' );

function smbfb_rest_routes() {
	$manage = static fn() => smbfb_can_manage();
	$id_arg = array(
		'id' => array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		),
	);

	register_rest_route(
		SMBFB_REST_NS,
		'/items',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'smbfb_rest_create_item',
				'permission_callback' => 'smbfb_rest_can_submit',
				'args'                => array(
					'comment'    => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => 5000,
					),
					'type'       => array(
						'type' => 'string',
						'enum' => array_keys( smbfb_types() ),
					),
					'page_url'   => array(
						'type'     => 'string',
						'format'   => 'uri',
						'required' => true,
					),
					'page_title' => array(
						'type'    => 'string',
						'default' => '',
					),
					'name'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'email'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'context'    => array(
						'type'    => 'object',
						'default' => array(),
					),
					'website'    => array(
						'description' => 'Honeypot; must stay empty.',
						'type'        => 'string',
						'default'     => '',
					),
					'elapsed'    => array(
						'description' => 'Milliseconds the form was open.',
						'type'        => 'integer',
						'default'     => 0,
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'smbfb_rest_list_items',
				'permission_callback' => $manage,
				'args'                => array(
					'status'    => array(
						'type'    => 'string',
						'enum'    => array_keys( smbfb_statuses() ),
						'default' => 'open',
					),
					'page_key'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'type'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'mentioned' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'search'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 30,
					),
				),
			),
		)
	);

	register_rest_route(
		SMBFB_REST_NS,
		'/items/(?P<id>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'smbfb_rest_get_item',
				'permission_callback' => $manage,
				'args'                => $id_arg,
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => 'smbfb_rest_update_item',
				'permission_callback' => $manage,
				'args'                => $id_arg + array(
					'status' => array(
						'type'     => 'string',
						'enum'     => array_keys( smbfb_statuses() ),
						'required' => true,
					),
				),
			),
		)
	);

	register_rest_route(
		SMBFB_REST_NS,
		'/items/(?P<id>\d+)/replies',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'smbfb_rest_create_reply',
			'permission_callback' => $manage,
			'args'                => $id_arg + array(
				'body'      => array(
					'type'      => 'string',
					'required'  => true,
					'minLength' => 1,
					'maxLength' => 5000,
				),
				'parent_id' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
				),
			),
		)
	);

	register_rest_route(
		SMBFB_REST_NS,
		'/users',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'smbfb_rest_users',
			'permission_callback' => $manage,
			'args'                => array(
				'search' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);
}

/**
 * Whether the current visitor gets the widget (and may submit through it).
 */
function smbfb_widget_allowed() {
	$allowed = match ( smbfb_setting( 'visibility' ) ) {
		'everyone'  => true,
		'users'     => is_user_logged_in(),
		'reviewers' => smbfb_can_manage(),
		default     => false,
	};
	return (bool) apply_filters( 'smbfb_widget_allowed', $allowed );
}

function smbfb_rest_can_submit() {
	if ( smbfb_widget_allowed() ) {
		return true;
	}
	return new WP_Error(
		'smbfb_forbidden',
		__( 'Sign in to leave feedback.', 'smbworks-feedback' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

function smbfb_ip_hash() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return '' === $ip ? '' : hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
}

/**
 * Keep the widget's page context small and flat: up to 20 scalar values.
 */
function smbfb_sanitize_context( $context ) {
	$clean = array();
	foreach ( (array) $context as $key => $value ) {
		$key = sanitize_key( $key );
		if ( '' === $key || ! is_scalar( $value ) || count( $clean ) >= 20 ) {
			continue;
		}
		$clean[ $key ] = mb_substr( sanitize_text_field( (string) $value ), 0, 500 );
	}
	return $clean;
}

/**
 * Guest submissions per IP per 10 minutes.
 */
function smbfb_rate_limited( $ip_hash ) {
	if ( '' === $ip_hash ) {
		return false;
	}
	$key   = 'smbfb_rl_' . substr( $ip_hash, 0, 32 );
	$count = (int) get_transient( $key );
	if ( $count >= (int) apply_filters( 'smbfb_guest_rate_limit', 10 ) ) {
		return true;
	}
	set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
	return false;
}

function smbfb_rest_create_item( WP_REST_Request $request ) {
	$user    = wp_get_current_user();
	$ip_hash = smbfb_ip_hash();

	if ( $user->exists() ) {
		// Signed-in identity always wins over whatever the form sent.
		$name  = $user->display_name;
		$email = $user->user_email;
	} else {
		// Bots fill every field and submit instantly. Pretend it worked so they don't retry.
		if ( '' !== $request['website'] || $request['elapsed'] < 2000 ) {
			return new WP_REST_Response( array( 'id' => 0 ), 201 );
		}
		$name  = sanitize_text_field( $request['name'] );
		$email = sanitize_email( $request['email'] );
		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'smbfb_identity', __( 'Please fill in your name and a valid email.', 'smbworks-feedback' ), array( 'status' => 400 ) );
		}
		if ( smbfb_rate_limited( $ip_hash ) ) {
			return new WP_Error( 'smbfb_rate_limited', __( 'You’ve sent a lot of feedback in a short time. Please try again in a few minutes.', 'smbworks-feedback' ), array( 'status' => 429 ) );
		}
	}

	$comment = sanitize_textarea_field( $request['comment'] );
	if ( '' === $comment ) {
		return new WP_Error( 'smbfb_empty', __( 'Please add your feedback.', 'smbworks-feedback' ), array( 'status' => 400 ) );
	}

	$types = smbfb_types();
	$id    = smbfb_create_item(
		array(
			'comment'      => $comment,
			'type'         => $request['type'] ?? array_key_first( $types ),
			'page_url'     => esc_url_raw( $request['page_url'] ),
			'page_title'   => sanitize_text_field( $request['page_title'] ),
			'author_id'    => $user->ID,
			'author_name'  => $name,
			'author_email' => $email,
			'ip_hash'      => $ip_hash,
			'context'      => smbfb_sanitize_context( $request['context'] ),
		)
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}

	$data = array( 'id' => $id );
	// Only signed-in users learn who a mention matched, so guests can't probe for usernames.
	if ( $user->exists() ) {
		$data['mentioned'] = array_map( static fn( $uid ) => get_userdata( $uid )->display_name, smbfb_parse_mentions( $comment ) );
	}
	return new WP_REST_Response( $data, 201 );
}

function smbfb_rest_list_items( WP_REST_Request $request ) {
	$filters = array(
		'page_key'  => $request['page_key'],
		'type'      => $request['type'],
		'mentioned' => $request['mentioned'] ? get_current_user_id() : 0,
		'search'    => $request['search'],
	);
	$result  = smbfb_query_items(
		$filters + array(
			'status'   => $request['status'],
			'page'     => $request['page'],
			'per_page' => $request['per_page'],
		)
	);

	return rest_ensure_response(
		array(
			'items'  => array_map( 'smbfb_format_item', $result['items'] ),
			'total'  => $result['total'],
			'pages'  => (int) ceil( $result['total'] / $request['per_page'] ),
			'counts' => smbfb_status_counts( $filters ),
			'filter' => array(
				'pages' => smbfb_pages(),
				'types' => smbfb_filter_types(),
			),
		)
	);
}

/**
 * @return object|WP_Error Item row.
 */
function smbfb_rest_item_or_404( $id ) {
	$item = smbfb_get_item( $id );
	return $item ? $item : new WP_Error( 'smbfb_not_found', __( 'Feedback item not found.', 'smbworks-feedback' ), array( 'status' => 404 ) );
}

function smbfb_rest_item_response( $item_id ) {
	$item = smbfb_get_item( $item_id );
	$data = smbfb_format_item( $item );

	$data['replies'] = array_map( 'smbfb_format_reply', smbfb_get_replies( $item_id ) );
	return rest_ensure_response( $data );
}

function smbfb_rest_get_item( WP_REST_Request $request ) {
	$item = smbfb_rest_item_or_404( $request['id'] );
	return is_wp_error( $item ) ? $item : smbfb_rest_item_response( $item->id );
}

function smbfb_rest_update_item( WP_REST_Request $request ) {
	$item = smbfb_rest_item_or_404( $request['id'] );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	$result = smbfb_set_status( $item->id, $request['status'], get_current_user_id() );
	return is_wp_error( $result ) ? $result : smbfb_rest_item_response( $item->id );
}

function smbfb_rest_create_reply( WP_REST_Request $request ) {
	$item = smbfb_rest_item_or_404( $request['id'] );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	$body = sanitize_textarea_field( $request['body'] );
	if ( '' === $body ) {
		return new WP_Error( 'smbfb_empty', __( 'Please write a reply.', 'smbworks-feedback' ), array( 'status' => 400 ) );
	}

	$reply_id = smbfb_add_reply( $item->id, $request['parent_id'], $body, wp_get_current_user() );
	if ( is_wp_error( $reply_id ) ) {
		return $reply_id;
	}
	$response = smbfb_rest_item_response( $item->id );
	$response->set_status( 201 );
	return $response;
}

/**
 * Reviewers for @mention autocomplete.
 */
function smbfb_rest_users( WP_REST_Request $request ) {
	$search = smbfb_mention_norm( $request['search'] );
	$out    = array();
	foreach ( smbfb_mentionable_users() as $user ) {
		$haystack = smbfb_mention_norm( $user->user_nicename . ' ' . $user->display_name . ' ' . $user->first_name . ' ' . $user->last_name );
		if ( '' !== $search && ! str_contains( $haystack, $search ) ) {
			continue;
		}
		$out[] = array(
			'id'     => $user->ID,
			'handle' => smbfb_mention_handle( $user ),
			'name'   => $user->display_name,
			'avatar' => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
		);
		if ( count( $out ) >= 8 ) {
			break;
		}
	}
	return rest_ensure_response( $out );
}
