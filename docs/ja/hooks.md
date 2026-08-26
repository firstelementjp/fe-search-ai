# 開発者向けフック

FE Search AIは、プロバイダー、検索、インデックス、チャット出力、ログ、設定UIを拡張するためのWordPressフィルターとアクションを提供します。このリファレンスは無料版が提供するフックを対象としています。Pro版やサードパーティー製アドオンが追加のフックを提供する場合があります。

## 利用上の注意

- 通常はプラグインまたはテーマから、FE Search AIの読み込み後にコールバックを登録します。
- 複数の引数を渡すフックでは、`add_filter()`または`add_action()`の`$accepted_args`を指定してください。
- アクションのコールバックからHTMLを出力する場合はエスケープし、外部入力はサニタイズしてください。
- プロバイダーのAPIキーはサーバー側だけで扱ってください。
- 動的フック名には、`fe_search_ai_embedding_result_for_openai`のように選択中のプロバイダースラッグが含まれます。
- リリースによってシグネチャが変わった場合は、ソースコードが正となります。

## チャット・回答フィルター

| フック                                   | フィルター値・追加引数               | 用途                                                                                                                                                                           |
| ---------------------------------------- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `fe_search_ai_rate_limit_settings`       | `array $settings`                    | RESTハンドラーとフロントエンド設定で使用するレート制限を変更します。キーは`ip_limit_count`、`global_limit_count`、`notify_threshold`、`notify_email`です。                     |
| `fe_search_ai_preprocess_user_question`  | `string $question`                   | サニタイズ後、検索とモデル処理の前に質問を変更します。プラグイン組み込みの個人情報・基本的なインジェクション対策もこのフックで実行されます。空文字列を返すと処理を停止します。 |
| `fe_search_ai_retrieved_chunks`          | `array $chunks`, `string $question`  | モデルへ渡す前に検索済みチャンクを変更します。有効な場合、組み込みのCohereリランカーが優先度20で実行されます。                                                                 |
| `fe_search_ai_preprocess_model_response` | `string $content`                    | ブラウザーへ送信する前に、モデル回答またはストリーミング中のテキスト断片を変更します。                                                                                         |
| `fe_search_ai_system_prompt`             | `string $prompt`, `string $provider` | コンテキストやプレースホルダーを展開する前に、設定済みシステムプロンプトを変更します。                                                                                         |
| `fe_search_ai_final_system_prompt`       | `string $prompt`, `string $provider` | すべてのプレースホルダーとコンテキストを展開した後の最終システムプロンプトを変更します。                                                                                       |
| `fe_search_ai_personal_data_patterns`    | `array $patterns`                    | メールアドレスと電話番号のマスキングに使用する正規表現を変更します。                                                                                                           |
| `fe_search_ai_rate_limit_message`        | `string $message`                    | リクエスト上限到達時にフロントエンドへ表示する翻訳済みメッセージを変更します。                                                                                                 |

## 検索・ランキングフィルター

