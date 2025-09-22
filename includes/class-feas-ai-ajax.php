<?php
class FEAS_AI_Ajax {

	public function __construct() {
		// add_action( 'template_redirect', array( $this, 'setup_stream_endpoint' ) );
		add_action( 'rest_api_init', array( $this, 'register_stream_endpoint' ) );
		add_action( 'wp_ajax_nopriv_feas_ai_log_query', array( $this, 'ajax_log_query' ) );
		add_action( 'wp_ajax_feas_ai_log_query', array( $this, 'ajax_log_query' ) );
	}

	// public function setup_stream_endpoint() {get_embeddings_via_selected_provider
	// 	if ( isset( $_GET['feas_ai_stream'] ) && $_GET['feas_ai_stream'] === 'true' ) {
	// 		$this->stream_handler();
	// 		exit;
	// 	}
	// }

	/**
	 * ストリーミング用のREST APIエンドポイントを登録する
	 */
	public function register_stream_endpoint() {
		register_rest_route( 'feas-ai/v1', '/stream', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'stream_handler' ),
			'permission_callback' => '__return_true', // 誰でもアクセス可能
		) );
	}

	// in includes/class-feas-ai-ajax.php

	/**
	 * REST APIのリクエストを処理するストリームハンドラ
	 * @param WP_REST_Request $request
	 */
	public function stream_handler( $request ) {
		// ヘッダーやバッファリング無効化
		@ini_set('zlib.output_compression', 0);
		header('X-Accel-Buffering: no');
		while (ob_get_level() > 0) { ob_end_flush(); }
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');

		// REST API用のNonce検証
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			echo "data: " . json_encode(['error' => 'Nonce verification failed.']) . "\n\n";
			flush();
			return;
		}

		// パラメータの取得
		$question = sanitize_text_field( $request->get_param( 'question' ) );
		$history_json = stripslashes( $request->get_param( 'history' ) ?? '[]' );
		$history = json_decode( $history_json, true );

		if ( empty( $question ) ) {
			echo "data: " . json_encode(['error' => '質問が空です。']) . "\n\n";
			flush();
			return;
		}

		// --- 新しい思考プロセス ---

		// 1. まず、質問のベクトル化と情報検索を試みる
		$question_vector_response = $this->get_embeddings_via_selected_provider( array( $question ) );

		if ( is_wp_error( $question_vector_response ) ) {
			// ベクトル化でエラーが発生した場合
			$error_message = 'エラー: 質問のベクトル化に失敗しました。(' . $question_vector_response->get_error_message() . ')';
			echo "data: " . json_encode(['text' => $error_message]) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
			return;
		}

		$question_vector = $question_vector_response['data'][0]['embedding'];
		$similar_chunks = $this->find_similar_chunks( $question, $question_vector );

		// 2. 検索結果を元に、AIの振る舞いを決定する
		$context_found = ! empty( $similar_chunks );
		echo "data: " . json_encode(['meta' => ['context_found' => $context_found]]) . "\n\n";
		flush();

		// 3. 回答を生成する (コンテキストの有無でAIの役割が自動で変わる)
		$this->stream_chat_completion( $question, $similar_chunks, $history );
	}

	// public function get_query_intent( $question, $history = array() ) {
	// 	$system_prompt = "ユーザーの最新の質問が、直前の会話履歴を踏まえて、以下のどれに該当するかを分類してください。\n- 'new_search': 明確に新しいトピックの検索を開始している（例：「〜の仕事はありますか？」）。\n- 'follow_up': 直前の会話内容を指す言葉（「それ」「その給与」など）を使い、深掘りしている。\n- 'greeting': 単純な挨拶や雑談。\n\n回答は必ず 'new_search', 'follow_up', 'greeting' のいずれか一言だけにしてください。";
//
	// 	// 新しい「思考」用の関数を呼び出す
	// 	$intent = $this->get_simple_chat_response( $question, $system_prompt );
//
	// 	// エラーが発生した場合は、安全のため「新しい検索」として扱う
	// 	if ( is_wp_error( $intent ) ) {
	// 		return 'new_search';
	// 	}
