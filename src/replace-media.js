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
	// Check if replaceMediaData is available
	if (typeof window.replaceMediaData === 'undefined') {
		// Silently fail if data is not available
		return;
	}

	// Function to show error messages
	function showErrorMessage(message) {
		// Try to use WordPress Block Editor notices first
		if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
			try {
				const noticesStore = wp.data.dispatch('core/notices');
				if (noticesStore && noticesStore.createErrorNotice) {
					noticesStore.createErrorNotice(message, {
						id: 'replace-media-error',
						isDismissible: true,
					});
					return;
				}
			} catch (error) {
				// Silently continue to fallback
			}
		}

		// Clean fallback - show an alert with proper translation
		/* eslint-disable no-alert */
		window.alert(__('Error:', 'replace-media') + ' ' + message);
		/* eslint-enable no-alert */
	}

	// Function to perform the actual replacement
	function performReplacement(attachmentId, file, button) {
		const formData = new FormData();
		formData.append('action', 'replace_media_file');
		formData.append('nonce', window.replaceMediaData.nonce);
		formData.append('attachment_id', attachmentId);
		formData.append('replacement_file', file);

		// Send AJAX request
		fetch(window.replaceMediaData.ajaxUrl, {
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
					const errorMessage = data.data || __('Error replacing file.', 'replace-media');
					showErrorMessage(errorMessage);
					if (button) {
						button.disabled = false;
						button.textContent = __('Replace File', 'replace-media');
					}
				}
			})
			.catch(error => {
				showErrorMessage(__('Error replacing file:', 'replace-media') + error.message);
				if (button) {
					button.disabled = false;
					button.textContent = __('Replace File', 'replace-media');
				}
			});
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

		const button = this;

		fileInput.addEventListener('change', function () {
			if (this.files.length === 0) {
				document.body.removeChild(fileInput);
				return;
			}

			const selectedFile = this.files[0];

			// Show loading state
			button.disabled = true;
			button.textContent = __('Replacing…', 'replace-media');

			// Perform the replacement directly (strict dimension checking is now server-side)
			performReplacement(attachmentId, selectedFile, button);

			// Clean up file input
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
