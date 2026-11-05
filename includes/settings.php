<?php
/**
 * Feedback → Settings: who sees the widget, who gets notified, and uninstall behaviour.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'smbfb_settings_register' );

function smbfb_default_settings() {
	return array(
		'visibility'          => 'everyone',
		'button_label'        => '',
		'position'            => 'right',
		'types'               => array(),
		'notify_emails'       => '',
		'notify_author'       => 1,
		'delete_on_uninstall' => 0,
	);
}

function smbfb_setting( $key ) {
	$values = wp_parse_args( get_option( 'smbfb_settings', array() ), smbfb_default_settings() );
	return $values[ $key ] ?? null;
}

/**
 * Types used until Settings → Feedback types is filled in.
 */
function smbfb_default_types() {
	return array(
		'correction' => __( 'Correction', 'smbworks-feedback' ),
		'bug'        => __( 'Bug / issue', 'smbworks-feedback' ),
		'suggestion' => __( 'Suggestion', 'smbworks-feedback' ),
		'question'   => __( 'Question', 'smbworks-feedback' ),
		'praise'     => __( 'Something I like', 'smbworks-feedback' ),
	);
}

/**
 * Feedback types offered in the widget, in order. Keys are stored with each item; labels are shown.
 */
function smbfb_types() {
	$saved = smbfb_setting( 'types' );
	return apply_filters( 'smbfb_types', $saved ? $saved : smbfb_default_types() );
}

/**
 * Label for a stored type key, including keys since removed from the settings.
 */
function smbfb_type_label( $key ) {
	$types = smbfb_types();
	return $types[ $key ] ?? ucfirst( str_replace( array( '-', '_' ), ' ', $key ) );
}

/**
 * Parse the settings textarea: one type per line, "key | Label" or just "Label" (key made from the label).
 *
 * @return array<string, string> Up to 20 types; empty when nothing usable was entered.
 */
function smbfb_parse_types( $text ) {
	$types = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		$label = sanitize_text_field( $parts[1] ?? $parts[0] );
		$key   = isset( $parts[1] ) && '' !== $parts[0] ? wp_strip_all_tags( $parts[0] ) : $label;
		$key   = substr( sanitize_key( str_replace( ' ', '-', remove_accents( $key ) ) ), 0, 20 );
		if ( '' === $key || '' === $label || isset( $types[ $key ] ) ) {
			continue;
		}
		$types[ $key ] = mb_substr( $label, 0, 60 );
		if ( count( $types ) >= 20 ) {
			break;
		}
	}
	return $types;
}

function smbfb_visibility_choices() {
	return array(
		'everyone'  => __( 'Everyone, including guests', 'smbworks-feedback' ),
		'users'     => __( 'Logged-in users', 'smbworks-feedback' ),
		'reviewers' => __( 'Feedback reviewers only', 'smbworks-feedback' ),
		'nobody'    => __( 'Nobody (widget off)', 'smbworks-feedback' ),
	);
}

/**
 * Textarea from the form, or an already-saved array (update_option() and WP-CLI pass those).
 */
function smbfb_sanitize_types( $value ) {
	if ( is_array( $value ) ) {
		$value = implode( "\n", array_map( static fn( $key, $label ) => $key . ' | ' . $label, array_keys( $value ), $value ) );
	}
	$types = smbfb_parse_types( $value );
	// Saving the defaults unchanged stores nothing, so they stay translatable.
	return smbfb_default_types() === $types ? array() : $types;
}

function smbfb_settings_sanitize( $input ) {
	$defaults = smbfb_default_settings();
	$input    = is_array( $input ) ? $input : array();

	$emails = array_filter( array_map( 'sanitize_email', explode( ',', (string) ( $input['notify_emails'] ?? '' ) ) ) );

	return array(
		'visibility'          => array_key_exists( $input['visibility'] ?? '', smbfb_visibility_choices() ) ? $input['visibility'] : $defaults['visibility'],
		'button_label'        => sanitize_text_field( $input['button_label'] ?? '' ),
		'position'            => in_array( $input['position'] ?? '', array( 'left', 'right' ), true ) ? $input['position'] : 'right',
		'types'               => smbfb_sanitize_types( $input['types'] ?? '' ),
		'notify_emails'       => implode( ', ', $emails ),
		'notify_author'       => empty( $input['notify_author'] ) ? 0 : 1,
		'delete_on_uninstall' => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
	);
}

