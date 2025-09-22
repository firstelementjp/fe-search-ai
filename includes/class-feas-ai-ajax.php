<?php
class FEAS_AI_Ajax {

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'setup_stream_endpoint' ) );
	}

	public function setup_stream_endpoint() {
		if ( isset( $_GET['feas_ai_stream'] ) && $_GET['feas_ai_stream'] === 'true' ) {
			$this->stream_handler();
			exit;
		}
	}

	public function stream_handler() {

		// Gzip圧縮が有効だとストリーミングの邪魔になることがあるので無効化
		if ( function_exists('apache_setenv') ) {
			@apache_setenv('no-gzip', 1);
		}
		@ini_set('zlib.output_compression', 0);

		// Nginxのプロキシバッファリングを無効化するためのヘッダー
		header('X-Accel-Buffering: no');

		// PHPの出力バッファリングを可能な限り無効化する
		// ob_implicit_flush(true);
		while (ob_get_level() > 0) {
			ob_end_flush();
		}

		// ヘッダーを設定して、ブラウザに「これからストリーミングを送りますよ」と伝える
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');

		// nonceと質問をPOSTデータから取得（JS側からPOSTで送られる）
		$nonce = $_POST['nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'feas_ai_ajax_nonce' ) ) {
			echo "data: " . json_encode(['error' => 'Nonce verification failed.']) . "\n\n";
			flush();
			return;
		}

		$question = isset( $_POST['question'] ) ? sanitize_text_field( $_POST['question'] ) : '';
		$history_json = isset( $_POST['history'] ) ? stripslashes( $_POST['history'] ) : '[]';
		$history = json_decode( $history_json, true );

		if ( empty( $question ) ) {
			echo "data: " . json_encode(['error' => '質問が空です。']) . "\n\n";
			return;
		}

		// ステップ1：質問の意図を判断する
		$intent = $this->get_query_intent( $question, $history );

		if ( $intent === 'search_query' ) {
			// --- 意図が「検索」の場合の処理 ---
			$question_vector_response = $this->get_embeddings_via_selected_provider( array( $question ) );
			$question_vector = $question_vector_response['data'][0]['embedding'];
			$similar_chunks = $this->find_similar_chunks( $question, $question_vector );

			if ( empty( $similar_chunks ) ) {
				$message = 'ご質問に該当する情報がサイト内見つかりませんでした。';
				echo "data: " . json_encode(['text' => $message]) . "\n\n";
				echo "data: [DONE]\n\n";
				flush();
				return;
			}
			// 検索結果を元に、専門家として回答を生成
			$this->get_chat_completion( $question, $similar_chunks, $history );

		} else {
			// --- 意図が「挨拶」の場合の処理 ---
			// DB検索は行わず、単純な会話アシスタントとして応答する
			$this->get_chat_completion( $question, array(), $history ); // コンテキストを空にして渡す
		}
	}

	public function get_query_intent( $question, $history = array() ) {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		if ( empty( $api_key ) ) return 'search_query'; // APIキーがなければ検索とみなす

		$system_prompt = "ユーザーの入力を分析し、サイト内情報の検索を意図した質問（'search_query'）か、単純な挨拶や雑談（'greeting'）かを分類してください。回答は必ず 'search_query' または 'greeting' のどちらか一言だけにしてください。";

		$api_url = 'https://api.openai.com/v1/chat/completions';
		$body = array(
			'model' => 'gpt-4o',
			'messages' => array(
				array('role' => 'system', 'content' => $system_prompt),
				array('role' => 'user', 'content' => $question),
			),
			'temperature' => 0,
		);
		$response = wp_remote_post( $api_url, array(
			'headers' => array('Content-Type'  => 'application/json', 'Authorization' => 'Bearer ' . $api_key),
			'body' => json_encode( $body ), 'timeout' => 10,
		));

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return 'search_query'; // エラーの場合は安全のため検索とみなす
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$intent = $response_body['choices'][0]['message']['content'] ?? 'search_query';

		return ( $intent === 'greeting' ) ? 'greeting' : 'search_query';
	}

	public function get_chat_completion( $question, $context_chunks, $history = array(), $system_prompt_override = null ) {
		$api_key = get_option( 'feas_ai_openai_api_key' );
		if ( empty( $api_key ) ) { return; }

		$context_str = '';
		$system_prompt = '';
		$messages = array();

		if ( $system_prompt_override ) {
			$system_prompt = $system_prompt_override;
			$messages[] = array('role' => 'system', 'content' => $system_prompt);
			$messages[] = array('role' => 'user', 'content' => $question);

		} else if ( empty( $context_chunks ) ) {
			$system_prompt = "あなたはウェブサイト「" . get_bloginfo('name') . "」の、フレンドリーで親切なAIアシスタントです。ユーザーとの会話の文脈を考慮し、自然に応じてください。回答は簡潔にしてください。";
			$messages[] = array('role' => 'system', 'content' => $system_prompt);
			// 過去の会話履歴を追加
			if(!empty($history)) {
				foreach( $history as $message ) {
					$messages[] = $message;
				}
			} else {
				 $messages[] = array('role' => 'user', 'content' => $question);
			}

		} else {
			$grouped_chunks = array();
			foreach ( $context_chunks as $chunk ) { $grouped_chunks[ $chunk['permalink'] ][] = $chunk['content_chunk']; }
			foreach ( $grouped_chunks as $permalink => $chunks ) {
				$context_str .= "--- 記事ここから ---\n";
				$context_str .= "情報元URL: " . $permalink . "\n";
				$context_str .= "内容:\n" . implode( "\n", $chunks ) . "\n";
				$context_str .= "--- 記事ここまで ---\n\n";
			}

			$system_prompt = "あなたはウェブサイト「" . get_bloginfo('name') . "」の求人情報を案内する、親切で有能なAIアシスタントです..."; // (プロンプト自体は変更なし)

			$messages[] = array('role' => 'system', 'content' => $system_prompt);
			if(!empty($history)) {
				foreach( $history as $message ) {
					$messages[] = $message;
				}
			}
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
			// OpenAIからデータが届くたびに、この無名関数が呼び出される
			$lines = explode("\n", trim($data));
			foreach ($lines as $line) {
				if (strpos($line, 'data: ') === 0) {
					$json_data = substr($line, 6);
					if ($json_data === '[DONE]') {
						// ストリーム終了の合図
						echo "data: [DONE]\n\n";
					} else {
						$chunk = json_decode($json_data, true);
						if (isset($chunk['choices'][0]['delta']['content'])) {
							$content = $chunk['choices'][0]['delta']['content'];
							// SSE形式でクライアントにデータを送信
							echo "data: " . json_encode(['text' => $content]) . "\n\n";
						}
					}
				}
			}
			// 出力を即座にクライアントに送信する
			if (ob_get_level() > 0) {
				ob_flush();
			}
			flush();
			return strlen( $data ); // cURLに処理したバイト数を返す
		});

		curl_exec( $ch );
		curl_close( $ch );
	}

	public function expand_query( $question ) {
		$system_prompt = "ユーザーの質問から中心となる検索キーワードを抽出し、その類義語や関連語をリストアップして、検索クエリを強化してください。元のキーワードも含め、最も重要な5つまでの単語をカンマ区切りで返してください。\n例1: コールスタッフの仕事 → コールスタッフ,コールセンター,電話応対,カスタマーサポート,仕事\n例2: 在宅でできる仕事 → 在宅,リモートワーク,縫製,パタンナー,仕事";

		// 既存のChat Completions API呼び出しを再利用
		// 検索ではなく、純粋なテキスト生成タスクとして依頼
		$response = $this->get_chat_completion( $question, array(), array(), $system_prompt );

		if ( is_wp_error( $response ) ) {
			return array( $question ); // エラー時は元の質問をそのまま返す
		}

		$keywords = explode( ',', trim( $response ) );
		return array_map( 'trim', $keywords );
	}

	public function find_similar_chunks( $question, $question_vector ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ai_vectors';

		// ステップ1：AIによるクエリ拡張
		$expanded_keywords = $this->expand_query( $question );

		// ステップ2：拡張されたキーワードでOR検索を行い、候補を広く集める
		$sql = "SELECT post_id, content_chunk, vector_data FROM {$table_name}";
		$where_clauses = array();
		$params = array();

		if ( ! empty( $expanded_keywords ) ) {
			foreach ( $expanded_keywords as $keyword ) {
				$where_clauses[] = "content_chunk LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $keyword ) . '%';
			}
			$sql .= " WHERE " . implode( ' OR ', $where_clauses );
		}

		$candidate_rows = empty($params)
			? $wpdb->get_results($sql)
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

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
}
