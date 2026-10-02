# 設定

設定画面（管理メニューの **FE Search AI**）はタブで構成されています。このページは無料版の各タブのフィールドリファレンスです。Pro版では **Models** と **Security** タブと追加フィールドが加わります。

- **Providers** — APIキーとモデルプロバイダーの選択
- **Sync** — インデックス対象コンテンツ、保存先、同期コントロール
- **Prompts** — サイト情報とベースシステムプロンプト
- **Display** — フローティングチャット、埋め込み、外観
- **Privacy** — データ取扱いのサマリーと法的文書リンク
- **Advanced settings** — Reranker、Qdrant、トークナイザー、ログ、データ管理

## Providers タブ

### API Keys

プロバイダーごとに1つのパスワードフィールドがあります。キーは暗号化されてデータベースに保存されます。各行には **Test** ボタンがあり、実際のAPIコールでキーを検証でき、現在使用中のモデルも表示されます。

| プロバイダー       | 用途                    | デフォルトモデル            |
| ------------------ | ----------------------- | --------------------------- |
| OpenAI (GPT)       | チャット補完とEmbedding | `gpt-5.4-mini`              |
| Anthropic (Claude) | チャット補完            | `claude-haiku-4-5-20251001` |
| Google (Gemini)    | チャット補完とEmbedding | `gemini-2.5-flash`          |
| Cohere (Rerank)    | 検索結果のリランク      | `rerank-v3.5`               |

モデル選択はPro版の機能です。無料版では各プロバイダーのデフォルトモデルが使われます。

API使用量を守るためのレート制限が組み込まれています: **IPアドレスあたり1時間50リクエスト**、**サイト全体で1日1,000リクエスト**。`fe_search_ai_rate_limit_settings` フィルターで変更できます（[開発者向けフック](hooks.md)を参照）。

### Chat AI

回答を生成するプロバイダー: OpenAI、Anthropic、Google から選択します。

### Vectorization AI

コンテンツと質問をEmbeddingベクトルに変換するプロバイダー: OpenAI（`text-embedding-3`）または Google（`text-embedding-004`）。EmbeddingプロバイダーがQdrantコレクションに必要なベクトル次元を決定するため、変更時は対応するコレクションと再インデックスが必要です。

### Rerank AI

リランクに使うプロバイダー。無料版ではCohereのみ対応。Cohere APIキーが必要です（Advanced タブの Reranker Settings も参照）。

## Sync タブ

インデックスの仕組み全体は[同期システム](sync.md)を参照してください。

### Content to Sync

#### Sync Targets

公開投稿タイプごとに1エントリのアコーディオンです（attachmentは除外）。各投稿タイプで以下を設定します。

- アコーディオン見出しの **有効化チェックボックス** — 投稿タイプを同期対象に含めます（postとpageはデフォルトで有効）。
- **Include in Chunk Data** — インデックスに含めるフィールド: Post Title、Post Content、Post Date、Post Author、および各公開タクソノミー。
- **タクソノミー設定** — タクソノミーごとに _Include only specified term IDs_（指定term IDのみ含める）か _Exclude specified term IDs_（指定term IDを除外）を選び、カンマ区切りのterm IDを入力します。空欄の場合は全termが対象です。

「Include in Chunk Data」の項目が1つもチェックされていない投稿タイプは、同期時にスキップされます。

#### Only Sync Specific Posts

カンマ区切りの投稿ID。設定するとこれらの投稿のみが同期され、投稿タイプのルールは無視されます（各投稿タイプのメタデータ設定自体は適用されます）。

#### Exclude Specific Posts

カンマ区切りの投稿ID。投稿タイプのルールに合致してもスキップされます。

#### Data Storage

チャンクとベクトルの保存先をチェックボックスで選択します。

- **WordPress database** — `{prefix}fe_search_ai_vectors` と `{prefix}fe_search_ai_keyword_index` にBM25キーワードインデックスを構築。外部サービス不要。
- **Qdrant (external vector database)** — ベクトルによる意味検索。AdvancedタブのQdrant接続設定が必要です。

#### Hybrid Search

有効（デフォルト）の場合、WordPressキーワードインデックスとQdrantベクトル検索を組み合わせ、Reciprocal Rank Fusionで融合します。**両方**のストレージが必要です。

