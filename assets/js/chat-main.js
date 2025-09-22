document.addEventListener('DOMContentLoaded', function() {
	// === DOM要素の取得 ===
	const bubble = document.getElementById('feas-ai-chat-bubble');
	const windowEl = document.getElementById('feas-ai-chat-window'); // 'window'は予約語なので変更
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
		addMessage(question, 'user');
		history.push({ role: 'user', content: question });
		input.value = '';

		const aiMessageWrapper = addMessage('', 'ai');
		const aiMessageParagraph = aiMessageWrapper.querySelector('p');
		aiMessageParagraph.innerHTML = '<span class="cursor"></span>';

		// const streamUrl = new URL(feas_ai_ajax_obj.home_url);
		// streamUrl.searchParams.append('feas_ai_stream', 'true');

		const formData = new FormData();
		formData.append('question', question);
		formData.append('nonce', feas_ai_ajax_obj.nonce);
		formData.append('history', JSON.stringify(history));

		let fullResponse = '';
		let contextFound = false; // コンテキストが見つかったかのフラグ

		fetch(feas_ai_ajax_obj.rest_url, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': feas_ai_ajax_obj.rest_nonce // ヘッダーにNonceを設定
			},
			body: formData,
		})
		.then(response => {
			if (!response.ok) { throw new Error('Network response was not ok'); }
			const reader = response.body.getReader();
			const decoder = new TextDecoder();

			function processStream() {
				reader.read().then(({ done, value }) => {
					if (done) {
						aiMessageParagraph.innerHTML = marked.parse(fullResponse);
						history.push({ role: 'assistant', content: fullResponse });
						sessionStorage.setItem('feas_ai_chat_history', JSON.stringify(history));

						// ▼ ログ送信処理を呼び出し ▼
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
									aiMessageParagraph.innerHTML = marked.parse(fullResponse + '<span class="cursor"></span>');
									messagesContainer.scrollTop = messagesContainer.scrollHeight;
								}
								if (jsonData.error) { fullResponse = 'エラー: ' + jsonData.error; }
								// バックエンドから送られてくるメタデータを受け取る
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
			aiMessageParagraph.textContent = '通信エラーが発生しました。';
			console.error('Fetch error:', error);
		});
	});

	// === ヘルパー関数 ===

	/**
	 * メッセージをチャット欄に追加する
	 */
	function addMessage(text, type) {
		const messageWrapper = document.createElement('div');
		messageWrapper.className = `feas-ai-message feas-ai-message-${type}`;

		const p = document.createElement('p');
		p.innerHTML = text; // バックエンドからHTMLが来ることを想定

		messageWrapper.appendChild(p);
		messagesContainer.appendChild(messageWrapper);
		messagesContainer.scrollTop = messagesContainer.scrollHeight;

		return messageWrapper;
	}

	/**
	 * 会話のログをサーバーに送信する
	 */
	function logConversation(question, answer, contextFound) {
		const logFormData = new FormData();
		logFormData.append('action', 'feas_ai_log_query');
		logFormData.append('nonce', feas_ai_ajax_obj.nonce);
		logFormData.append('session_id', sessionId);
		logFormData.append('question', question);
		logFormData.append('answer', answer);
		logFormData.append('context_found', contextFound);

		fetch(feas_ai_ajax_obj.ajax_url, {
			method: 'POST',
			body: logFormData,
		});
	}
});
