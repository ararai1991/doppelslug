<?php
/**
 * Finds published content whose slugs start like a given slug.
 *
 * @package Doppelslug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects lookalike slugs and reproduces how WordPress guesses 404 addresses.
 *
 * Slugs are stored percent-encoded (non-Latin characters become %xx), so all
 * comparisons run on decoded characters and are encoded again for queries.
 */
final class Doppelslug_Detector {

	/**
	 * Most lookalike posts reported for one slug.
	 */
	const MAX_CONFLICTS = 20;

	/**
	 * Shortest shared start, in characters, that can count as a lookalike.
	 */
	const MIN_SHARED = 3;

	/**
	 * Cache group for query results.
	 */
	const CACHE_GROUP = 'doppelslug';

	/**
	 * Where each address leads right now, keyed by encoded address, for this request.
	 *
	 * @var array<string, int>
	 */
	private static $targets = array();

	/**
	 * Post types WordPress may redirect a guessed address to. Mirrors redirect_guess_404_permalink().
	 *
	 * @return string[]
	 */
	public static function guessable_post_types() {
		return array_values( array_filter( get_post_types( array( 'exclude_from_search' => false ) ), 'is_post_type_viewable' ) );
	}

	/**
	 * Post statuses WordPress may redirect a guessed address to. Mirrors redirect_guess_404_permalink().
	 *
	 * @return string[]
	 */
	public static function viewable_statuses() {
		return array_values( array_filter( get_post_stati(), 'is_post_status_viewable' ) );
	}

	/**
	 * How this site currently treats an address that doesn't match any content.
	 *
	 * @return string 'plain' (no pretty permalinks), 'off' (partial addresses are never completed),
	 *                'unique' (only unambiguous ones are) or 'default' (WordPress picks one).
	 */
	public static function guessing_state() {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return 'plain';
		}

		// Ask the core filters, so guessing that a theme or another plugin switched off is respected too.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a core filter.
		if ( false === apply_filters( 'do_redirect_guess_404_permalink', true ) ) {
			return 'off';
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a core filter.
		if ( apply_filters( 'strict_redirect_guess_404_permalink', false ) ) {
			return 'off';
		}

		return 'unique' === Doppelslug_Settings::get( 'guessing' ) ? 'unique' : 'default';
	}

	/**
	 * The slug WordPress will give the post when it is published.
	 *
	 * @param WP_Post $post  Post being edited.
	 * @param string  $slug  Slug typed in the editor; may be empty.
	 * @param string  $title Title typed in the editor, used when there is no slug yet.
	 * @return string Percent-encoded slug, or '' when there is nothing to build one from.
	 */
	public static function predict_slug( WP_Post $post, $slug, $title ) {
		$slug = sanitize_title( '' !== $slug ? $slug : $title );

		if ( '' === $slug ) {
			return '';
		}

		return wp_unique_post_slug( $slug, $post->ID, 'publish', $post->post_type, $post->post_parent );
	}

	/**
	 * Finds published content whose slug starts like this one, grouped by the address they share.
	 *
	 * @param WP_Post $post Post being edited.
	 * @param string  $slug Percent-encoded slug the post has or will get.
	 * @return array {
	 *     @type string $status 'conflicts', 'ok', 'guessing_off' or 'not_applicable'.
	 *     @type string $state  Result of guessing_state().
	 *     @type string $slug   The slug that was checked.
	 *     @type bool   $live   Whether visitors can already reach the post at this slug.
	 *     @type array  $groups Each with 'kind' ('same', 'takeover' or 'shared'), 'address'
	 *                          (encoded), 'target' (post ID the address leads to now, 0 for
	 *                          Not Found) and 'posts' (IDs of the lookalike posts).
	 *     @type int    $total  Number of lookalike posts across all groups.
	 *     @type bool   $more   Whether more lookalikes exist than were reported.
	 * }
	 */
	public static function check( WP_Post $post, $slug ) {
		$result = array(
			'status' => 'not_applicable',
			'state'  => self::guessing_state(),
			'slug'   => $slug,
			'live'   => '' !== $slug && $slug === $post->post_name && is_post_status_viewable( $post->post_status ),
			'groups' => array(),
			'total'  => 0,
			'more'   => false,
		);

		if ( '' === $slug || 'plain' === $result['state'] || ! in_array( $post->post_type, self::guessable_post_types(), true ) ) {
			return $result;
		}

		if ( 'off' === $result['state'] ) {
			$result['status'] = 'guessing_off';
			return $result;
		}

		$chars  = self::chars( $slug );
		$length = count( $chars );
		$min    = self::min_shared_length( $chars, Doppelslug_Settings::get( 'sensitivity' ), $post );
		$rows   = self::find(
			self::encode( array_slice( $chars, 0, $min ) ),
			array(
				'exclude_id' => $post->ID,
				'limit'      => self::MAX_CONFLICTS + 1,
			)
		);

		if ( count( $rows ) > self::MAX_CONFLICTS ) {
			$result['more'] = true;
			$rows           = array_slice( $rows, 0, self::MAX_CONFLICTS );
		}

		$groups = array();

		foreach ( $rows as $row ) {
			$other  = self::chars( $row->post_name );
			$shared = self::shared_length( $chars, $other );

			if ( $shared < $min ) {
				continue;
			}

			if ( $shared === $length && count( $other ) === $length ) {
				// Another item has this exact slug.
				$kind    = 'same';
				$address = $chars;
			} elseif ( $shared === $length && ! $result['live'] ) {
				// This slug is the start of another one, so publishing claims an address that leads there today.
				$kind    = 'takeover';
				$address = $chars;
			} else {
				// The longest address both slugs start with that is neither one exactly.
				$kind    = 'shared';
				$cut     = ( $shared === $length || count( $other ) === $shared ) ? $shared - 1 : $shared;
				$address = array_slice( $chars, 0, $cut );

				while ( $address && '-' === end( $address ) ) {
					array_pop( $address );
				}
			}

			if ( ! $address ) {
				continue;
			}

			$encoded = self::encode( $address );
			$key     = $kind . ':' . $encoded;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'kind'    => $kind,
					'address' => $encoded,
					'target'  => 'same' === $kind ? 0 : self::current_target( $encoded, $result['state'] ),
					'posts'   => array(),
				);
			}

