<?php
/**
 * Integration tests for Doppelslug, run inside WordPress Playground.
 *
 * Mounted at /wordpress/doppelslug-tests/ and driven over HTTP by tests/run-playground-tests.sh.
 * Never shipped with the plugin.
 *
 * phpcs:ignoreFile -- Test harness, not plugin code.
 *
 * @package Doppelslug
 */

require dirname( __DIR__ ) . '/wp-load.php';

header( 'Content-Type: text/plain; charset=utf-8' );

$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

/**
 * Records one assertion.
 */
function ds_assert( $label, $condition, $detail = '' ) {
	global $ds_failures;
	if ( $condition ) {
		echo "PASS  {$label}\n";
	} else {
		++$ds_failures;
		echo "FAIL  {$label}" . ( '' !== $detail ? "\n      {$detail}" : '' ) . "\n";
	}
}

/**
 * Creates (or recreates) a test post.
 */
function ds_post( $slug, $title, $status = 'publish', $type = 'post', $author = 1 ) {
	$id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => 'Test content.',
			'post_name'   => $slug,
			'post_status' => $status,
			'post_type'   => $type,
			'post_author' => $author,
		)
	);
	update_post_meta( $id, '_doppelslug_test', 1 );
	return $id;
}

function ds_user( $login, $role ) {
	$user = get_user_by( 'login', $login );
	if ( $user ) {
		return $user->ID;
	}
	return wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password(),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
}

/**
 * Calls the REST endpoint as a user and returns array( status, data ).
 */
function ds_check( $user_id, $post_id, $slug = '', $title = '' ) {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( 'GET', '/doppelslug/v1/check' );
	$request->set_query_params(
		array(
			'post_id' => $post_id,
			'slug'    => $slug,
			'title'   => $title,
		)
	);
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

function ds_set( $key, $value ) {
	$settings         = (array) get_option( 'doppelslug_settings', array() );
	$settings[ $key ] = $value;
	update_option( 'doppelslug_settings', $settings );
}

function ds_ids() {
	return (array) get_option( 'doppelslug_test_ids', array() );
}

function ds_has_html( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			if ( ds_has_html( $item ) ) {
				return true;
			}
		}
		return false;
	}
	return is_string( $value ) && preg_match( '/<[a-z\/!]/i', $value );
}

$ds_failures = 0;

