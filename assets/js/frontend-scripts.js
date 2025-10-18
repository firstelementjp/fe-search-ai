
const { __ } = wp.i18n;

/**
 * A simple wrapper for WordPress AJAX calls using the Fetch API.
 * @param {string} action - The wp_ajax_{action} hook to target.
 * @param {object} data - Additional data to send in the request body.
 * @returns {Promise<object>} - A promise that resolves to the JSON response.
 */
async function wpPost(action, data = {}) {
	const formData = new URLSearchParams({ action, ...data });
	const response = await fetch(feas_ai_ajax_obj.ajax_url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: formData
	});
	return response.json();
}

function initFEAIChat() {
	const container = document.getElementById('feas-ai-chat-container');
	if (!container || container.dataset.initialized) {
		return; // 既に初期化済みか、要素がなければ何もしない
	}
	container.dataset.initialized = 'true';

	const bubble = document.getElementById('feas-ai-chat-bubble');
	const windowEl = document.getElementById('feas-ai-chat-window');
	const closeBtn = document.getElementById('feas-ai-chat-close');
	const form = document.getElementById('feas-ai-chat-form');
	const input = document.getElementById('feas-ai-chat-input');
	const messagesContainer = document.getElementById('feas-ai-chat-messages');
	const fullscreenBtn = document.getElementById('feas-ai-chat-fullscreen-toggle');
	const optionsToggle = document.getElementById('feas-ai-options-toggle');
	const optionsMenu = document.getElementById('feas-ai-options-menu');
	const shiftEnterToggle = document.getElementById('feas-ai-shift-enter-toggle');

	if (!bubble || !form) return;

	// ページ読み込み時に、既に全画面モードで表示すべきか判定する
	if (container.classList.contains('is-fullscreen')) {
		windowEl.classList.remove('hidden');
		bubble.classList.add('hidden');
	}

	let sessionId = sessionStorage.getItem('feas_ai_session_id');
	if (!sessionId) {
		sessionId = Date.now().toString(36) + Math.random().toString(36).substr(2);
		sessionStorage.setItem('feas_ai_session_id', sessionId);
	}

	let charQueue = [];
	let renderInterval;
	let currentAiMessageElement = null;
	let fullResponse = '';

	bubble.addEventListener('click', () => {
		windowEl.classList.remove('hidden');
		bubble.classList.add('hidden');
	});

	closeBtn.addEventListener('click', () => {
		windowEl.classList.add('hidden');
		bubble.classList.remove('hidden');
	});

	fullscreenBtn.addEventListener('click', () => {
		container.classList.toggle('is-fullscreen');
	});

	// まずlocalStorageからユーザーの個人設定を読み込む
	let userPrefersShiftEnter = localStorage.getItem('feas_ai_user_prefers_shift_enter');

	// 個人設定がなければ、サイトのデフォルト設定を使う
	if (userPrefersShiftEnter === null) {
		userPrefersShiftEnter = feas_ai_ajax_obj.send_on_shift_enter;
	} else {
		userPrefersShiftEnter = userPrefersShiftEnter === 'true'; // 文字列を真偽値に変換
	}

	// UIのチェックボックスの状態を、現在の設定に合わせる
	shiftEnterToggle.checked = userPrefersShiftEnter;

	// --- 2. イベントリスナーの修正 ---
	optionsToggle.addEventListener('click', () => {
		optionsMenu.classList.toggle('hidden');
	});

	/**
	 * Handles changes to the "Shift+Enter to send" checkbox.
	 * Updates the user's preference and saves it to localStorage.
	 */
	shiftEnterToggle.addEventListener('change', () => {
		// ユーザーが設定を変更したら、その値を
		// userPrefersShiftEnter 変数に即座に反映させる
		userPrefersShiftEnter = shiftEnterToggle.checked;

		// 次回以降の訪問のために、変更をlocalStorageに保存する
		localStorage.setItem('feas_ai_user_prefers_shift_enter', userPrefersShiftEnter);
	});

	/**
	 * Handles keyboard input to determine when to send a message.
	 * This acts as a "gatekeeper" for the form submission.
	 */
	input.addEventListener('keydown', (e) => {
		if (e.isComposing || e.key !== 'Enter') {
			return;
		}
		e.preventDefault();

		// ★ サイトのデフォルト設定ではなく、ユーザーの最新の設定を参照する
		const shiftPressed = e.shiftKey;
		const shouldSubmit = (userPrefersShiftEnter && shiftPressed) || (!userPrefersShiftEnter && !shiftPressed);

		if (shouldSubmit) {
			form.dispatchEvent(new Event('submit', { cancelable: true }));
		}
	});

	/**
	 * Handles the actual form submission process.
	 * This is the "worker" that gets triggered by either the user clicking the send button,
	 * or by the 'keydown' event listener above.
	 *
	 * (This is your existing, fully functional code, with no changes to its internal logic.)
	 */
	form.addEventListener('submit', function(e) {
		e.preventDefault();
		const question = input.value.trim();
		if (!question) return;

		let history = JSON.parse(sessionStorage.getItem('feas_ai_chat_history')) || [];
		const aiMessageWrapper = addMessage(`<p>${question}</p>`, 'user'); // Modified to get wrapper
		history.push({ role: 'user', content: question });

		const recentHistory = history.slice(-10);
		input.value = '';
		disableForm();

		currentAiMessageElement = addMessage('<p><span class="feas-ai-spinner"></span></p>', 'ai').querySelector('p');
		const currentAiMessageWrapper = currentAiMessageElement.parentElement; // Get the wrapper for feedback buttons

		fullResponse = '';
		let contextFound = false;
		let isFirstChunk = true;
		let currentLogId = null; // Variable to store the log ID

		startRenderingQueue();

		fetch(feas_ai_ajax_obj.rest_url, {
			method: 'POST',
			headers: { 'X-WP-Nonce': feas_ai_ajax_obj.rest_nonce },
			body: new URLSearchParams({
				question: question,
				history: JSON.stringify(recentHistory)
			}),
		})
		.then(response => {
			if (!response.ok) { throw new Error('Network response was not ok.'); }
			const reader = response.body.getReader();
			const decoder = new TextDecoder();

			function processStream() {
				reader.read().then(({ done, value }) => {
					if (done) {
						waitForQueueToEmpty().then(() => {
							clearInterval(renderInterval);
							const currentAiMessageWrapper = currentAiMessageElement.parentElement;
							currentAiMessageWrapper.innerHTML = marked.parse(fullResponse);
							history.push({ role: 'assistant', content: fullResponse });
							sessionStorage.setItem('feas_ai_chat_history', JSON.stringify(history));

							// Log conversation and get the log ID back
							logConversation(question, fullResponse, contextFound).then(logId => {
								currentLogId = logId;
								if (feas_ai_ajax_obj.is_license_active && currentLogId) {
									const feedbackWrapper = document.createElement('div');
									feedbackWrapper.className = 'feas-ai-feedback';
									feedbackWrapper.innerHTML = `
										<button class="feedback-btn good" data-log-id="${currentLogId}" data-rating="1" title="Good">
											<svg class="feedback-svg" xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24" width="20px" fill="currentColor"><path d="M0 0h24v24H0V0zm0 0h24v24H0V0z" fill="none"/><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>
										</button>
										<button class="feedback-btn bad" data-log-id="${currentLogId}" data-rating="-1" title="Bad">
											<svg class="feedback-svg" xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24" width="20px" fill="currentColor"><path d="M0 0h24v24H0V0zm0 0h24v24H0V0z" fill="none"/><path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z"/></svg>
										</button>
									`;
									currentAiMessageWrapper.appendChild(feedbackWrapper);
								}
							});

							enableForm();
						});
						return;
					}

					const chunk = decoder.decode(value, {stream: true});
					const lines = chunk.split('\n\n');
					lines.forEach(line => {
						if (line.startsWith('data: ')) {
							const dataContent = line.substring(6).trim();
							if (dataContent === '[DONE]') return;
							try {
								const jsonData = JSON.parse(dataContent);
								if (jsonData.meta) { contextFound = jsonData.meta.context_found; }
								if (jsonData.text) {
									if (isFirstChunk) {
										charQueue = [];
										isFirstChunk = false;
									}
									charQueue.push(...jsonData.text.split(''));
								}
							} catch (e) {
								console.error("JSON Parse Error:", e, dataContent);
							}
						}
					});
					processStream();
				}).catch(error => { handleError(error); });
			}
			processStream();
		})
		.catch(error => { handleError(error); });
	});

	function addMessage(html, type) {
		const messageWrapper = document.createElement('div');
		messageWrapper.className = `feas-ai-message feas-ai-message-${type}`;
		messageWrapper.innerHTML = html;
		messagesContainer.appendChild(messageWrapper);
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
		return messageWrapper;
	}

	// function logConversation(question, answer, contextFound) {
	// 	fetch(feas_ai_ajax_obj.ajax_url, {
	// 		method: 'POST',
	// 		body: new URLSearchParams({
	// 			action: 'feas_ai_log_query',
	// 			nonce: feas_ai_ajax_obj.nonce,
	// 			session_id: sessionId,
	// 			question: question,
	// 			answer: answer,
	// 			context_found: contextFound ? '1' : '0',
	// 		}),
	// 	});
	// }

	/**
	 * Logs the conversation to the database via AJAX and returns the new log ID.
	 * @param {string} question - The user's question.
	 * @param {string} answer - The AI's full answer.
	 * @param {boolean} contextFound - Whether context was found.
	 * @returns {Promise<number|null>} The ID of the newly created log entry.
	 */
	async function logConversation(question, answer, contextFound) {
		if (!feas_ai_ajax_obj.is_license_active) return null;

		try {
			const response = await wpPost('feas_ai_log_query', {
				nonce: feas_ai_ajax_obj.nonce,
				session_id: sessionId,
				question: question,
				answer: answer,
				context_found: contextFound ? '1' : '0',
			});

			if (response.success && response.data.log_id) {
				return response.data.log_id;
			}
		} catch (error) {
			console.error('Error logging conversation:', error);
		}
		return null;
	}

	function renderQueue() {
		if (currentAiMessageElement && currentAiMessageElement.querySelector('.feas-ai-spinner') && charQueue.length > 0) {
			currentAiMessageElement.innerHTML = '';
		}
		if (charQueue.length === 0) return;

		const charsToRender = charQueue.splice(0, feas_ai_ajax_obj.animation_speed || 3).join('');

		fullResponse += charsToRender;

		currentAiMessageElement.innerHTML = marked.parse(fullResponse);
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
	}

	function startRenderingQueue() {
		clearInterval(renderInterval);
		renderInterval = setInterval(renderQueue, 25);
	}

	function waitForQueueToEmpty() {
		return new Promise(resolve => {
			const interval = setInterval(() => {
				if (charQueue.length === 0) {
					clearInterval(interval);
					resolve();
				}
			}, 50);
		});
	}

	function disableForm() {
		input.disabled = true;
		form.querySelector('button').disabled = true;
	}

	function enableForm() {
		input.disabled = false;
		form.querySelector('button').disabled = false;
		input.focus();
	}

	function handleError(error) {
		clearInterval(renderInterval);
		if (currentAiMessageElement) {
			currentAiMessageElement.innerHTML = '<p>通信エラーが発生しました。</p>';
		}
		console.error('Chat Error:', error);
		enableForm();
	}

	/**
	 * Handles clicks on the feedback (good/bad) buttons using event delegation.
	 */
	messagesContainer.addEventListener('click', function(e) {
		// ★★★ BUG FIX: Use .closest() to find the button, even if the SVG icon inside it was clicked. ★★★
		const button = e.target.closest('.feedback-btn');

		// If a feedback button (or something inside it) was clicked...
		if (button) {
			const logId = button.dataset.logId;
			const rating = button.dataset.rating;
			const feedbackWrapper = button.parentElement;

			if (!logId || !rating) return;

			// Send the rating to the server.
			wpPost('feas_ai_rate_answer', {
				nonce: feas_ai_ajax_obj.nonce,
				log_id: logId,
				rating: rating,
			});

			// Provide visual feedback.
			feedbackWrapper.querySelectorAll('.feedback-btn').forEach(btn => {
				btn.disabled = true;
				if (btn !== button) {
					btn.style.opacity = '0.5';
				}
			});

			// Create and insert the "Thank you" message.
			const thanksMessage = document.createElement('span');
			thanksMessage.className = 'feas-ai-feedback-thanks';
			thanksMessage.textContent = 'Thank you for your feedback!';
			feedbackWrapper.replaceWith(thanksMessage); // Replace the buttons with the message.
		}
	});
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initFEAIChat);
} else {
	// 既にDOMが構築済みの場合（wp_footerで呼ばれるなど）
	initFEAIChat();
}