| フック                                            | フィルター値・追加引数                                                       | 用途                                                                                                              |
| ------------------------------------------------- | ---------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_qdrant_search_limit`                | `int $limit`, `string $question`                                             | Qdrantから取得する候補数を変更します。初期値は設定済みのリランク初期候補数です。                                  |
| `fe_search_ai_max_chunks_for_llm`                 | `int $max_chunks`, `string $question`                                        | モデルのコンテキスト用に返すBM25チャンクの最大数を変更します。初期値：`100`。                                     |
| `fe_search_ai_bm25_candidate_limit`               | `int $limit`, `string $question`                                             | BM25スコアリング前に評価するキーワードインデックス行数を変更します。初期値：`500`と`$max_chunks * 20`の大きい方。 |
| `fe_search_ai_bm25_k1`                            | `float $k1`, `string $question`                                              | BM25の単語頻度飽和パラメーターを変更します。初期値：`1.2`。0以下は初期値に戻ります。                              |
| `fe_search_ai_bm25_b`                             | `float $b`, `string $question`                                               | BM25の文書長正規化パラメーターを変更します。初期値：`0.75`。有効範囲は`0`から`1`です。                            |
| `fe_search_ai_hybrid_candidate_limit`             | `int $limit`, `string $question`                                             | ハイブリッド検索で各検索元から取得する候補数を変更します。設定値の初期値は`50`です。                              |
| `fe_search_ai_hybrid_rrf_k`                       | `int $k`, `string $question`                                                 | Reciprocal Rank Fusionの定数を変更します。初期値：`60`。                                                          |
| `fe_search_ai_hybrid_search_limit`                | `int $limit`, `string $question`                                             | 統合後のハイブリッド検索結果の最大数を変更します。初期値：`100`。                                                 |
| `fe_search_ai_retrieval_trace_payload`            | `array $payload`, `array $chunks`, `string $question`, `string $sequence_id` | ログ記録、アクション発火、任意のDB保存より前に、安全化された検索トレースペイロードを変更します。                  |
| `fe_search_ai_enable_retrieval_trace_persistence` | `bool $enabled`, `array $trace`                                              | 検索トレースのDB保存を有効にします。初期値：`false`。                                                             |

## 同期・チャンク・要約フィルター

| フック                                          | フィルター値・追加引数                                                                       | 用途                                                                                                                     |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `fe_search_ai_sync_target_has_snippet`          | `bool $has_snippet`, `array $post_type_options`, `string $post_type_slug`                    | 有効な投稿タイプにインデックス対象のスニペット項目があり、同期へ含めるかを変更します。                                   |
| `fe_search_ai_sync_query_args`                  | `array $args`                                                                                | 一括同期対象の投稿を収集する`get_posts()`の引数を変更します。                                                            |
| `fe_search_ai_post_language_code`               | `string $language_code`, `int $post_id`, `WP_Post $post`                                     | リアルタイムインデックス時に検出された投稿の言語コードを変更します。                                                     |
| `fe_search_ai_taxonomy_items`                   | `array $items`, `WP_Post $post`, `array $post_type_options`                                  | チャンクデータへ含める整形済みタクソノミーメタデータを変更します。                                                       |
| `fe_search_ai_post_metadata_parts`              | `array $parts`, `WP_Post $post`, `array $post_type_options`                                  | 各投稿チャンクの先頭へ付加するメタデータ行全体を変更します。                                                             |
| `fe_search_ai_chunk_size`                       | `int $size`, `WP_Post $post`                                                                 | おおよその最大チャンク文字数を変更します。初期値：`1000`。                                                               |
| `fe_search_ai_qdrant_snippet_length`            | `int $length`, `WP_Post $post`, `array $chunk`                                               | Qdrantペイロードの最大スニペット長を変更します。初期値：`1000`。                                                         |
| `fe_search_ai_chunk_summary`                    | `string\|null $override`, `array $chunk`, `WP_Post $post`, `string $language_code`           | チャンクの要約生成全体を上書きします。`null`で組み込みプロバイダー処理、文字列でその要約、空文字列で要約なしになります。 |
| `fe_search_ai_chunk_summary_prompt_context`     | `array $context`, `array $chunk`, `WP_Post $post`, `string $language_code`                   | チャンク要約プロンプトの構築に使用する構造化コンテキストを変更します。                                                   |
| `fe_search_ai_chunk_summary_generation_prompt`  | `string $prompt`, `array $context`, `array $chunk`, `WP_Post $post`, `string $language_code` | 要約生成のためにチャットプロバイダーへ送信する最終プロンプトを変更します。                                               |
| `fe_search_ai_chunk_summary_output_postprocess` | `string $summary`, `array $chunk`, `WP_Post $post`, `string $language_code`                  | 生成済みチャンク要約を保存前に後処理します。                                                                             |
| `fe_search_ai_summary_chat_result`              | `string\|null $override`, `string $prompt`, `string $provider`                               | 要約生成に使用するプロバイダーリクエストを上書きします。`null`を返すと選択中の組み込みプロバイダーを使用します。         |

## トークナイズフィルター

| フック                                        | フィルター値・追加引数                                              | 用途                                                                               |
| --------------------------------------------- | ------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `fe_search_ai_stop_words`                     | `array $stop_words`, `string $locale`                               | ロケール別のストップワード一覧を変更します。                                       |
| `fe_search_ai_tokens_for_lang`                | `array $tokens`, `string $normalized_text`, `string $language_code` | 言語別の分割後、ストップワード除去とステミング前の生トークンを変更します。         |
| `fe_search_ai_tokenize_text`                  | `array $tokens`, `string $normalized_text`, `string $locale`        | ストップワード除去、ステミング、任意の重複除去後の最終トークンを変更します。       |
| `fe_search_ai_tokenizer_status`               | `array $statuses`                                                   | 同期画面のトークナイザーステータス欄へHTMLのステータス行を追加します。             |
| `fe_search_ai_japanese_tokenizer_status_text` | `string $status_html`, `string $tokenizer`                          | 組み込み同期ハンドラーが生成する日本語トークナイザーのステータスHTMLを変更します。 |

## プロバイダー・連携フィルター

| フック                                          | フィルター値・追加引数                                                                                | 用途                                                                                                                                           |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_chat_providers`                   | `array $providers`                                                                                    | チャットプロバイダーの選択肢を変更します。配列はプロバイダースラッグと表示名の対応です。                                                       |
| `fe_search_ai_embedding_providers`              | `array $providers`                                                                                    | 埋め込みプロバイダーの選択肢を変更します。配列はプロバイダースラッグと表示名の対応です。                                                       |
| `fe_search_ai_rerank_providers`                 | `array $providers`                                                                                    | リランクプロバイダーの選択肢を変更します。配列はプロバイダースラッグと表示名の対応です。                                                       |
| `fe_search_ai_embedding_result_for_{$provider}` | `array\|WP_Error\|null $result`, `array $texts`                                                       | 埋め込み生成を上書きする動的プロバイダーフックです。`null`を返すと組み込み処理を使用します。例：`fe_search_ai_embedding_result_for_openai`。   |
| `fe_search_ai_handle_custom_api_test`           | `array\|null $result`, `string $provider`, `string $api_key`                                          | カスタムプロバイダーのAPIキーテストを処理します。`['is_valid' => bool, 'message' => string]`、または組み込み処理を使う場合は`null`を返します。 |
| `fe_search_ai_get_sync_handler_instance`        | 任意の値。通常は`apply_filters( 'fe_search_ai_get_sync_handler_instance', null )`として呼び出します。 | 連携コードへ有効な`FE_Search_AI_Sync_Handler`インスタンスを返します。無料版がこの連携フィルターのコールバックを提供します。                    |
| `fe_search_ai_enable_github_updates`            | `bool $enabled`                                                                                       | GitHub Releases更新チェッカーを有効化します。初期値：`true`。                                                                                  |
| `fe_search_ai_admin_allowed_hooks`              | `array $hook_suffixes`                                                                                | FE Search AIの管理画面アセットを読み込む管理画面フックサフィックスを追加します。                                                               |

