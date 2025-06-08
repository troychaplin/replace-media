<?php
/**
 * Plugin Name:       Replace Media
 * Description:       This plugin allows you to replace media in the media library.
 * Requires at least: 6.6
 * Requires PHP:      7.0
 * Version:           0.0.1
 * Author:            Troy Chaplin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       replace-media
 *
 * @package Replace_Media
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
define( 'REPLACE_MEDIA_PATH', plugin_dir_path( __FILE__ ) );
define( 'REPLACE_MEDIA_URL', plugin_dir_url( __FILE__ ) );

// Include Composer's autoload file.
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

// Instantiate the classes.
$replace_media_classes = array(
	\Replace_Media\Enqueues::class,
	\Replace_Media\PluginPaths::class,
	\Replace_Media\ManageMedia::class,
);

foreach ( $replace_media_classes as $replace_media_class ) {
	new $replace_media_class();
}