			$groups[ $key ]['posts'][] = (int) $row->ID;
		}

		foreach ( $groups as $key => $group ) {
			// Claiming an address that shows Not Found today breaks nothing.
			if ( 'takeover' === $group['kind'] && ! $group['target'] ) {
				unset( $groups[ $key ] );
				continue;
			}

			$result['total'] += count( $group['posts'] );
		}

		$result['groups'] = array_values( $groups );
		$result['status'] = $result['groups'] ? 'conflicts' : 'ok';

		return $result;
	}

	/**
	 * Post ID a visitor who opens this address reaches right now, or 0 for Not Found.
	 *
	 * @param string $address Percent-encoded address.
	 * @param string $state   Result of guessing_state().
	 * @return int
	 */
	public static function current_target( $address, $state ) {
		$key = $state . ':' . $address;

		if ( ! isset( self::$targets[ $key ] ) ) {
			self::$targets[ $key ] = 'unique' === $state ? self::unique_match( $address ) : self::core_guess( $address );
		}

		return self::$targets[ $key ];
	}

	/**
	 * Post ID WordPress's own guess redirects this address to, or 0.
	 *
	 * Sends exactly the SQL redirect_guess_404_permalink() sends, with no ORDER BY or LIMIT,
	 * so the database returns the same row a real visitor gets.
	 *
	 * @param string $address Percent-encoded address.
	 * @return int
	 */
	public static function core_guess( $address ) {
		global $wpdb;

		$types    = self::guessable_post_types();
		$statuses = self::viewable_statuses();

		if ( ! $types || ! $statuses ) {
			return 0;
		}

		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE %s AND post_type IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
			array_merge( array( $wpdb->esc_like( $address ) . '%' ), $types, $statuses )
		);

		$cache_key = 'guess:' . md5( $sql ) . ':' . wp_cache_get_last_changed( 'posts' );
		$post_id   = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $post_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; core's guess has no API equivalent.
			$post_id = (int) $wpdb->get_var( $sql );
			wp_cache_set( $cache_key, $post_id, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		return (int) $post_id;
	}

	/**
	 * Post ID an address leads to when only unambiguous matches redirect, or 0.
	 *
	 * An exact slug match wins; otherwise exactly one post must start with the address.
	 *
	 * @param string $address Percent-encoded address.
	 * @param array  $args    Optional query refinements, see find().
	 * @return int
	 */
	public static function unique_match( $address, array $args = array() ) {
		$args['limit'] = 3;
		$rows          = self::find( $address, $args );

		if ( ! $rows ) {
			return 0;
		}

		// Rows are ordered by slug, so an exact match comes first.
		$exact = strtolower( $rows[0]->post_name ) === strtolower( $address );

		if ( 1 === count( $rows ) || ( $exact && strtolower( $rows[1]->post_name ) !== strtolower( $address ) ) ) {
			return (int) $rows[0]->ID;
		}

		return 0;
	}

	/**
	 * Published, guessable posts whose slug starts with the given text, ordered by slug.
	 *
	 * @param string $address Percent-encoded slug start.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type string[] $post_types Post types to search. Default all guessable types.
	 *     @type int      $year       Only posts from this year.
	 *     @type int      $monthnum   Only posts from this month.
	 *     @type int      $day        Only posts from this day of the month.
	 *     @type int      $exclude_id Post ID to leave out.
	 *     @type int      $limit      Most rows to return. Default 20.
	 * }
	 * @return object[] Rows with ID and post_name.
	 */
	public static function find( $address, array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'post_types' => self::guessable_post_types(),
				'year'       => 0,
				'monthnum'   => 0,
				'day'        => 0,
				'exclude_id' => 0,
				'limit'      => self::MAX_CONFLICTS,
			)
		);

		$types    = array_values( (array) $args['post_types'] );
		$statuses = self::viewable_statuses();
		$year     = absint( $args['year'] );
		$month    = absint( $args['monthnum'] );
		$day      = absint( $args['day'] );

		if ( '' === $address || ! $types || ! $statuses ) {
			return array();
		}

		$sql = $wpdb->prepare(
			"SELECT ID, post_name FROM {$wpdb->posts}
			WHERE post_name LIKE %s
			AND post_type IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')
			AND post_status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')
			AND ID <> %d
			AND ( 0 = %d OR YEAR( post_date ) = %d )
			AND ( 0 = %d OR MONTH( post_date ) = %d )
			AND ( 0 = %d OR DAYOFMONTH( post_date ) = %d )
			ORDER BY post_name, ID
			LIMIT %d',
			array_merge(
				array( $wpdb->esc_like( $address ) . '%' ),
				$types,
				$statuses,
				array( absint( $args['exclude_id'] ), $year, $year, $month, $month, $day, $day, max( 1, absint( $args['limit'] ) ) )
			)
		);

		$cache_key = 'find:' . md5( $sql ) . ':' . wp_cache_get_last_changed( 'posts' );
		$rows      = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $rows ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; WP_Query cannot match the start of a slug.
			$rows = (array) $wpdb->get_results( $sql );
			wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		return $rows;
	}

	/**
	 * Number of characters at the start two slugs must share before a warning, per the sensitivity setting.
	 *
	 * @param string[] $chars       Characters of the slug being checked.
	 * @param string   $sensitivity 'sensitive', 'balanced' or 'relaxed'.
	 * @param WP_Post  $post        Post being edited.
	 * @return int
	 */
	public static function min_shared_length( array $chars, $sensitivity, WP_Post $post ) {
		$length = count( $chars );
		$dashes = array_keys( $chars, '-', true );

		if ( 'sensitive' === $sensitivity ) {
			// The first word and the dash after it.
			$min = $dashes ? $dashes[0] + 1 : $length;
		} elseif ( 'relaxed' === $sensitivity ) {
			// Everything except the last word.
			$min = $dashes ? end( $dashes ) + 1 : $length;
		} else {
			$min = (int) ceil( $length / 2 );
		}

		/**
		 * Filters how many characters at the start two slugs must share before Doppelslug warns.
		 *
		 * @param int     $min         Minimum shared characters.
		 * @param string  $slug        Decoded slug being checked.
		 * @param string  $sensitivity Sensitivity setting.
		 * @param WP_Post $post        Post being edited.
		 */
		$min = (int) apply_filters( 'doppelslug_min_shared_length', $min, implode( '', $chars ), $sensitivity, $post );

		return min( $length, max( self::MIN_SHARED, $min ) );
	}

	/**
	 * Splits a stored slug into readable characters.
	 *
	 * @param string $slug Percent-encoded slug.
	 * @return string[]
	 */
	private static function chars( $slug ) {
		$decoded = rawurldecode( (string) $slug );

		if ( '' === $decoded ) {
			return array();
		}

		$chars = preg_split( '//u', $decoded, -1, PREG_SPLIT_NO_EMPTY );

		return false === $chars ? str_split( $decoded ) : $chars;
	}

	/**
	 * Joins characters back into a slug encoded the way sanitize_title() stores it.
	 *
	 * @param string[] $chars Characters.
	 * @return string
	 */
	private static function encode( array $chars ) {
		return strtolower( utf8_uri_encode( implode( '', $chars ) ) );
	}

	/**
	 * Number of characters two slugs share at the start.
	 *
	 * @param string[] $a First slug's characters.
	 * @param string[] $b Second slug's characters.
	 * @return int
	 */
	private static function shared_length( array $a, array $b ) {
		$max    = min( count( $a ), count( $b ) );
		$shared = 0;

		while ( $shared < $max && $a[ $shared ] === $b[ $shared ] ) {
			++$shared;
		}

		return $shared;
	}
}