### カスタム埋め込みプロバイダーの例

```php
add_filter(
    'fe_search_ai_embedding_result_for_example',
    function ( $result, $texts ) {
        // Return an OpenAI-compatible embedding response or a WP_Error.
        return $result;
    },
    10,
    2
);
```

## フロントエンド表示フィルター

| フック                             | フィルター値・追加引数                    | 用途                                                                                                                                                          |
| ---------------------------------- | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_should_display_chat` | `bool $should_display`                    | 現在のリクエストでフローティングチャットUIを表示するか変更します。                                                                                            |
| `fe_search_ai_chat_ui_html`        | `string $html`, `array $args`             | フローティング表示と埋め込み表示のチャットUI HTML全体を変更します。                                                                                           |
| `fe_search_ai_dynamic_styles_css`  | `string $style_html`, `string $key_color` | チャットUIが出力する動的な`<style>`ブロックを変更します。                                                                                                     |
| `fe_search_ai_frontend_color_css`  | `string $css`, `array $colors`            | フロントエンドのインラインCSS変数を変更します。色配列には`accent`、`background`、`text`、`border`、グラデーション、入力欄、ユーザー吹き出しの色が含まれます。 |

## プライバシー・ログフィルター

会話本文および個人情報を含む可能性のある内容は、初期状態では保存されません。以下のフィルターを有効にする場合は、プライバシーと法令順守への影響を確認してください。

| フック                                              | フィルター値・追加引数                                             | 用途                                                                                                                        |
| --------------------------------------------------- | ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_privacy_provider_registry`            | `array $registry`, `array $settings`, `array $pro_settings`        | プライバシータブとフロントエンド通知で使うプロバイダーのデータ取扱い情報を変更します。                                      |
| `fe_search_ai_active_privacy_recipients`            | `array $recipients`, `array $settings`, `array $pro_settings`      | 現在の設定で有効と表示する送信先を変更します。                                                                              |
| `fe_search_ai_privacy_config`                       | `array $config`, `array $settings`, `array $pro_settings`          | バージョン付きのフロントエンドプライバシー・同意・ログ設定を変更します。                                                    |
| `fe_search_ai_validate_chat_consent`                | `bool $valid`, `string $token`, `WP_REST_Request $request`         | チャット処理前に必須同意を検証します。初期値は`true`で、Pro版は有効時にトークンを検証します。                               |
| `fe_search_ai_conversation_log_mode`                | `string $mode`, `string $session_id`, `string $token`              | 会話ログを`none`、`diagnostic`、`analytics`から選択します。初期値：`none`。                                                 |
| `fe_search_ai_allow_conversation_log_question_text` | `bool $allowed`, `string $session_id`                              | 会話ログへの質問全文の保存を許可します。初期値：`false`。                                                                   |
| `fe_search_ai_allow_conversation_log_answer_text`   | `bool $allowed`, `string $session_id`                              | 会話ログへの回答全文の保存を許可します。初期値：`false`。                                                                   |
| `fe_search_ai_allow_conversation_log_pii`           | `bool $allowed`, `string $session_id`                              | 個人情報を含む可能性のある質問・回答本文の保存を許可します。初期値：`false`。                                               |
| `fe_search_ai_conversation_log_payload`             | `array $row`, `string $session_id`                                 | 挿入前の会話ログ行を変更します。保持されるキーは`session_id`、`question`、`answer`、`context_found`、`created_at`のみです。 |
| `fe_search_ai_allow_system_log_entry`               | `bool $allowed`, `string $level`, `string $message`, `array $data` | デバッグモード確認後に、個別のシステムログ記録を許可または抑止します。初期値：`true`。                                      |
| `fe_search_ai_system_log_forbidden_keys`            | `array $keys`, `string $level`, `string $message`, `array $data`   | 挿入前のシステムログペイロードから再帰的に除去するデータキーを変更します。                                                  |
| `fe_search_ai_system_log_payload`                   | `array $data`, `string $level`, `string $message`                  | 挿入前にサニタイズ済みのシステムログコンテキストデータを変更します。このフィルター後にも禁止キーは再度除去されます。        |
| `fe_search_ai_log_retention_days`                   | `int $days`                                                        | システムログの保持日数を変更します。初期値：`30`日。                                                                        |
| `fe_search_ai_conversation_log_retention_days`      | `int $days`                                                        | 会話ログの保持日数を変更します。初期値：`7`日。                                                                             |

