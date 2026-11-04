<?php
/**
 * Feedback inbox in wp-admin: a split pane (list on the left, thread on the right) driven by the REST API.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'smbfb_admin_menu' );
add_action( 'admin_enqueue_scripts', 'smbfb_admin_enqueue' );
add_filter( 'plugin_action_links_' . plugin_basename( SMBFB_FILE ), 'smbfb_action_links' );

function smbfb_admin_menu() {
	$open  = smbfb_can_manage() ? smbfb_open_count() : 0;
	$badge = $open ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $open, number_format_i18n( $open ) ) : '';

	add_menu_page(
		__( 'Feedback', 'smbworks-feedback' ),
		__( 'Feedback', 'smbworks-feedback' ) . $badge,
		'smbfb_manage_feedback',
		'smbworks-feedback',
		'smbfb_inbox_page',
		'dashicons-format-chat',
		26
	);
	add_submenu_page( 'smbworks-feedback', __( 'Feedback inbox', 'smbworks-feedback' ), __( 'Inbox', 'smbworks-feedback' ), 'smbfb_manage_feedback', 'smbworks-feedback', 'smbfb_inbox_page' );
	add_submenu_page( 'smbworks-feedback', __( 'Feedback settings', 'smbworks-feedback' ), __( 'Settings', 'smbworks-feedback' ), 'manage_options', 'smbworks-feedback-settings', 'smbfb_settings_page' );
}

function smbfb_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=smbworks-feedback-settings' ) ), esc_html__( 'Settings', 'smbworks-feedback' ) ) );
	}
	return $links;
}

function smbfb_admin_enqueue( $hook ) {
	if ( 'toplevel_page_smbworks-feedback' !== $hook ) {
		return;
	}

	smbfb_register_mentions();
	wp_enqueue_style( 'smbfb-admin', SMBFB_URL . 'assets/admin.css', array( 'smbfb-mentions' ), SMBFB_VERSION );
	wp_enqueue_script( 'smbfb-admin', SMBFB_URL . 'assets/admin.js', array( 'wp-api-fetch', 'wp-i18n', 'smbfb-mentions' ), SMBFB_VERSION, array( 'in_footer' => true ) );
	wp_set_script_translations( 'smbfb-admin', 'smbworks-feedback' );

	$config = array(
		'namespace' => SMBFB_REST_NS,
		'statuses'  => smbfb_statuses(),
		'userId'    => get_current_user_id(),
	);
	wp_add_inline_script( 'smbfb-admin', 'window.smbfbInbox = ' . wp_json_encode( $config ) . ';', 'before' );
}

function smbfb_inbox_page() {
	if ( ! smbfb_can_manage() ) {
		return;
	}
	?>
	<div class="wrap smbfb-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Feedback', 'smbworks-feedback' ); ?></h1>
		<hr class="wp-header-end">
		<div id="smbfb-inbox" class="smbfb-inbox" aria-busy="true">
			<noscript><p><?php esc_html_e( 'The feedback inbox needs JavaScript.', 'smbworks-feedback' ); ?></p></noscript>
		</div>
	</div>
	<?php
}
