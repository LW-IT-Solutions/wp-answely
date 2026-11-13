<?php
/**
 * Answely: remove all settings of the plugin when it is deleted.
 *
 * Documents sent by the content sync stay at Answely; switch the sync off
 * before deleting the plugin to remove them there (see the settings page).
 *
 * @package Answely
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'answely', 'answely_sync', 'answely_sync_sperre', 'answely_customer', 'answely_push_token', 'answely_status' ) as $answely_option ) {
	delete_option( $answely_option );
}
delete_transient( 'answely_customer_pair_proof' );
wp_clear_scheduled_hook( 'answely_sync_lauf' );
delete_post_meta_by_key( '_answely_exclude' );
