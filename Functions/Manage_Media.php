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
	}

	/**
	 * Enqueue necessary scripts and styles.
	 */
	public function enqueue_scripts() {
		$screen = get_current_screen();
		error_log('Replace Media: Current screen ID: ' . $screen->id);
		error_log('Replace Media: Current screen base: ' . $screen->base);
		
		// Check for all possible media contexts
		$allowed_screens = array( 'upload', 'media', 'attachment' );
		if ( ! in_array( $screen->id, $allowed_screens ) && ! in_array( $screen->base, $allowed_screens ) ) {
			error_log('Replace Media: Not on a media screen, skipping script enqueue');
			return;
		}

		error_log('Replace Media: Enqueueing scripts for media screen');
		error_log('Replace Media: Script URL: ' . REPLACE_MEDIA_URL . 'build/replace-media.js');
		
		// Register the script first
		wp_register_script(
			'replace-media-script',
			REPLACE_MEDIA_URL . 'build/replace-media.js',
			array( 'jquery', 'wp-i18n', 'media-views' ),
			'1.0.0',
			true
		);

		error_log('Replace Media: Localizing script data');
		$localized_data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'replace_media_nonce' ),
		);
		error_log('Replace Media: Localized data: ' . print_r($localized_data, true));
		
		wp_localize_script(
			'replace-media-script',
			'replaceMediaData',
			$localized_data
		);

		// Enqueue the script after localization
		wp_enqueue_script('replace-media-script');
		
		error_log('Replace Media: Script enqueued and localized');
	}

	/**
	 * Add replace media button to attachment fields.
	 *
	 * @param array    $form_fields Array of form fields.
	 * @param \WP_Post $post        The attachment post object.
	 * @return array Modified form fields.
	 */
	public function add_replace_media_button( $form_fields, $post ) {
		error_log('Replace Media: Adding replace button for attachment ' . $post->ID);
		
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
		error_log( 'Replace Media: AJAX request received' );
		error_log( 'POST data: ' . print_r( $_POST, true ) );
		error_log( 'FILES data: ' . print_r( $_FILES, true ) );

		// Verify nonce
		if ( ! check_ajax_referer( 'replace_media_nonce', 'nonce', false ) ) {
			error_log( 'Replace Media: Nonce verification failed' );
			wp_send_json_error( __( 'Security check failed.', 'replace-media' ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			error_log( 'Replace Media: User does not have upload_files capability' );
			wp_send_json_error( __( 'You do not have permission to replace media files.', 'replace-media' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			error_log( 'Replace Media: Invalid attachment ID' );
			wp_send_json_error( __( 'Invalid attachment ID.', 'replace-media' ) );
		}

		if ( ! isset( $_FILES['replacement_file'] ) ) {
			error_log( 'Replace Media: No file uploaded' );
			wp_send_json_error( __( 'No file was uploaded.', 'replace-media' ) );
		}

		$file = $_FILES['replacement_file'];
		$attachment = get_post( $attachment_id );

		if ( ! $attachment ) {
			error_log( 'Replace Media: Attachment not found' );
			wp_send_json_error( __( 'Attachment not found.', 'replace-media' ) );
		}

		// Get the current file path
		$current_file = get_attached_file( $attachment_id );
		if ( ! $current_file ) {
			error_log( 'Replace Media: Current file not found' );
			wp_send_json_error( __( 'Current file not found.', 'replace-media' ) );
		}

		error_log( 'Replace Media: Current file path: ' . $current_file );

		// Handle the file upload
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		require_once( ABSPATH . 'wp-admin/includes/media.php' );

		try {
			// Remove the old file
			error_log( 'Replace Media: Removing old file' );
			
			// Get the current file path and directory
			$current_file = get_attached_file( $attachment_id );
			$current_dir = dirname( $current_file );
			
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

			// Upload the new file
			error_log( 'Replace Media: Uploading new file' );
			$upload = wp_handle_upload( $file, array( 'test_form' => false ) );

			if ( isset( $upload['error'] ) ) {
				error_log( 'Replace Media: Upload error - ' . $upload['error'] );
				wp_send_json_error( $upload['error'] );
			}

			error_log( 'Replace Media: File uploaded successfully. Path: ' . $upload['file'] );

			// Update the attachment metadata
			error_log( 'Replace Media: Generating attachment metadata' );
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			error_log( 'Replace Media: File replaced successfully' );
			wp_send_json_success( array(
				'message' => __( 'File replaced successfully.', 'replace-media' ),
				'url'     => wp_get_attachment_url( $attachment_id ),
			) );
		} catch ( Exception $e ) {
			error_log( 'Replace Media: Exception caught - ' . $e->getMessage() );
			wp_send_json_error( $e->getMessage() );
		}
	}
}
