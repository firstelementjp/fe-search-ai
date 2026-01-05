/**
 * FE Search AI Frontend Scripts
 *
 * This file handles all the JavaScript functionality for the public-facing
 * chat UI, including sending messages, streaming responses, handling
 * session history, and managing user interaction events.
 *
 * @package
 * @since      1.0.0
 */

/* global fe_search_ai_ajax_obj, marked */

// Initialize WordPress internationalization functions.
// Fallback to identity translation to prevent the whole frontend UI from breaking
// if wp.i18n is not available due to load order or caching issues.
const __ = window.wp?.i18n?.__ || (text => text);

/**
 * Configuration constants for FE Search AI
 */
const FE_SEARCH_AI_CONFIG = {
	// Animation speed settings (1-10 scale)
	ANIMATION: {
		MIN_SPEED: 1,
		MAX_SPEED: 10,
		DEFAULT_SPEED: 3,
		INTERVALS: {
			1: 160, // Very slow
			2: 110, // Slow
			3: 70, // Normal
		},
		FAST_END_INTERVAL: 25, // Speed 9 equivalent
		IMMEDIATE_INTERVAL: 20, // Speed 10 fallback
		IMMEDIATE_CHARS: 10000, // Speed 10 character count
		MIN_CHARS_FAST: 2, // Minimum chars at speed 4
		MAX_CHARS_FAST: 15, // Maximum chars at speed 9
	},

	// Chat settings
	CHAT: {
		HISTORY_LIMIT: 10, // Number of messages to include in history
		FEEDBACK_RATINGS: {
			GOOD: '1',
			BAD: '-1',
		},
		HTTP_STATUS: {
			RATE_LIMIT: 429,
		},
	},

	// Storage keys
	STORAGE: {
		SESSION_ID: 'fe_search_ai_session_id',
		CHAT_HISTORY: 'fe_search_ai_chat_history',
		SEND_MODE: 'fe_search_ai_send_mode',
		USER_CONSENT: 'fe_search_ai_user_consented',
		SESSION_LOGS: 'fe_search_ai_session_logs',
	},

	// UI update intervals
	INTERVALS: {
		QUEUE_CHECK: 50, // ms to check if queue is empty
	},

	// Send mode options
	SEND_MODES: ['enter', 'shift_enter', 'cmd_enter'],

	// Stream parsing
	STREAM: {
		DATA_PREFIX_LENGTH: 6, // Length of "data: " prefix
	},

	// Error handling
	ERRORS: {
		TYPES: {
			NETWORK: 'network_error',
			RATE_LIMIT: 'rate_limit',
			STORAGE: 'storage_error',
			PARSE: 'parse_error',
			LOGGING: 'logging_error',
			UNKNOWN: 'unknown_error',
		},
		MESSAGES: {
			network_error: __(
				'Network error occurred. Please check your connection.',
				'fe-search-ai'
			),
			rate_limit: __('Rate limit exceeded. Please try again later.', 'fe-search-ai'),
			storage_error: __(
				'Storage error occurred. Some features may not work properly.',
				'fe-search-ai'
			),
			parse_error: __('Data parsing error occurred. Please try again.', 'fe-search-ai'),
			unknown_error: __('An error occurred. Please try again.', 'fe-search-ai'),
		},
	},
};

/**
 * A simple wrapper for WordPress AJAX calls using the Fetch API.
 * @param {string} action - The wp_ajax_{action} hook to target.
 * @param {Object} data   - Additional data to send in the request body.
 * @return {Promise<object>} - A promise that resolves to the JSON response.
 */
async function wpPost(action, data = {}) {
	const formData = new URLSearchParams({ action, ...data });
	const response = await fetch(fe_search_ai_ajax_obj.ajax_url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: formData,
	});
	return response.json();
}

/**
 * Handles errors in a consistent way across the application.
 *
 * @param {Error|string} error       - The error object or error type string.
 * @param {string}       context     - Context where the error occurred.
 * @param {boolean}      showMessage - Whether to show error message to user.
 */