#### Embeddings from Summaries

有効（デフォルト）の場合、各チャンクをまずチャットプロバイダーで構造化サマリー（トピック・事実・エンティティ・キーワード）に変換し、生テキストではなくサマリーをベクトル化します。幅広い質問へのマッチングが改善する傾向がありますが、同期時のAPIコールが増えます。

#### Sync Limit

同期する投稿数の上限。最新の投稿から数えます。デフォルト `100`、`-1` で全対象投稿を同期します。お試し同期やコスト抑制に使います。

#### Batch Size

1回のAJAXバッチで処理する投稿数。デフォルト `10`、範囲1–100。共有サーバーでは小さい値でタイムアウトを避け、速く終わらせたい場合は大きくします。

### Synchronization

インデックスの状態と同期ボタン — [同期システム](sync.md)を参照してください。

## Prompts タブ

| フィールド             | 説明                                                                                                                                   |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| **Site Name (AI)**     | AIが認識するサイト名。`{site_name}` プレースホルダーに挿入されます。空欄ならサイトタイトル。                                           |
| **Site Purpose (AI)**  | サイトが提供する内容とユーザーの目的。`{site_purpose}` プレースホルダーに挿入されます。空欄ならキャッチフレーズ。                      |
| **Base System Prompt** | チャットモデルに送る指示文全体。空欄なら標準プロンプト。`{site_name}`、`{site_purpose}` などのプレースホルダーが実行時に展開されます。 |
| **Structured Output**  | 対応するプロバイダーでJSON構造化出力を要求。非対応なら通常テキストにフォールバック。デフォルトはオフ。                                 |

## Display タブ

### Floating Mode Settings

| フィールド                             | 説明                                                                                                      |
| -------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| **Enable floating chat**               | サイト全体にチャットバブルを表示（デフォルト: オン）。                                                    |
| **Login status**                       | ログインユーザー／非ログインユーザーへの表示（両方デフォルト: オン）。                                    |
| **Display device**                     | PC／モバイルでの表示（両方デフォルト: オン）。                                                            |
| **Conditions for Displaying the Chat** | 表示するページ種別: Home、Archive、Search result page、404 page、Single pages（すべてデフォルト: オン）。 |
| **Display only with these post IDs**   | カンマ区切りID。入力すると他の表示ルールは無視されます。                                                  |
| **Do not display with these post IDs** | 除外するカンマ区切りID。                                                                                  |

### Embed Mode

手動設置用の `[fe-search-ai]` ショートコードを表示します。[検索UIの設置](search.md)を参照してください。

### Chat UI Appearance

**Text & Colors:**

| フィールド                   | デフォルト                                            |
| ---------------------------- | ----------------------------------------------------- |
| Chat window title            | `FE Search AI`                                        |
| First greeting               | `Hello! I am FE Search AI. How can I help you today?` |
| Input field placeholder      | `Ask a question about this site…`                     |
| Submit button text           | `Send`                                                |
| Bubble / Send Button Color   | `#E9E9E9`                                             |
| グラデーション（任意）       | 開始 `#00AFFE`、終了 `#973CFF`、角度 `135`°           |
| Animate the bubble           | オン。グラデーションをゆっくりアニメーションします    |
| Chat window background color | `#FFFFFF`                                             |
| Base text color              | `#333333`                                             |

**Interaction:**

- **Typing Animation Speed** — スライダー1（Smooth）〜10（Fast）。デフォルト `7`。
- **Send Key Settings (Default)** — 送信キーを `Enter`、`Shift+Enter`、`Cmd/Ctrl+Enter` から選択。訪問者はチャットの設定メニューで上書きできます。

**Footer Notice:** チャットウィンドウ下部、設定アイコン横に表示する文言。空欄ならデフォルトのAI注意書き。

## Privacy タブ

- **Current Data Handling** — 現在有効な送信先（どのサービスが訪問者入力を受け取るか）、各レコード種別のサーバー保持期間、診断ログの有効状態を一覧表示します。
- **Legal Documents** — チャットのプライバシー通知に表示する利用規約・プライバシーポリシーページ。プライバシーページ未選択の場合、WordPress標準のプライバシー設定のページが使われます。