switch ( $action ) {
	case 'setup':
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules( false );

		foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_doppelslug_test' ) ) as $old ) {
			wp_delete_post( $old->ID, true );
		}
		foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $sample ) {
			wp_delete_post( $sample->ID, true ); // "Hello world!" would otherwise match "h..." prefixes.
		}

		$ids = array(
			'author'      => ds_user( 'ds_author', 'author' ),
			'contributor' => ds_user( 'ds_contributor', 'contributor' ),
			'subscriber'  => ds_user( 'ds_subscriber', 'subscriber' ),
		);

		$ids['book']      = ds_post( 'how-write-book', 'How to write a book' );
		$ids['blog']      = ds_post( 'how-write-blog', 'How to write a blog', 'draft', 'post', $ids['author'] );
		$ids['takeover']  = ds_post( 'how-write', 'How to write', 'draft' );
		$ids['untitled']  = ds_post( '', '', 'draft' );
		$ids['services']  = ds_post( 'services', 'Services', 'publish', 'page' );
		$ids['svc_post']  = ds_post( 'services', 'Our services', 'draft' );
		$ids['fa_book']   = ds_post( '', 'آموزش نوشتن کتاب' );
		$ids['fa_blog']   = ds_post( '', 'آموزش نوشتن وبلاگ', 'draft' );
		$ids['rice']      = ds_post( 'how-to-cook-rice', 'How to cook rice' );
		$ids['bike']      = ds_post( 'how-to-fix-a-bike', 'How to fix a bike' );
		$ids['pasta']     = ds_post( 'how-to-cook-pasta', 'How to cook pasta', 'draft' );
		$ids['zebra']     = ds_post( 'zebra-crossing-guide', 'Zebra crossing guide', 'draft' );
		$ids['block']     = ds_post( 'my-pattern', 'My pattern', 'publish', 'wp_block' );
		$ids['tags']      = ds_post( 'title-with-tags', 'Book <em>&amp;</em> "quotes"' );

		update_option( 'doppelslug_test_ids', $ids );
		delete_option( 'doppelslug_settings' );
		echo wp_json_encode( $ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		break;

	case 'unit':
		$ids   = ds_ids();
		$admin = 1;
		delete_option( 'doppelslug_settings' );

		// 1. The reported case: draft how-write-blog vs published how-write-book.
		list( $code, $r ) = ds_check( $admin, $ids['blog'], 'how-write-blog' );
		ds_assert( 'admin can check', 200 === $code, "status {$code}" );
		ds_assert( 'how-write-blog conflicts', 'conflicts' === $r['status'], wp_json_encode( $r ) );
		ds_assert( 'one shared group', 1 === count( $r['groups'] ) && 'shared' === $r['groups'][0]['kind'], wp_json_encode( $r['groups'] ) );
		ds_assert( 'address is .../how-write-b', '/how-write-b' === substr( $r['groups'][0]['address'], -12 ), $r['groups'][0]['address'] );
		ds_assert( 'lookalike is the book', $ids['book'] === $r['groups'][0]['posts'][0]['id'] );
		ds_assert( 'says where it leads now', false !== strpos( $r['groups'][0]['now'], 'How to write a book' ), $r['groups'][0]['now'] );
		ds_assert( 'settings link for admin', '' !== $r['settingsUrl'] );
		ds_assert( 'no HTML anywhere in the report', ! ds_has_html( $r ), wp_json_encode( $r ) );

		// 2. Slug predicted from the title when there is no slug yet.
		list( $code, $r ) = ds_check( $admin, $ids['untitled'], '', 'How write blogging' );
		ds_assert( 'title-only check predicts how-write-blogging', 'how-write-blogging' === $r['slug'] && 'conflicts' === $r['status'], wp_json_encode( $r ) );

		// 3. Draft how-write would take over /how-write, which leads to the book today.
		list( $code, $r ) = ds_check( $admin, $ids['takeover'], 'how-write' );
		ds_assert( 'takeover detected', 'conflicts' === $r['status'] && 'takeover' === $r['groups'][0]['kind'], wp_json_encode( $r ) );
		ds_assert( 'takeover names current destination', false !== strpos( $r['groups'][0]['message'], 'How to write a book' ), $r['groups'][0]['message'] );

		// 4. Same slug used by a page.
		list( $code, $r ) = ds_check( $admin, $ids['svc_post'], 'services' );
		ds_assert( 'exact duplicate across post types', 'conflicts' === $r['status'] && 'same' === $r['groups'][0]['kind'], wp_json_encode( $r ) );

		// 5. Non-Latin slugs.
		$fa = get_post( $ids['fa_blog'] );
		list( $code, $r ) = ds_check( $admin, $ids['fa_blog'], '', $fa->post_title );
		ds_assert( 'Persian slugs conflict', 'conflicts' === $r['status'], wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
		ds_assert( 'Persian address is readable and trimmed', isset( $r['groups'][0] ) && '/آموزش-نوشتن' === mb_substr( $r['groups'][0]['address'], -12 ), isset( $r['groups'][0] ) ? $r['groups'][0]['address'] : '' );

		// 6. Sensitivity.
		list( $code, $r ) = ds_check( $admin, $ids['pasta'], 'how-to-cook-pasta' );
		$found = wp_list_pluck( call_user_func_array( 'array_merge', wp_list_pluck( $r['groups'], 'posts' ) ?: array( array() ) ), 'id' );
		ds_assert( 'balanced: rice yes, bike no', in_array( $ids['rice'], $found, true ) && ! in_array( $ids['bike'], $found, true ), wp_json_encode( $found ) );

		ds_set( 'sensitivity', 'sensitive' );
		list( $code, $r ) = ds_check( $admin, $ids['pasta'], 'how-to-cook-pasta' );
		$found = wp_list_pluck( call_user_func_array( 'array_merge', wp_list_pluck( $r['groups'], 'posts' ) ?: array( array() ) ), 'id' );
		ds_assert( 'sensitive: rice, bike and book', in_array( $ids['rice'], $found, true ) && in_array( $ids['bike'], $found, true ) && in_array( $ids['book'], $found, true ), wp_json_encode( $found ) );

		ds_set( 'sensitivity', 'relaxed' );
		list( $code, $r ) = ds_check( $admin, $ids['blog'], 'how-write-blog' );
		ds_assert( 'relaxed: how-write-blog still warns', 'conflicts' === $r['status'] );
		list( $code, $r ) = ds_check( $admin, $ids['zebra'], 'how-to-cook-pasta-fast' );
		ds_assert( 'relaxed: how-to-cook-pasta-fast ignores rice', 'ok' === $r['status'], wp_json_encode( $r ) );
		delete_option( 'doppelslug_settings' );

		// 7. Nothing similar.
		list( $code, $r ) = ds_check( $admin, $ids['zebra'], 'zebra-crossing-guide' );
		ds_assert( 'distinct slug is ok', 'ok' === $r['status'] && '' !== $r['summary'], wp_json_encode( $r ) );

		// 8. Titles are plain text.
		list( $code, $r ) = ds_check( $admin, $ids['zebra'], 'title-with-tags-2' );
		$title = isset( $r['groups'][0]['posts'][0]['title'] ) ? $r['groups'][0]['posts'][0]['title'] : '';
		ds_assert( 'titles are stripped and decoded', false === strpos( $title, '<' ) && false !== strpos( $title, '&' ), $title );

		// 9. Permissions.
		list( $code ) = ds_check( 0, $ids['blog'], 'how-write-blog' );
		ds_assert( 'logged out is rejected (401)', 401 === $code, "status {$code}" );
		list( $code ) = ds_check( $ids['subscriber'], $ids['blog'], 'how-write-blog' );
		ds_assert( 'subscriber is rejected (403)', 403 === $code, "status {$code}" );
		list( $code ) = ds_check( $ids['contributor'], $ids['blog'], 'how-write-blog' );
		ds_assert( "contributor cannot check someone else's post", 403 === $code, "status {$code}" );
		list( $code, $r ) = ds_check( $ids['author'], $ids['blog'], 'how-write-blog' );
		ds_assert( 'author can check own post', 200 === $code && 'conflicts' === $r['status'], "status {$code}" );
		ds_assert( 'no settings link for non-admins', '' === $r['settingsUrl'] );
		list( $code ) = ds_check( $admin, 999999, 'x' );
		ds_assert( 'missing post is rejected', in_array( $code, array( 403, 404 ), true ), "status {$code}" );
		list( $code ) = ds_check( $admin, 'abc', 'x' );
		ds_assert( 'non-numeric post_id is rejected (400)', 400 === $code, "status {$code}" );
		list( $code ) = ds_check( $admin, $ids['blog'], str_repeat( 'a', 1001 ) );
		ds_assert( 'over-long slug is rejected (400)', 400 === $code, "status {$code}" );

		// 10. Modes.
		ds_set( 'guessing', 'unique' );
		list( $code, $r ) = ds_check( $admin, $ids['blog'], 'how-write-blog' );
		ds_assert( 'unique mode: Not Found wording', false !== strpos( $r['groups'][0]['message'], 'Not Found' ), $r['groups'][0]['message'] );
		ds_set( 'guessing', 'exact' );
		list( $code, $r ) = ds_check( $admin, $ids['blog'], 'how-write-blog' );
		ds_assert( 'exact mode: guessing_off', 'guessing_off' === $r['status'], $r['status'] );
		ds_set( 'guessing', 'off' );
		list( $code, $r ) = ds_check( $admin, $ids['blog'], 'how-write-blog' );
		ds_assert( 'off mode: guessing_off', 'guessing_off' === $r['status'], $r['status'] );
		delete_option( 'doppelslug_settings' );

		add_filter( 'do_redirect_guess_404_permalink', '__return_false', 99 );
		list( $code, $r ) = ds_check( $admin, $ids['blog'], 'how-write-blog' );
		ds_assert( 'guessing disabled by another plugin is respected', 'guessing_off' === $r['status'], $r['status'] );
		remove_filter( 'do_redirect_guess_404_permalink', '__return_false', 99 );

		// 11. Not applicable.
		list( $code, $r ) = ds_check( $admin, $ids['block'], 'my-pattern' );
		ds_assert( 'non-viewable post type is not applicable', 'not_applicable' === $r['status'], $r['status'] );
		update_option( 'permalink_structure', '' );
		list( $code, $r ) = ds_check( $admin, $ids['blog'], 'how-write-blog' );
		ds_assert( 'plain permalinks are not applicable', 'not_applicable' === $r['status'], $r['status'] );
		update_option( 'permalink_structure', '/%postname%/' );

		// 12. Settings sanitizer.
		$clean = Doppelslug_Settings::sanitize( array( 'guessing' => 'evil', 'sensitivity' => array( 'x' ), 'extra' => 1 ) );
		ds_assert( 'sanitizer falls back to defaults and drops extras', Doppelslug_Settings::defaults() === $clean, wp_json_encode( $clean ) );
		$clean = Doppelslug_Settings::sanitize( 'not-an-array' );
		ds_assert( 'sanitizer handles non-arrays', Doppelslug_Settings::defaults() === $clean );

		// 13. Unique match: an exact slug wins over longer ones.
		$extra = ds_post( 'how-write-book-2', 'How to write a book, part 2' );
		ds_assert( 'unique: exact slug wins', $ids['book'] === Doppelslug_Detector::unique_match( 'how-write-book' ) );
		ds_assert( 'unique: ambiguous prefix gives 0', 0 === Doppelslug_Detector::unique_match( 'how-write-bo' ) );
		ds_assert( 'unique: single match redirects', $extra === Doppelslug_Detector::unique_match( 'how-write-book-' ) );
		wp_delete_post( $extra, true );

		// 14. Many lookalikes are capped.
		$many = array();
		for ( $i = 1; $i <= 22; $i++ ) {
			$many[] = ds_post( 'many-posts-' . $i, 'Many posts ' . $i );
		}
		list( $code, $r ) = ds_check( $admin, $ids['zebra'], 'many-posts-x' );
		ds_assert( 'more than 20 lookalikes are capped', true === $r['more'] && 20 === $r['total'], wp_json_encode( array( $r['more'], $r['total'] ) ) );
		foreach ( $many as $id ) {
			wp_delete_post( $id, true );
		}

		// 15. Core's query and ours agree.
		ds_assert( 'core_guess finds the book for how-write-b', $ids['book'] === Doppelslug_Detector::core_guess( 'how-write-b' ) );

		echo "\n" . ( $ds_failures ? "{$ds_failures} FAILED" : 'ALL PASSED' ) . "\n";
		break;

	case 'mode':
		ds_set( 'guessing', sanitize_key( $_GET['value'] ) );
		echo 'mode=' . Doppelslug_Settings::get( 'guessing' );
		break;

	case 'publish':
		$ids = ds_ids();
		wp_publish_post( $ids[ sanitize_key( $_GET['key'] ) ] );
		echo get_permalink( $ids[ sanitize_key( $_GET['key'] ) ] );
		break;

	case 'core_guess':
		$post_id = Doppelslug_Detector::core_guess( sanitize_title( wp_unslash( $_GET['address'] ) ) );
		echo $post_id ? get_permalink( $post_id ) : 'none';
		break;

	case 'plugin':
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$file = sanitize_key( $_GET['slug'] ) . '/' . sanitize_key( $_GET['slug'] ) . '.php';
		if ( 'on' === $_GET['state'] ) {
			activate_plugin( $file );
		} else {
			deactivate_plugins( $file );
		}
		echo is_plugin_active( $file ) ? 'active' : 'inactive';
		break;

	case 'uninstall':
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		update_option( 'doppelslug_settings', array( 'guessing' => 'unique', 'sensitivity' => 'relaxed' ) );
		$before = false !== get_option( 'doppelslug_settings' );
		deactivate_plugins( 'doppelslug/doppelslug.php' );
		uninstall_plugin( 'doppelslug/doppelslug.php' );
		wp_cache_flush();
		$after = get_option( 'doppelslug_settings', 'gone' );
		echo ( $before && 'gone' === $after ) ? 'PASS  uninstall removes doppelslug_settings' : 'FAIL  uninstall left: ' . wp_json_encode( $after );
		activate_plugin( 'doppelslug/doppelslug.php' );
		echo "
" . ( false !== get_option( 'doppelslug_settings' ) ? 'PASS  reactivation stores defaults again' : 'FAIL  no defaults after reactivation' );
		break;

	case 'i18n':
		$ids  = ds_ids();
		$blog = get_post( $ids['blog'] );
		wp_set_current_user( 1 );
		delete_option( 'doppelslug_settings' );

		$english = 'published item has an address that starts like this one.';
		$results = array();
		foreach ( array( 'fa_IR', 'de_DE', 'en_US' ) as $lang ) {
			// Not $locale: this file runs in global scope, where $locale is WordPress's own global.
			$switched         = 'en_US' === $lang ? true : switch_to_locale( $lang );
			$results[ $lang ] = array( $switched, Doppelslug_Report::build( $blog, 'how-write-blog' ) );
			if ( 'en_US' !== $lang && $switched ) {
				restore_previous_locale();
			}
		}

		list( $ok, $fa ) = $results['fa_IR'];
		ds_assert( 'Persian locale is installed', $ok );
		ds_assert( 'Persian: summary is translated', false !== strpos( $fa['summary'], 'نشانی' ) && false === strpos( $fa['summary'], $english ), $fa['summary'] );
		ds_assert( 'Persian: group message is translated', false !== strpos( $fa['groups'][0]['message'], 'بازدیدکنندگانی' ), $fa['groups'][0]['message'] );
		ds_assert( 'Persian: address stays left-to-right inside the sentence', false !== strpos( $fa['groups'][0]['message'], "\u{2066}" ) );
		ds_assert( 'Persian: setting labels are translated', 'تنظیمات' === __( 'Settings', 'doppelslug' ) || ( switch_to_locale( 'fa_IR' ) && 'تنظیمات' === __( 'Settings', 'doppelslug' ) && restore_previous_locale() ) );

		list( $ok, $de ) = $results['de_DE'];
		ds_assert( 'German locale is installed', $ok );
		ds_assert( 'German falls back to English', false !== strpos( $de['summary'], $english ), $de['summary'] );

		list( $ok, $en ) = $results['en_US'];
		ds_assert( 'English is the source language', false !== strpos( $en['summary'], $english ), $en['summary'] );

		echo "\n" . ( $ds_failures ? "{$ds_failures} FAILED" : 'ALL PASSED' ) . "\n";
		break;

	case 'user_locale':
		update_user_meta( 1, 'locale', 'en_US' === $_GET['value'] ? '' : sanitize_key( $_GET['value'] ) );
		echo 'locale=' . get_user_locale( 1 );
		break;

	case 'versions':
		echo 'WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION;
		break;

	case 'debuglog':
		$log = WP_CONTENT_DIR . '/debug.log';
		echo file_exists( $log ) ? file_get_contents( $log ) : '(no debug.log)';
		break;

	default:
		echo 'unknown action';
}
