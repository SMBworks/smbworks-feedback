<?php
/**
 * Dashboard widget: open counts and the open items that need attention first.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_dashboard_setup', 'smbfb_dashboard_setup' );
add_action( 'admin_enqueue_scripts', 'smbfb_dashboard_enqueue' );

function smbfb_dashboard_setup() {
	if ( smbfb_can_manage() ) {
		wp_add_dashboard_widget( 'smbfb_dashboard', __( 'Feedback', 'smbworks-feedback' ), 'smbfb_dashboard_widget' );
	}
}

function smbfb_dashboard_enqueue( $hook ) {
	if ( 'index.php' === $hook && smbfb_can_manage() ) {
		wp_enqueue_style( 'smbfb-dashboard', SMBFB_URL . 'assets/dashboard.css', array(), SMBFB_VERSION );
	}
}

/**
 * Link into the inbox with filters applied (read from the hash by admin.js).
 */
function smbfb_inbox_url( $filters = array() ) {
	$url = admin_url( 'admin.php?page=smbworks-feedback' );
	return $filters ? $url . '#' . http_build_query( $filters ) : $url;
}

function smbfb_dashboard_widget() {
	$user_id   = get_current_user_id();
	$open      = smbfb_query_items(
		array(
			'status'   => 'open',
			'orderby'  => 'priority',
			'per_page' => (int) apply_filters( 'smbfb_dashboard_items', 5 ),
		)
	);
	$high      = smbfb_query_items(
		array(
			'status'   => 'open',
			'priority' => 'high',
			'per_page' => 1,
		)
	)['total'];
	$mentioned = smbfb_query_items(
		array(
			'status'    => 'open',
			'mentioned' => $user_id,
			'per_page'  => 1,
		)
	)['total'];

	$stats = array(
		array( $open['total'], __( 'Open', 'smbworks-feedback' ), array(), '' ),
		array( $high, __( 'High priority', 'smbworks-feedback' ), array( 'priority' => 'high' ), $high ? 'is-high' : '' ),
		array( $mentioned, __( 'Mention you', 'smbworks-feedback' ), array( 'mentioned' => 1 ), '' ),
	);
	?>
	<div class="smbfb-dash">
		<ul class="smbfb-dash__stats">
			<?php foreach ( $stats as list( $count, $label, $filters, $class ) ) : ?>
				<li class="<?php echo esc_attr( $class ); ?>">
					<a href="<?php echo esc_url( smbfb_inbox_url( $filters ) ); ?>">
						<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
						<span><?php echo esc_html( $label ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( ! $open['items'] ) : ?>
			<p class="smbfb-dash__empty"><?php esc_html_e( 'No open feedback.', 'smbworks-feedback' ); ?></p>
		<?php else : ?>
			<h3 class="smbfb-dash__heading"><?php esc_html_e( 'Needs attention', 'smbworks-feedback' ); ?></h3>
			<ul class="smbfb-dash__items">
				<?php
				foreach ( $open['items'] as $row ) :
					$priority = smbfb_priority_slug( $row->priority );
					?>
					<li>
						<a href="<?php echo esc_url( smbfb_item_admin_url( $row->id ) ); ?>">
							<span class="smbfb-dash__meta">
								<?php if ( 'normal' !== $priority ) : ?>
									<span class="smbfb-dash__priority smbfb-dash__priority--<?php echo esc_attr( $priority ); ?>"><?php echo esc_html( smbfb_priorities()[ $priority ][1] ); ?></span>
								<?php endif; ?>
								<span class="smbfb-dash__type"><?php echo esc_html( smbfb_type_label( $row->type ) ); ?></span>
								<span class="smbfb-dash__page"><?php echo esc_html( smbfb_page_label( $row ) ); ?></span>
							</span>
							<span class="smbfb-dash__comment"><?php echo esc_html( wp_trim_words( $row->comment, 18 ) ); ?></span>
							<span class="smbfb-dash__byline">
								<?php
								printf(
									/* translators: 1: author name, 2: time ago, e.g. "3 hours" */
									esc_html__( '%1$s · %2$s ago', 'smbworks-feedback' ),
									esc_html( $row->author_name ),
									esc_html( human_time_diff( strtotime( $row->last_activity_at . ' UTC' ) ) )
								);
								?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p class="smbfb-dash__footer">
			<a href="<?php echo esc_url( smbfb_inbox_url() ); ?>"><?php esc_html_e( 'Open the feedback inbox', 'smbworks-feedback' ); ?></a>
		</p>
	</div>
	<?php
}
