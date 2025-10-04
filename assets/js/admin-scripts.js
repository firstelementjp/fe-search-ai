jQuery(document).ready(function($) {

	// --- DOM要素 ---
	const startBtn = $('#feas-ai-start-sync');
	const progressContainer = $('#feas-ai-progress-container');
	const progressBar = $('#feas-ai-progress-bar');
	const statusDiv = $('#feas-ai-sync-status');
	const statusSpinner = statusDiv.find('.spin');
	const statusText = statusDiv.find('.status-text');

	// --- 変数 ---
	let postIDsToSync = []; // ★ 投稿IDリストを保持する変数を定義

	// --- 同期開始ボタンの処理 ---
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

		$.post(ajaxurl, {
			action: 'feas_ai_start_sync',
			nonce: feas_ai_sync_obj.nonce
		})
		.done(function(response) {
			if (response.success) {
				const totalPages = response.data.total_pages;
				const totalPosts = response.data.total_posts;
				postIDsToSync = response.data.post_ids; // ★★★ IDリストをここで受け取り、保存する

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

	// --- バッチ処理関数 ---
	function processBatch(currentPage, totalPages, totalPosts) {
		const batch_size = 10;
		const processedPosts = Math.min((currentPage - 1) * batch_size, totalPosts);
		const progress = totalPosts > 0 ? Math.round((processedPosts / totalPosts) * 100) : 0;

		progressBar.css('width', progress + '%').text(progress + '%');
		statusText.text(`投稿を処理中... (${processedPosts} / ${totalPosts})`);
		statusSpinner.show();

		$.post(ajaxurl, {
			action: 'feas_ai_process_batch',
			nonce: feas_ai_sync_obj.nonce,
			page: currentPage,
			post_ids: JSON.stringify(postIDsToSync) // ★★★ 保存したIDリストをサーバーに送る
		})
		.done(function(response) {
			if (response.success && response.data.message.indexOf('No more posts') === -1) {
				if (currentPage < totalPages) {
					processBatch(currentPage + 1, totalPages, totalPosts);
				} else {
					progressBar.css('width', '100%').text('100%');
					statusText.html(`<strong style="color: green;">同期が完了しました！(${totalPosts}件)</strong>`);
					startBtn.prop('disabled', false);
					statusSpinner.hide();
					$.post(ajaxurl, { action: 'feas_ai_update_sync_timestamp', nonce: feas_ai_sync_obj.nonce });
				}
			} else {
				// 完了メッセージ（No more posts）が返ってきた場合も成功とみなす
				if (response.success) {
					progressBar.css('width', '100%').text('100%');
					statusText.html(`<strong style="color: green;">同期が完了しました！(${totalPosts}件)</strong>`);
					startBtn.prop('disabled', false);
					statusSpinner.hide();
					$.post(ajaxurl, { action: 'feas_ai_update_sync_timestamp', nonce: feas_ai_sync_obj.nonce });
					return;
				}
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

	// プロンプト設定のタブ切り替え
	// $('.feas-ai-tabs-wrapper .nav-tab').on('click', function(e) {
	// 	e.preventDefault();
	// 	const wrapper = $(this).closest('.feas-ai-tabs-wrapper');
	// 	wrapper.find('.nav-tab').removeClass('nav-tab-active');
	// 	$(this).addClass('nav-tab-active');
//
	// 	wrapper.find('.tab-content').hide();
	// 	const targetTab = $(this).attr('href');
	// 	$(targetTab).show();
	// });

	// 初期表示で最初のタブを選択状態にする
	// if ($('.feas-ai-tabs-wrapper .nav-tab').length) {
	// 	$('.feas-ai-tabs-wrapper .nav-tab').first().trigger('click');
	// }

	function manageLicense(action, button) {
		const $button = $(button);
		const $spinner = $button.siblings('.spinner');
		const licenseKey = $('#feas_ai_pro_license_key_input').val();

		$spinner.css('visibility', 'visible');
		$button.prop('disabled', true);

		$.post(ajaxurl, {
			action: 'feas_ai_manage_license',
			security: feas_ai_sync_obj.nonce, // Nonceをsecurityというキーで送る
			license_key: licenseKey,
			license_action: action
		})
		.always(function() {
			// 完了後、ページをリロードして表示を更新
			location.reload();
		});
	}

	// クリックイベントの登録（イベント委譲を使うとより堅牢）
	$('body').on('click', '#feas_ai_license_activate', function(){
		manageLicense('activate', this);
	});

	$('body').on('click', '#feas_ai_license_deactivate', function(){
		manageLicense('deactivate', this);
	});

	// --- 設定ページのタブ切り替え ---
	const $tabsWrapper = $('.nav-tab-wrapper');
	if ( $tabsWrapper.length ) {
		const $tabs = $tabsWrapper.find('.nav-tab');
		const $tabContents = $('.tab-content');

		$tabs.on('click', function(e) {
			e.preventDefault();

			const targetContent = $(this).attr('href');

			// タブのアクティブ状態を更新
			$tabs.removeClass('nav-tab-active');
			$(this).addClass('nav-tab-active');

			// コンテンツの表示を更新
			$tabContents.hide(); // すべてのコンテンツを非表示
			$(targetContent).show(); // ターゲットのみ表示

			// URLのハッシュを更新して、リロードしてもタブが維持されるようにする
			if (history.pushState) {
				history.pushState(null, null, targetContent);
			} else {
				location.hash = targetContent;
			}
		});

		// ページ読み込み時に、URLのハッシュに基づいてタブをアクティブにする
		let activeTab = window.location.hash;
		if ( ! activeTab || !$tabs.filter('[href="' + activeTab + '"]').length ) {
			activeTab = $tabs.first().attr('href');
		}

		$tabs.filter('[href="' + activeTab + '"]').trigger('click');
	}

	$('#feas-ai-delete-vectors-button').on('click', function() {
		if (!confirm('本当にすべての同期データを削除しますか？この操作は元に戻せません。')) {
			return;
		}

		const $button = $(this);
		const $spinner = $button.siblings('.spinner');
		const $status = $('#feas-ai-delete-status');

		$button.prop('disabled', true);
		$spinner.css('visibility', 'visible');
		$status.text('');

		$.post(ajaxurl, {
			action: 'feas_ai_delete_vectors',
			nonce: feas_ai_sync_obj.nonce
		})
		.done(function(response) {
			if (response.success) {
				$status.css('color', 'green').text(response.data);
				// ページをリロードして、インデックス数を更新
				setTimeout(function(){ location.reload(); }, 1000);
			} else {
				$status.css('color', 'red').text('エラーが発生しました。');
			}
		})
		.fail(function() {
			$status.css('color', 'red').text('通信エラーが発生しました。');
		})
		.always(function() {
			$button.prop('disabled', false);
			$spinner.css('visibility', 'hidden');
		});
	});

	// --- プロンプト設定のアコーディオン（最終版） ---
	// $('#feas-ai-prompt-accordion .accordion-title').on('click', function() {
	// 	const $content = $(this).next('.accordion-content');
	// 	const $textarea = $content.find('.feas-ai-prompt-editor');
//
	// 	// まだ初期化されていなければCodeMirrorを生成
	// 	if ($textarea.length && !$textarea.data('codemirror-initialized')) {
	// 		const editor = CodeMirror.fromTextArea($textarea[0], {
	// 			lineNumbers: true,
	// 			mode: 'markdown',
	// 			lineWrapping: true
	// 		});
	// 		$textarea.data('codemirror-instance', editor);
	// 		$textarea.data('codemirror-initialized', true);
	// 	}
//
	// 	// ★ アニメーションなしで、単純に表示/非表示を切り替える
	// 	$content.toggle();
//
	// 	// ★ 表示された後に、再描画をかける
	// 	if ($content.is(':visible') && $textarea.data('codemirror-initialized')) {
	// 		const editor = $textarea.data('codemirror-instance');
	// 		editor.refresh();
	// 	}
	// });

	// ページ内のすべてのアコーディオンラッパーを対象にする
	$('.feas-ai-accordion-wrapper').each(function() {
		const $accordion = $(this);
		$accordion.find('.accordion-title').on('click', function(e) {
			// チェックボックス自体をクリックした場合は、アコーディオンを開閉しない
			if (e.target.tagName === 'INPUT' && e.target.type === 'checkbox') {
				return;
			}
			e.preventDefault();
			$(this).next('.accordion-content').slideToggle();
		});
	});

	// --- CodeMirrorの初期化 ---
	$('.feas-ai-prompt-editor').each(function() {
		const textarea = this;
		// 親要素が表示されていることを確認してから初期化
		if ($(textarea).is(':visible')) {
			initializeCodeMirror(textarea);
		} else {
			// タブがクリックされた時に初期化されるようにイベントを設定
			const tabContent = $(textarea).closest('.tab-content');
			if (tabContent.length) {
				const tabId = tabContent.attr('id');
				$('a[href="#' + tabId + '"]').one('click', function() {
					initializeCodeMirror(textarea);
				});
			}
		}
	});

	function initializeCodeMirror(textarea) {
		if ($(textarea).data('codemirror-initialized')) return;

		const editor = CodeMirror.fromTextArea(textarea, {
			lineNumbers: true,
			mode: 'markdown',
			lineWrapping: true
		});
		$(textarea).data('codemirror-initialized', true);

		// CodeMirrorが表示された直後にリフレッシュ
		setTimeout(function() {
			editor.refresh();
		}, 1);
	}

});
