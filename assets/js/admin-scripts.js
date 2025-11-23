/**
 * FE AI Search Admin Scripts
 *
 * This file handles all the JavaScript functionality for the plugin's admin
 * settings page, including AJAX-based synchronization, API tests, and UI interactions
 * like tab navigation and CodeMirror initialization.
 *
 * @package
 * @since   1.0.0
 */

/* global ajaxurl, fe_ai_search_sync_obj */

document.addEventListener('DOMContentLoaded', () => {
	// Initialize WordPress internationalization functions.
	const { __ } = window.wp.i18n;

	// ==========================================================================
	// DOM Element Caching
	// ==========================================================================
	const startBtn = document.querySelector('#fe-ai-start-sync');
	const progressContainer = document.querySelector('#fe-ai-search-progress-container');
	const progressBar = document.querySelector('#fe-ai-search-progress-bar');
	const statusDiv = document.querySelector('#fe-ai-search-sync-status');
	const statusSpinner = statusDiv?.querySelector('.spin');
	const statusText = statusDiv?.querySelector('.status-text');
	const deleteButton = document.querySelector('#fe-ai-search-delete-vectors-button');
	const deleteStatus = document.querySelector('#fe-ai-search-delete-status');
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
	 *
	 * @param {string} action - The wp_ajax_{action} hook to target.
	 * @param {Object} data   - Additional data to send in the request body.
	 * @return {Promise<Object>} A promise that resolves to the JSON response.
	 */
	async function wpPost(action, data = {}) {
		const formData = new URLSearchParams({ action, ...data });
		const response = await fetch(ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: formData,
		});

		return response.json();
	}

	// ==========================================================================
	// Manual Synchronization
	// ==========================================================================

	const rebuildBtn = document.querySelector('#fe-ai-search-start-sync');
	const smartSyncBtn = document.querySelector('#fe-ai-search-smart-sync');

	/**
	 * Initiates a sync process (either full rebuild or smart sync).
	 *
	 * @param {string} action              The AJAX action to call.
	 * @param {string} confirmationMessage The message to display in the confirm() dialog.
	 * @return {void}
	 */
	async function startSyncProcess(action, confirmationMessage) {
		if (!confirm(confirmationMessage)) {
			return;
		}

		// Disable both buttons to prevent multiple clicks.
		rebuildBtn.disabled = true;
		smartSyncBtn.disabled = true;
		statusSpinner.style.display = 'inline-block';
		statusText.textContent = __('Preparing for synchronization…', 'fe-ai-search');
		progressContainer.style.display = 'block';
		progressBar.style.width = '0%';
		progressBar.textContent = '0%';

		try {
			const response = await wpPost(action, {
				nonce: fe_ai_search_sync_obj.nonce,
			});
			if (!response.success) {
				// If the backend sends an error (like settings changed), display it.
				throw new Error(response.data.message || 'Failed to start sync.');
			}

			const { total_pages, total_posts, post_ids, batch_size } = response.data;
			postIDsToSync = post_ids;

			if (total_pages > 0) {
				processBatch(1, total_pages, total_posts, batch_size);
			} else {
				statusText.textContent = __('There are no posts to sync.', 'fe-ai-search');
				statusSpinner.style.display = 'none';
				rebuildBtn.disabled = false;
				smartSyncBtn.disabled = false;
			}
		} catch (error) {
			statusText.innerHTML = `<span style="color:red;">${__('Error:', 'fe-ai-search')} ${error.message}</span>`;
			statusSpinner.style.display = 'none';
			rebuildBtn.disabled = false;
			smartSyncBtn.disabled = false;
		}
	}

	// --- Event Listeners for Sync Buttons ---

	// Handler for the "Rebuild Index" button
	rebuildBtn?.addEventListener('click', e => {
		e.preventDefault();
		const message = __(
			'Are you sure you want to delete all existing indexes and rebuild them? This is a resource-intensive process.',
			'fe-ai-search'
		);
		startSyncProcess('fe_ai_search_start_sync', message);
	});
	// Handler for the "Sync Changes" button
	smartSyncBtn?.addEventListener('click', e => {
		e.preventDefault();
		const message = __(
			'Are you sure you want to sync recent changes? This will process new, updated, and deleted posts.',
			'fe-ai-search'
		);
		startSyncProcess('fe_ai_search_start_smart_sync', message);
	});
	/**
	 * Recursively processes one batch of posts at a time.
	 *
	 * @param {number} currentPage The current batch number to process.
	 * @param {number} totalPages  The total number of batches.
	 * @param {number} totalPosts  The total number of posts to sync.
	 * @param {number} batch_size  Number of posts to process in each batch.
	 * @return {void}
	 */
	async function processBatch(currentPage, totalPages, totalPosts, batch_size) {
		const processed = Math.min((currentPage - 1) * batch_size, totalPosts);
		const progress = totalPosts ? Math.round((processed / totalPosts) * 100) : 0;

		progressBar.style.width = `${progress}%`;
		progressBar.textContent = `${progress}%`;
		statusText.textContent = `${__('Processing posts…', 'fe-ai-search')} (${processed} / ${totalPosts})`;
		statusSpinner.style.display = 'inline-block';

		// サーバーからの生テキストを保持する変数
		let responseText = '';

		try {
			const formData = new URLSearchParams({
				action: 'fe_ai_search_process_batch',
				nonce: fe_ai_search_sync_obj.nonce,
				page: currentPage,
				post_ids: JSON.stringify(postIDsToSync),
			});

			// 1. fetchを直接使い、応答をまずテキストとして受け取る
			const rawResponse = await fetch(ajaxurl, {
				method: 'POST',
				body: formData,
			});
			responseText = await rawResponse.text();

			// ★ コンソールに、サーバーからの生の応答を出力
			console.log(`Raw server response for batch ${currentPage}:`, responseText);

			// 2. 受け取ったテキストをJSONとして解析してみる
			const response = JSON.parse(responseText);

			if (response.success) {
				// If the server reports there are no more posts, treat this as a
				// normal completion regardless of currentPage / totalPages.
				if (response.data && response.data.message === 'No more posts to process.') {
					progressBar.style.width = '100%';
					progressBar.textContent = '100%';
					statusText.innerHTML = `<strong style="color:green;">${__('Synchronization complete!', 'fe-ai-search')} (${totalPosts} ${__('items', 'fe-ai-search')})</strong>`;
					rebuildBtn.disabled = false;
					smartSyncBtn.disabled = false;
					statusSpinner.style.display = 'none';
					await wpPost('fe_ai_search_update_sync_timestamp', {
						nonce: fe_ai_search_sync_obj.nonce,
					});
					await wpPost('fe_ai_search_update_settings_hash', {
						nonce: fe_ai_search_sync_obj.nonce,
					});
					// Reload the page so that the "Last Sync" label reflects the updated timestamp.
					location.reload();
				} else if (currentPage < totalPages) {
					await processBatch(currentPage + 1, totalPages, totalPosts, batch_size);
				} else {
					// Final batch is complete.
					progressBar.style.width = '100%';
					progressBar.textContent = '100%';
					statusText.innerHTML = `<strong style="color:green;">${__('Synchronization complete!', 'fe-ai-search')} (${totalPosts} ${__('items', 'fe-ai-search')})</strong>`;
					rebuildBtn.disabled = false;
					smartSyncBtn.disabled = false;
					statusSpinner.style.display = 'none';
					await wpPost('fe_ai_search_update_sync_timestamp', {
						nonce: fe_ai_search_sync_obj.nonce,
					});
					await wpPost('fe_ai_search_update_settings_hash', {
						nonce: fe_ai_search_sync_obj.nonce,
					});
					// Reload the page so that the "Last Sync" label reflects the updated timestamp.
					location.reload();
				}
			} else {
				throw new Error(response.data.message || 'Batch processing failed.');
			}
		} catch (error) {
			// JSONの解析に失敗した場合、コンソールにエラーを出力
			if (error instanceof SyntaxError) {
				console.error('Failed to parse JSON. See the raw response above for details.');
			}
			statusText.innerHTML = `<span style="color:red;">${__('Error: A problem occurred while processing batch', 'fe-ai-search')} ${currentPage}.</span>`;
			statusSpinner.style.display = 'none';
			rebuildBtn.disabled = false;
			smartSyncBtn.disabled = false;
		}
	}

	// ==========================================================================
	// UI Interactions
	// ==========================================================================

	// --- API Key Test Buttons ---
	document.querySelectorAll('.fe-ai-search-test-api').forEach(button => {
		button.addEventListener('click', async () => {
			const provider = button.dataset.provider;
			const apiKeyId = button.dataset.apiKeyId || `fe_ai_search_${provider}_api_key`; // デフォルトのIDを構築
			const endpointId = button.dataset.endpointId;
			const apiKeyInput = document.getElementById(apiKeyId);
			const apiKey = apiKeyInput ? apiKeyInput.value : '';
			const spinner = button.parentElement.querySelector('.spinner');
			const status = button.parentElement.querySelector('.fe-ai-search-api-status');
			if (!apiKey) {
				// APIキーが空でも、エンドポイントテストの場合は警告しない（キーが不要な場合もあるため）
				if (!endpointId) {
					alert(__('Please enter an API key.', 'fe-ai-search'));
					return;
				}
			}
			spinner.style.visibility = 'visible';
			status.textContent = '';
			// AJAXで送信するデータを準備
			const postData = {
				nonce: fe_ai_search_sync_obj.nonce,
				provider,
				api_key: apiKey,
			};
			// エンドポイントIDがあれば、その値もデータに追加
			if (endpointId) {
				const endpointInput = document.getElementById(endpointId);
				if (endpointInput) {
					postData.endpoint = endpointInput.value;
				}
			}

			try {
				const response = await wpPost('fe_ai_search_test_api_key', postData);
				status.innerHTML = response.data;
			} catch {
				status.innerHTML = `<span style="color:red;">✖${__('A communication error has occurred.', 'fe-ai-search')}</span>`;
			} finally {
				spinner.style.visibility = 'hidden';
			}
		});
	});
	// Delete Synced Data Button
	deleteButton?.addEventListener('click', async () => {
		if (
			!confirm(
				__(
					'Are you sure you want to delete all synced data? This action cannot be undone.',
					'fe-ai-search'
				)
			)
		) {
			return;
		}
		deleteButton.disabled = true;
		const spinner = deleteButton.parentElement.querySelector('.spinner');
		spinner.style.visibility = 'visible';
		deleteStatus.textContent = '';
		try {
			const response = await wpPost('fe_ai_search_delete_vectors', {
				nonce: fe_ai_search_sync_obj.nonce,
			});
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
	// "Change Model" Link Handler
	document.querySelectorAll('.fe-ai-search-change-model-link').forEach(link => {
		link.addEventListener('click', e => {
			e.preventDefault();
			const targetTabId = link.getAttribute('href');
			const targetTab = document.querySelector(
				`.nav-tab-wrapper a.nav-tab[href="${targetTabId}"]`
			);
			if (targetTab) {
				targetTab.click();
			}
		});
	});
	// --- Animation Speed Slider UI ---
	const animationSpeedSlider = document.querySelector('#fe_ai_search_animation_speed_slider');
	if (animationSpeedSlider) {
		const animationSpeedValue = document.querySelector('#fe_ai_search_animation_speed_value');

		animationSpeedSlider.addEventListener('input', () => {
			animationSpeedValue.textContent = animationSpeedSlider.value;
		});
	}
	// ==========================================================================
	// Accordion UI Logic (FINAL & COMPLETE VERSION)
	// ==========================================================================

	/**
	 * Initializes any open accordions within a specific container.
	 * This is the core function for making accordions visible.
	 *
	 * @param {HTMLElement} container - The element to search within.
	 * @param               textarea
	 */
	// --- CodeMirror Initialization ---
	// This function is now called specifically when its container becomes visible.
	function initializeCodeMirror(textarea) {
		if (!textarea || textarea.dataset.codemirrorInitialized) {
			return;
		}
		const isDisabled = textarea.dataset.disabled === 'true';
		const editor = CodeMirror.fromTextArea(textarea, {
			lineNumbers: true,
			mode: 'markdown',
			lineWrapping: true,
			readOnly: isDisabled,
		});
		if (isDisabled) {
			editor.getWrapperElement().style.backgroundColor = '#f0f0f0';
		}
		textarea.dataset.codemirrorInitialized = 'true';
		// Refresh is now handled by the tab/accordion logic.
	}

	// --- Accordion Click Handler (Event Delegation) ---
	// We listen on the entire settings page for clicks.
	const settingsWrapper = document.querySelector('.wrap');
	if (settingsWrapper) {
		settingsWrapper.addEventListener('click', e => {
			// Check if an accordion title was the target of the click.
			const title = e.target.closest('.accordion-title');
			if (!title) {
				return;
			}
			// If a checkbox inside the title was clicked, let it do its thing.
			if (e.target.tagName === 'INPUT' && e.target.type === 'checkbox') {
				return;
			}

			e.preventDefault();
			const content = title.nextElementSibling;
			if (content && content.classList.contains('accordion-content')) {
				// Simply toggle the display property. No animations, but always reliable.
				const isOpen = content.style.display === 'block';
				content.style.display = isOpen ? 'none' : 'block';

				// If it was just opened, initialize any CodeMirror instances inside.
				if (!isOpen) {
					content.querySelectorAll('.fe-ai-search-prompt-editor').forEach(textarea => {
						initializeCodeMirror(textarea);
					});
					// Also explicitly refresh any that might already exist.
					content.querySelectorAll('.CodeMirror').forEach(cm => {
						if (cm.CodeMirror) {
							cm.CodeMirror.refresh();
						}
					});
				}
			}
		});
	}
	// --- Tab Navigation Logic ---
	if (tabsWrapper) {
		const tabContents = document.querySelectorAll('.tab-content');
		tabContents.forEach(content => (content.style.display = 'none'));

		const activateTab = targetId => {
			if (!targetId || !document.querySelector(targetId)) {
				return;
			}

			const targetTab = tabsWrapper.querySelector(`a[href = "${targetId}"]`);
			const targetContent = document.querySelector(targetId);

			tabsWrapper
				.querySelectorAll('.nav-tab')
				.forEach(tab => tab.classList.remove('nav-tab-active'));
			tabContents.forEach(content => (content.style.display = 'none'));

			if (targetTab && targetContent) {
				targetTab.classList.add('nav-tab-active');
				targetContent.style.display = 'block';

				// When a tab becomes visible, initialize any CodeMirror editors inside it.
				targetContent.querySelectorAll('.fe-ai-search-prompt-editor').forEach(textarea => {
					initializeCodeMirror(textarea);
				});
			}

			if (history.pushState) {
				history.pushState(null, '', targetId);
			} else {
				location.hash = targetId;
			}
		};

		tabsWrapper.addEventListener('click', e => {
			if (e.target.classList.contains('nav-tab')) {
				e.preventDefault();
				activateTab(e.target.getAttribute('href'));
			}
		});

		// On initial page load, activate the correct tab.
		setTimeout(() => {
			const hash = window.location.hash;
			const initialTabAnchor = tabsWrapper.querySelector(`a[href = "${hash}"]`);
			const initialTabId =
				hash && initialTabAnchor
					? hash
					: tabsWrapper.querySelector('.nav-tab')?.getAttribute('href');
			if (initialTabId) {
				activateTab(initialTabId);
			}
		}, 50);
	}

	// ==========================================================================
	// License Activation & Deactivation
	// ==========================================================================

	// We must use event delegation on the document body, because the license tab
	// is part of the main settings form, not a separate Pro feature.
	document.body.addEventListener('click', async e => {
		let action = '';
		let button = null;
		if (e.target.id === 'fe_ai_search_license_activate') {
			action = 'activate';
			button = e.target;
		} else if (e.target.id === 'fe_ai_search_license_deactivate') {
			action = 'deactivate';
			button = e.target;
		}

		// If a license button was not clicked, do nothing.
		if (!button) {
			return;
		}

		const licenseKeyInput = document.getElementById('fe_ai_search_pro_license_key_input');
		const licenseKey = licenseKeyInput ? licenseKeyInput.value : '';
		const spinner = button.parentElement.querySelector('.spinner');
		button.disabled = true;
		spinner.style.visibility = 'visible';
		try {
			// Use the wpPost helper function we already defined.
			const response = await wpPost('fe_ai_search_manage_license', {
				nonce: fe_ai_search_sync_obj.nonce,
				license_key: licenseKey,
				license_action: action,
			});

			// Always reload the page to show the new status.
			location.reload();
		} catch (error) {
			alert(__('An error occurred during activation. Please try again.', 'fe-ai-search'));
			button.disabled = false;
			spinner.style.visibility = 'hidden';
		}
	});
});
