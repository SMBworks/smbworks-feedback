<?php
/**
 * Tables, schema version and capabilities.
 *
 * To change the schema: edit smbfb_schema_sql() (dbDelta adds columns and keys), bump SMBFB_DB_VERSION,
 * and put anything dbDelta can't do (renames, data moves, drops) in smbfb_migrations() under the new version.
 */

defined( 'ABSPATH' ) || exit;

define( 'SMBFB_DB_VERSION', 2 );

/**
 * Table names, keyed by short name.
 */
function smbfb_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'smbfb_' . $name;
}

function smbfb_schema_sql() {
	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	$items   = smbfb_table( 'items' );
	$replies = smbfb_table( 'replies' );
	$mention = smbfb_table( 'mentions' );

	// dbDelta is picky: one column per line, two spaces after PRIMARY KEY, KEY not INDEX.
	return array(
		"CREATE TABLE $items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'open',
  type varchar(20) NOT NULL DEFAULT '',
  priority tinyint(3) unsigned NOT NULL DEFAULT 1,
  comment longtext NOT NULL,
  page_url text NOT NULL,
  page_key varchar(191) NOT NULL DEFAULT '',
  page_title varchar(255) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_name varchar(100) NOT NULL DEFAULT '',
  author_email varchar(100) NOT NULL DEFAULT '',
  ip_hash char(64) NOT NULL DEFAULT '',
  context longtext NULL,
  reply_count int(10) unsigned NOT NULL DEFAULT 0,
  resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  resolved_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  last_activity_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status_activity (status,last_activity_at),
  KEY status_priority (status,priority,last_activity_at),
  KEY page_key (page_key),
  KEY type (type),
  KEY author_id (author_id),
  KEY author_email (author_email)
) $charset;",
		"CREATE TABLE $replies (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_name varchar(100) NOT NULL DEFAULT '',
  author_email varchar(100) NOT NULL DEFAULT '',
  body longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY item_id (item_id),
  KEY author_email (author_email)
) $charset;",
		"CREATE TABLE $mention (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  reply_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  notified_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY item_reply_user (item_id,reply_id,user_id),
  KEY user_id (user_id)
) $charset;",
	);
}

/**
 * One-off steps per schema version, run after dbDelta when upgrading past that version.
 *
 * @return array<int, callable>
 */
function smbfb_migrations() {
	// 2: priority column. Its default (normal) covers existing rows, so dbDelta is enough.
	return array();
}

function smbfb_install() {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$from = (int) get_option( 'smbfb_db_version', 0 );

	dbDelta( smbfb_schema_sql() );

	foreach ( smbfb_migrations() as $version => $step ) {
		if ( $version > $from && $version <= SMBFB_DB_VERSION ) {
			$step();
		}
	}

	smbfb_add_caps();
	add_option( 'smbfb_settings', smbfb_default_settings() );
	update_option( 'smbfb_db_version', SMBFB_DB_VERSION );
}

function smbfb_maybe_upgrade() {
	if ( (int) get_option( 'smbfb_db_version', 0 ) < SMBFB_DB_VERSION ) {
		smbfb_install();
	}
}

/**
 * Roles that review feedback by default. Add more under Users with any role editor, or with the filter.
 */
function smbfb_add_caps() {
	$roles = apply_filters( 'smbfb_reviewer_roles', array( 'administrator', 'editor' ) );
	foreach ( $roles as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->add_cap( 'smbfb_manage_feedback' );
		}
	}
}

/**
 * Reviewers see the inbox, reply, change status and can be @mentioned.
 */
function smbfb_can_manage( $user_id = 0 ) {
	return $user_id ? user_can( $user_id, 'smbfb_manage_feedback' ) : current_user_can( 'smbfb_manage_feedback' );
}