## 設定サニタイズフィルター

| フック                                   | フィルター値・追加引数                                     | 用途                                                               |
| ---------------------------------------- | ---------------------------------------------------------- | ------------------------------------------------------------------ |
| `fe_search_ai_sanitize_sync_target`      | `array $sanitized`, `array $raw`, `string $post_type_slug` | 保存前に、投稿タイプごとのサニタイズ済み同期対象設定を変更します。 |
| `fe_search_ai_sanitize_display_floating` | `array $sanitized`, `array $raw`                           | 保存前に、サニタイズ済みフローティング表示設定を変更します。       |

## アクション

### 実行時・ライフサイクルアクション

| フック                                  | 引数                                                                              | 用途                                                                                                                                                                                                                    |
| --------------------------------------- | --------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_retrieval_trace`          | `array $trace`                                                                    | 安全化された検索トレースペイロードの構築後に発火します。組み込みレコーダーも監視しますが、`fe_search_ai_enable_retrieval_trace_persistence`が有効な場合だけ保存します。                                                 |
| `fe_search_ai_stream_for_{$provider}`   | `string $question`, `array $context_chunks`, `array $history`, `string $provider` | 組み込みストリーミング処理の前に発火する動的アクションです。カスタムプロバイダーのコールバックは回答をストリーミングし、組み込み処理を止めるために`exit()`を呼ぶ必要があります。例：`fe_search_ai_stream_for_example`。 |
| `fe_search_ai_daily_log_rotation_event` | なし                                                                              | プラグインが毎日実行するよう登録するWP-Cronイベントです。組み込みコールバックがシステムログと会話ログをローテーションします。                                                                                           |

### 設定UIアクション

マークアップを出力するコールバックでは、適切にエスケープしてください。

| フック                                               | 引数                                                  | 位置                                                                             |
| ---------------------------------------------------- | ----------------------------------------------------- | -------------------------------------------------------------------------------- |
| `fe_search_ai_settings_tabs`                         | なし                                                  | 設定画面のナビゲーションタブ内。                                                 |
| `fe_search_ai_settings_tabs_content`                 | なし                                                  | 設定コンテンツ領域の末尾。`fe_search_ai_settings_tabs`と組み合わせて使用します。 |
| `fe_search_ai_after_api_settings_fields`             | なし                                                  | 「プロバイダー」タブの全フィールド後。                                           |
| `fe_search_ai_after_vector_store_settings_fields`    | `bool $is_pro`                                        | 「同期」タブのベクトルストア項目後。                                             |
| `fe_search_ai_after_display_embed_settings_fields`   | なし                                                  | 埋め込み表示項目後。                                                             |
| `fe_search_ai_after_display_settings_fields`         | `bool $is_pro`                                        | 「表示」タブの全フィールド後。                                                   |
| `fe_search_ai_after_prompt_settings_fields`          | なし                                                  | 「プロンプト」タブの全フィールド後。                                             |
| `fe_search_ai_after_data_management_settings_fields` | `bool $is_pro`                                        | データ管理設定テーブル内。                                                       |
| `fe_search_ai_after_advanced_settings_fields`        | `bool $is_pro`                                        | 「高度な設定」タブの全フィールド後。                                             |
| `fe_search_ai_api_keys_table_rows`                   | `FE_Search_AI_Settings $settings`                     | APIキーテーブル本体の末尾。                                                      |
| `fe_search_ai_after_api_key_fields`                  | `FE_Search_AI_Settings $settings`                     | APIキーテーブル後。                                                              |
| `fe_search_ai_sync_target_rows`                      | `WP_Post_Type $post_type`, `array $post_type_options` | 各投稿タイプの同期対象テーブル本体の末尾。                                       |

## 使用例

### 2段階のプロンプトを変更する

```php
add_filter(
    'fe_search_ai_system_prompt',
    function ( $prompt, $provider ) {
        return $prompt . "\nAnswer concisely.";
    },
    10,
    2
);

add_filter(
    'fe_search_ai_final_system_prompt',
    function ( $prompt, $provider ) {
        return $prompt . "\nCite relevant site content when possible.";
    },
    10,
    2
);
```

### ハイブリッド検索を調整する

```php
add_filter( 'fe_search_ai_hybrid_candidate_limit', function ( $limit, $question ) {
    return 75;
}, 10, 2 );

add_filter( 'fe_search_ai_hybrid_search_limit', function ( $limit, $question ) {
    return 50;
}, 10, 2 );
```

### 設定タブを追加する

```php
add_action( 'fe_search_ai_settings_tabs', function () {
    echo '<a href="#tab_example" class="nav-tab">' . esc_html__( 'Example', 'example-plugin' ) . '</a>';
} );

add_action( 'fe_search_ai_settings_tabs_content', function () {
    echo '<div id="tab_example" class="tab-content">';
    echo '<p>' . esc_html__( 'Example settings.', 'example-plugin' ) . '</p>';
    echo '</div>';
} );
```

## ソースコード

正確な実装と最新のフックシグネチャは、[GitHubリポジトリ](https://github.com/firstelementjp/fe-search-ai)を確認してください。
