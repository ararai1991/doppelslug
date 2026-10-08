<?php
/**
 * Removes Doppelslug's settings when the plugin is deleted.
 *
 * @package Doppelslug
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'doppelslug_settings' );

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $doppelslug_site_id ) {
		switch_to_blog( $doppelslug_site_id );
		delete_option( 'doppelslug_settings' );
		restore_current_blog();
	}
}
