<?php
/**
 * Settings storage and the Settings → Doppelslug screen.
 *
 * @package Doppelslug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the plugin's two settings in one option and renders their screen.
 */
final class Doppelslug_Settings {

	/**
	 * Option that holds every setting.
	 */
	const OPTION = 'doppelslug_settings';

	/**
	 * Settings page slug, also used as the option group.
	 */
	const PAGE = 'doppelslug';

	/**
	 * Allowed values for how 404 addresses are guessed.
	 */
	const GUESSING_MODES = array( 'default', 'unique', 'exact', 'off' );

	/**
	 * Allowed values for how similar two slugs must be before a warning.
	 */
	const SENSITIVITIES = array( 'sensitive', 'balanced', 'relaxed' );

	/**
	 * Default settings. Guessing stays exactly as WordPress ships it until an admin changes it.
	 *
	 * @return array<string, string>
	 */
	public static function defaults() {
		return array(
			'guessing'    => 'default',
			'sensitivity' => 'balanced',
		);
	}

	/**
	 * Reads one setting.
	 *
	 * @param string $key Setting name.
	 * @return string
	 */
	public static function get( $key ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );

		return isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
	}

	/**
	 * Stores the defaults on activation so the option is autoloaded from the first request.
	 */
	public static function activate() {
		add_option( self::OPTION, self::defaults() );
	}

	/**
	 * Hooks the settings screen into wp-admin.
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DOPPELSLUG_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Registers the option, its section, and its fields with the Settings API.
	 */
	public static function register_setting() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( 'doppelslug_main', '', array( __CLASS__, 'render_intro' ), self::PAGE );

		add_settings_field(
			'doppelslug_guessing',
			esc_html__( 'Addresses that don’t exist', 'doppelslug' ),
			array( __CLASS__, 'render_guessing' ),
			self::PAGE,
			'doppelslug_main'
		);

		add_settings_field(
			'doppelslug_sensitivity',
			esc_html__( 'Warning sensitivity', 'doppelslug' ),
			array( __CLASS__, 'render_sensitivity' ),
			self::PAGE,
			'doppelslug_main'
		);
	}

	/**
	 * Keeps only known values; anything else falls back to the default.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, string>
	 */
	public static function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = self::defaults();

		if ( isset( $input['guessing'] ) && in_array( $input['guessing'], self::GUESSING_MODES, true ) ) {
			$output['guessing'] = $input['guessing'];
		}

		if ( isset( $input['sensitivity'] ) && in_array( $input['sensitivity'], self::SENSITIVITIES, true ) ) {
			$output['sensitivity'] = $input['sensitivity'];
		}

		return $output;
	}

	/**
	 * Adds Settings → Doppelslug.
	 */
	public static function add_page() {
		add_options_page(
			esc_html__( 'Doppelslug', 'doppelslug' ),
			esc_html__( 'Doppelslug', 'doppelslug' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
					esc_html__( 'Settings', 'doppelslug' )
				)
			);
		}

		return $links;
	}

	/**
	 * Renders the settings screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'doppelslug' ), 403 );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Explains what WordPress does with addresses that don't exist.
	 */
	public static function render_intro() {
		$example = untrailingslashit( (string) preg_replace( '#^https?://#i', '', home_url() ) ) . '/how-write-b';

		echo '<p>';
		printf(
			/* translators: %s: example address on this site, such as example.com/how-write-b */
			esc_html__( 'When a visitor opens an address that doesn’t exist, such as %s, WordPress looks for a published post or page whose slug starts with that text and redirects to it. If several slugs start the same way, which one the visitor lands on is left to the database.', 'doppelslug' ),
			'<code dir="ltr">' . esc_html( $example ) . '</code>'
		);
		echo '</p><p>';
		esc_html_e( 'Doppelslug warns authors about lookalike slugs in the editor. The settings below control how those redirects behave and how similar two slugs must be before a warning appears.', 'doppelslug' );
		echo '</p>';
	}

	/**
	 * Renders the guessing-mode radio buttons.
	 */
	public static function render_guessing() {
		self::render_radios(
			'guessing',
			__( 'Addresses that don’t exist', 'doppelslug' ),
			array(
				'default' => array(
					__( 'Let WordPress decide (default)', 'doppelslug' ),
					__( 'Redirects to the first matching post the database returns. When several posts match, which one wins is not defined.', 'doppelslug' ),
				),
				'unique'  => array(
					__( 'Redirect only when one post matches (recommended)', 'doppelslug' ),
					__( 'An address that matches a single post still redirects, and an exact slug match always wins. An address that matches several posts shows the Not Found page instead of a random post.', 'doppelslug' ),
				),
				'exact'   => array(
					__( 'Redirect only exact slug matches', 'doppelslug' ),
					__( 'Still finds a post that moved to a different date or parent page, but never completes a partial address.', 'doppelslug' ),
				),
				'off'     => array(
					__( 'Never redirect', 'doppelslug' ),
					__( 'Addresses that don’t exist always show the Not Found page.', 'doppelslug' ),
				),
			)
		);
	}

	/**
	 * Renders the sensitivity radio buttons.
	 */
	public static function render_sensitivity() {
		self::render_radios(
			'sensitivity',
			__( 'Warning sensitivity', 'doppelslug' ),
			array(
				'sensitive' => array(
					__( 'Sensitive', 'doppelslug' ),
					__( 'Warn when slugs share their first word, for example how-to-cook-rice and how-to-fix-a-bike.', 'doppelslug' ),
				),
				'balanced'  => array(
					__( 'Balanced (default)', 'doppelslug' ),
					__( 'Warn when another slug shares at least the first half of this one, for example how-write-book and how-write-blog.', 'doppelslug' ),
				),
				'relaxed'   => array(
					__( 'Relaxed', 'doppelslug' ),
					__( 'Warn only when slugs differ just in their last word, for example how-to-cook-rice and how-to-cook-pasta.', 'doppelslug' ),
				),
			)
		);
	}

	/**
	 * Renders a group of radio buttons, each with a description.
	 *
	 * @param string                  $key     Setting name.
	 * @param string                  $legend  Accessible group label.
	 * @param array<string, string[]> $choices Value => array( label, description ).
	 */
	private static function render_radios( $key, $legend, array $choices ) {
		$current = self::get( $key );

		printf( '<fieldset><legend class="screen-reader-text">%s</legend>', esc_html( $legend ) );

		foreach ( $choices as $value => $text ) {
			printf(
				'<p><label><input type="radio" name="%1$s" value="%2$s" %3$s> %4$s</label></p><p class="description" style="margin: 0 0 12px; margin-inline-start: 24px;">%5$s</p>',
				esc_attr( self::OPTION . '[' . $key . ']' ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $text[0] ),
				esc_html( $text[1] )
			);
		}

		echo '</fieldset>';
	}
}
