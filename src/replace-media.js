/**
 * Block Editor Script Functionality
 *
 * The following scripts are compiled into a single asset and loaded into the block editor.
 *
 */

// import './scripts/block-styles';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { addAction } from '@wordpress/hooks';

/**
 * Handle the media replacement functionality.
 */
document.addEventListener('DOMContentLoaded', function () {
	// Check if replaceMediaData is available
	if (typeof replaceMediaData === 'undefined') {
		// Silently fail if data is not available
		return;
	}

	// Function to initialize replace buttons
	function initReplaceButtons() {
		const replaceButtons = document.querySelectorAll('.replace-media-button');

		replaceButtons.forEach(button => {
			// Remove any existing click handlers
			button.removeEventListener('click', handleReplaceClick);
			// Add new click handler
			button.addEventListener('click', handleReplaceClick);
		});
	}

	// Handle replace button click
	function handleReplaceClick(e) {
		e.preventDefault();

		const attachmentId = this.getAttribute('data-attachment-id');
		if (!attachmentId) {
			return;
		}

		// Create file input
		const fileInput = document.createElement('input');
		fileInput.type = 'file';
		fileInput.style.display = 'none';
		document.body.appendChild(fileInput);

		fileInput.addEventListener('change', function () {
			if (this.files.length === 0) {
				return;
			}

			const formData = new FormData();
			formData.append('action', 'replace_media_file');
			formData.append('nonce', replaceMediaData.nonce);
			formData.append('attachment_id', attachmentId);
			formData.append('replacement_file', this.files[0]);

			// Show loading state
			const button = document.querySelector(
				`.replace-media-button[data-attachment-id="${attachmentId}"]`
			);
			if (button) {
				button.disabled = true;
				button.textContent = __('Replacing...', 'replace-media');
			}

			// Send AJAX request
			fetch(replaceMediaData.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			})
				.then(response => {
					if (!response.ok) {
						throw new Error(`HTTP error! status: ${response.status}`);
					}
					return response.json();
				})
				.then(data => {
					if (data.success) {
						// Refresh the media library
						window.location.reload();
					} else {
						const errorMessage =
							data.data || __('Error replacing file.', 'replace-media');
						alert(errorMessage);
						if (button) {
							button.disabled = false;
							button.textContent = __('Replace File', 'replace-media');
						}
					}
				})
				.catch(error => {
					alert(__('Error replacing file: ', 'replace-media') + error.message);
					if (button) {
						button.disabled = false;
						button.textContent = __('Replace File', 'replace-media');
					}
				});

			// Clean up
			document.body.removeChild(fileInput);
		});

		fileInput.click();
	}

	// Initialize buttons on page load
	initReplaceButtons();

	// Initialize buttons when media modal is opened
	if (wp.media && wp.media.frame) {
		wp.media.frame.on('open', initReplaceButtons);
	}

	// Initialize buttons when attachment details are shown
	document.addEventListener('click', function (e) {
		if (e.target && e.target.classList.contains('attachment-details')) {
			initReplaceButtons();
		}
	});
});