データ処理の全体像は[プライバシーとデータの取扱い](privacy.md)を参照してください。

## Advanced settings タブ

### Reranker Settings

Providers タブでのCohere APIキー設定が前提です。

| フィールド                         | デフォルト | 範囲  | 説明                                                                   |
| ---------------------------------- | ---------- | ----- | ---------------------------------------------------------------------- |
| Enable reranker                    | オン       | —     | 取得チャンクをCohereで並べ替えてからLLMに送ります。                    |
| Top N chunks for LLM               | `5`        | 1–20  | リランク上位N件のみをモデルに送信します。                              |
| Initial candidates (vector search) | `50`       | 5–200 | リランク前にQdrantから取得する候補数。                                 |
| Hybrid candidate limit             | `50`       | 5–200 | ハイブリッド検索時のソース別候補数。                                   |
| Rerank timeout (sec)               | `15`       | 1–60  | Cohereリクエストのタイムアウト。タイムアウト時は元の順序を維持します。 |

### Qdrant Settings

| フィールド          | 説明                                                                                                |
| ------------------- | --------------------------------------------------------------------------------------------------- |
| **Qdrant Endpoint** | Qdrant HTTP APIのベースURL（ポート含む）。例: `https://your-instance.qdrant.io:6333`                |
| **Qdrant API Key**  | 暗号化して保存。空欄の場合は保存済みキーを維持します。                                              |
| **Collection Name** | このサイトで使うコレクション名。コレクションのベクトルサイズはEmbeddingモデルと一致させてください。 |

注意: Qdrant Cloudの**無料**クラスターでは、一定期間アクセスがないとコレクションが削除される場合があります。

### Japanese Tokenizer

サイトロケールが `ja` / `ja_JP` の場合のみ表示。キーワード（BM25）インデックスと検索のトークナイズに影響します。ベクトルDBのみを使う場合は使用されません。

- **エンジン**: `Built-in (TinySegmenter)`（デフォルト、PHP 8.0以上が必要）または `Yahoo! Japanese MA API`。PHP 8.0未満ではYahoo! MAが強制され、セレクターは無効化されます。
- **Yahoo! App ID**: 暗号化して保存。`wp-config.php` に `FE_SEARCH_AI_YAHOO_APP_ID` を定義するとそちらが優先されます。

### Advanced Settings

| フィールド                         | 説明                                                                                                                                                                            |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Load default plugin CSS**        | デフォルト: オン。チャットUIをテーマのCSSだけで構成する場合はオフにします。                                                                                                     |
| **Load default plugin JavaScript** | デフォルト: オン。チャットUIのHTMLを独自実装に置き換えAPI通信も自前で処理する場合のみオフにします。                                                                             |
| **Debug Mode**                     | `{prefix}fe_search_ai_system_logs` に動作ログを記録します。トラブルシューティング時のみ有効化してください（パフォーマンスに影響します）。                                       |
| **Log Retention (days)**           | システムログを日次ローテーションで削除するまでの日数。デフォルト `30`、範囲1–365。                                                                                              |
| **Retrieval Trace Persistence**    | 検索品質分析用にretrieval trace（クエリのハッシュ、投稿ID、ランキングスコア。質問・回答本文は含まない）を保存します。デフォルトはオフ。保持期間も設定可（デフォルト `30` 日）。 |

### Data Management

破壊的なメンテナンス操作。各操作は明示的なボタンで実行します。

- **Delete Synced Data** — 全ベクトルとキーワードインデックスを削除。再同期するまでAI検索は動作しません。
- **Delete System Logs** — `{prefix}fe_search_ai_system_logs` を空にします。
- **Delete Retrieval Traces** — `{prefix}fe_search_ai_retrieval_traces` と `{prefix}fe_search_ai_retrieval_trace_items` を空にします。

### Delete Data on Uninstall

有効にすると、アンインストール時にプラグインの全テーブルと設定を削除します。オフのままなら、再インストール時に以前の設定と同期データが残ります。

## 暗号化

すべてのAPIキーとYahoo! App IDは `FE_Search_AI_Encryption_Helper` で暗号化されて保存され、リクエスト時のみ復号されます。