function smbfb_settings_register() {
	register_setting(
		'smbfb_settings',
		'smbfb_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'smbfb_settings_sanitize',
			'default'           => smbfb_default_settings(),
		)
	);

	add_settings_section( 'smbfb_widget', __( 'Widget', 'smbworks-feedback' ), '__return_false', 'smbfb_settings' );
	add_settings_section( 'smbfb_email', __( 'Email', 'smbworks-feedback' ), '__return_false', 'smbfb_settings' );
	add_settings_section( 'smbfb_data', __( 'Data', 'smbworks-feedback' ), '__return_false', 'smbfb_settings' );

	add_settings_field(
		'visibility',
		__( 'Show the widget to', 'smbworks-feedback' ),
		static function () {
			$current = smbfb_setting( 'visibility' );
			echo '<select name="smbfb_settings[visibility]" id="smbfb_visibility">';
			foreach ( smbfb_visibility_choices() as $value => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Guests enter a name and email; signed-in users are filled in for them.', 'smbworks-feedback' ) . '</p>';
		},
		'smbfb_settings',
		'smbfb_widget',
		array( 'label_for' => 'smbfb_visibility' )
	);

	add_settings_field(
		'button_label',
		__( 'Button label', 'smbworks-feedback' ),
		static function () {
			printf(
				'<input type="text" class="regular-text" name="smbfb_settings[button_label]" id="smbfb_button_label" value="%s" placeholder="%s">',
				esc_attr( smbfb_setting( 'button_label' ) ),
				esc_attr__( 'Feedback', 'smbworks-feedback' )
			);
		},
		'smbfb_settings',
		'smbfb_widget',
		array( 'label_for' => 'smbfb_button_label' )
	);

	add_settings_field(
		'position',
		__( 'Button position', 'smbworks-feedback' ),
		static function () {
			$current = smbfb_setting( 'position' );
			echo '<select name="smbfb_settings[position]" id="smbfb_position">';
			printf( '<option value="right"%s>%s</option>', selected( $current, 'right', false ), esc_html__( 'Bottom right', 'smbworks-feedback' ) );
			printf( '<option value="left"%s>%s</option>', selected( $current, 'left', false ), esc_html__( 'Bottom left', 'smbworks-feedback' ) );
			echo '</select>';
		},
		'smbfb_settings',
		'smbfb_widget',
		array( 'label_for' => 'smbfb_position' )
	);

	add_settings_field(
		'types',
		__( 'Feedback types', 'smbworks-feedback' ),
		static function () {
			$saved = smbfb_setting( 'types' );
			$lines = array();
			foreach ( $saved ? $saved : smbfb_default_types() as $key => $label ) {
				$lines[] = $key . ' | ' . $label;
			}
			printf(
				'<textarea class="large-text code" rows="7" name="smbfb_settings[types]" id="smbfb_types">%s</textarea>',
				esc_textarea( implode( "\n", $lines ) )
			);
			echo '<p class="description">' . esc_html__( 'One per line, in the order the widget lists them: key | Label. The key is saved with each item, so change labels freely but keep keys stable. A line with only a label gets a key made from it. Up to 20 types; leave empty for the defaults.', 'smbworks-feedback' ) . '</p>';
			if ( has_filter( 'smbfb_types' ) ) {
				echo '<p class="description">' . esc_html__( 'Note: code on this site also changes the list through the smbfb_types filter.', 'smbworks-feedback' ) . '</p>';
			}
		},
		'smbfb_settings',
		'smbfb_widget',
		array( 'label_for' => 'smbfb_types' )
	);

	add_settings_field(
		'notify_emails',
		__( 'New feedback goes to', 'smbworks-feedback' ),
		static function () {
			printf(
				'<input type="text" class="regular-text" name="smbfb_settings[notify_emails]" id="smbfb_notify_emails" value="%s">',
				esc_attr( smbfb_setting( 'notify_emails' ) )
			);
			echo '<p class="description">' . esc_html__( 'Comma-separated addresses. Leave empty to email only the people @mentioned.', 'smbworks-feedback' ) . '</p>';
		},
		'smbfb_settings',
		'smbfb_email',
		array( 'label_for' => 'smbfb_notify_emails' )
	);

	add_settings_field(
		'notify_author',
		__( 'Replies', 'smbworks-feedback' ),
		static function () {
			printf(
				'<label><input type="checkbox" name="smbfb_settings[notify_author]" value="1"%s> %s</label>',
				checked( smbfb_setting( 'notify_author' ), 1, false ),
				esc_html__( 'Email the person who left the feedback when someone replies', 'smbworks-feedback' )
			);
		},
		'smbfb_settings',
		'smbfb_email'
	);

	add_settings_field(
		'delete_on_uninstall',
		__( 'Uninstall', 'smbworks-feedback' ),
		static function () {
			printf(
				'<label><input type="checkbox" name="smbfb_settings[delete_on_uninstall]" value="1"%s> %s</label>',
				checked( smbfb_setting( 'delete_on_uninstall' ), 1, false ),
				esc_html__( 'Delete all feedback, tables and settings when the plugin is deleted', 'smbworks-feedback' )
			);
		},
		'smbfb_settings',
		'smbfb_data'
	);
}

function smbfb_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Feedback settings', 'smbworks-feedback' ) . '</h1><form action="options.php" method="post">';
	settings_fields( 'smbfb_settings' );
	do_settings_sections( 'smbfb_settings' );
	submit_button();
	echo '</form></div>';
}
