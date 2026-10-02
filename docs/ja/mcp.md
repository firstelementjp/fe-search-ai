# MCP連携

FE Search AI Proは、LM Studio、Cursor、Windsurf、Claude Desktopなどの外部AIエージェントがWordPressサイトのコンテンツをツールとして検索できるMCP（Model Context Protocol）サーバーを提供します。

> **Pro機能です。** FE Search AI Proと有効なライセンスが必要です。[ライセンスとPro](license.md)を参照してください。

## 概要

プラグインはMCP互換のHTTPエンドポイントを公開します。

```text
/wp-json/fesai/v1/mcp
```

このエンドポイントは **MCP Streamable HTTP transport** を実装しており、新旧両方のプロトコルをサポートします。従来型クライアント（initializeハンドシェイク、プロトコルバージョン 2025-06-18 / 2025-11-25）と、新しいクライアント（リクエストごとの `_meta` メタデータ、プロトコルバージョン 2026-07-28以降）の両方に対応します。Bearerトークン認証が必要です。`GET`/`DELETE` リクエストは `405` を返し、JSON-RPCトラフィックは `POST` のみが担います。

MCPクライアントはこのエンドポイント経由で **`site_search`** ツールを検出・呼び出せます。インデックス済みコンテンツに対してセマンティック検索を実行し、関連チャンクとURLを返します。

## 1. APIトークンの生成

1. WordPress管理画面で **FE Search AI → 設定 → 詳細設定** を開きます。Proが有効な場合、タブ下部に **API Token Management** セクションが表示されます。
2. **Generate New Token** をクリックします。
3. トークンに分かりやすい名前を付けます（例: 「LM Studio」）。
4. 生成されたトークン（`fesai_tk_...` 形式）をコピーします。

トークンは同じセクションからいつでも失効できます。公開しないでください。

## 2. MCPクライアントの設定

詳細設定タブの **MCP Integration** セクションに、そのサイト用の接続JSONが表示されます。

### Streamable HTTP対応クライアント（LM Studio、Cursor、Windsurfなど）

クライアントのMCPサーバー設定に貼り付け、`<YOUR_TOKEN>` をAPIトークンに置き換えます。

```json
{
	"mcpServers": {
		"example.com": {
			"type": "http",
			"url": "https://example.com/wp-json/fesai/v1/mcp",
			"headers": {
				"Authorization": "Bearer <YOUR_TOKEN>"
			}
		}
	}
}
```

### Claude Desktop（mcp-remoteブリッジ）

`claude_desktop_config.json` はstdioサーバーのみ受け付けるため、Proの設定画面では `mcp-remote` npmパッケージをstdio→HTTPブリッジとして使う設定も生成されます。`~/Library/Application Support/Claude/claude_desktop_config.json` に貼り付けてください。

```json
{
	"mcpServers": {
		"example.com": {
			"command": "npx",
			"args": [
				"-y",
				"mcp-remote",
				"https://example.com/wp-json/fesai/v1/mcp",
				"--header",
				"Authorization:${AUTH_HEADER}"
			],
			"env": {
				"AUTH_HEADER": "Bearer <YOUR_TOKEN>",
				"NODE_TLS_REJECT_UNAUTHORIZED": "0"
			}
		}
	}
}
```

`NODE_TLS_REJECT_UNAUTHORIZED=0` は自己署名証明書を使うローカル開発向けです。有効なTLS証明書のある本番サイトでは削除してください。

## 3. 接続テスト

1. クライアントでMCPサーバーを有効化します。
2. 新しいチャットを開始し、サイト固有の情報を必要とする質問をします。
3. エージェントが自動的に `site_search` ツールを呼び出し、サイトから取得したコンテンツで回答すれば成功です。

## 利用可能なツール

### site_search

WordPressサイト内のコンテンツを検索します。クライアントに送られるツール説明には、設定したAI向けサイト名・サイトの目的が含まれます（[プロンプト](config.md)参照）。エージェントがいつツールを呼ぶか判断する材料になります。

| パラメータ | 型     | 必須 | 説明                                             |
| ---------- | ------ | ---- | ------------------------------------------------ |
| `query`    | string | はい | ユーザーの質問に基づく、具体的で簡潔な検索クエリ |

```json
{
	"name": "site_search",
	"arguments": {
		"query": "backend engineer job openings"
	}
}
```

クライアントはエンドポイントに `tools/list` を呼ぶことでツール一覧を取得できます。

## 関連: `/query` RESTエンドポイント

同じAPIトークンは `POST /wp-json/fesai/v1/query` の認証にも使えます。MCPを話さないヘッドレスサイトや外部アプリケーション向けの、非ストリーミングのQ&Aエンドポイントです。

```bash
curl -X POST "https://example.com/wp-json/fesai/v1/query" \
  -H "Authorization: Bearer fesai_tk_..." \
  -H "Content-Type: application/json" \
  -d '{
    "question": "返金ポリシーを教えてください",
    "history": []
  }'
```

| パラメータ | 型                      | 必須   | 説明                                                                                           |
| ---------- | ----------------------- | ------ | ---------------------------------------------------------------------------------------------- |
| `question` | string                  | はい   | ユーザーの質問。検索前にPIIマスキング（`fe_search_ai_preprocess_user_question`）が適用されます |
| `history`  | array または JSON文字列 | いいえ | 会話履歴。共有の `sanitize_chat_history()` パイプラインでサニタイズされます                    |

レスポンス:

```json
{
	"answer": "...",
	"meta": { "context_found": true }
}
```

無料版の `/stream` エンドポイントと異なり、`/query` は完全な回答を単一のJSONレスポンスで返し（SSEなし）、設定済みのチャットプロバイダーを使い、フロントエンドのnonceではなくBearerトークンを要求します。

## プライバシー

MCPの検索クエリにも、チャットUIと同じPIIマスキングと禁止ワード前処理（`fe_search_ai_preprocess_user_question`）が適用されます。クエリテキストはサーバー側に保存されず、[プライバシーとデータの取扱い](privacy.md)に記載の診断設定に基づく運用メタデータのみ保持される場合があります。

## セキュリティ上の注意

- エンドポイントはBearerトークン認証が必須です（無効・未指定は `401`）。
- トークンはWordPress管理画面からいつでも失効できます。クライアントを識別できるよう説明的な名前を付けてください。
- WP Cerber等のセキュリティプラグインを使っている場合、RESTエンドポイントをホワイトリストに登録しないと `403` でブロックされることがあります。

## トラブルシューティング

### 403 Forbidden

- `Authorization` ヘッダーに有効なBearerトークンが付いているか確認してください。
- トークンが失効していないか確認してください。
- セキュリティプラグイン・ファイアウォールで `/wp-json/fesai/v1/mcp` をホワイトリスト登録してください。

### 401 Unauthorized

- トークンが無効または未指定です。`fesai_tk_...` 形式であること、および **API Token Management** にトークンが存在することを確認してください。
- 必要に応じてトークンを再生成してください。

### 検索結果が返らない

- サイトが同期済みか確認してください（**FE Search AI → 同期**）。
- 対象コンテンツが公開済み（下書き・非公開でない）か確認してください。
- クエリをより具体的にしてください。
