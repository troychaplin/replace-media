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
		// Try to use WordPress admin notices if available
		if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch('core/notices')) {
			try {
				wp.data.dispatch('core/notices').createErrorNotice(message);
				return;
			} catch (error) {
				// Fall back to alert if notices don't work
				console.error('Notice system error:', error);
			}
		}

		// Fallback to browser alert
		alert(__('Error: ', 'replace-media') + message);
		console.error('Replace Media Error:', message);
	}

	// Function to show confirmation dialog with custom styling
	function showConfirmationDialog(message, onConfirm, onCancel) {
		// Create modal overlay
		const overlay = document.createElement('div');
		overlay.style.cssText = `
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.5);
			z-index: 100000;
			display: flex;
			align-items: center;
			justify-content: center;
		`;

		// Create modal content
		const modal = document.createElement('div');
		modal.style.cssText = `
			background: white;
			padding: 20px;
			border-radius: 4px;
			max-width: 500px;
			box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
		`;

		// Create message
		const messageEl = document.createElement('p');
		messageEl.textContent = message;
		messageEl.style.margin = '0';

		// Create button container
		const buttonContainer = document.createElement('div');
		buttonContainer.style.cssText = 'text-align: right; margin-top: 20px;';

		// Create cancel button
		const cancelBtn = document.createElement('button');
		cancelBtn.textContent = __('Cancel', 'replace-media');
		cancelBtn.className = 'button';
		cancelBtn.style.marginRight = '10px';

		// Create confirm button
		const confirmBtn = document.createElement('button');
		confirmBtn.textContent = __('Proceed', 'replace-media');
		confirmBtn.className = 'button button-primary';

		// Add event listeners
		cancelBtn.addEventListener('click', function () {
			document.body.removeChild(overlay);
			if (onCancel) onCancel();
		});

		confirmBtn.addEventListener('click', function () {
			document.body.removeChild(overlay);
			if (onConfirm) onConfirm();
		});

		// Close on overlay click
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				document.body.removeChild(overlay);
				if (onCancel) onCancel();
			}
		});

		// Assemble modal
		buttonContainer.appendChild(cancelBtn);
		buttonContainer.appendChild(confirmBtn);
		modal.appendChild(messageEl);
		modal.appendChild(buttonContainer);
		overlay.appendChild(modal);

		// Add to page
		document.body.appendChild(overlay);
	}

	// Function to check dimensions before replacement
	function checkDimensions(attachmentId, file, onProceed) {
		const formData = new FormData();
		formData.append('action', 'replace_media_file');
		formData.append('nonce', window.replaceMediaData.nonce);
		formData.append('attachment_id', attachmentId);
		formData.append('replacement_file', file);
		formData.append('check_dimensions_only', '1');

		return fetch(window.replaceMediaData.ajaxUrl, {
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
					if (data.data.skip_check) {
						// Not an image, proceed directly
						onProceed(true);
					} else if (data.data.dimension_warning) {
						// Show warning and ask for confirmation
						showConfirmationDialog(
							data.data.message,
							() => onProceed(true), // User confirmed
							() => onProceed(false) // User cancelled
						);
					} else {
						// No dimension issues, proceed
						onProceed(true);
					}
				} else {
					throw new Error(
						data.data || __('Error checking file dimensions.', 'replace-media')
					);
				}
			});
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
				showErrorMessage(__('Error replacing file: ', 'replace-media') + error.message);
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
