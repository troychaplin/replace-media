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

/**
 * Handle the media replacement functionality.
 */
document.addEventListener('DOMContentLoaded', function () {
	console.log('Replace Media script loaded');

	// Check if replaceMediaData is available
	if (typeof replaceMediaData === 'undefined') {
		console.error(
			'Replace Media: replaceMediaData is not defined. Script may not be properly localized.'
		);
		return;
	}

	console.log('Replace Media: replaceMediaData available:', replaceMediaData);

	// Function to initialize replace buttons
	function initReplaceButtons() {
		const replaceButtons = document.querySelectorAll('.replace-media-button');
		console.log('Found replace buttons:', replaceButtons.length);

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
		console.log('Replace button clicked');

		const attachmentId = this.getAttribute('data-attachment-id');
		console.log('Attachment ID:', attachmentId);

		// Create file input
		const fileInput = document.createElement('input');
		fileInput.type = 'file';
		fileInput.style.display = 'none';
		document.body.appendChild(fileInput);

		fileInput.addEventListener('change', function () {
			if (this.files.length === 0) {
				console.log('No file selected');
				return;
			}

			console.log('File selected:', this.files[0].name);

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

			console.log('Sending AJAX request to:', replaceMediaData.ajaxUrl);

			// Send AJAX request
			fetch(replaceMediaData.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			})
				.then(response => {
					console.log('Response status:', response.status);
					if (!response.ok) {
						throw new Error(`HTTP error! status: ${response.status}`);
					}
					return response.text().then(text => {
						try {
							return JSON.parse(text);
						} catch (e) {
							console.error('Error parsing JSON:', text);
							throw new Error('Invalid JSON response from server');
						}
					});
				})
				.then(data => {
					console.log('Response data:', data);
					if (data.success) {
						console.log('File replaced successfully');
						// Refresh the media library
						window.location.reload();
					} else {
						console.error('Error response:', data.data);
						// Show error in a more user-friendly way
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
					console.error('Fetch error:', error);
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
		wp.media.frame.on('open', function () {
			console.log('Media modal opened');
			initReplaceButtons();
		});
	}

	// Initialize buttons when attachment details are shown
	document.addEventListener('click', function (e) {
		if (e.target && e.target.classList.contains('attachment-details')) {
			console.log('Attachment details shown');
			initReplaceButtons();
		}
	});
});