function handleError(error, context = 'unknown', showMessage = true) {
	let errorType = FE_SEARCH_AI_CONFIG.ERRORS.TYPES.UNKNOWN;
	let errorMessage = FE_SEARCH_AI_CONFIG.ERRORS.MESSAGES.unknown_error;

	// Log error for debugging (only in development or if debug mode is enabled)
	if (typeof fe_search_ai_ajax_obj !== 'undefined' && fe_search_ai_ajax_obj.debug) {
		console.error(`[FE Search AI] Error in ${context}:`, error);
	}

	// Determine error type and message
	if (typeof error === 'string') {
		errorType = error;
		errorMessage = FE_SEARCH_AI_CONFIG.ERRORS.MESSAGES[error] || errorMessage;
	} else if (error instanceof Error) {
		// Categorize error based on message or type
		if (error.message.includes('fetch') || error.message.includes('network')) {
			errorType = FE_SEARCH_AI_CONFIG.ERRORS.TYPES.NETWORK;
			errorMessage = FE_SEARCH_AI_CONFIG.ERRORS.MESSAGES.network_error;
		} else if (error.message.includes('rate_limit') || error.message.includes('429')) {
			errorType = FE_SEARCH_AI_CONFIG.ERRORS.TYPES.RATE_LIMIT;
			errorMessage = FE_SEARCH_AI_CONFIG.ERRORS.MESSAGES.rate_limit;
		} else if (error.message.includes('storage') || error.message.includes('localStorage')) {
			errorType = FE_SEARCH_AI_CONFIG.ERRORS.TYPES.STORAGE;
			errorMessage = FE_SEARCH_AI_CONFIG.ERRORS.MESSAGES.storage_error;
		} else if (error.message.includes('parse') || error.message.includes('JSON')) {
			errorType = FE_SEARCH_AI_CONFIG.ERRORS.TYPES.PARSE;
			errorMessage = FE_SEARCH_AI_CONFIG.ERRORS.MESSAGES.parse_error;
		}
	}

	// Show error message to user if requested and addMessage function is available
	if (showMessage && typeof window.addMessage === 'function') {
		window.addMessage(
			`<p><strong>${__('Error', 'fe-search-ai')}:</strong> ${errorMessage}</p>`,
			'system'
		);
	}

	return { errorType, errorMessage };
}

/**
 * Safely executes a function with error handling.
 *
 * @param {Function} fn       - Function to execute.
 * @param {string}   context  - Context for error logging.
 * @param {*}        fallback - Fallback value if function fails.
 * @return {*} Result of function or fallback value.
 */
function safeExecute(fn, context = 'unknown', fallback = null) {
	try {
		return fn();
	} catch (error) {
		handleError(error, context, false); // Don't show message for internal operations
		return fallback;
	}
}

/**
 * Safely executes an async function with error handling.
 *
 * @param {Function} fn       - Async function to execute.
 * @param {string}   context  - Context for error logging.
 * @param {*}        fallback - Fallback value if function fails.
 * @return {Promise<*>} Result of function or fallback value.
 */
async function safeExecuteAsync(fn, context = 'unknown', fallback = null) {
	try {
		return await fn();
	} catch (error) {
		handleError(error, context, false); // Don't show message for internal operations
		return fallback;
	}
}

/**
 * Retrieves and validates all required DOM elements for the chat interface.
 *
 * @return {Object|null} Object containing DOM elements or null if essential elements are missing.
 */
function getChatDOMElements() {
	// Essential elements - if any are missing, we cannot initialize
	const bubble = document.getElementById('fe_search_ai_chat_bubble');
	const chatWindowElement = document.getElementById('fe_search_ai_chat_window');
	const form = document.getElementById('fe_search_ai_chat_form');
	const input = document.getElementById('fe_search_ai_chat_input');
	const messagesContainer = document.getElementById('fe_search_ai_chat_messages');
	const container = document.getElementById('fe_search_ai_chat_container');

	if (!bubble || !form || !input || !messagesContainer || !container) {
		return null;
	}

	// Additional elements that are only needed if basic elements exist
	const closeBtn = document.getElementById('fe_search_ai_chat_close');
	const fullscreenBtn = document.getElementById('fe_search_ai_chat_fullscreen_toggle');
	const optionsToggle = document.getElementById('fe_search_ai_options_toggle');
	const optionsMenu = document.getElementById('fe_search_ai_options_menu');
	const shiftEnterToggle = document.getElementById('fe_search_ai_send_mode_toggle');

	return {
		bubble,
		chatWindowElement,
		form,
		input,
		messagesContainer,
		container,
		closeBtn,
		fullscreenBtn,
		optionsToggle,
		optionsMenu,
		shiftEnterToggle,
	};
}

/**
 * Initializes session management for the chat.
 *
 * @return {Object} Session management object containing sessionId and sessionHistory.
 */
