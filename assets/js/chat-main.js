document.addEventListener('DOMContentLoaded', function() {
	// === DOM要素の取得 ===
	const bubble = document.getElementById('feas-ai-chat-bubble');
	const window = document.getElementById('feas-ai-chat-window');
	const closeBtn = document.getElementById('feas-ai-chat-close');
	const form = document.getElementById('feas-ai-chat-form');
	const input = document.getElementById('feas-ai-chat-input');
	const messagesContainer = document.getElementById('feas-ai-chat-messages');

	// === イベントリスナーの設定 ===

	// チャットバブルをクリックしたらウィンドウを開閉
	bubble.addEventListener('click', () => {
		window.classList.remove('hidden');
		bubble.classList.add('hidden');
	});

	// 閉じるボタンでウィンドウを隠す
	closeBtn.addEventListener('click', () => {
		window.classList.add('hidden');
		bubble.classList.remove('hidden');
	});

	// フォームが送信された時の処理
	form.addEventListener('submit', function(e) {
		e.preventDefault();
		const question = input.value.trim();
		if (!question) return;

		// 1. 会話履歴をsessionStorageから読み込む
		let history = JSON.parse(sessionStorage.getItem('feas_ai_chat_history')) || [];

		// 2. ユーザーの質問をチャット画面と履歴に追加
		addMessage(question, 'user');
		history.push({ role: 'user', content: question });
		input.value = '';

		// 3. AIの応答待機中のUIを作成
		const aiMessageWrapper = addMessage('', 'ai');
		const aiMessageParagraph = aiMessageWrapper.querySelector('p');
		aiMessageParagraph.innerHTML = '<span class="cursor"></span>';

		// 4. バックエンドへのリクエストを準備
		const streamUrl = new URL(feas_ai_ajax_obj.home_url);
		streamUrl.searchParams.append('feas_ai_stream', 'true');

		const formData = new FormData();
		formData.append('question', question);
		formData.append('nonce', feas_ai_ajax_obj.nonce);
		formData.append('history', JSON.stringify(history));

		let fullResponse = '';

		// 5. ストリーミング通信を開始
		fetch(streamUrl, {
			method: 'POST',
			body: formData,
		})
		.then(response => {
			if (!response.ok) {
				throw new Error('Network response was not ok');
			}
			const reader = response.body.getReader();
			const decoder = new TextDecoder();

			function processStream() {
				reader.read().then(({ done, value }) => {
					if (done) {
						aiMessageParagraph.innerHTML = marked.parse(fullResponse); // 最終レンダリング
						history.push({ role: 'assistant', content: fullResponse });
						sessionStorage.setItem('feas_ai_chat_history', JSON.stringify(history));
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
								if (jsonData.error) {
									fullResponse = 'エラー: ' + jsonData.error;
								}
							} catch (e) {}
						}
					});
					processStream(); // 次のデータを読み込む
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
});
