jQuery(document).ready(function($) {

	const startBtn = $('#feas-ai-start-sync');
	const progressContainer = $('#feas-ai-progress-container');
	const progressBar = $('#feas-ai-progress-bar');
	const statusDiv = $('#feas-ai-sync-status');
	const statusSpinner = statusDiv.find('.spin');
	const statusText = statusDiv.find('.status-text');

	const batch_size = 10; // PHP側の設定と合わせる

	startBtn.on('click', function(e) {
		e.preventDefault();
		if (!confirm('既存のインデックスはすべて削除され、再構築されます。よろしいですか？')) {
			return;
		}

		startBtn.prop('disabled', true);
		statusSpinner.show();
		statusText.text('同期の準備をしています...');
		progressContainer.show();
		progressBar.css('width', '0%').text('0%');

		// 1. 同期開始リクエスト
		$.post(ajaxurl, {
			action: 'feas_ai_start_sync',
			nonce: feas_ai_sync_obj.nonce
		})
		.done(function(response) {
			if (response.success) {
				const totalPages = response.data.total_pages;
				const totalPosts = response.data.total_posts;
				if (totalPages > 0) {
					processBatch(1, totalPages, totalPosts);
				} else {
					statusText.text('同期対象の投稿がありません。');
					startBtn.prop('disabled', false);
					statusSpinner.hide();
				}
			} else {
				statusText.html('<span style="color: red;">エラー: 同期の準備に失敗しました。</span>');
				startBtn.prop('disabled', false);
				statusSpinner.hide();
			}
		})
		.fail(function() {
			statusText.html('<span style="color: red;">エラー: サーバーとの通信に失敗しました。</span>');
			startBtn.prop('disabled', false);
			statusSpinner.hide();
		});
	});

	function processBatch(currentPage, totalPages, totalPosts) {
		// 処理済み投稿数に基づいて、より細かい進捗を計算
		const processedPosts = Math.min((currentPage - 1) * batch_size, totalPosts);
		const progress = totalPosts > 0 ? Math.round((processedPosts / totalPosts) * 100) : 0;

		progressBar.css('width', progress + '%').text(progress + '%');
		statusText.text(`投稿を処理中... (${processedPosts} / ${totalPosts})`);
		statusSpinner.show();

		// 2. バッチ処理リクエスト
		$.post(ajaxurl, {
			action: 'feas_ai_process_batch',
			nonce: feas_ai_sync_obj.nonce,
			page: currentPage
		})
		.done(function(response) {
			if (response.success) {
				if (currentPage < totalPages) {
					processBatch(currentPage + 1, totalPages, totalPosts);
				} else {
					// 全バッチ完了
					progressBar.css('width', '100%').text('100%');
					statusText.html(`<strong style="color: green;">同期が完了しました！(${totalPosts}件)</strong>`);
					startBtn.prop('disabled', false);
					statusSpinner.hide();

					// ▼▼▼ タイムスタンプ更新のリクエストを追加 ▼▼▼
					$.post(ajaxurl, { action: 'feas_ai_update_sync_timestamp', nonce: feas_ai_sync_obj.nonce });
				}
			} else {
				statusText.html(`<span style="color: red;">エラー: バッチ ${currentPage} の処理中に問題が発生しました。</span>`);
				startBtn.prop('disabled', false);
				statusSpinner.hide();
			}
		})
		.fail(function() {
			statusText.html(`<span style="color: red;">エラー: バッチ ${currentPage} の処理中にサーバーとの通信に失敗しました。</span>`);
			startBtn.prop('disabled', false);
			statusSpinner.hide();
		});
	}

	$('.feas-ai-test-api').on('click', function() {
		const $button = $(this);
		const provider = $button.data('provider');
		const $input = $('#feas_ai_' + provider + '_api_key');
		const apiKey = $input.val();
		const $spinner = $button.siblings('.spinner');
		const $status = $button.siblings('.feas-ai-api-status');

		if (!apiKey) {
			alert('APIキーを入力してください。');
			return;
		}

		$spinner.css('visibility', 'visible');
		$status.text('');

		$.post(ajaxurl, {
			action: 'feas_ai_test_api_key',
			nonce: feas_ai_sync_obj.nonce,
			provider: provider,
			api_key: apiKey
		})
		.done(function(response) {
			if (response.success) {
				$status.html(response.data);
			} else {
				$status.html(response.data);
			}
		})
		.fail(function() {
			$status.html('<span style="color: red;">✖ 通信エラーが発生しました。</span>');
		})
		.always(function() {
			$spinner.css('visibility', 'hidden');
		});
	});

});
