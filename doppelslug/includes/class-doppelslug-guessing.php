<?php
/**
 * Applies the chosen guessing mode to 404 requests on the front end.
 *
 * @package Doppelslug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the three core filters that control redirect_guess_404_permalink().
 *
 * The callbacks only run on 404 requests, so normal page loads never read the settings.
 */
final class Doppelslug_Guessing {

	/**
	 * Hooks the core guessing filters.
	 */
	public static function register() {
		add_filter( 'do_redirect_guess_404_permalink', array( __CLASS__, 'filter_do_guess' ) );
		add_filter( 'strict_redirect_guess_404_permalink', array( __CLASS__, 'filter_strict_guess' ) );
		add_filter( 'pre_redirect_guess_404_permalink', array( __CLASS__, 'filter_unique_guess' ) );
	}

	/**
	 * Switches guessing off entirely in 'off' mode.
	 *
	 * @param bool $do_guess Whether WordPress should guess.
	 * @return bool
	 */
	public static function filter_do_guess( $do_guess ) {
		return 'off' === Doppelslug_Settings::get( 'guessing' ) ? false : $do_guess;
	}

	/**
	 * Limits guessing to exact slug matches in 'exact' mode.
	 *
	 * @param bool $strict Whether WordPress should only redirect exact matches.
	 * @return bool
	 */
	public static function filter_strict_guess( $strict ) {
		return 'exact' === Doppelslug_Settings::get( 'guessing' ) ? true : $strict;
	}

	/**
	 * In 'unique' mode, redirects only when the address points at one post.
	 *
	 * Mirrors redirect_guess_404_permalink(), including its post type and date refinements,
	 * but returns false (Not Found) when several posts match.
	 *
	 * @param null|string|false $pre Short-circuit value from earlier callbacks.
	 * @return null|string|false Redirect URL, false for Not Found, or null to let WordPress guess.
	 */
	public static function filter_unique_guess( $pre ) {
		if ( null !== $pre || 'unique' !== Doppelslug_Settings::get( 'guessing' ) ) {
			return $pre;
		}

		// Leave exact-match guessing, if something else switched it on, to WordPress.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a core filter.
		if ( apply_filters( 'strict_redirect_guess_404_permalink', false ) ) {
			return $pre;
		}

		$name = (string) get_query_var( 'name' );

		if ( '' === $name ) {
			return $pre;
		}

		$post_types = Doppelslug_Detector::guessable_post_types();
		$requested  = get_query_var( 'post_type' );

		if ( $requested ) {
			$post_types = array_values( array_intersect( (array) $requested, $post_types ) );

			if ( ! $post_types ) {
				return false;
			}
		}

		$post_id = Doppelslug_Detector::unique_match(
			$name,
			array(
				'post_types' => $post_types,
				'year'       => absint( get_query_var( 'year' ) ),
				'monthnum'   => absint( get_query_var( 'monthnum' ) ),
				'day'        => absint( get_query_var( 'day' ) ),
			)
		);

		if ( ! $post_id ) {
			return false;
		}

		$feed = (string) get_query_var( 'feed' );
		$page = absint( get_query_var( 'page' ) );

		if ( '' !== $feed ) {
			return get_post_comments_feed_link( $post_id, $feed );
		}

		if ( $page > 1 ) {
			return trailingslashit( get_permalink( $post_id ) ) . user_trailingslashit( (string) $page, 'single_paged' );
		}

		return get_permalink( $post_id );
	}
}
