<?php
/**
 * Plugin Name:       Doppelslug
 * Plugin URI:        https://github.com/ararai1991/doppelslug
 * Description:       Warns authors when a slug starts like another one, so shortened or mistyped addresses can't send visitors to the wrong post.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            ararai1991
 * Author URI:        https://github.com/ararai1991
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       doppelslug
 *
 * @package Doppelslug
 */

defined( 'ABSPATH' ) || exit;

define( 'DOPPELSLUG_VERSION', '1.0.0' );
define( 'DOPPELSLUG_FILE', __FILE__ );
define( 'DOPPELSLUG_DIR', plugin_dir_path( __FILE__ ) );

require_once DOPPELSLUG_DIR . 'includes/class-doppelslug-settings.php';
require_once DOPPELSLUG_DIR . 'includes/class-doppelslug-detector.php';
require_once DOPPELSLUG_DIR . 'includes/class-doppelslug-guessing.php';
require_once DOPPELSLUG_DIR . 'includes/class-doppelslug-report.php';
require_once DOPPELSLUG_DIR . 'includes/class-doppelslug-rest.php';

register_activation_hook( __FILE__, array( 'Doppelslug_Settings', 'activate' ) );

Doppelslug_Guessing::register();
Doppelslug_Rest::register();

if ( is_admin() ) {
	require_once DOPPELSLUG_DIR . 'includes/class-doppelslug-editor.php';

	Doppelslug_Settings::register();
	Doppelslug_Editor::register();
}