function initializeSessionManagement() {
	let sessionId = safeExecute(
		() => sessionStorage.getItem(FE_SEARCH_AI_CONFIG.STORAGE.SESSION_ID),
		'initializeSessionManagement.get_session'
	);

	if (!sessionId) {
		sessionId = Date.now().toString(36) + Math.random().toString(36).substr(2);
		safeExecute(
			() => sessionStorage.setItem(FE_SEARCH_AI_CONFIG.STORAGE.SESSION_ID, sessionId),
			'initializeSessionManagement.set_session'
		);
	}

	const sessionHistory = safeExecute(
		() => JSON.parse(sessionStorage.getItem(FE_SEARCH_AI_CONFIG.STORAGE.CHAT_HISTORY)) || [],
		'initializeSessionManagement.parse_history',
		[]
	);

	return { sessionId, sessionHistory };
}

/**
 * Sets up UI event listeners for the chat interface.
 *
 * @param {Object}      elements                   - DOM elements object.
 * @param {HTMLElement} elements.bubble            - Chat bubble element.
 * @param {HTMLElement} elements.chatWindowElement - Chat window element.
 * @param {HTMLElement} elements.closeBtn          - Close button element.
 * @param {HTMLElement} elements.fullscreenBtn     - Fullscreen button element.
 * @param {HTMLElement} elements.optionsToggle     - Options toggle element.
 * @param {HTMLElement} elements.optionsMenu       - Options menu element.
 * @param {HTMLElement} elements.messagesContainer - Messages container element.
 * @param {HTMLElement} elements.container         - Main container element.
 * @param {Function}    ensureConsentUI            - Function to ensure consent UI is shown.
 */
function setupUIEventListeners(elements, ensureConsentUI) {
	const {
		bubble,
		chatWindowElement,
		messagesContainer,
		container,
		closeBtn,
		fullscreenBtn,
		optionsToggle,
		optionsMenu,
	} = elements;

	// Toggle chat window
	bubble.addEventListener('click', () => {
		chatWindowElement.classList.remove('hidden');
		bubble.classList.add('hidden');
		// Ensure the latest messages are visible when opening the window.
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
		ensureConsentUI();
	});

	// Close button event listener (if close button exists)
	if (closeBtn) {
		closeBtn.addEventListener('click', () => {
			chatWindowElement.classList.add('hidden');
			bubble.classList.remove('hidden');
		});
	}

	// Fullscreen button event listener (if fullscreen button exists)
	if (fullscreenBtn) {
		fullscreenBtn.addEventListener('click', () => {
			container.classList.toggle('is-fullscreen');
		});
	}

	// Options toggle event listener (if options elements exist)
	if (optionsToggle && optionsMenu) {
		optionsToggle.addEventListener('click', () => {
			optionsMenu.classList.toggle('hidden');
		});
	}
}

/**
 * Sets up keyboard event listener for chat input.
 *
 * @param {HTMLElement} input       - Chat input element.
 * @param {HTMLElement} form        - Chat form element.
 * @param {Function}    getSendMode - Function to get current send mode.
 */
function setupKeyboardEventListener(input, form, getSendMode) {
	/**
	 * Handles keyboard input ("Gatekeeper").
	 * Decides whether to submit the form or create a new line.
	 */
	input.addEventListener('keydown', event => {
		if (event.isComposing || event.key !== 'Enter') {
			return;
		}

		// Prevent default Enter action (form submission or newline).
		event.preventDefault();

		// Get current send mode dynamically
		const sendMode = getSendMode();

		// --- Cmd/Ctrl+Enter mode ---
		if (sendMode === 'cmd_enter') {
			const modifierPressed = event.metaKey || event.ctrlKey;
			if (modifierPressed) {
				// Programmatically trigger the 'submit' event.
				form.dispatchEvent(new Event('submit', { cancelable: true }));
			} else {
				// Cmd/Ctrl+Enter required but only Enter pressed -> newline.
				input.value += '\n';
			}
			return;
		}

		// --- Shift+Enter and Enter modes ---
		const shiftPressed = event.shiftKey;
		const modifierPressed = event.metaKey || event.ctrlKey;
		const shouldSubmit =
			// Shift+Enter mode: submit only on Shift+Enter (no Cmd/Ctrl).
			(sendMode === 'shift_enter' && shiftPressed && !modifierPressed) ||
			// Enter mode: submit only on bare Enter (no Shift, no Cmd/Ctrl).
			(sendMode === 'enter' && !shiftPressed && !modifierPressed);

		if (shouldSubmit) {
			// Programmatically trigger the 'submit' event.
			form.dispatchEvent(new Event('submit', { cancelable: true }));
		} else if (sendMode === 'shift_enter' && !shiftPressed) {
			// Shift+Enter required but only Enter pressed -> newline.
			input.value += '\n';
		}
	});
}

