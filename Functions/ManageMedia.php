<?php // phpcs:ignore Squiz.Commenting.FileComment.Missing

namespace Replace_Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ManageMedia
 *
 * This class handles the media replacement functionality.
 *
 * @package Replace_Media
 */
class ManageMedia {

	/**
	 * Constructor for the class.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_replace_media_file', array( $this, 'handle_media_replacement' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_replace_media_button' ), 10, 2 );

		// TODO: delete.
		// add_filter( 'big_image_size_threshold', '__return_false' );
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

		// Check for all possible media contexts.
		$allowed_screens = array( 'upload', 'media', 'attachment' );
		if ( ! in_array( $screen->id, $allowed_screens, true ) && ! in_array( $screen->base, $allowed_screens, true ) ) {
			$this->log_debug( 'Not on a media screen, skipping script enqueue.' );
			return;
		}

		$this->log_debug( 'Enqueueing scripts for media screen.' );
		$this->log_debug( 'Script URL: ' . REPLACE_MEDIA_URL . 'build/replace-media.js' );

		// Register the script first.
		wp_register_script(
			'replace-media-script',
			REPLACE_MEDIA_URL . 'build/replace-media.js',
			array( 'jquery', 'wp-i18n', 'media-views' ),
			'1.0.0',
			true
		);

		$this->log_debug( 'Localizing script data.' );
		$localized_data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'replace_media_nonce' ),
		);
		$this->log_debug( 'Localized data: ' . print_r( $localized_data, true ) );

		wp_localize_script(
			'replace-media-script',
			'replaceMediaData',
			$localized_data
		);

		// Enqueue the script after localization.
		wp_enqueue_script( 'replace-media-script' );

		$this->log_debug( 'Script enqueued and localized.' );
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
		// Enable error reporting for debugging.
		error_reporting( E_ALL );
		if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
			define( 'WP_DEBUG_DISPLAY', true );
		}

		// Log the request.
		$this->log_debug( 'AJAX request received.' );
		$this->log_debug( 'POST data: ' . print_r( $_POST, true ) );
		$this->log_debug( 'FILES data: ' . print_r( $_FILES, true ) );

		// Verify nonce.
		if ( ! check_ajax_referer( 'replace_media_nonce', 'nonce', false ) ) {
			$this->log_debug( 'Nonce verification failed.' );
			wp_send_json_error( __( 'Security check failed.', 'replace-media' ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			$this->log_debug( 'User does not have upload_files capability.' );
			wp_send_json_error( __( 'You do not have permission to replace media files.', 'replace-media' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			$this->log_debug( 'Invalid attachment ID.' );
			wp_send_json_error( __( 'Invalid attachment ID.', 'replace-media' ) );
		}

		if ( ! isset( $_FILES['replacement_file'] ) ) {
			$this->log_debug( 'No file uploaded.' );
			wp_send_json_error( __( 'No file was uploaded.', 'replace-media' ) );
		}

		$file       = $_FILES['replacement_file'];
		$attachment = get_post( $attachment_id );

		if ( ! $attachment ) {
			$this->log_debug( 'Attachment not found.' );
			wp_send_json_error( __( 'Attachment not found.', 'replace-media' ) );
		}

		// Get the current file path.
		$current_file = get_attached_file( $attachment_id );
		if ( ! $current_file ) {
			$this->log_debug( 'Current file not found.' );
			wp_send_json_error( __( 'Current file not found.', 'replace-media' ) );
		}

		$this->log_debug( 'Current file path: ' . $current_file );

		// Handle the file upload.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		try {
			// Get the current file path and directory.
			$current_file     = get_attached_file( $attachment_id );
			$current_dir      = dirname( $current_file );
			$current_filename = basename( $current_file );

			$this->log_debug( 'Current file path: ' . $current_file );
			$this->log_debug( 'Current filename: ' . $current_filename );

			// Extract original filename (handle scaled images).
			$original_filename = $this->get_original_filename( $current_filename );
			$is_scaled_image   = $original_filename !== $current_filename;

			$this->log_debug( 'Original filename: ' . $original_filename );
			$this->log_debug( 'Is scaled image: ' . ( $is_scaled_image ? 'yes' : 'no' ) );

			// Validate that the new file has the correct name.
			$new_filename = basename( $file['name'] );
			if ( $new_filename !== $original_filename ) {
				$this->log_debug( 'Filename mismatch. Expected: ' . $original_filename . ', New: ' . $new_filename );

				if ( $is_scaled_image ) {
					wp_send_json_error(
						sprintf(
							/* translators: 1: The original filename without -scaled, 2: The current scaled filename */
							__( 'This image was automatically scaled by WordPress. Please upload your replacement file with the original filename: %1$s (not %2$s)', 'replace-media' ),
							$original_filename,
							$current_filename
						)
					);
				} else {
					wp_send_json_error(
						sprintf(
							/* translators: %s: The original filename that must be matched. */
							__( 'The new file must have the same name as the original file (%s). Please rename your file and try again.', 'replace-media' ),
							$original_filename
						)
					);
				}
			}

			// Validate dimensions for images.
			$current_image_info = getimagesize( $current_file );
			if ( $current_image_info ) {
				// This is an image, check dimensions.
				$new_image_info = getimagesize( $file['tmp_name'] );
				if ( ! $new_image_info ) {
					wp_send_json_error( __( 'The uploaded file is not a valid image.', 'replace-media' ) );
				}

				$current_width  = $current_image_info[0];
				$current_height = $current_image_info[1];
				$new_width      = $new_image_info[0];
				$new_height     = $new_image_info[1];

				// Check if current image is scaled and try to get original dimensions.
				$comparison_width  = $current_width;
				$comparison_height = $current_height;

				if ( $is_scaled_image ) {
					// Try to get dimensions from the original file.
					$original_file_path = path_join( $current_dir, $original_filename );

					if ( file_exists( $original_file_path ) ) {
						$original_image_info = getimagesize( $original_file_path );
						if ( $original_image_info ) {
							$comparison_width  = $original_image_info[0];
							$comparison_height = $original_image_info[1];
							$this->log_debug( "Using original dimensions for comparison: {$comparison_width}x{$comparison_height}" );
						} else {
							$this->log_debug( "Original file exists but couldn't get dimensions, using scaled dimensions" );
						}
					} else {
						$this->log_debug( "Original file doesn't exist, using scaled dimensions for comparison" );
					}
				}

				$this->log_debug( "Current dimensions: {$current_width}x{$current_height}" );
				$this->log_debug( "Comparison dimensions: {$comparison_width}x{$comparison_height}" );
				$this->log_debug( "New dimensions: {$new_width}x{$new_height}" );

				// Enforce strict dimension matching for all images to prevent layout issues.
				if ( $new_width !== $comparison_width || $new_height !== $comparison_height ) {
					wp_send_json_error(
						sprintf(
							/* translators: 1: required dimensions, 2: uploaded dimensions */
							__( 'The replacement must have the exact same dimensions as the original image. Required: %1$s, Uploaded: %2$s.', 'replace-media' ),
							"{$comparison_width}x{$comparison_height}",
							"{$new_width}x{$new_height}"
						)
					);
				}
			}

			// Delete the old files.
			$this->delete_attachment_files( $attachment_id, $current_file, $is_scaled_image, $original_filename );

			// Move the uploaded file to the correct location with the original filename.
			$target_path = path_join( $current_dir, $original_filename );

			// Move the uploaded file to the target location.
			if ( ! move_uploaded_file( $file['tmp_name'], $target_path ) ) {
				$this->log_debug( 'Failed to move uploaded file.' );
				wp_send_json_error( __( 'Failed to move uploaded file.', 'replace-media' ) );
			}

			$this->log_debug( 'File moved successfully to: ' . $target_path );

			// Update the attachment metadata.
			$this->log_debug( 'Generating attachment metadata.' );
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $target_path );

			$this->log_debug( 'Generated metadata: ' . print_r( $attachment_data, true ) );

			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			// Check if WordPress created a new scaled version and update the attachment file path accordingly
			$final_file_path = $target_path;

			if ( ! empty( $attachment_data['original_image'] ) ) {
				$this->log_debug( 'WordPress created a scaled image.' );

				// When WordPress creates a scaled image, the metadata structure is:
				// - 'file' already points to the scaled version
				// - 'big_image' contains info about the original
				$scaled_relative_path = $attachment_data['file'];
				$upload_dir           = wp_get_upload_dir();
				$scaled_full_path     = path_join( $upload_dir['basedir'], $scaled_relative_path );

				$this->log_debug( 'Scaled relative path from metadata: ' . $scaled_relative_path );
				$this->log_debug( 'Scaled full path: ' . $scaled_full_path );

				if ( file_exists( $scaled_full_path ) ) {
					$final_file_path = $scaled_full_path;
					$this->log_debug( 'Using scaled file as final path: ' . $final_file_path );
				} else {
					$this->log_debug( 'Scaled file does not exist, using original path' );
				}
			} else {
				$this->log_debug( 'No scaled version was created, using original file.' );
			}

			// Update the attachment's file path in the database
			$this->log_debug( 'Updating attached file to: ' . $final_file_path );
			update_attached_file( $attachment_id, $final_file_path );

			// Verify what was actually saved
			$saved_file_path = get_attached_file( $attachment_id );
			$this->log_debug( 'Verified attached file path: ' . $saved_file_path );
			$this->log_debug( 'Final URL: ' . wp_get_attachment_url( $attachment_id ) );

			$this->log_debug( 'File replaced successfully.' );
			wp_send_json_success(
				array(
					'message' => __( 'File replaced successfully.', 'replace-media' ),
					'url'     => wp_get_attachment_url( $attachment_id ),
				)
			);
		} catch ( Exception $e ) {
			$this->log_debug( 'Exception caught - ' . $e->getMessage() );
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Handle dimension checking for uploaded file.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	private function handle_dimension_check( $attachment_id ) {
		if ( ! isset( $_FILES['replacement_file'] ) ) {
			wp_send_json_error( __( 'No file was uploaded for dimension check.', 'replace-media' ) );
		}

		$file         = $_FILES['replacement_file'];
		$current_file = get_attached_file( $attachment_id );

		if ( ! $current_file ) {
			wp_send_json_error( __( 'Current file not found.', 'replace-media' ) );
		}

		// Get dimensions of current file
		$current_image_info = getimagesize( $current_file );
		if ( ! $current_image_info ) {
			// If current file is not an image, skip dimension check
			wp_send_json_success( array( 'skip_check' => true ) );
			return;
		}

		// Get dimensions of new file
		$new_image_info = getimagesize( $file['tmp_name'] );
		if ( ! $new_image_info ) {
			wp_send_json_error( __( 'The uploaded file is not a valid image.', 'replace-media' ) );
		}

		$current_width  = $current_image_info[0];
		$current_height = $current_image_info[1];
		$new_width      = $new_image_info[0];
		$new_height     = $new_image_info[1];

		// Check if current image is scaled and try to get original dimensions
		$current_filename  = basename( $current_file );
		$original_filename = $this->get_original_filename( $current_filename );
		$is_scaled_image   = $original_filename !== $current_filename;

		$comparison_width  = $current_width;
		$comparison_height = $current_height;
		$comparison_label  = 'current';

		if ( $is_scaled_image ) {
			// Try to get dimensions from the original file
			$current_dir        = dirname( $current_file );
			$original_file_path = path_join( $current_dir, $original_filename );

			if ( file_exists( $original_file_path ) ) {
				$original_image_info = getimagesize( $original_file_path );
				if ( $original_image_info ) {
					$comparison_width  = $original_image_info[0];
					$comparison_height = $original_image_info[1];
					$comparison_label  = 'original';
					$this->log_debug( "Using original dimensions for comparison: {$comparison_width}x{$comparison_height}" );
				} else {
					$this->log_debug( "Original file exists but couldn't get dimensions, using scaled dimensions" );
				}
			} else {
				$this->log_debug( "Original file doesn't exist, using scaled dimensions for comparison" );
			}
		}

		$this->log_debug( "Current (scaled) dimensions: {$current_width}x{$current_height}" );
		$this->log_debug( "Comparison dimensions: {$comparison_width}x{$comparison_height}" );
		$this->log_debug( "New dimensions: {$new_width}x{$new_height}" );

		// Enforce strict dimension matching for all images to prevent layout issues
		if ( $new_width !== $comparison_width || $new_height !== $comparison_height ) {
			wp_send_json_error(
				sprintf(
					/* translators: 1: required dimensions, 2: uploaded dimensions */
					__( 'The replacement must have the exact same dimensions as the original image. Required: %1$s, Uploaded: %2$s.', 'replace-media' ),
					"{$comparison_width}x{$comparison_height}",
					"{$new_width}x{$new_height}"
				)
			);
		}

		// If we reach here for scaled images, dimensions are acceptable
		wp_send_json_success( array( 'dimension_warning' => false ) );
	}

	/**
	 * Extract original filename from a potentially scaled filename.
	 *
	 * @param string $filename The filename to process.
	 * @return string The original filename without -scaled suffix.
	 */
	private function get_original_filename( $filename ) {
		// Check if filename contains -scaled
		if ( preg_match( '/^(.+)-scaled(\.[^.]+)$/', $filename, $matches ) ) {
			return $matches[1] . $matches[2];
		}
		return $filename;
	}

	/**
	 * Delete all files associated with an attachment.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $current_file The current file path.
	 * @param bool   $is_scaled_image Whether the current file is scaled.
	 * @param string $original_filename The original filename.
	 */
	private function delete_attachment_files( $attachment_id, $current_file, $is_scaled_image, $original_filename ) {
		$current_dir = dirname( $current_file );
		$meta        = wp_get_attachment_metadata( $attachment_id );

		// Delete the current main file
		if ( file_exists( $current_file ) ) {
			wp_delete_file( $current_file );
			$this->log_debug( 'Deleted current file: ' . $current_file );
		}

		// If this is a scaled image, also delete the original file if it exists
		if ( $is_scaled_image ) {
			$original_file_path = path_join( $current_dir, $original_filename );
			if ( file_exists( $original_file_path ) ) {
				wp_delete_file( $original_file_path );
				$this->log_debug( 'Deleted original file: ' . $original_file_path );
			}
		}

		// Delete all generated image sizes
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $size_info ) {
				$size_file = path_join( $current_dir, $size_info['file'] );
				if ( file_exists( $size_file ) ) {
					wp_delete_file( $size_file );
					$this->log_debug( 'Deleted size file: ' . $size_file );
				}
			}
		}
	}
}
