<?php
/**
 * REST endpoint the editors call to check a slug.
 *
 * @package Doppelslug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers GET /doppelslug/v1/check.
 */
final class Doppelslug_Rest {

	/**
	 * Route namespace.
	 */
	const ROUTE_NAMESPACE = 'doppelslug/v1';

	/**
	 * Hooks route registration.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the read-only check route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/check',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'check' ),
				'permission_callback' => array( __CLASS__, 'can_check' ),
				'args'                => array(
					'post_id' => array(
						'description' => __( 'ID of the post being edited.', 'doppelslug' ),
						'type'        => 'integer',
						'required'    => true,
						'minimum'     => 1,
					),
					'slug'    => array(
						'description' => __( 'Slug typed in the editor. Leave empty to use the title.', 'doppelslug' ),
						'type'        => 'string',
						'default'     => '',
						'maxLength'   => 1000,
					),
					'title'   => array(
						'description' => __( 'Title typed in the editor, used when there is no slug yet.', 'doppelslug' ),
						'type'        => 'string',
						'default'     => '',
						'maxLength'   => 1000,
					),
				),
			)
		);
	}

	/**
	 * Only someone who can edit the post may check its slug.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_check( WP_REST_Request $request ) {
		return current_user_can( 'edit_post', (int) $request['post_id'] );
	}

	/**
	 * Returns the lookalike report for the post's current or planned slug.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function check( WP_REST_Request $request ) {
		$post = get_post( (int) $request['post_id'] );

		if ( ! $post ) {
			return new WP_Error( 'doppelslug_post_not_found', __( 'Post not found.', 'doppelslug' ), array( 'status' => 404 ) );
		}

		$slug = Doppelslug_Detector::predict_slug(
			$post,
			(string) $request['slug'],
			sanitize_text_field( (string) $request['title'] )
		);

		return rest_ensure_response( Doppelslug_Report::build( $post, $slug ) );
	}
}
