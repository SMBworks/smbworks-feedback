<?php
/**
 * Front-end widget: a floating button and a dialog, printed in wp_footer so it works with any theme.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'smbfb_widget_enqueue' );
add_action( 'wp_footer', 'smbfb_widget_markup', 100 );

function smbfb_register_mentions() {
	wp_register_style( 'smbfb-mentions', SMBFB_URL . 'assets/mentions.css', array(), SMBFB_VERSION );
	wp_register_script( 'smbfb-mentions', SMBFB_URL . 'assets/mentions.js', array(), SMBFB_VERSION, array( 'in_footer' => true ) );
}

function smbfb_widget_should_load() {
	if ( is_admin() || is_feed() || is_embed() || is_customize_preview() || wp_is_json_request() ) {
		return false;
	}
	return smbfb_widget_allowed();
}

function smbfb_widget_enqueue() {
	if ( ! smbfb_widget_should_load() ) {
		return;
	}

	$user     = wp_get_current_user();
	$reviewer = smbfb_can_manage();
	smbfb_register_mentions();

	// Only reviewers get the autocomplete; it lists users, so guests never load it.
	wp_enqueue_style( 'smbfb-widget', SMBFB_URL . 'assets/widget.css', $reviewer ? array( 'smbfb-mentions' ) : array(), SMBFB_VERSION );
	wp_enqueue_script(
		'smbfb-widget',
		SMBFB_URL . 'assets/widget.js',
		$reviewer ? array( 'smbfb-mentions' ) : array(),
		SMBFB_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	$config = array(
		'endpoint' => rest_url( SMBFB_REST_NS . '/items' ),
		'users'    => $reviewer ? rest_url( SMBFB_REST_NS . '/users' ) : '',
		// A guest nonce would go stale under page caching, and guests don't need one.
		'nonce'    => $user->exists() ? wp_create_nonce( 'wp_rest' ) : '',
		'signedIn' => $user->exists(),
		'i18n'     => array(
			'required'  => __( 'Please fill in your name, email, and feedback.', 'smbworks-feedback' ),
			'empty'     => __( 'Please add your feedback.', 'smbworks-feedback' ),
			'sending'   => __( 'Sending…', 'smbworks-feedback' ),
			'sent'      => __( 'Thanks — feedback sent!', 'smbworks-feedback' ),
			/* translators: %s: comma-separated names */
			'notified'  => __( 'Thanks — feedback sent! Notified: %s', 'smbworks-feedback' ),
			'error'     => __( 'Something went wrong. Please try again.', 'smbworks-feedback' ),
			'noMatches' => __( 'No matching reviewers', 'smbworks-feedback' ),
		),
	);
	wp_add_inline_script( 'smbfb-widget', 'window.smbfbWidget = ' . wp_json_encode( $config ) . ';', 'before' );
}

function smbfb_widget_markup() {
	if ( ! wp_script_is( 'smbfb-widget', 'enqueued' ) ) {
		return;
	}

	$user     = wp_get_current_user();
	$label    = smbfb_setting( 'button_label' );
	$label    = '' !== $label ? $label : __( 'Feedback', 'smbworks-feedback' );
	$position = 'left' === smbfb_setting( 'position' ) ? 'left' : 'right';
	$readonly = $user->exists() ? ' readonly' : '';
	?>
	<div class="smbfb" data-position="<?php echo esc_attr( $position ); ?>">
		<button class="smbfb-launch" type="button" aria-haspopup="dialog" aria-controls="smbfb-dialog">
			<span aria-hidden="true">💬</span> <?php echo esc_html( $label ); ?>
		</button>

		<div class="smbfb-overlay" hidden>
			<form class="smbfb-dialog" id="smbfb-dialog" role="dialog" aria-modal="true" aria-labelledby="smbfb-title" novalidate>
				<h2 class="smbfb-title" id="smbfb-title"><?php esc_html_e( 'Send feedback', 'smbworks-feedback' ); ?></h2>
				<p class="smbfb-sub"><?php esc_html_e( 'Captures this page automatically — just add your thoughts.', 'smbworks-feedback' ); ?></p>

				<?php // Signed-in users see their account details, read-only; the server uses the account either way. ?>
				<div class="smbfb-identity-fields">
					<label for="smbfb-name"><?php esc_html_e( 'Name', 'smbworks-feedback' ); ?></label>
					<input id="smbfb-name" name="name" type="text" autocomplete="name" placeholder="<?php esc_attr_e( 'Your name', 'smbworks-feedback' ); ?>" value="<?php echo esc_attr( $user->exists() ? $user->display_name : '' ); ?>"<?php echo $readonly; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed string. ?>>

					<label for="smbfb-email"><?php esc_html_e( 'Email', 'smbworks-feedback' ); ?></label>
					<input id="smbfb-email" name="email" type="email" autocomplete="email" placeholder="you@example.com" value="<?php echo esc_attr( $user->exists() ? $user->user_email : '' ); ?>"<?php echo $readonly; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed string. ?>>
				</div>

				<label for="smbfb-type"><?php esc_html_e( 'Type', 'smbworks-feedback' ); ?></label>
				<select id="smbfb-type" name="type">
					<?php foreach ( smbfb_types() as $value => $type_label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="smbfb-comment"><?php esc_html_e( 'Feedback', 'smbworks-feedback' ); ?></label>
				<textarea id="smbfb-comment" name="comment" maxlength="5000" placeholder="<?php esc_attr_e( 'What’s on your mind about this page? Type @ and a name to notify a teammate — you can mention several people.', 'smbworks-feedback' ); ?>"></textarea>

				<div class="smbfb-hp" aria-hidden="true">
					<label for="smbfb-website">Website</label>
					<input id="smbfb-website" name="website" type="text" tabindex="-1" autocomplete="off">
				</div>

				<p class="smbfb-status" role="status" aria-live="polite"></p>

				<div class="smbfb-actions">
					<button class="smbfb-cancel" type="button"><?php esc_html_e( 'Cancel', 'smbworks-feedback' ); ?></button>
					<button class="smbfb-submit" type="submit"><?php esc_html_e( 'Send', 'smbworks-feedback' ); ?></button>
				</div>
			</form>
		</div>
	</div>
	<?php
}
