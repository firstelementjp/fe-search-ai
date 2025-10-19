/**
 * FE AI Search Frontend Scripts
 *
 * This file handles all the JavaScript functionality for the public-facing
 * chat UI, including sending messages, streaming responses, handling
 * session history, and managing user interaction events.
 *
 * @package    fe-ai-search
 * @since      1.0.0
 */

// Initialize WordPress internationalization functions.
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

/**
 * Main function to initialize the chat interface.
 */
function initFEAIChat() {
	const container = document.getElementById('feas-ai-chat-container');
	if (!container || container.dataset.initialized) {
		return; // Already initialized or element not found.
	}
	container.dataset.initialized = 'true';

	// --- 1. DOM Element Caching ---
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

	if (!bubble || !form || !input || !messagesContainer) return;

	// Handle fullscreen mode on load
	if (container.classList.contains('is-fullscreen')) {
		windowEl.classList.remove('hidden');
		bubble.classList.add('hidden');
	}

	// --- 2. State Variables ---
	let sessionId = sessionStorage.getItem('feas_ai_session_id');
	if (!sessionId) {
		sessionId = Date.now().toString(36) + Math.random().toString(36).substr(2);
		sessionStorage.setItem('feas_ai_session_id', sessionId);
	}

	let sessionHistory = JSON.parse(sessionStorage.getItem('feas_ai_chat_history')) || [];
	let charQueue = [];
	let renderInterval;
	let currentAiMessageElement = null;
	let fullResponse = '';

	// --- 3. Initialize Settings (Shift+Enter) ---
	let userPrefersShiftEnter = localStorage.getItem('feas_ai_user_prefers_shift_enter');

	if (userPrefersShiftEnter === null) {
		// If no user preference, use the site's default setting
		userPrefersShiftEnter = feas_ai_ajax_obj.send_on_shift_enter;
	} else {
		// Convert stored string back to boolean
		userPrefersShiftEnter = userPrefersShiftEnter === 'true';
	}
	shiftEnterToggle.checked = userPrefersShiftEnter;

	// --- 4. Event Listeners ---

	// Toggle chat window
	bubble.addEventListener('click', () => {
		windowEl.classList.remove('hidden');
		bubble.classList.add('hidden');
	});

	closeBtn.addEventListener('click', () => {
		windowEl.classList.add('hidden');
		bubble.classList.remove('hidden');
	});

	// Toggle fullscreen mode
	fullscreenBtn.addEventListener('click', () => {
		container.classList.toggle('is-fullscreen');
	});

	// Toggle settings menu
	optionsToggle.addEventListener('click', () => {
		optionsMenu.classList.toggle('hidden');
	});

	// Handle 'Shift+Enter' setting change
	shiftEnterToggle.addEventListener('change', () => {
		userPrefersShiftEnter = shiftEnterToggle.checked;
		localStorage.setItem('feas_ai_user_prefers_shift_enter', userPrefersShiftEnter);
	});

	/**
	 * Handles keyboard input ("Gatekeeper").
	 * Decides whether to submit the form or create a new line.
	 */
	input.addEventListener('keydown', (e) => {
		if (e.isComposing || e.key !== 'Enter') {
			return;
		}

		// Prevent default Enter action (form submission or newline)
		e.preventDefault();

		const shiftPressed = e.shiftKey;
		const shouldSubmit = (userPrefersShiftEnter && shiftPressed) || (!userPrefersShiftEnter && !shiftPressed);

		if (shouldSubmit) {
			// Programmatically trigger the 'submit' event.
			form.dispatchEvent(new Event('submit', { cancelable: true }));
		}
		// If it's not a submit action (e.g., Enter alone when Shift+Enter is required),
		// we must manually add the newline since we prevented the default behavior.
		else if (useShiftEnter && !shiftPressed) {
			input.value += '\n';
		}
	});

	/**
	 * Handles the actual form submission ("Worker").
	 * This is triggered by the 'keydown' listener or the send button.
	 */
	form.addEventListener('submit', function(e) {
		e.preventDefault();

		// Check session message limit
		const SESSION_LIMIT = feas_ai_ajax_obj.ip_limit_count;
		if ( SESSION_LIMIT > 0 && sessionHistory.length > SESSION_LIMIT * 2 ) {
			addMessage('<p>' + __('You have reached the message limit for this session. Please refresh the page to start a new conversation.', 'fe-ai-search') + '</p>', 'system');
			return;
		}

		const question = input.value.trim();
		if (!question) return;

		// Update the session history variable
		const aiMessageWrapper = addMessage(`<p>${question}</p>`, 'user');
		sessionHistory.push({ role: 'user', content: question });

		const recentHistory = sessionHistory.slice(-10);
		input.value = '';
		input.style.height = 'auto'; // Reset height
		disableForm();

		const aiMessageWrapperForFeedback = addMessage('<p><span class="feas-ai-spinner"></span></p>', 'ai');
		currentAiMessageElement = aiMessageWrapperForFeedback.querySelector('p');

		fullResponse = '';
		let contextFound = false;
		let isFirstChunk = true;
		let currentLogId = null;

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
							// Replace the spinner wrapper with the final, parsed content
							aiMessageWrapperForFeedback.innerHTML = marked.parse(fullResponse);

							sessionHistory.push({ role: 'assistant', content: fullResponse });
							sessionStorage.setItem('feas_ai_chat_history', JSON.stringify(sessionHistory));

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
											<svg class="feedback-svg" xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24" width="20px" fill="currentColor"><path d="M0 0h24v24H0V0zm0 0h24v24H0V0z" fill="none"/><path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79-.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z"/></svg>
										</button>
									`;
									aiMessageWrapperForFeedback.appendChild(feedbackWrapper);
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

	/**
	 * Handles clicks on the feedback (good/bad) buttons using event delegation.
	 */
	messagesContainer.addEventListener('click', function(e) {
		const button = e.target.closest('.feedback-btn');
		if (button) {
			const logId = button.dataset.logId;
			const rating = button.dataset.rating;
			const feedbackWrapper = button.parentElement;

			if (!logId || !rating) return;

			wpPost('feas_ai_rate_answer', {
				nonce: feas_ai_ajax_obj.nonce,
				log_id: logId,
				rating: rating,
			});

			feedbackWrapper.querySelectorAll('.feedback-btn').forEach(btn => {
				btn.disabled = true;
				if (btn !== button) {
					btn.style.opacity = '0.5';
				}
			});
			const thanksMessage = document.createElement('span');
			thanksMessage.className = 'feas-ai-feedback-thanks';
			thanksMessage.textContent = 'Thank you for your feedback!';
			feedbackWrapper.replaceWith(thanksMessage);
		}
	});

	// --- 5. Helper Functions ---

	/**
	 * Adds a message to the chat UI.
	 * @param {string} html - The HTML content of the message.
	 * @param {string} type - 'user', 'ai', or 'system'.
	 * @returns {HTMLElement} The new message wrapper element.
	 */
	function addMessage(html, type) {
		const messageWrapper = document.createElement('div');
		messageWrapper.className = `feas-ai-message feas-ai-message-${type}`;
		messageWrapper.innerHTML = html;
		messagesContainer.appendChild(messageWrapper);
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
		return messageWrapper;
	}

	/**
	 * Logs the conversation to the database via AJAX.
	 * @param {string} question - The user's question.
	 * @param {string} answer - The AI's full answer.
	 * @param {boolean} contextFound - Whether context was found.
	 * @returns {Promise<number|null>} The ID of the newly created log entry.
	 */
	async function logConversation(question, answer, contextFound) {
		if (!feas_ai_ajax_obj.is_license_active) {
			return null;
		}

		try {
			const response = await wpPost('feas_ai_log_query', {
				nonce: feas_ai_ajax_obj.nonce,
				session_id: sessionId,
				question: question,
				answer: answer,
				context_found: contextFound ? '1' : '0',
			});

			if (response.success && response.data && response.data.log_id) {
				return response.data.log_id;
			}
		} catch (error) {
			console.error('Error logging conversation:', error);
		}
		return null;
	}

	/**
	 * Renders the typing animation queue.
	 */
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

	/**
	 * Starts the rendering interval for the typing animation.
	 */
	function startRenderingQueue() {
		clearInterval(renderInterval);
		renderInterval = setInterval(renderQueue, 25);
	}

	/**
	 * Waits for the character queue to be empty.
	 * @returns {Promise<void>}
	 */
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

	/**
	 * Disables the chat form.
	 */
	function disableForm() {
		input.disabled = true;
		form.querySelector('button').disabled = true;
	}

	/**
	 * Enables the chat form.
	 */
	function enableForm() {
		input.disabled = false;
		form.querySelector('button').disabled = false;
		input.focus();
	}

	/**
	 * Handles fetch or stream errors.
	 * @param {Error} error - The error object.
	 */
	function handleError(error) {
		clearInterval(renderInterval);
		if (currentAiMessageElement) {
			currentAiMessageElement.innerHTML = '<p>' + __('An error occurred. Please try again.', 'fe-ai-search') + '</p>';
		}
		console.error('Chat Error:', error);
		enableForm();
	}
}

// Auto-run the initializer.
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initFEAIChat);
} else {
	// DOM is already ready.
	initFEAIChat();
}
