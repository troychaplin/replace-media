<?php // phpcs:ignore Squiz.Commenting.FileComment.Missing

namespace Replace_Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Manage_Media
 *
 * This class handles the media replacement functionality.
 *
 * @package Replace_Media
 */
class Manage_Media {

	/**
	 * Constructor for the class.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_replace_media_file', array( $this, 'handle_media_replacement' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_replace_media_button' ), 10, 2 );

        // TODO: delete
        add_filter( 'big_image_size_threshold', '__return_false' );
	}

	/**
	 * Log debug messages if debug mode is enabled.
	 *
	 * @param string $message The message to log.
	 */
	private function log_debug( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Replace Media: ' . $message );
		}
	}

	/**
	 * Enqueue necessary scripts and styles.
	 */
	public function enqueue_scripts() {
		$screen = get_current_screen();
		$this->log_debug( 'Current screen ID: ' . $screen->id );
		$this->log_debug( 'Current screen base: ' . $screen->base );
		
		// Check for all possible media contexts
		$allowed_screens = array( 'upload', 'media', 'attachment' );
		if ( ! in_array( $screen->id, $allowed_screens ) && ! in_array( $screen->base, $allowed_screens ) ) {
			$this->log_debug( 'Not on a media screen, skipping script enqueue' );
			return;
		}

		$this->log_debug( 'Enqueueing scripts for media screen' );
		$this->log_debug( 'Script URL: ' . REPLACE_MEDIA_URL . 'build/replace-media.js' );
		
		// Register the script first
		wp_register_script(
			'replace-media-script',
			REPLACE_MEDIA_URL . 'build/replace-media.js',
			array( 'jquery', 'wp-i18n', 'media-views' ),
			'1.0.0',
			true
		);

		$this->log_debug( 'Localizing script data' );
		$localized_data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'replace_media_nonce' ),
		);
		$this->log_debug( 'Localized data: ' . print_r($localized_data, true) );
		
		wp_localize_script(
			'replace-media-script',
			'replaceMediaData',
			$localized_data
		);

		// Enqueue the script after localization
		wp_enqueue_script('replace-media-script');
		
		$this->log_debug( 'Script enqueued and localized' );
	}

	/**
	 * Add replace media button to attachment fields.
	 *
	 * @param array    $form_fields Array of form fields.
	 * @param \WP_Post $post        The attachment post object.
	 * @return array Modified form fields.
	 */
	public function add_replace_media_button( $form_fields, $post ) {
		$this->log_debug( 'Adding replace button for attachment ' . $post->ID );
		
		$form_fields['replace_media'] = array(
			'label' => __( 'Replace Media', 'replace-media' ),
			'input' => 'html',
			'html'  => sprintf(
				'<button type="button" class="button replace-media-button" data-attachment-id="%d">%s</button>',
				$post->ID,
				__( 'Replace File', 'replace-media' )
			),
		);

		return $form_fields;
	}

	/**
	 * Handle the media replacement AJAX request.
	 */
	public function handle_media_replacement() {
		// Enable error reporting for debugging
		error_reporting( E_ALL );
		ini_set( 'display_errors', 1 );

		// Log the request
		$this->log_debug( 'AJAX request received' );
		$this->log_debug( 'POST data: ' . print_r( $_POST, true ) );
		$this->log_debug( 'FILES data: ' . print_r( $_FILES, true ) );

		// Verify nonce
		if ( ! check_ajax_referer( 'replace_media_nonce', 'nonce', false ) ) {
			$this->log_debug( 'Nonce verification failed' );
			wp_send_json_error( __( 'Security check failed.', 'replace-media' ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			$this->log_debug( 'User does not have upload_files capability' );
			wp_send_json_error( __( 'You do not have permission to replace media files.', 'replace-media' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			$this->log_debug( 'Invalid attachment ID' );
			wp_send_json_error( __( 'Invalid attachment ID.', 'replace-media' ) );
		}

		if ( ! isset( $_FILES['replacement_file'] ) ) {
			$this->log_debug( 'No file uploaded' );
			wp_send_json_error( __( 'No file was uploaded.', 'replace-media' ) );
		}

		$file = $_FILES['replacement_file'];
		$attachment = get_post( $attachment_id );

		if ( ! $attachment ) {
			$this->log_debug( 'Attachment not found' );
			wp_send_json_error( __( 'Attachment not found.', 'replace-media' ) );
		}

		// Get the current file path
		$current_file = get_attached_file( $attachment_id );
		if ( ! $current_file ) {
			$this->log_debug( 'Current file not found' );
			wp_send_json_error( __( 'Current file not found.', 'replace-media' ) );
		}

		$this->log_debug( 'Current file path: ' . $current_file );

		// Handle the file upload
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		require_once( ABSPATH . 'wp-admin/includes/media.php' );

		try {
			// Get the current file path and directory
			$current_file = get_attached_file( $attachment_id );
			$current_dir = dirname( $current_file );
			$current_filename = basename( $current_file );
			
			$this->log_debug( 'Current file path: ' . $current_file );
			$this->log_debug( 'Current filename: ' . $current_filename );
			
			// Validate that the new file has the same name
			$new_filename = basename( $file['name'] );
			if ( $new_filename !== $current_filename ) {
				$this->log_debug( 'Filename mismatch. Current: ' . $current_filename . ', New: ' . $new_filename );
				wp_send_json_error( sprintf(
					__( 'The new file must have the same name as the original file (%s). Please rename your file and try again.', 'replace-media' ),
					$current_filename
				) );
			}
			
			// Delete the old file and its metadata
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $meta['file'] ) ) {
				$file_path = path_join( $current_dir, $meta['file'] );
				if ( file_exists( $file_path ) ) {
					unlink( $file_path );
				}
			}
			
			// Delete all image sizes
			if ( ! empty( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size => $size_info ) {
					$size_file = path_join( $current_dir, $size_info['file'] );
					if ( file_exists( $size_file ) ) {
						unlink( $size_file );
					}
				}
			}

			// Move the uploaded file to the correct location with the original filename
			$target_path = path_join( $current_dir, $current_filename );
			
			// Move the uploaded file to the target location
			if ( ! move_uploaded_file( $file['tmp_name'], $target_path ) ) {
				$this->log_debug( 'Failed to move uploaded file' );
				wp_send_json_error( __( 'Failed to move uploaded file.', 'replace-media' ) );
			}

			$this->log_debug( 'File moved successfully to: ' . $target_path );

			// Update the attachment metadata
			$this->log_debug( 'Generating attachment metadata' );
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $target_path );
			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			$this->log_debug( 'File replaced successfully' );
			wp_send_json_success( array(
				'message' => __( 'File replaced successfully.', 'replace-media' ),
				'url'     => wp_get_attachment_url( $attachment_id ),
			) );
		} catch ( Exception $e ) {
			$this->log_debug( 'Exception caught - ' . $e->getMessage() );
			wp_send_json_error( $e->getMessage() );
		}
	}
}
