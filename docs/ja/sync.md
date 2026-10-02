# 同期システム

同期システムは、FE Search AI が使う検索インデックスを構築・維持します。

## 同期で行われること

同期では、選択したWordPressの投稿・固定ページ・カスタム投稿タイプからコンテンツを抽出します。各投稿について以下を実行します。

1. コンテンツをチャンクに分割（デフォルト約1,000文字。`fe_search_ai_chunk_size` で調整可）。
2. 任意でチャンクごとの構造化サマリー（トピック・事実・エンティティ・キーワード）をチャットプロバイダーで生成。
3. 選択したEmbeddingプロバイダーでベクトルを生成し、Qdrantに保存。
4. `{prefix}fe_search_ai_keyword_index` テーブルにBM25ランキング用のキーワードインデックスを構築。

## 同期モード

### Sync Changes（スマート同期）

前回完了した同期以降に変更されたコンテンツのみを処理します。具体的には:

- 公開状態でなくなった投稿のインデックス行を削除します。
- `post_modified_gmt` が前回同期タイムスタンプより新しい投稿をインデックスします。
- 前回同期以降に同期設定が変わっている場合は実行を拒否し、**Rebuild Index** の実行を促します。

スマート同期には基準（前回同期タイムスタンプ）が必要です。一度も同期が完了していない場合は、先に **Rebuild Index** を1回実行するよう案内されます。

### Rebuild Index（全同期）

`{prefix}fe_search_ai_vectors` と `{prefix}fe_search_ai_keyword_index` を空にしてから、対象となる全投稿を一からインデックスし直します。次のタイミングで使います。

- 初回セットアップ時
- 同期対象、トークナイザー、Embeddingプロバイダーなどインデックスに影響する設定を変更した後
- インデックス健全性の指標に異常がある場合（後述）

### リアルタイム同期

有効化された投稿タイプの投稿が公開・更新されると、`save_post` 経由で自動的に再インデックスされます。投稿のゴミ箱移動・削除（`wp_trash_post`、`delete_post`）ではインデックスから削除されます。`publish` ステータスのみが対象で、自動保存とリビジョンはスキップされます。投稿の言語は Polylang、WPML、Bogo があればそこから検出し、なければサイトロケールにフォールバックします（`fe_search_ai_post_language_code` フィルター）。

全同期とリアルタイム同期のタイムスタンプは別々に記録され、**Last Bulk Sync** と **Last Realtime Sync** として表示されます。

## インデックス健全性の指標

Sync画面にはインデックスの状態が表示されます。

| 指標                            | 意味                                                                                                            |
| ------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| **Indexed Posts**               | インデックスに存在するユニークな投稿数。                                                                        |
| **Vectors**                     | `{prefix}fe_search_ai_vectors` のチャンク行数。1投稿は通常複数チャンクになります。                              |
| **Keyword Token Count Missing** | `keyword_token_count` が `0` のチャンク行数。非ゼロならそのチャンクではBM25キーワードマッチが機能していません。 |
| **Average Keyword Tokens**      | チャンクあたりの平均トークン数（0の行を除く）。ゼロに近い場合はトークナイズが機能していない可能性があります。   |
| **Keyword Index Rows**          | `{prefix}fe_search_ai_keyword_index` の総行数（term頻度）。                                                     |

**Keyword Token Count Missing** が非ゼロなのに **Keyword Index Rows** に行がある、またはキーワード検索結果にBM25スコアが付かない場合は、**Rebuild Index** を実行してメタデータを再構築してください。

## バッチ処理と中断

同期はAJAXバッチ（1リクエストあたりBatch Size件）の逐次処理で、プログレスバーが表示されます。最終同期タイムスタンプと同期設定ハッシュは**全バッチ完了後**にのみ更新されます。

同期が中断した場合（ブラウザを閉じた、タイムアウト、サーバーエラー）:

- **Rebuild Index** は開始時にインデックスを空にするため、もう一度 Rebuild Index を実行する必要があります。**Sync Changes** を再実行しても前回完了した同期以降に変更された投稿しか処理されず、インデックスは不完全なままです。
- 中断した **Sync Changes** はそのまま再実行できます。処理済みの投稿は既にインデックスされており、再処理しても問題ありません（投稿単位で冪等。再インデックス前に既存行は削除されます）。

同期が繰り返し止まる場合は Batch Size を下げ、PHP の `memory_limit` / `max_execution_time` を確認してください。

## Retrieval trace

すべてのチャットリクエストで _retrieval trace_（どのチャンクがどのスコアで取得されたかのコンパクトな記録）が生成されます。検索品質の分析に使われ、質問・回答の本文は一切含まれません。

トレースの内容:

- `trace_id`、`sequence_id` — 識別子
- `query_hash`、`query_length` — 質問のSHA-256と文字数（本文そのものではない）
- `pipeline` — どのステージが寄与したか: `bm25`、`qdrant`、`hybrid`、`cohere`
- `metadata.source_counts` — 最終結果が `qdrant`、`keyword`、`both`、`unknown` のどのソース由来かの内訳
- `items` — チャンクごとの `final_rank`、`post_id`、タイトル、チャンク/パーマリンクハッシュ、スコア（`bm25_score`、`qdrant_score`、`hybrid_score`、`cohere_relevance_score`）とランク（`bm25_rank`、`qdrant_rank`、`hybrid_rank`、`cohere_rank`）

### トレースの確認方法

- **Debug Mode** を有効にすると、`{prefix}fe_search_ai_system_logs` に `Retrieval trace scores captured.` というエントリで記録されます。
- **Retrieval Trace Persistence**（Advanced settings タブ）を有効にすると、`{prefix}fe_search_ai_retrieval_traces` と `{prefix}fe_search_ai_retrieval_trace_items` に保存されます。設定した保持期間（デフォルト30日）を過ぎた分は日次ローテーションで削除され、**Delete Retrieval Traces** で一括削除、アンインストール時削除の設定にも従います。

簡易ヘルスチェック: 最終トレースアイテムに `bm25_score` が無いのにキーワードインデックスに行がある場合、チャンクのトークン数がゼロになっている可能性が高いので **Rebuild Index** を実行してください。

関連フィルター: `fe_search_ai_retrieval_trace_payload`、`fe_search_ai_enable_retrieval_trace_persistence`、`fe_search_ai_retrieval_trace_retention_days`。

## 日本語コンテンツ

日本語サイトではトークナイズがBM25キーワードマッチングに影響します。TinySegmenter（PHP 8.0以上）は外部API不要で使えます。より高精度なトークナイズが必要な場合は Yahoo! JAPAN 日本語形態素解析API を設定できます。トークナイザーを変更した場合は **Rebuild Index** が必要です。

## 同期のトラブルシューティング

- APIキーが有効か確認（Providers タブの **Test** ボタン）。
- Qdrantのエンドポイント、APIキー、コレクション、およびコレクションのベクトルサイズがEmbeddingモデルと一致しているか確認。
- 低スペックのホスティングでは Batch Size を下げる。
- PHP の memory limit と max execution time を確認。
- Debug Mode を有効にして `{prefix}fe_search_ai_system_logs` を確認するか、`wp-content/debug.log` をチェック。
