<?php
/**
 * Plugin Name:       SMBworks Feedback
 * Description:       Front-end feedback widget with @mentions, threaded replies and a review inbox in wp-admin. Works with any theme.
 * Version:           1.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            SMBworks
 * Author URI:        https://smbworks.llc/
 * License:           GPL-2.0-or-later
 * Text Domain:       smbworks-feedback
 */

defined( 'ABSPATH' ) || exit;

define( 'SMBFB_VERSION', '1.1.0' );
define( 'SMBFB_FILE', __FILE__ );
define( 'SMBFB_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMBFB_URL', plugin_dir_url( __FILE__ ) );

require_once SMBFB_DIR . 'includes/schema.php';
require_once SMBFB_DIR . 'includes/settings.php';
require_once SMBFB_DIR . 'includes/model.php';
require_once SMBFB_DIR . 'includes/mentions.php';
require_once SMBFB_DIR . 'includes/rest.php';
require_once SMBFB_DIR . 'includes/widget.php';
require_once SMBFB_DIR . 'includes/admin.php';
require_once SMBFB_DIR . 'includes/dashboard.php';
require_once SMBFB_DIR . 'includes/privacy.php';

register_activation_hook( __FILE__, 'smbfb_install' );

// Covers updates copied over the old folder and network activation, which skip the activation hook on other sites.
add_action( 'plugins_loaded', 'smbfb_maybe_upgrade' );
