<?php // phpcs:ignore Squiz.Commenting.FileComment.Missing

namespace Replace_Media;

use Replace_Media\PluginPaths;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Enqueues
 *
 * This class is responsible for enqueueing scripts and styles for the plugin.
 *
 * @package Replace_Media
 */
class Enqueues {

	/**
	 * Constructor for the class.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueues the block assets for the editor
	 */
	public function enqueue_admin_assets() {
		$asset_file = include PluginPaths::plugin_path() . 'build/replace-media.asset.php';

		wp_enqueue_script(
			'replace-media-js',
			PluginPaths::plugin_url() . 'build/replace-media.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			false
		);
	}
}
