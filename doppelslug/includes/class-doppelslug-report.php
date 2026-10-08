<?php
/**
 * Turns a lookalike check into the plain-text report the editors display.
 *
 * @package Doppelslug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the translated, display-ready report returned by the REST endpoint.
 *
 * Every value is plain text or a URL; the editors render them as text, never as HTML.
 */
final class Doppelslug_Report {

	/**
	 * Builds the report for a post and the slug it has or will get.
	 *
	 * @param WP_Post $post Post being edited.
	 * @param string  $slug Percent-encoded slug.
	 * @return array<string, mixed>
	 */
	public static function build( WP_Post $post, $slug ) {
		$check  = Doppelslug_Detector::check( $post, $slug );
		$report = array(
			'status'      => $check['status'],
			'slug'        => rawurldecode( $check['slug'] ),
			'summary'     => '',
			'tip'         => '',
			'groups'      => array(),
			'total'       => $check['total'],
			'more'        => $check['more'],
			'settingsUrl' => current_user_can( 'manage_options' ) ? esc_url_raw( admin_url( 'options-general.php?page=' . Doppelslug_Settings::PAGE ) ) : '',
		);

		if ( 'guessing_off' === $check['status'] ) {
			$report['summary'] = __( 'Addresses that don’t exist are never redirected on this site, so lookalike slugs can’t send visitors to the wrong post.', 'doppelslug' );
			return $report;
		}

		if ( 'ok' === $check['status'] ) {
			$report['summary'] = __( 'No published content has an address that starts like this one.', 'doppelslug' );
			return $report;
		}

		if ( 'conflicts' !== $check['status'] ) {
			return $report;
		}

		self::prime_posts( $check['groups'] );

		$report['summary'] = $check['more']
			? sprintf(
				/* translators: %s: number of posts, at least 20 */
				_n( 'At least %s published item has an address that starts like this one.', 'At least %s published items have addresses that start like this one.', $check['total'], 'doppelslug' ),
				number_format_i18n( $check['total'] )
			)
			: sprintf(
				/* translators: %s: number of posts */
				_n( '%s published item has an address that starts like this one.', '%s published items have addresses that start like this one.', $check['total'], 'doppelslug' ),
				number_format_i18n( $check['total'] )
			);

		$report['tip'] = __( 'Tip: make the start of the slug more distinctive, for example by putting the most specific word first.', 'doppelslug' );

		foreach ( $check['groups'] as $group ) {
			$report['groups'][] = self::describe_group( $group, $post, $check );
		}

		return $report;
	}

	/**
	 * Describes one shared address and the posts behind it.
	 *
	 * @param array   $group Group from Doppelslug_Detector::check().
	 * @param WP_Post $post  Post being edited.
	 * @param array   $check Full check result.
	 * @return array<string, mixed>
	 */
	private static function describe_group( array $group, WP_Post $post, array $check ) {
		$address = self::display_address( $group['address'] );
		$shown   = self::ltr( $address );
		$count   = count( $group['posts'] );
		$posts   = array();

		foreach ( $group['posts'] as $post_id ) {
			$posts[] = array(
				'id'    => $post_id,
				'title' => self::plain_title( $post_id ),
				'link'  => esc_url_raw( (string) get_permalink( $post_id ) ),
			);
		}

		if ( 'same' === $group['kind'] ) {
			$message = _n( 'Another published item already uses this exact slug:', 'Other published items already use this exact slug:', $count, 'doppelslug' );
		} elseif ( 'takeover' === $group['kind'] ) {
			$message = sprintf(
				/* translators: 1: address such as example.com/how-write, 2: title of the post it leads to now */
				__( '%1$s currently leads to “%2$s”. After you publish, it will open this post instead. Posts whose addresses start with it:', 'doppelslug' ),
				$shown,
				self::isolate( self::plain_title( $group['target'] ) )
			);
		} elseif ( 'unique' === $check['state'] && $check['live'] ) {
			$message = sprintf(
				/* translators: %s: address such as example.com/how-write-b */
				_n( 'Visitors who type %s see a Not Found page, because it matches this post and also:', 'Visitors who type %s see a Not Found page, because it matches this post and also these:', $count, 'doppelslug' ),
				$shown
			);
		} elseif ( 'unique' === $check['state'] ) {
			$message = sprintf(
				/* translators: %s: address such as example.com/how-write-b */
				_n( 'After you publish, visitors who type %s will see a Not Found page, because it will match this post and also:', 'After you publish, visitors who type %s will see a Not Found page, because it will match this post and also these:', $count, 'doppelslug' ),
				$shown
			);
		} else {
			$message = sprintf(
				/* translators: %s: address such as example.com/how-write-b */
				_n( 'Visitors who type %s could land on the following post instead of this one:', 'Visitors who type %s could land on one of the following posts instead of this one:', $count, 'doppelslug' ),
				$shown
			);
		}

		return array(
			'kind'    => $group['kind'],
			'address' => $address,
			'message' => $message,
			'now'     => 'shared' === $group['kind'] ? self::describe_target( $group['target'], $post ) : '',
			'posts'   => $posts,
		);
	}

	/**
	 * Says where a shared address leads right now.
	 *
	 * @param int     $target Post ID, or 0 for Not Found.
	 * @param WP_Post $post   Post being edited.
	 * @return string
	 */
	private static function describe_target( $target, WP_Post $post ) {
		if ( ! $target ) {
			return __( 'Right now it shows a Not Found page.', 'doppelslug' );
		}

		if ( $target === $post->ID ) {
			return __( 'Right now it opens this post.', 'doppelslug' );
		}

		return sprintf(
			/* translators: %s: post title */
			__( 'Right now it opens “%s”.', 'doppelslug' ),
			self::isolate( self::plain_title( $target ) )
		);
	}

	/**
	 * Keeps an address left-to-right inside a sentence, so it reads correctly in right-to-left languages.
	 *
	 * @param string $text Address.
	 * @return string The text between invisible Unicode left-to-right isolate marks.
	 */
	private static function ltr( $text ) {
		return "\u{2066}" . $text . "\u{2069}";
	}

	/**
	 * Keeps a title in its own direction inside a sentence, such as an English title in a Persian one.
	 *
	 * @param string $text Title.
	 * @return string The text between invisible Unicode first-strong isolate marks.
	 */
	private static function isolate( $text ) {
		return "\u{2068}" . $text . "\u{2069}";
	}

	/**
	 * Loads every post a report mentions with one query.
	 *
	 * @param array $groups Groups from Doppelslug_Detector::check().
	 */
	private static function prime_posts( array $groups ) {
		$ids = array();

		foreach ( $groups as $group ) {
			$ids   = array_merge( $ids, $group['posts'] );
			$ids[] = $group['target'];
		}

		$ids = array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );

		if ( $ids ) {
			get_posts(
				array(
					'include'                => $ids,
					'post_type'              => 'any',
					'post_status'            => 'any',
					'update_post_meta_cache' => false,
				)
			);
		}
	}

	/**
	 * A post's title as plain text.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function plain_title( $post_id ) {
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );

		return '' !== trim( $title ) ? $title : __( '(no title)', 'doppelslug' );
	}

	/**
	 * A readable address such as example.com/how-write-b, with non-Latin characters decoded.
	 *
	 * @param string $address Percent-encoded address.
	 * @return string
	 */
	private static function display_address( $address ) {
		$home = untrailingslashit( (string) preg_replace( '#^https?://#i', '', home_url() ) );

		return $home . '/' . rawurldecode( $address );
	}
}
