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
	}

	/**
	 * Enqueue necessary scripts and styles.
	 */
	public function enqueue_scripts() {
		// Get the current screen.
		$screen = get_current_screen();

		// Check for all possible media contexts.
		$allowed_screens = array( 'upload', 'media', 'attachment' );
		if ( ! in_array( $screen->id, $allowed_screens, true ) && ! in_array( $screen->base, $allowed_screens, true ) ) {
			return;
		}

		// Register the script first.
		wp_register_script(
			'replace-media-script',
			REPLACE_MEDIA_URL . 'build/replace-media.js',
			array( 'jquery', 'wp-i18n', 'media-views' ),
			'1.0.0',
			true
		);

		// Set the localized data.
		$localized_data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'replace_media_nonce' ),
		);

		// Localize the script.
		wp_localize_script(
			'replace-media-script',
			'replaceMediaData',
			$localized_data
		);

		// Enqueue the script after localization.
		wp_enqueue_script( 'replace-media-script' );
	}

	/**
	 * Add replace media button to attachment fields.
	 *
	 * @param array    $form_fields Array of form fields.
	 * @param \WP_Post $post        The attachment post object.
	 * @return array Modified form fields.
	 */
	public function add_replace_media_button( $form_fields, $post ) {
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
		if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
			define( 'WP_DEBUG_DISPLAY', true );
		}

		// Verify nonce.
		if ( ! check_ajax_referer( 'replace_media_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed.', 'replace-media' ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( 'You do not have permission to replace media files.', 'replace-media' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'replace-media' ) );
		}

		if ( ! isset( $_FILES['replacement_file'] ) ) {
			wp_send_json_error( __( 'No file was uploaded.', 'replace-media' ) );
		}

		// Validate and sanitize file upload components.
		$file_name  = isset( $_FILES['replacement_file']['name'] ) ? sanitize_file_name( $_FILES['replacement_file']['name'] ) : '';
		$file_type  = isset( $_FILES['replacement_file']['type'] ) ? sanitize_mime_type( $_FILES['replacement_file']['type'] ) : '';
		$file_size  = isset( $_FILES['replacement_file']['size'] ) ? absint( $_FILES['replacement_file']['size'] ) : 0;
		$file_error = isset( $_FILES['replacement_file']['error'] ) ? absint( $_FILES['replacement_file']['error'] ) : 0;

		// Validate tmp_name exists and is a proper upload (tmp_name is system-generated, not user input).
		$file_tmp_name = '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is system-generated, not user input
		if ( isset( $_FILES['replacement_file']['tmp_name'] ) && is_uploaded_file( $_FILES['replacement_file']['tmp_name'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is system-generated, not user input
			$file_tmp_name = $_FILES['replacement_file']['tmp_name'];
		} else {
			wp_send_json_error( __( 'Invalid file upload.', 'replace-media' ) );
		}

		// Create sanitized file array.
		$file = array(
			'name'     => $file_name,
			'tmp_name' => $file_tmp_name,
			'type'     => $file_type,
			'size'     => $file_size,
			'error'    => $file_error,
		);

		// Check for upload errors.
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload error.', 'replace-media' ) );
		}

		$attachment = \get_post( $attachment_id );

		if ( ! $attachment ) {
			$this->logger->debug( 'Attachment not found.' );
			wp_send_json_error( __( 'Attachment not found.', 'replace-media' ) );
		}

		// Get the current file path.
		$current_file = get_attached_file( $attachment_id );
		if ( ! $current_file ) {
			wp_send_json_error( __( 'Current file not found.', 'replace-media' ) );
		}

		// Handle the file upload.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		try {
			// Get the current file path and directory.
			$current_file     = get_attached_file( $attachment_id );
			$current_dir      = dirname( $current_file );
			$current_filename = basename( $current_file );

			// Extract original filename (handle scaled images).
			$original_filename = $this->get_original_filename( $current_filename );
			$is_scaled_image   = $original_filename !== $current_filename;

			// Validate that the new file has the correct name.
			$new_filename = basename( $file['name'] );
			if ( $new_filename !== $original_filename ) {
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
						}
					}
				}

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
				wp_send_json_error( __( 'Failed to move uploaded file.', 'replace-media' ) );
			}

			// Update the attachment metadata.
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $target_path );

			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			// Check if WordPress created a new scaled version and update the attachment file path accordingly.
			$final_file_path = $target_path;

			if ( ! empty( $attachment_data['original_image'] ) ) {
				// When WordPress creates a scaled image, the metadata structure is:
				// - 'file' already points to the scaled version
				// - 'big_image' contains info about the original.
				$scaled_relative_path = $attachment_data['file'];
				$upload_dir           = wp_get_upload_dir();
				$scaled_full_path     = path_join( $upload_dir['basedir'], $scaled_relative_path );

				if ( file_exists( $scaled_full_path ) ) {
					$final_file_path = $scaled_full_path;
				}
			}

			// Update the attachment's file path in the database.
			update_attached_file( $attachment_id, $final_file_path );

			// Verify what was actually saved.
			$saved_file_path = get_attached_file( $attachment_id );

			wp_send_json_success(
				array(
					'message' => __( 'File replaced successfully.', 'replace-media' ),
					'url'     => wp_get_attachment_url( $attachment_id ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Extract original filename from a potentially scaled filename.
	 *
	 * @param string $filename The filename to process.
	 * @return string The original filename without -scaled suffix.
	 */
	private function get_original_filename( $filename ) {
		// Check if filename contains -scaled.
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

		// Delete the current main file.
		if ( file_exists( $current_file ) ) {
			wp_delete_file( $current_file );
		}

		// If this is a scaled image, also delete the original file if it exists.
		if ( $is_scaled_image ) {
			$original_file_path = path_join( $current_dir, $original_filename );
			if ( file_exists( $original_file_path ) ) {
				wp_delete_file( $original_file_path );
			}
		}

		// Delete all generated image sizes.
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $size_info ) {
				$size_file = path_join( $current_dir, $size_info['file'] );
				if ( file_exists( $size_file ) ) {
					wp_delete_file( $size_file );
				}
			}
		}
	}
}
