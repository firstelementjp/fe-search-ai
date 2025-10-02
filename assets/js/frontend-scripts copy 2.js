
const { __ } = wp.i18n;

document.addEventListener('DOMContentLoaded', function() {
	const container = document.getElementById('feas-ai-chat-container');
	if (!container) return;

	const bubble = document.getElementById('feas-ai-chat-bubble');
	const windowEl = document.getElementById('feas-ai-chat-window');
	const closeBtn = document.getElementById('feas-ai-chat-close');
	const form = document.getElementById('feas-ai-chat-form');
	const input = document.getElementById('feas-ai-chat-input');
	const messagesContainer = document.getElementById('feas-ai-chat-messages');

	if (!bubble || !form) return;

	let sessionId = sessionStorage.getItem('feas_ai_session_id');
	if (!sessionId) {
		sessionId = Date.now().toString(36) + Math.random().toString(36).substr(2);
		sessionStorage.setItem('feas_ai_session_id', sessionId);
	}

	let charQueue = [];
	let renderInterval;
	let currentAiMessageElement = null;

	bubble.addEventListener('click', () => {
		windowEl.classList.remove('hidden');
		bubble.classList.add('hidden');
	});

	closeBtn.addEventListener('click', () => {
		windowEl.classList.add('hidden');
		bubble.classList.remove('hidden');
	});

	form.addEventListener('submit', function(e) {
		e.preventDefault();
		const question = input.value.trim();
		if (!question) return;

		let history = JSON.parse(sessionStorage.getItem('feas_ai_chat_history')) || [];
		addMessage(`<p>${question}</p>`, 'user');
		history.push({ role: 'user', content: question });

		const recentHistory = history.slice(-10);
		input.value = '';
		disableForm();

		const aiMessageWrapper = addMessage('<p><span class="feas-ai-spinner"></span></p>', 'ai');
		let fullResponse = '';
		let contextFound = false;
		let isFirstChunk = true;
		// let currentProvider = 'openai';

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
						aiMessageWrapper.querySelector('.cursor')?.remove();
						aiMessageWrapper.innerHTML = marked.parse(fullResponse);
						history.push({ role: 'assistant', content: fullResponse });
						sessionStorage.setItem('feas_ai_chat_history', JSON.stringify(history));
						logConversation(question, fullResponse, contextFound);
						enableForm();
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
								if (jsonData.meta) {
									contextFound = jsonData.meta.context_found;
									// currentProvider = jsonData.meta.provider; // ★ PHPからプロバイダー名を受け取る
								}
								if (jsonData.text) {
									if (isFirstChunk) {
										aiMessageWrapper.innerHTML = ''; // スピナーを消す
										isFirstChunk = false;
									}
									fullResponse += jsonData.text;
									// if (currentProvider === 'openai') {
										// OpenAIの場合：単純な改行置換で、暫定的に表示
										// aiMessageWrapper.innerHTML = fullResponse.replace(/\n/g, '<br>');
									// } else {
										// Claude, Geminiの場合：毎回Markdownとしてパース
										aiMessageWrapper.innerHTML = marked.parse(fullResponse);
									// }
									// messagesContainer.scrollTop = messagesContainer.scrollHeight;
								}
								// if (jsonData.meta && jsonData.meta.context_found) {
								// 	contextFound = true;
								// }
							} catch (e) {}
						}
					});
					messagesContainer.scrollTop = messagesContainer.scrollHeight;
					processStream();

				}).catch(error => {handleError(error);});
			}
			processStream();
		})
		.catch(error => {handleError(error);});
	});

	function addMessage(html, type) {
		const messageWrapper = document.createElement('div');
		messageWrapper.className = `feas-ai-message feas-ai-message-${type}`;
		messageWrapper.innerHTML = html;
		messagesContainer.appendChild(messageWrapper);
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
		return messageWrapper;
	}

	function logConversation(question, answer, contextFound) {
		fetch(feas_ai_ajax_obj.ajax_url, {
			method: 'POST',
			body: new URLSearchParams({
				action: 'feas_ai_log_query',
				nonce: feas_ai_ajax_obj.nonce,
				session_id: sessionId,
				question: question,
				answer: answer,
				context_found: contextFound ? '1' : '0',
			}),
		});
	}

	function renderQueue() {
		if (charQueue.length === 0) return;

		const char = charQueue.shift();
		currentAiMessageElement.innerHTML += (char === '\n' ? '<br>' : char);
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
			currentAiMessageElement.innerHTML = '<p>' + __( 'A communication error has occurred.', 'fe-ai-search' ) + '</p>';
		}
		console.error('Chat Error:', error);
		enableForm();
	}
});