/**
 * Sets up send mode event listener.
 *
 * @param {HTMLElement} shiftEnterToggle - Send mode toggle element.
 * @param {Function}    setSendMode      - Function to update send mode.
 */
function setupSendModeEventListener(shiftEnterToggle, setSendMode) {
	shiftEnterToggle.addEventListener('change', () => {
		let nextMode = shiftEnterToggle.value;
		if (!FE_SEARCH_AI_CONFIG.SEND_MODES.includes(nextMode)) {
			nextMode = 'enter';
		}
		setSendMode(nextMode);
		localStorage.setItem(FE_SEARCH_AI_CONFIG.STORAGE.SEND_MODE, nextMode);
	});
}

/**
 * Initializes send mode settings.
 *
 * @param {HTMLElement} shiftEnterToggle - The send mode toggle element.
 * @return {string} The configured send mode.
 */
function initializeSendModeSettings(shiftEnterToggle) {
	const storedSendMode = localStorage.getItem(FE_SEARCH_AI_CONFIG.STORAGE.SEND_MODE);
	let sendMode = storedSendMode || fe_search_ai_ajax_obj.send_mode || 'enter';

	if (!FE_SEARCH_AI_CONFIG.SEND_MODES.includes(sendMode)) {
		sendMode = 'enter';
	}

	// Reflect initial state in the dropdown
	if (shiftEnterToggle) {
		shiftEnterToggle.value = sendMode;
	}

	return sendMode;
}

/**
 * Initializes the FE Search AI chat interface.
 *
 * This function sets up the entire chat UI including:
 * - DOM element caching and validation
 * - Session management (ID generation and history)
 * - Event listeners for user interactions
 * - Privacy consent handling
 * - Form submission and streaming response handling
 *
 * @return {void}
 */