//
	// 	// AIからの回答が期待通りかチェックし、そうでなければ「新しい検索」として扱う
	// 	if ( in_array( $intent, ['new_search', 'follow_up', 'greeting'] ) ) {
	// 		return $intent;
	// 	} else {
	// 		return 'new_search';
	// 	}
	// }

	/**
	 * 内部的な思考タスクのための、シンプルな非ストリーミングAI応答関数
	 */
	private function get_simple_chat_response( $question, $system_prompt ) {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', 'APIキーが設定されていません。' );
		}

		$messages = array(
			array( 'role' => 'system', 'content' => $system_prompt ),
			array( 'role' => 'user', 'content' => $question ),
		);

		$api_url = 'https://api.openai.com/v1/chat/completions';
		$body = array( 'model' => 'gpt-4o', 'messages' => $messages, 'temperature' => 0 );

		// ストリーミングではない、通常のwp_remote_postを使用
		$response = wp_remote_post( $api_url, array(
			'headers' => array('Content-Type'  => 'application/json', 'Authorization' => 'Bearer ' . $api_key),
			'body' => json_encode( $body ), 'timeout' => 20,
		));

		if ( is_wp_error( $response ) ) return $response;

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$error_message = $response_body['error']['message'] ?? '不明なAPIエラー';
			return new WP_Error( 'api_error', 'APIエラー: ' . $error_message );
		}

		return $response_body['choices'][0]['message']['content'] ?? '';
	}

	/**
	 * ユーザーへの最終的な回答をストリーミングで生成・出力する
	 */
	// in includes/class-feas-ai-ajax.php

	public function stream_chat_completion( $question, $context_chunks, $history = array() ) {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		if ( empty( $api_key ) ) { return; }

		$messages = array();

		if ( empty( $context_chunks ) ) {
			// --- コンテキストがない（挨拶など）場合 ---
			$system_prompt = "あなたはウェブサイト「" . get_bloginfo('name') . "」の、フレンドリーで親切なAIアシスタントです。ユーザーとの会話の文脈を考慮し、自然に応じてください。回答は簡潔にしてください。";

			$messages[] = array('role' => 'system', 'content' => $system_prompt);
			// 過去の会話履歴をメッセージに追加
			if( ! empty( $history ) ) {
				foreach( $history as $message ) {
					$messages[] = $message;
				}
			}
			// 履歴がない場合（＝最初の挨拶）でも、ユーザーの質問を必ず含める
			if ( empty( $history ) ) {
				$messages[] = array('role' => 'user', 'content' => $question);
			}

		} else {
			// --- コンテキストがある（検索）場合 ---
			$context_str = '';
			$grouped_chunks = array();
			foreach ( $context_chunks as $chunk ) {
				$grouped_chunks[ $chunk['permalink'] ][] = $chunk['content_chunk'];
			}

			foreach ( $grouped_chunks as $permalink => $chunks ) {
				$context_str .= "--- 記事ここから ---\n";
				$context_str .= "情報元URL: " . $permalink . "\n";
				$context_str .= "内容:\n" . implode( "\n", $chunks ) . "\n";
				$context_str .= "--- 記事ここまで ---\n\n";
			}

			$system_prompt = "あなたはウェブサイト「" . get_bloginfo('name') . "」の求人情報を案内する、親切で有能なAIアシスタントです。\n以下の「サイト内情報」を元に、ユーザーの質問に回答してください。\n\n## 指示\n- まず最初に「〜に関するお仕事がX件見つかりました。」のように、検索結果の概要を述べてください。\n- サイト内情報は、情報元となる記事ごとに区切られています。記事ごとに個別の要約を作成してください。\n- 各求人について、以下の項目を**必ず**抽出して箇条書きで示してください：\n    - 【仕事内容】\n    - 【雇用形態】\n    - 【勤務地】\n    - 【給与】\n- 各記事の要約の直後に、情報元となるURL自体を記載してください。\n- 最後に「他に何かお探しですか？」のような、会話を促す一言を加えてください。\n- 文脈にない情報（例：「➡️ 詳細はこちら」）を創作しないでください。\n\n## 回答フォーマット\n- 全体をMarkdown形式で記述してください。\n- 各求人のタイトル（【募集要項】など）を`###`（H3見出し）で示してください。";

			$messages[] = array('role' => 'system', 'content' => $system_prompt);
			// 過去の会話履歴を追加
			if( ! empty( $history ) ) {
				foreach( $history as $message ) {
					$messages[] = $message;
				}
			}
			// 最後に、今回の質問と検索結果コンテキストを追加
			$messages[] = array('role' => 'user', 'content' => "サイト内情報:\n" . $context_str . "\n\n上記の情報を元に、次の質問に答えてください:\n" . $question);
		}

		$api_url = 'https://api.openai.com/v1/chat/completions';
		$body = array(
			'model' => 'gpt-4o',
			'messages' => $messages,
			'temperature' => 0.5,
			'stream' => true,
		);

		// cURLを使ってストリーミング通信を行う
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $api_url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_key
		) );
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) {
			$lines = explode("\n", trim($data));
			foreach ($lines as $line) {
				if (strpos($line, 'data: ') === 0) {
					$json_data = substr($line, 6);
					if ($json_data === '[DONE]') {
						echo "data: [DONE]\n\n";
					} else {
						$chunk = json_decode($json_data, true);
						if (isset($chunk['choices'][0]['delta']['content'])) {
							$content = $chunk['choices'][0]['delta']['content'];
							echo "data: " . json_encode(['text' => $content]) . "\n\n";
						}
					}
				}
			}
			if (ob_get_level() > 0) { ob_flush(); }
			flush();
			return strlen( $data );
		});

		curl_exec( $ch );
		curl_close( $ch );
	}

	public function expand_query( $question ) {
		$system_prompt = "ユーザーの質問から中心となる検索キーワードを抽出し、その類義語や関連語をリストアップして、検索クエリを強化してください。元のキーワードも含め、最も重要な5つまでの単語をカンマ区切りで返してください。\n例1: コールスタッフの仕事 → コールスタッフ,コールセンター,電話応対,カスタマーサポート,仕事\n例2: 在宅でできる仕事 → 在宅,リモートワーク,縫製,パタンナー,仕事";

		/// ▼ 呼び出す関数を変更 ▼
		$response = $this->get_simple_chat_response( $question, $system_prompt );

		if ( is_wp_error( $response ) ) return array( $question );

		$keywords = explode( ',', trim( $response ) );
		return array_map( 'trim', $keywords );
	}

	// in includes/class-feas-ai-ajax.php

	// in includes/class-feas-ai-ajax.php

	public function find_similar_chunks( $question, $question_vector ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'feas_ai_vectors';

		// ステップ1：AIによるクエリ拡張
		$expanded_keywords = $this->expand_query( $question );

		// ステップ2：拡張されたキーワードでOR検索を行い、候補を広く集める
		$sql = "SELECT post_id, content_chunk, vector_data FROM `{$table_name}`";
		$where_clauses = array();
		$params = array();

		if ( ! empty( $expanded_keywords ) ) {
			foreach ( $expanded_keywords as $keyword ) {
				if( empty(trim($keyword)) ) continue; // 空のキーワードを無視
				$where_clauses[] = "`content_chunk` LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $keyword ) . '%';
			}
		}

		// キーワードがない、または空の場合はヒットしないので、空の配列を返す
		if ( empty( $where_clauses ) ) {
			return array();
		}

		$sql .= " WHERE " . implode( ' OR ', $where_clauses );

		$candidate_rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		// ▼▼▼ このブロックを削除 ▼▼▼
		// // もしキーワードでヒットしなければ、ベクトル検索で全件から探す
		// if ( empty( $candidate_rows ) ) {
		//     $candidate_rows = $wpdb->get_results( "SELECT post_id, content_chunk, vector_data FROM {$table_name}" );
		// }
		// ▲▲▲ このブロックを削除 ▲▲▲

		if ( empty( $candidate_rows ) ) {
			return array();
		}

		// ステップ3：全ての候補チャンクの類似度を計算する
		$similarities = array();
		foreach ( $candidate_rows as $row ) {
			$db_vector = json_decode( $row->vector_data, true );
			if ( ! is_array( $db_vector ) || empty( $db_vector ) ) continue;

			$similarity = $this->calculate_cosine_similarity_php( $question_vector, $db_vector );

			$similarities[] = array(
				'post_id'       => $row->post_id,
				'content_chunk' => $row->content_chunk,
				'similarity'    => $similarity,
				'permalink'     => get_permalink( $row->post_id ),
			);
		}

		// ステップ4：類似度スコアで配列を降順にソート
		usort( $similarities, function( $a, $b ) {
			return $b['similarity'] <=> $a['similarity'];
		} );

		// ステップ5：上位7件の「チャンク」をコンテキストとして返す
		return array_slice( $similarities, 0, 7 );
	}

	public function calculate_cosine_similarity_php( $vec1, $vec2 ) {
		$dot_product = 0;
		$norm1 = 0;
		$norm2 = 0;
		$count = count( $vec1 );

		for ( $i = 0; $i < $count; $i++ ) {
			$dot_product += $vec1[$i] * $vec2[$i];
			$norm1 += $vec1[$i] * $vec1[$i];
			$norm2 += $vec2[$i] * $vec2[$i];
		}

		if ( $norm1 == 0 || $norm2 == 0 ) {
			return 0.0;
		}

		return $dot_product / ( sqrt( $norm1 ) * sqrt( $norm2 ) );
	}

	public function get_embeddings_via_selected_provider( $texts ) {
		$provider = get_option( 'feas_ai_api_provider', 'openai' ); // 'openai'をデフォルトに

		if ( 'google' === $provider ) {
			return $this->get_gemini_embeddings( $texts );
		} else {
			return $this->get_embeddings( $texts ); // OpenAI
		}
	}

	public function get_embeddings( $texts ) {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', 'OpenAI APIキーが設定されていません。' );
		}

		$api_url = 'https://api.openai.com/v1/embeddings';

		$body = array(
			'model' => 'text-embedding-3-small', // 最新の安価で高性能なモデル
			'input' => $texts,
		);

		$response = wp_remote_post( $api_url, array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'        => json_encode( $body ),
			'timeout'     => 60, // タイムアウトを60秒に設定
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code !== 200 ) {
			$error_message = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : '不明なAPIエラー';
			return new WP_Error( 'api_error', 'APIエラー: ' . $error_message );
		}

		return $response_body;
	}

	public function get_gemini_embeddings( $texts ) {
		$api_key = get_option( 'feas_ai_google_api_key' ); // Google用のAPIキーを取得
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', 'Google Cloud APIキーが設定されていません。' );
		}

		// ★ エンドポイントが異なる
		$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:batchEmbedContents?key=' . $api_key;

		// ★ リクエストボディの構造が異なる
		$requests = array();
		foreach ( $texts as $text ) {
			$requests[] = array(
				'model' => 'models/text-embedding-004',
				'content' => array(
					'parts' => array(
						array( 'text' => $text )
					)
				)
			);
		}
		$body = array( 'requests' => $requests );

		$response = wp_remote_post( $api_url, array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				// ★ ヘッダーの形式が異なる
			),
			'body'        => json_encode( $body ),
			'timeout'     => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code !== 200 ) {
			$error_message = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : '不明なAPIエラー';
			return new WP_Error( 'api_error', 'APIエラー: ' . $error_message );
		}

		// ★ レスポンスからベクトルデータを取り出す構造が異なる
		$vectors = array();
		if ( isset( $response_body['embeddings'] ) ) {
			foreach ( $response_body['embeddings'] as $embedding_data ) {
				$vectors[] = $embedding_data['values'];
			}
		}

		// OpenAI版とレスポンスの形を揃えるため、独自に整形して返す
		$formatted_response = array();
		foreach( $vectors as $i => $vector ) {
			$formatted_response['data'][$i]['embedding'] = $vector;
		}

		return $formatted_response;
	}

	public function ajax_log_query() {
		check_ajax_referer( 'feas_ai_ajax_nonce', 'nonce' );

		global $wpdb;
		$logs_table = $wpdb->prefix . 'feas_ai_logs';

		$question = isset( $_POST['question'] ) ? sanitize_text_field( $_POST['question'] ) : '';
		$answer = isset( $_POST['answer'] ) ? wp_kses_post( $_POST['answer'] ) : '';
		$session_id = isset( $_POST['session_id'] ) ? sanitize_key( $_POST['session_id'] ) : '';
		$context_found = isset( $_POST['context_found'] ) ? (bool) $_POST['context_found'] : false;

		if ( ! empty( $question ) && ! empty( $session_id ) ) {
			$wpdb->insert(
				$logs_table,
				array(
					'session_id'    => $session_id,
					'question'      => $question,
					'answer'        => $answer,
					'context_found' => $context_found,
					'created_at'    => current_time( 'mysql' ),
				)
			);
		}

		wp_send_json_success();
	}
}
