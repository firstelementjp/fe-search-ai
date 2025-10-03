
const { __ } = wp.i18n;

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

			currentAiMessageElement = addMessage('<p><span class="feas-ai-spinner"></span></p>', 'ai').querySelector('p');

			fullResponse = '';
			let contextFound = false;
			let isFirstChunk = true;

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
								currentAiMessageElement.innerHTML = marked.parse(fullResponse);
								history.push({ role: 'assistant', content: fullResponse });
								sessionStorage.setItem('feas_ai_chat_history', JSON.stringify(history));
								logConversation(question, fullResponse, contextFound);
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
			if (currentAiMessageElement && currentAiMessageElement.querySelector('.feas-ai-spinner') && charQueue.length > 0) {
				currentAiMessageElement.innerHTML = '';
			}
			if (charQueue.length === 0) return;

			const charsToRender = charQueue.splice(0, 10).join(''); // Render every 10 characters

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
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initFEAIChat);
} else {
	// 既にDOMが構築済みの場合（wp_footerで呼ばれるなど）
	initFEAIChat();
}
