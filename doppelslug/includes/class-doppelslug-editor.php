<?php
/**
 * Loads the lookalike warnings into the block editor and the Classic Editor.
 *
 * @package Doppelslug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the editor scripts and adds the Classic Editor box, only on edit screens
 * for post types WordPress can redirect a guessed address to.
 */
final class Doppelslug_Editor {

	/**
	 * Hooks the editor integrations.
	 */
	public static function register() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_classic_editor' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
	}

	/**
	 * Loads the sidebar panel, pre-publish check, and notice for the block editor.
	 */
	public static function enqueue_block_editor() {
		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->base || ! self::supports( $screen->post_type ) ) {
			return;
		}

		wp_enqueue_script(
			'doppelslug-block-editor',
			plugins_url( 'assets/js/block-editor.js', DOPPELSLUG_FILE ),
			array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-notices', 'wp-plugins', 'wp-url' ),
			DOPPELSLUG_VERSION,
			true
		);
		wp_set_script_translations( 'doppelslug-block-editor', 'doppelslug', DOPPELSLUG_DIR . 'languages' );

		wp_enqueue_style( 'doppelslug-editor', plugins_url( 'assets/css/editor.css', DOPPELSLUG_FILE ), array(), DOPPELSLUG_VERSION );
	}

	/**
	 * Loads the box script on Classic Editor screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_classic_editor( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || $screen->is_block_editor() || ! self::supports( $screen->post_type ) ) {
			return;
		}

		wp_enqueue_script(
			'doppelslug-classic-editor',
			plugins_url( 'assets/js/classic-editor.js', DOPPELSLUG_FILE ),
			array( 'jquery', 'wp-api-fetch', 'wp-i18n', 'wp-url' ),
			DOPPELSLUG_VERSION,
			true
		);
		wp_set_script_translations( 'doppelslug-classic-editor', 'doppelslug', DOPPELSLUG_DIR . 'languages' );

		wp_enqueue_style( 'doppelslug-editor', plugins_url( 'assets/css/editor.css', DOPPELSLUG_FILE ), array(), DOPPELSLUG_VERSION );
	}

	/**
	 * Adds the side box at the top of the sidebar. It appears only in the Classic Editor (the block
	 * editor uses its own panel) and stays hidden until classic-editor.js finds a lookalike.
	 *
	 * @param string $post_type Post type being edited.
	 */
	public static function add_meta_box( $post_type ) {
		if ( ! self::supports( $post_type ) ) {
			return;
		}

		add_meta_box(
			'doppelslug',
			esc_html__( 'Lookalike URLs', 'doppelslug' ),
			array( __CLASS__, 'render_meta_box' ),
			$post_type,
			'side',
			'high',
			array( '__back_compat_meta_box' => true )
		);

		add_filter( "postbox_classes_{$post_type}_doppelslug", array( __CLASS__, 'hide_until_needed' ) );
	}

	/**
	 * Starts the box hidden, so it takes no space when there is nothing to warn about.
	 *
	 * @param string[] $classes Postbox classes.
	 * @return string[]
	 */
	public static function hide_until_needed( $classes ) {
		$classes[] = 'doppelslug-quiet';

		return $classes;
	}

	/**
	 * Renders the box shell; classic-editor.js fills it in.
	 */
	public static function render_meta_box() {
		printf(
			'<div class="doppelslug-box" aria-live="polite"><p>%s</p></div>',
			esc_html__( 'Checking for lookalike addresses…', 'doppelslug' )
		);
	}

	/**
	 * Whether WordPress can redirect a guessed address to this post type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private static function supports( $post_type ) {
		return in_array( $post_type, Doppelslug_Detector::guessable_post_types(), true );
	}
}
