document.addEventListener('DOMContentLoaded', () => {
	const { __, _x, _n, _nx } = wp.i18n;

	// --- DOM Elements ---
	const startBtn = document.querySelector('#feas-ai-start-sync');
	const progressContainer = document.querySelector('#feas-ai-progress-container');
	const progressBar = document.querySelector('#feas-ai-progress-bar');
	const statusDiv = document.querySelector('#feas-ai-sync-status');
	const statusSpinner = statusDiv?.querySelector('.spin');
	const statusText = statusDiv?.querySelector('.status-text');
	const deleteButton = document.querySelector('#feas-ai-delete-vectors-button');
	const deleteStatus = document.querySelector('#feas-ai-delete-status');

	let postIDsToSync = [];

	// --- Helper: Ajax wrapper using fetch ---
	async function wpPost(action, data = {}) {
		const formData = new URLSearchParams({ action, ...data });
		const response = await fetch(ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: formData
		});
		return response.json();
	}

	// --- Sync Start Button Handler ---
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
			const response = await wpPost('feas_ai_start_sync', { nonce: feas_ai_sync_obj.nonce });
			if (!response.success) throw new Error();

			const { total_pages, total_posts, post_ids } = response.data;
			postIDsToSync = post_ids;

			if (total_pages > 0) {
				processBatch(1, total_pages, total_posts);
			} else {
				statusText.textContent = __('There are no posts to sync.', 'fe-ai-search');
				statusSpinner.style.display = 'none';
				startBtn.disabled = false;
			}
		} catch {
			statusText.innerHTML = `<span style="color:red;">${__('Error: Failed to communicate with the server.', 'fe-ai-search')}</span>`;
			statusSpinner.style.display = 'none';
			startBtn.disabled = false;
		}
	});

	// --- Batch Processing Function ---
	async function processBatch(currentPage, totalPages, totalPosts) {
		const batchSize = 100;
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
					progressBar.style.width = '100%';
					progressBar.textContent = '100%';
					statusText.innerHTML = `<strong style="color:green;">${__('Synchronization complete!', 'fe-ai-search')} (${totalPosts} ${__('items', 'fe-ai-search')})</strong>`;
					startBtn.disabled = false;
					statusSpinner.style.display = 'none';
					wpPost('feas_ai_update_sync_timestamp', { nonce: feas_ai_sync_obj.nonce });
				}
			} else {
				throw new Error();
			}
		} catch {
			statusText.innerHTML = `<span style="color:red;">${__('Error: A problem occurred while processing batch', 'fe-ai-search')} ${currentPage}.</span>`;
			statusSpinner.style.display = 'none';
			startBtn.disabled = false;
		}
	}

	// --- API Key Test Buttons ---
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

	// --- Tab Navigation ---
	const tabsWrapper = document.querySelector('.nav-tab-wrapper');
	if (tabsWrapper) {
		const tabContents = document.querySelectorAll('.tab-content');

		// 初期状態では、すべてのコンテンツを非表示
		tabContents.forEach(content => content.style.display = 'none');

		const activateTab = (targetId) => {
			// targetIdが不正な場合は何もしない
			if (!targetId || !document.querySelector(targetId)) return;

			const targetTab = tabsWrapper.querySelector(`a[href="${targetId}"]`);
			const targetContent = document.querySelector(targetId);

			tabsWrapper.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('nav-tab-active'));
			tabContents.forEach(content => content.style.display = 'none');

			if (targetTab && targetContent) {
				targetTab.classList.add('nav-tab-active');
				targetContent.style.display = 'block';

				// 表示されたタブ内のCodeMirrorを初期化・リフレッシュ
				targetContent.querySelectorAll('.feas-ai-prompt-editor').forEach(initializeCodeMirror);
			}

			if (history.pushState) {
				history.pushState(null, null, targetId);
			} else {
				location.hash = targetId;
			}
		};

		// ▼▼▼ イベント委譲を使って、親要素でクリックを監視する ▼▼▼
		tabsWrapper.addEventListener('click', e => {
			// クリックされたのが .nav-tab クラスを持つ要素か確認
			if (e.target.classList.contains('nav-tab')) {
				e.preventDefault();
				activateTab(e.target.getAttribute('href'));
			}
		});

		// ページ読み込み時の初期タブ表示
		// DOMの準備が完全に整った後に実行するため、少し遅延させる
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
	} else {
		console.error('Tab wrapper (.nav-tab-wrapper) not found!');
	}

	// --- Delete Synced Data Button ---
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
				deleteStatus.style.color = 'red';
				deleteStatus.textContent = __('An error occurred.', 'fe-ai-search');
			}
		} catch {
			deleteStatus.style.color = 'red';
			deleteStatus.textContent = __('A communication error has occurred.', 'fe-ai-search');
		} finally {
			deleteButton.disabled = false;
			spinner.style.visibility = 'hidden';
		}
	});

	// --- Accordion UI Logic ---
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

	// --- CodeMirror Initialization ---
	function initializeCodeMirror(textarea) {
		if (textarea.dataset.codemirrorInitialized) return;
		const editor = CodeMirror.fromTextArea(textarea, {
			lineNumbers: true,
			mode: 'markdown',
			lineWrapping: true
		});
		textarea.dataset.codemirrorInitialized = true;
		setTimeout(() => editor.refresh(), 1);
	}

	document.querySelectorAll('.feas-ai-prompt-editor').forEach(textarea => {
		const tabContent = textarea.closest('.tab-content');
		if (textarea.offsetParent !== null) {
			initializeCodeMirror(textarea);
		} else if (tabContent) {
			const tabId = tabContent.id;
			const tab = document.querySelector(`a[href="#${tabId}"]`);
			tab?.addEventListener('click', () => initializeCodeMirror(textarea), { once: true });
		}
	});
});
