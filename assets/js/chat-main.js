document.addEventListener('DOMContentLoaded', function() {
	// === DOM要素の取得 ===
	const bubble = document.getElementById('feas-ai-chat-bubble');
	const windowEl = document.getElementById('feas-ai-chat-window');
	const closeBtn = document.getElementById('feas-ai-chat-close');
	const form = document.getElementById('feas-ai-chat-form');
	const input = document.getElementById('feas-ai-chat-input');
	const messagesContainer = document.getElementById('feas-ai-chat-messages');

	// === セッションIDの管理 ===
	let sessionId = sessionStorage.getItem('feas_ai_session_id');
	if (!sessionId) {
		sessionId = Date.now().toString(36) + Math.random().toString(36).substr(2);
		sessionStorage.setItem('feas_ai_session_id', sessionId);
	}

	// === イベントリスナーの設定 ===
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

		// ユーザーの質問メッセージを表示
		addMessage(`<p>${question}</p>`, 'user');
		history.push({ role: 'user', content: question });
		input.value = '';

		// AIの返信欄を、カーソル付きの初期状態で表示
		const aiMessageWrapper = addMessage('<p><span class="cursor"></span></p>', 'ai');

		const bodyParams = new URLSearchParams();
		bodyParams.append('question', question);
		bodyParams.append('history', JSON.stringify(history));

		let fullResponse = '';
		let contextFound = false;

		fetch(feas_ai_ajax_obj.rest_url, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': feas_ai_ajax_obj.rest_nonce
			},
			body: bodyParams,
		})
		.then(response => {
			if (!response.ok) {
				return response.text().then(text => {
					throw new Error('Network response was not ok. Server says: ' + text);
				});
			}
			const reader = response.body.getReader();
			const decoder = new TextDecoder();

			function processStream() {
				reader.read().then(({ done, value }) => {
					if (done) {
						// ストリーム完了後、ラッパーの中身全体を最終的なHTMLで置き換える
						aiMessageWrapper.innerHTML = marked.parse(fullResponse);
						history.push({ role: 'assistant', content: fullResponse });
						sessionStorage.setItem('feas_ai_chat_history', JSON.stringify(history));
						logConversation(question, fullResponse, contextFound);
						return;
					}

					const chunk = decoder.decode(value, {stream: true});
					const lines = chunk.split('\n\n');

					lines.forEach(line => {
						if (line.startsWith('data: ')) {
							const dataContent = line.substring(6).trim();
							if (dataContent === '[DONE]') { return; }
							try {
								const jsonData = JSON.parse(dataContent);
								if (jsonData.text) {
									fullResponse += jsonData.text;
									// ストリーミング中、ラッパーの中身全体を更新し続ける
									aiMessageWrapper.innerHTML = marked.parse(fullResponse + '<span class="cursor"></span>');
									messagesContainer.scrollTop = messagesContainer.scrollHeight;
								}
								if (jsonData.meta && jsonData.meta.context_found) {
									contextFound = true;
								}
							} catch (e) {}
						}
					});
					processStream();
				});
			}
			processStream();
		})
		.catch(error => {
			aiMessageWrapper.innerHTML = '<p>通信エラーが発生しました。</p>';
			console.error('Fetch error:', error);
		});
	});

	// === ヘルパー関数 ===
	function addMessage(html, type) {
		const messageWrapper = document.createElement('div');
		messageWrapper.className = `feas-ai-message feas-ai-message-${type}`;
		messageWrapper.innerHTML = html; // 受け取ったHTMLをそのまま設定

		messagesContainer.appendChild(messageWrapper);
		messagesContainer.scrollTop = messagesContainer.scrollHeight;
		return messageWrapper;
	}

	/**
	 * 会話のログをサーバーに送信する
	 */
	function logConversation(question, answer, contextFound) {
		const logBodyParams = new URLSearchParams();
		logBodyParams.append('action', 'feas_ai_log_query');
		logBodyParams.append('nonce', feas_ai_ajax_obj.nonce);
		logBodyParams.append('session_id', sessionId);
		logBodyParams.append('question', question);
		logBodyParams.append('answer', answer);
		logBodyParams.append('context_found', contextFound ? '1' : '0');

		fetch(feas_ai_ajax_obj.ajax_url, {
			method: 'POST',
			body: logBodyParams,
		});
	}
});