function initFEAIChat() {
	// DOM Element Caching
	const domElements = getChatDOMElements();
	if (!domElements) {
		return; // Essential DOM elements are missing
	}

	const {
		bubble,
		chatWindowElement,
		form,
		input,
		messagesContainer,
		container,
		shiftEnterToggle,
	} = domElements;

	// Prevent duplicate initialization
	if (container.dataset.initialized) {
		return; // Already initialized.
	}
	container.dataset.initialized = 'true';

	// Handle fullscreen mode on load
	if (container.classList.contains('is-fullscreen')) {
		chatWindowElement.classList.remove('hidden');
		bubble.classList.add('hidden');
	}

	// Session Management
	const { sessionId, sessionHistory } = initializeSessionManagement();

	// State Variables
	let characterQueue = [];
	let renderInterval;
	let currentAiMessageElement = null;
	let fullResponse = '';
	// Typing animation configuration, derived from PHP setting animation_speed (1-10).
	let typingCharsPerTick = 3;
	let typingInterval = 50;
	let typingImmediate = false; // true の場合、チャンク到着時に即レンダリングを試みる
	const privacyConfig = fe_search_ai_ajax_obj.privacy || {};
	const consentStorageKey = FE_SEARCH_AI_CONFIG.STORAGE.USER_CONSENT;

	/**
	 * Check if the user has already given consent.
	 *
	 * @return {boolean} True when consent is not required or already granted.
	 */
	function hasUserConsented() {
		if (!privacyConfig.enable_consent) {
			return true;
		}
		const stored = safeExecute(
			() => localStorage.getItem(consentStorageKey),
			'hasUserConsented.get',
			''
		);
		return stored === '1' || stored === 'true';
	}

	/**
	 * Ensure consent UI guidance is shown.
	 *
	 * This is a minimal fallback to avoid breaking the UI when the Pro consent
	 * overlay is not present.
	 *
	 * @return {void}
	 */
	function ensureConsentUI() {
		if (!privacyConfig.enable_consent || hasUserConsented()) {
			return;
		}
		if (typeof window.addMessage === 'function') {
			window.addMessage(
				`<p><strong>${__('Notice', 'fe-search-ai')}:</strong> ${__(
					'Please agree to the Terms of Service and Privacy Policy to start the chat.',
					'fe-search-ai'
				)}</p>`,
				'system'
			);
		}
	}

	// Initialize Settings
	const sendMode = initializeSendModeSettings(shiftEnterToggle);

	// Event Listeners

	// Setup UI event listeners
	setupUIEventListeners(domElements, ensureConsentUI);

	// Setup send mode event listener with a setter function
	let currentSendMode = sendMode;
	const setSendMode = newMode => {
		currentSendMode = newMode;
	};
	setupSendModeEventListener(shiftEnterToggle, setSendMode);

	// Setup keyboard event listener with dynamic send mode
	setupKeyboardEventListener(input, form, () => currentSendMode);

	/**
	 * Handles the actual form submission ("Worker").
	 * This is triggered by the 'keydown' listener or the send button.
	 */
	form.addEventListener('submit', function (e) {
		e.preventDefault();

		// If privacy consent is required but not yet given, block submission.
		if (privacyConfig.enable_consent && !hasUserConsented()) {
			ensureConsentUI();
			return;
		}

		// Check session message limit
		const SESSION_LIMIT = fe_search_ai_ajax_obj.ip_limit_count;
		if (SESSION_LIMIT > 0 && sessionHistory.length > SESSION_LIMIT * 2) {
			addMessage(
				'<p>' +
					__(
						'You have reached the message limit for this session. Please refresh the page to start a new conversation.',
						'fe-search-ai'
					) +
					'</p>',
				'system'
			);
			return;
		}

		const question = input.value.trim();
		if (!question) return;

		// Update the session history variable
		addMessage(`<p>${question}</p>`, 'user');
		sessionHistory.push({ role: 'user', content: question });

		const recentHistory = sessionHistory.slice(-FE_SEARCH_AI_CONFIG.CHAT.HISTORY_LIMIT);
		input.value = '';
		input.style.height = 'auto'; // Reset height
		disableForm();

		const aiMessageWrapperForFeedback = addMessage(
			'<p><span class="fe-search-ai-spinner"></span></p>',
			'ai'
		);
		currentAiMessageElement = aiMessageWrapperForFeedback.querySelector('p');

		fullResponse = '';
		let contextFound = false;
		let isFirstChunk = true;
		let currentLogId = null;

		startRenderingQueue();

		fetch(fe_search_ai_ajax_obj.rest_url, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': fe_search_ai_ajax_obj.rest_nonce,
				'X-FE-AI-Session': sessionId,
			},
			body: new URLSearchParams({
				question,
				history: JSON.stringify(recentHistory),
			}),
		})
			.then(response => {
				if (!response.ok) {
					// Handle HTTP errors explicitly so the UI is not stuck.
					if (response.status === FE_SEARCH_AI_CONFIG.CHAT.HTTP_STATUS.RATE_LIMIT) {
						handleError(new Error('rate_limit'));
					} else {
						handleError(new Error('network_error'));
					}
					return;
				}
				const reader = response.body.getReader();
				const decoder = new TextDecoder();

				function processStream() {
					reader
						.read()
						.then(({ done, value }) => {
							if (done) {
								waitForQueueToEmpty().then(() => {
									clearInterval(renderInterval);
									// Replace the spinner wrapper with the final, parsed content
									aiMessageWrapperForFeedback.innerHTML =
										marked.parse(fullResponse);

									sessionHistory.push({
										role: 'assistant',
										content: fullResponse,
									});
									sessionStorage.setItem(
										FE_SEARCH_AI_CONFIG.STORAGE.CHAT_HISTORY,
										JSON.stringify(sessionHistory)
									);

									// Log conversation and get the log ID back
									logConversation(question, fullResponse, contextFound)
										.then(logId => {
											currentLogId = logId;
											if (currentLogId) {
												const feedbackWrapper =
													document.createElement('div');
												feedbackWrapper.className = 'fe-search-ai-feedback';
												feedbackWrapper.innerHTML = `
											<button class="feedback-btn good" data-log-id="${currentLogId}" data-rating="${FE_SEARCH_AI_CONFIG.CHAT.FEEDBACK_RATINGS.GOOD}" title="Good">
												<svg class="feedback-svg" xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24" width="20px" fill="currentColor"><path d="M0 0h24v24H0V0zm0 0h24v24H0V0z" fill="none"/><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>
											</button>
											<button class="feedback-btn bad" data-log-id="${currentLogId}" data-rating="${FE_SEARCH_AI_CONFIG.CHAT.FEEDBACK_RATINGS.BAD}" title="Bad">
												<svg class="feedback-svg" xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24" width="20px" fill="currentColor"><path d="M0 0h24v24H0V0zm0 0h24v24H0V0z" fill="none"/><path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z"/></svg>
											</button>
										`;
												aiMessageWrapperForFeedback.appendChild(
													feedbackWrapper
												);
											}
										})
										.catch(() => {
											// Swallow logging errors to avoid impacting the chat UI.
										});

									enableForm();
								});
								return;
							}

							const chunk = decoder.decode(value, { stream: true });
							const lines = chunk.split('\n\n');
							lines.forEach(line => {
								if (line.startsWith('data: ')) {
									const dataContent = line
										.substring(FE_SEARCH_AI_CONFIG.STREAM.DATA_PREFIX_LENGTH)
										.trim();
									if (dataContent === '[DONE]') return;
									try {
										const jsonData = JSON.parse(dataContent);
										if (jsonData.meta) {
											contextFound = jsonData.meta.context_found;
										}
										if (jsonData.text) {
											if (isFirstChunk) {
												characterQueue = [];
												isFirstChunk = false;
											}
											characterQueue.push(...jsonData.text.split(''));
										}
									} catch (parseError) {
										// Silently ignore malformed JSON chunks in the stream.
									}
								}
							});
							processStream();
						})
						.catch(error => {
							// Handle stream errors gracefully.
							handleStreamError(error);
						});
				}
				processStream();
			})
			.catch(error => {
				// Handle fetch errors gracefully.
				handleError(error, 'fetch_request');
			});
	});

	/**
	 * Handles clicks on the feedback (good/bad) buttons using event delegation.
	 */
	messagesContainer.addEventListener('click', function (event) {
		const button = event.target.closest('.feedback-btn');
		if (button) {
			const logId = button.dataset.logId;
			const rating = button.dataset.rating;
			const feedbackWrapper = button.parentElement;

			if (!logId || !rating) return;

			wpPost('fe_search_ai_rate_answer', {
				nonce: fe_search_ai_ajax_obj.nonce,
				log_id: logId,
				rating,
			});

			feedbackWrapper.querySelectorAll('.feedback-btn').forEach(btn => {
				btn.disabled = true;
				if (btn !== button) {
					btn.style.opacity = '0.5';
				}
			});
			const thanksMessage = document.createElement('span');
			thanksMessage.className = 'fe-search-ai-feedback-thanks';
			thanksMessage.textContent = __('Thank you for your feedback!', 'fe-search-ai');
			feedbackWrapper.replaceWith(thanksMessage);
		}
	});

	/* ========================================================================
	 * Helper Functions
	 * ========================================================================
	 */

	/**
	 * Adds a message to the chat UI.
	 * @param {string} html - The HTML content of the message.
	 * @param {string} type - 'user', 'ai', or 'system'.
	 * @return {HTMLElement} The new message wrapper element.
	 */
	function addMessage(html, type) {
		const messageWrapper = document.createElement('div');
		messageWrapper.className = `fe-search-ai-message fe-search-ai-message-${type}`;
		messageWrapper.innerHTML = html;
		messagesContainer.appendChild(messageWrapper);
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
		return messageWrapper;
	}

	/**
	 * Restores the session history from sessionStorage into the visible chat UI.
	 * Also restores feedback buttons for assistant messages based on server logs.
	 *
	 * @return {void}
	 */
	async function restoreSessionHistory() {
		if (!Array.isArray(sessionHistory) || sessionHistory.length === 0) {
			return;
		}

		// Get session logs from server to check rating status
		const sessionLogs = await getSessionLogs();
		const logMap = new Map();
		sessionLogs.forEach(log => {
			logMap.set(log.answer, {
				logId: log.id,
				rating: log.rating,
			});
		});

		sessionHistory.forEach(entry => {
			if (!entry || typeof entry.content !== 'string') {
				return;
			}

			if (entry.role === 'user') {
				addMessage(`<p>${entry.content}</p>`, 'user');
			} else if (entry.role === 'assistant') {
				// Assistant messages are stored as plain markdown text.
				const messageElement = addMessage(marked.parse(entry.content), 'ai');

				// Check if this message has a log entry and restore feedback UI
				const logInfo = logMap.get(entry.content);
				if (logInfo && logInfo.logId) {
					restoreFeedbackUI(messageElement, logInfo.logId, logInfo.rating);
				}
			} else if (entry.role === 'system') {
				addMessage(`<p>${entry.content}</p>`, 'system');
			}
		});
	}

	/**
	 * Restores feedback UI for a message based on rating status.
	 * @param {HTMLElement} messageElement - The message element to add feedback to.
	 * @param {number}      logId          - The log ID for the feedback.
	 * @param {string|null} rating         - The rating status ('1', '-1', '0', or null).
	 */
	function restoreFeedbackUI(messageElement, logId, rating) {
		// Only show thanks message if rating is explicitly set (1 or -1)
		if (rating === '1' || rating === '-1') {
			// Already rated - show thanks message
			const thanksMessage = document.createElement('span');
			thanksMessage.className = 'fe-search-ai-feedback-thanks';
			thanksMessage.textContent = __('Thank you for your feedback!', 'fe-search-ai');
			messageElement.appendChild(thanksMessage);
		} else {
			// Not rated yet (rating is null, 0, or undefined) - show feedback buttons
			const feedbackWrapper = document.createElement('div');
			feedbackWrapper.className = 'fe-search-ai-feedback';
			feedbackWrapper.innerHTML = `
				<button class="feedback-btn good" data-log-id="${logId}" data-rating="${FE_SEARCH_AI_CONFIG.CHAT.FEEDBACK_RATINGS.GOOD}" title="Good">
					<svg class="feedback-svg" xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24" width="20px" fill="currentColor"><path d="M0 0h24v24H0V0zm0 0h24v24H0V0z" fill="none"/><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>
				</button>
				<button class="feedback-btn bad" data-log-id="${logId}" data-rating="${FE_SEARCH_AI_CONFIG.CHAT.FEEDBACK_RATINGS.BAD}" title="Bad">
					<svg class="feedback-svg" xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24" width="20px" fill="currentColor"><path d="M0 0h24v24H0V0zm0 0h24v24H0V0z" fill="none"/><path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z"/></svg>
				</button>
			`;
			messageElement.appendChild(feedbackWrapper);
		}
	}

	/**
	 * Logs the conversation to the database via AJAX.
	 * @param {string}  question     - The user's question.
	 * @param {string}  answer       - The AI's full answer.
	 * @param {boolean} contextFound - Whether context was found.
	 * @return {Promise<number|null>} The ID of the newly created log entry.
	 */
	async function logConversation(question, answer, contextFound) {
		const questionLength = typeof question === 'string' ? question.length : 0;

		return safeExecuteAsync(
			async () => {
				const response = await wpPost('fe_search_ai_log_query', {
					nonce: fe_search_ai_ajax_obj.nonce,
					session_id: sessionId,
					question: '',
					question_length: questionLength,
					answer,
					context_found: contextFound ? '1' : '0',
				});

				if (response.success && response.data && response.data.log_id) {
					// Save log_id to sessionStorage for feedback restoration
					saveLogIdToSession(response.data.log_id, question, answer);
					return response.data.log_id;
				}
				return null;
			},
			'logConversation',
			null
		);
	}

	/**
	 * Saves log_id and conversation data to sessionStorage for feedback restoration.
	 * @param {number} logId    - The log ID from the server.
	 * @param {string} question - The user's question.
	 * @param {string} answer   - The AI's answer.
	 */
	function saveLogIdToSession(logId, question, answer) {
		safeExecute(() => {
			const sessionLogs =
				JSON.parse(sessionStorage.getItem(FE_SEARCH_AI_CONFIG.STORAGE.SESSION_LOGS)) || [];
			sessionLogs.push({
				logId,
				question,
				answer,
				timestamp: Date.now(),
			});
			sessionStorage.setItem(
				FE_SEARCH_AI_CONFIG.STORAGE.SESSION_LOGS,
				JSON.stringify(sessionLogs)
			);
		}, 'saveLogIdToSession');
	}

	/**
	 * Retrieves session logs from the server for feedback restoration.
	 * @return {Promise<Array>} Array of log objects with rating information.
	 */
	async function getSessionLogs() {
		return safeExecuteAsync(
			async () => {
				const response = await wpPost('fe_search_ai_get_session_logs', {
					nonce: fe_search_ai_ajax_obj.nonce,
					session_id: sessionId,
				});

				if (response.success && response.data && response.data.logs) {
					return response.data.logs;
				}
				return [];
			},
			'getSessionLogs',
			[]
		);
	}

	/**
	 * Configure typing animation speed based on the PHP setting.
	 *
	 * Map the animation_speed (1–10) value to both
	 * the number of characters per tick and the tick interval
	 * so that:
	 * - 1 feels very slow, typing one character at a time
	 * - 10 feels almost instantaneous, outputting large chunks at once.
	 */
	function configureTypingSpeed() {
		let speed =
			fe_search_ai_ajax_obj.animation_speed || FE_SEARCH_AI_CONFIG.ANIMATION.DEFAULT_SPEED;
		if (typeof speed !== 'number') {
			speed = parseInt(speed, 10) || FE_SEARCH_AI_CONFIG.ANIMATION.DEFAULT_SPEED;
		}
		// Clamp to [1, 10]
		speed = Math.max(
			FE_SEARCH_AI_CONFIG.ANIMATION.MIN_SPEED,
			Math.min(FE_SEARCH_AI_CONFIG.ANIMATION.MAX_SPEED, speed)
		);

		// Default to the normal mode (rendering the queue in small chunks).
		typingImmediate = false;

		// --- Interval configuration ---
		// 1–3: Manually tuned for a natural feel (always 1 char, but different tempo)
		//  1 => ~160ms, 2 => ~110ms, 3 => ~70ms
		// 4–9: Linearly interpolate from the value at 3 to the fast value at 9 (~25ms)
		if (speed <= 3) {
			typingInterval = FE_SEARCH_AI_CONFIG.ANIMATION.INTERVALS[speed];
		} else if (speed < 10) {
			// Normalize speed 4..9 to 0..1 and interpolate 70ms -> 25ms.
			// Use an easing curve so it accelerates more in the higher range.
			const fastRatioLinear = (speed - 3) / 6; // 4..9 => 1/6..1
			const fastRatio = Math.pow(fastRatioLinear, 1.5); // 4,5,6 are slower, 7–9 accelerate quickly
			const startInterval = FE_SEARCH_AI_CONFIG.ANIMATION.INTERVALS[3]; // Equivalent to speed=3
			const endInterval = FE_SEARCH_AI_CONFIG.ANIMATION.FAST_END_INTERVAL; // Equivalent to speed=9
			typingInterval = Math.round(startInterval - (startInterval - endInterval) * fastRatio);
		} else {
			// speed=10: Handled as "immediate mode", but keep a minimum value as a fallback.
			typingInterval = FE_SEARCH_AI_CONFIG.ANIMATION.IMMEDIATE_INTERVAL;
		}

		// --- Characters-per-tick configuration ---
		// 1–3: Always 1 character per tick
		// 4–9: Linearly increase from 2 characters up to ~15 characters at speed 9
		// 10: Immediate mode (render the entire queue at once)
		if (speed === FE_SEARCH_AI_CONFIG.ANIMATION.MAX_SPEED) {
			typingImmediate = true;
			typingCharsPerTick = FE_SEARCH_AI_CONFIG.ANIMATION.IMMEDIATE_CHARS;
		} else if (speed <= 3) {
			typingCharsPerTick = 1;
		} else {
			// Normalize speed 4..9 to 0..1 and linearly increase from 2 to 15 characters.
			const charsRatio = (speed - 4) / 5; // 4..9 => 0..1
			const minChars = FE_SEARCH_AI_CONFIG.ANIMATION.MIN_CHARS_FAST; // About 2 characters at speed=4
			const maxChars = FE_SEARCH_AI_CONFIG.ANIMATION.MAX_CHARS_FAST;
			const rawChars = minChars + (maxChars - minChars) * charsRatio;
			typingCharsPerTick = Math.max(1, Math.round(rawChars));
		}
	}

	// Build the typing speed table from the settings once at initialization.
	configureTypingSpeed();

	// Restore previous session history into the chat UI so that
	// conversations persist across page navigations.
	restoreSessionHistory();

	/**
	 * Renders the typing animation queue.
	 */
	function renderQueue() {
		if (
			currentAiMessageElement &&
			currentAiMessageElement.querySelector('.fe-search-ai-spinner') &&
			characterQueue.length > 0
		) {
			currentAiMessageElement.innerHTML = '';
		}
		if (characterQueue.length === 0) return;

		// In immediate mode, render the entire queue at once.
		const takeCount = typingImmediate ? characterQueue.length : typingCharsPerTick;
		const charsToRender = characterQueue.splice(0, takeCount).join('');
		fullResponse += charsToRender;

		currentAiMessageElement.innerHTML = marked.parse(fullResponse);
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
	}

	/**
	 * Starts the rendering interval for the typing animation.
	 */
	function startRenderingQueue() {
		clearInterval(renderInterval);
		renderInterval = setInterval(renderQueue, typingInterval);
	}

	/**
	 * Waits for the character queue to be empty.
	 * @return {Promise<void>}
	 */
	function waitForQueueToEmpty() {
		return new Promise(resolve => {
			const interval = setInterval(() => {
				if (characterQueue.length === 0) {
					clearInterval(interval);
					resolve();
				}
			}, FE_SEARCH_AI_CONFIG.INTERVALS.QUEUE_CHECK);
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
	function handleStreamError(error) {
		clearInterval(renderInterval);
		if (currentAiMessageElement) {
			let message = __('An error occurred. Please try again.', 'fe-search-ai');
			if (error && error.message === 'rate_limit') {
				if (
					typeof fe_search_ai_ajax_obj !== 'undefined' &&
					fe_search_ai_ajax_obj.rate_limit_message
				) {
					message = fe_search_ai_ajax_obj.rate_limit_message;
				} else {
					message = __('Rate limit exceeded. Please try again later.', 'fe-search-ai');
				}
			}
			addMessage(`<p>${message}</p>`, 'system', currentAiMessageElement);
		}
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
