<?php
/**
 * Updates from GitHub Releases, through the core `Update URI` header (WordPress 5.8+).
 *
 * WordPress asks update_plugins_github.com for this plugin twice a day. We answer with the latest release
 * that has a smbworks-feedback.zip attached (built by .github/workflows/release.yml), and WordPress shows
 * the usual update notice, button and auto-update toggle.
 */

defined( 'ABSPATH' ) || exit;

define( 'SMBFB_UPDATE_SLUG', 'smbworks-feedback' );

add_filter( 'update_plugins_github.com', 'smbfb_update_check', 10, 3 );
add_filter( 'plugins_api', 'smbfb_update_details', 10, 3 );
add_action( 'load-update-core.php', 'smbfb_update_forget' );
add_action( 'upgrader_process_complete', 'smbfb_update_forget' );

/**
 * Repository (owner/name) that releases come from. Forks can point elsewhere.
 */
function smbfb_update_repository() {
	return apply_filters( 'smbfb_update_repository', 'SMBworks/smbworks-feedback' );
}

/**
 * Latest usable release, cached for six hours (one hour after a failed lookup).
 *
 * @return array{version: string, package: string, url: string, notes: string, published: string}|null
 */
function smbfb_latest_release() {
	$cached = get_site_transient( 'smbfb_latest_release' );
	if ( false !== $cached ) {
		return $cached ? $cached : null;
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/' . smbfb_update_repository() . '/releases/latest',
		apply_filters(
			'smbfb_update_request_args',
			array(
				'timeout'    => 10,
				'headers'    => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
				// GitHub requires a user agent; keep it generic.
				'user-agent' => 'SMBworks-Feedback/' . SMBFB_VERSION,
			)
		)
	);

	$release = null;
	if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$release = is_array( $data ) ? smbfb_parse_release( $data ) : null;
	}

	// Cache misses too (as ''), so a GitHub outage or rate limit doesn't mean a request on every check.
	set_site_transient( 'smbfb_latest_release', $release ? $release : '', $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $release;
}

/**
 * Pull what we need from a GitHub release. Only releases with the built zip count: GitHub's own
 * source archives unpack into a differently named folder, which would install a second copy.
 */
function smbfb_parse_release( $data ) {
	if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) || empty( $data['tag_name'] ) ) {
		return null;
	}

	$package = '';
	foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
		if ( SMBFB_UPDATE_SLUG . '.zip' === ( $asset['name'] ?? '' ) ) {
			$package = (string) $asset['browser_download_url'];
		}
	}
	$version = ltrim( (string) $data['tag_name'], 'vV' );

	if ( '' === $package || ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
		return null;
	}

	return array(
		'version'   => $version,
		'package'   => esc_url_raw( $package ),
		'url'       => esc_url_raw( (string) ( $data['html_url'] ?? '' ) ),
		'notes'     => (string) ( $data['body'] ?? '' ),
		'published' => (string) ( $data['published_at'] ?? '' ),
	);
}

/**
 * Answer WordPress's update check for this plugin. Core compares the version and decides.
 */
function smbfb_update_check( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( SMBFB_FILE ) !== $plugin_file ) {
		return $update;
	}

	$release = smbfb_latest_release();
	if ( ! $release ) {
		return $update;
	}

	return array(
		'slug'    => SMBFB_UPDATE_SLUG,
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	);
}

/**
 * The "View details" popup on the Plugins and Updates screens.
 */
function smbfb_update_details( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || SMBFB_UPDATE_SLUG !== ( $args->slug ?? '' ) ) {
		return $result;
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$release = smbfb_latest_release();
	$plugin  = get_plugin_data( SMBFB_FILE, false, false );
	$notes   = $release && '' !== trim( $release['notes'] )
		? '<pre style="white-space:pre-wrap">' . esc_html( $release['notes'] ) . '</pre>'
		: '<p>' . esc_html__( 'See the release page on GitHub for changes.', 'smbworks-feedback' ) . '</p>';

	return (object) array(
		'name'          => $plugin['Name'],
		'slug'          => SMBFB_UPDATE_SLUG,
		'version'       => $release ? $release['version'] : $plugin['Version'],
		'author'        => $plugin['Author'],
		'homepage'      => 'https://github.com/' . smbfb_update_repository(),
		'requires'      => $plugin['RequiresWP'],
		'requires_php'  => $plugin['RequiresPHP'],
		'last_updated'  => $release ? $release['published'] : '',
		'download_link' => $release ? $release['package'] : '',
		'sections'      => array(
			'description' => '<p>' . esc_html( $plugin['Description'] ) . '</p>',
			'changelog'   => $notes,
		),
	);
}

/**
 * Drop the cached release after an update, and when someone presses "Check again" under Dashboard → Updates.
 */
function smbfb_update_forget() {
	delete_site_transient( 'smbfb_latest_release' );
}
