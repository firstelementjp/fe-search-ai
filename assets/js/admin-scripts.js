/**
 * FE AI Search Admin Scripts
 *
 * This file handles all the JavaScript functionality for the plugin's admin
 * settings page, including AJAX-based synchronization, API tests, and UI interactions
 * like tab navigation and CodeMirror initialization.
 *
 * @package    fe-ai-search
 * @since      1.0.0
 */

document.addEventListener('DOMContentLoaded', () => {
	// Initialize WordPress internationalization functions.
	const { __ } = window.wp.i18n;

	// ==========================================================================
	// DOM Element Caching
	// ==========================================================================

	const startBtn = document.querySelector('#feas-ai-start-sync');
	const progressContainer = document.querySelector('#feas-ai-progress-container');
	const progressBar = document.querySelector('#feas-ai-progress-bar');
	const statusDiv = document.querySelector('#feas-ai-sync-status');
	const statusSpinner = statusDiv?.querySelector('.spin');
	const statusText = statusDiv?.querySelector('.status-text');
	const deleteButton = document.querySelector('#feas-ai-delete-vectors-button');
	const deleteStatus = document.querySelector('#feas-ai-delete-status');
	const tabsWrapper = document.querySelector('.nav-tab-wrapper');

	// ==========================================================================
	// Variables
	// ==========================================================================

	let postIDsToSync = []; // Holds the list of post IDs for the batch sync process.

	// ==========================================================================
	// Helper Functions
	// ==========================================================================

	/**
	 * A simple wrapper for WordPress AJAX calls using the Fetch API.
	 * @param {string} action - The wp_ajax_{action} hook to target.
	 * @param {object} data - Additional data to send in the request body.
	 * @returns {Promise<object>} - A promise that resolves to the JSON response.
	 */
	async function wpPost(action, data = {}) {
		const formData = new URLSearchParams({ action, ...data });
		const response = await fetch(ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: formData
		});
		return response.json();
	}

	// ==========================================================================
	// Manual Synchronization
	// ==========================================================================

	// Handles the initial click of the "Start Syncing" button.
	startBtn?.addEventListener('click', async e => {
		e.preventDefault();
		if (!confirm(__('Are you sure you want to delete all existing indexes and rebuild them?', 'fe-ai-search'))) return;

		startBtn.disabled = true;
		statusSpinner.style.display = 'inline-block';
		statusText.textContent = __('Preparing for synchronization...', 'fe-ai-search');
		progressContainer.style.display = 'block';
		progressBar.style.width = '0%';
		progressBar.textContent = '0%';

		try {
			// Get the list of post IDs to sync.
			const response = await wpPost('feas_ai_start_sync', { nonce: feas_ai_sync_obj.nonce });
			if (!response.success) throw new Error('Failed to start sync.');

			const { total_pages, total_posts, post_ids, batch_size } = response.data;
			postIDsToSync = post_ids;

			if (total_pages > 0) {
				// Start the recursive batch processing.
				processBatch(1, total_pages, total_posts, batch_size);
			} else {
				statusText.textContent = __('There are no posts to sync.', 'fe-ai-search');
				statusSpinner.style.display = 'none';
				startBtn.disabled = false;
			}
		} catch (error) {
			statusText.innerHTML = `<span style="color:red;">${__('Error: Failed to communicate with the server.', 'fe-ai-search')}</span>`;
			statusSpinner.style.display = 'none';
			startBtn.disabled = false;
		}
	});

	/**
	 * Recursively processes one batch of posts at a time.
	 * @param {number} currentPage - The current batch number to process.
	 * @param {number} totalPages - The total number of batches.
	 * @param {number} totalPosts - The total number of posts to sync.
	 */
	async function processBatch(currentPage, totalPages, totalPosts, batch_size) {
		// const batchSize = 100; // This should match the PHP setting.
		const processed = Math.min((currentPage - 1) * batchSize, totalPosts);
		const progress = totalPosts ? Math.round((processed / totalPosts) * 100) : 0;

		progressBar.style.width = `${progress}%`;
		progressBar.textContent = `${progress}%`;
		statusText.textContent = `${__('Processing posts...', 'fe-ai-search')} (${processed} / ${totalPosts})`;
		statusSpinner.style.display = 'inline-block';

		try {
			const response = await wpPost('feas_ai_process_batch', {
				nonce: feas_ai_sync_obj.nonce,
				page: currentPage,
				post_ids: JSON.stringify(postIDsToSync)
			});

			if (response.success) {
				if (currentPage < totalPages) {
					await processBatch(currentPage + 1, totalPages, totalPosts);
				} else {
					// Final batch is complete.
					progressBar.style.width = '100%';
					progressBar.textContent = '100%';
					statusText.innerHTML = `<strong style="color:green;">${__('Synchronization complete!', 'fe-ai-search')} (${totalPosts} ${__('items', 'fe-ai-search')})</strong>`;
					startBtn.disabled = false;
					statusSpinner.style.display = 'none';
					wpPost('feas_ai_update_sync_timestamp', { nonce: feas_ai_sync_obj.nonce });
				}
			} else {
				throw new Error(response.data.message || 'Batch processing failed.');
			}
		} catch (error) {
			statusText.innerHTML = `<span style="color:red;">${__('Error: A problem occurred while processing batch', 'fe-ai-search')} ${currentPage}.</span>`;
			statusSpinner.style.display = 'none';
			startBtn.disabled = false;
		}
	}

	// ==========================================================================
	// UI Interactions
	// ==========================================================================

	// API Key Test Buttons
	document.querySelectorAll('.feas-ai-test-api').forEach(button => {
		button.addEventListener('click', async () => {
			const provider = button.dataset.provider;
			const input = document.querySelector(`#feas_ai_${provider}_api_key`);
			const apiKey = input?.value;
			const spinner = button.parentElement.querySelector('.spinner');
			const status = button.parentElement.querySelector('.feas-ai-api-status');

			if (!apiKey) {
				alert(__('Please enter an API key.', 'fe-ai-search'));
				return;
			}

			spinner.style.visibility = 'visible';
			status.textContent = '';

			try {
				const response = await wpPost('feas_ai_test_api_key', {
					nonce: feas_ai_sync_obj.nonce,
					provider,
					api_key: apiKey
				});
				status.innerHTML = response.data;
			} catch {
				status.innerHTML = `<span style="color:red;">✖ ${__('A communication error has occurred.', 'fe-ai-search')}</span>`;
			} finally {
				spinner.style.visibility = 'hidden';
			}
		});
	});

	// Tab Navigation
	if (tabsWrapper) {
		const tabContents = document.querySelectorAll('.tab-content');
		tabContents.forEach(content => content.style.display = 'none');

		const activateTab = (targetId) => {
			if (!targetId || !document.querySelector(targetId)) return;

			const targetTab = tabsWrapper.querySelector(`a[href="${targetId}"]`);
			const targetContent = document.querySelector(targetId);

			tabsWrapper.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('nav-tab-active'));
			tabContents.forEach(content => content.style.display = 'none');

			if (targetTab && targetContent) {
				targetTab.classList.add('nav-tab-active');
				targetContent.style.display = 'block';
				targetContent.querySelectorAll('.feas-ai-prompt-editor').forEach(initializeCodeMirror);
			}

			if (history.pushState) {
				history.pushState(null, '', targetId);
			} else {
				location.hash = targetId;
			}
		};

		// Use event delegation to handle clicks on tab links.
		tabsWrapper.addEventListener('click', e => {
			if (e.target.classList.contains('nav-tab')) {
				e.preventDefault();
				activateTab(e.target.getAttribute('href'));
			}
		});

		// Activate the correct tab on page load (based on URL hash).
		setTimeout(() => {
			const hash = window.location.hash;
			if (hash && tabsWrapper.querySelector(`a[href="${hash}"]`)) {
				activateTab(hash);
			} else {
				const firstTab = tabsWrapper.querySelector('.nav-tab');
				if (firstTab) {
					activateTab(firstTab.getAttribute('href'));
				}
			}
		}, 0);
	}

	// Delete Synced Data Button
	deleteButton?.addEventListener('click', async () => {
		if (!confirm(__('Are you sure you want to delete all synced data? This action cannot be undone.', 'fe-ai-search'))) return;

		deleteButton.disabled = true;
		const spinner = deleteButton.parentElement.querySelector('.spinner');
		spinner.style.visibility = 'visible';
		deleteStatus.textContent = '';

		try {
			const response = await wpPost('feas_ai_delete_vectors', { nonce: feas_ai_sync_obj.nonce });
			if (response.success) {
				deleteStatus.style.color = 'green';
				deleteStatus.textContent = response.data;
				setTimeout(() => location.reload(), 1000);
			} else {
				throw new Error(response.data.message || 'Deletion failed.');
			}
		} catch (error) {
			deleteStatus.style.color = 'red';
			deleteStatus.textContent = __('A communication error has occurred.', 'fe-ai-search');
		} finally {
			deleteButton.disabled = false;
			spinner.style.visibility = 'hidden';
		}
	});

	// Accordion UI Logic
	document.querySelectorAll('.feas-ai-accordion-wrapper').forEach(wrapper => {
		wrapper.querySelectorAll('.accordion-title').forEach(title => {
			title.addEventListener('click', e => {
				if (e.target.tagName === 'INPUT' && e.target.type === 'checkbox') return;
				e.preventDefault();
				const content = title.nextElementSibling;
				if (content) {
					content.classList.toggle('open');
					content.style.display = content.classList.contains('open') ? 'block' : 'none';

					const cm = content.querySelector('.CodeMirror');
					if (cm && cm.CodeMirror) {
						setTimeout(() => cm.CodeMirror.refresh(), 100);
					}
				}
			});
		});
	});

	// "Change Model" Link Handler
	document.querySelectorAll('.feas-ai-change-model-link').forEach(link => {
		link.addEventListener('click', e => {
			e.preventDefault();
			const targetTabId = link.getAttribute('href');
			const targetTab = document.querySelector(`.nav-tab-wrapper a.nav-tab[href="${targetTabId}"]`);
			if (targetTab) {
				targetTab.click();
			}
		});
	});

	// CodeMirror Initialization
	function initializeCodeMirror(textarea) {
		if (!textarea || textarea.dataset.codemirrorInitialized) return;

		const isDisabled = textarea.dataset.disabled === 'true';
		const editor = CodeMirror.fromTextArea(textarea, {
			lineNumbers: true,
			mode: 'markdown',
			lineWrapping: true,
			readOnly: isDisabled
		});

		if (isDisabled) {
			editor.getWrapperElement().style.backgroundColor = '#f0f0f0';
		}
		textarea.dataset.codemirrorInitialized = 'true';
	}

	// --- Animation Speed Slider UI ---
	const animationSpeedSlider = document.querySelector('#feas_ai_animation_speed_slider');
	if (animationSpeedSlider) {
		const animationSpeedValue = document.querySelector('#feas_ai_animation_speed_value');

		animationSpeedSlider.addEventListener('input', () => {
			animationSpeedValue.textContent = animationSpeedSlider.value;
		});
	}
});
