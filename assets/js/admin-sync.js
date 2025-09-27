jQuery(document).ready(function($) {

	const startBtn = $('#feas-ai-start-sync');
	const progressBarContainer = $('#feas-ai-progress-bar-container');
	const progressBar = $('#feas-ai-progress-bar');
	const statusDiv = $('#feas-ai-sync-status');

	startBtn.on('click', function(e) {

		e.preventDefault();

		if (!confirm('既存のインデックスはすべて削除され、再構築されます。よろしいですか？')) {
			return;
		}

		startBtn.prop('disabled', true);
		statusDiv.html('同期の準備をしています...');
		progressBarContainer.show();
		progressBar.css('width', '0%').text('0%');

		// 1. 同期開始リクエスト
		$.post(ajaxurl, {
			action: 'feas_ai_start_sync',
			nonce: feas_ai_sync_obj.nonce // Mainクラスで定義したnonceを利用
		})
		.done(function(response) {
			if (response.success) {
				const totalPages = response.data.total_pages;
				if (totalPages > 0) {
					processBatch(1, totalPages);
				} else {
					statusDiv.html('同期対象の投稿がありません。');
					startBtn.prop('disabled', false);
				}
			} else {
				statusDiv.html('<span style="color: red;">エラー: 同期の準備に失敗しました。</span>');
				startBtn.prop('disabled', false);
			}
		})
		.fail(function() {
			statusDiv.html('<span style="color: red;">エラー: サーバーとの通信に失敗しました。</span>');
			startBtn.prop('disabled', false);
		});
	});

	function processBatch(currentPage, totalPages) {
		const progress = Math.round((currentPage - 1) / totalPages * 100);
		progressBar.css('width', progress + '%').text(progress + '%');
		statusDiv.html(`処理中... ${currentPage} / ${totalPages} バッチ`);

		// 2. バッチ処理リクエスト
		$.post(ajaxurl, {
			action: 'feas_ai_process_batch',
			nonce: feas_ai_sync_obj.nonce,
			page: currentPage
		})
		.done(function(response) {
			if (response.success) {
				if (currentPage < totalPages) {
					processBatch(currentPage + 1, totalPages);
				} else {
					// 全バッチ完了
					progressBar.css('width', '100%').text('100%');
					statusDiv.html('<strong style="color: green;">同期が完了しました！</strong>');
					startBtn.prop('disabled', false);
				}
			} else {
				statusDiv.html(`<span style="color: red;">エラー: バッチ ${currentPage} の処理中に問題が発生しました。</span>`);
				startBtn.prop('disabled', false);
			}
		})
		.fail(function() {
			statusDiv.html(`<span style="color: red;">エラー: バッチ ${currentPage} の処理中にサーバーとの通信に失敗しました。</span>`);
			startBtn.prop('disabled', false);
		});
	}
});
