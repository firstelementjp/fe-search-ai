# 外部サービスのセットアップ

このページでは、FE Search AIが利用する外部サービスの準備手順を説明します。ベクトル保存先となるQdrantのコレクション作成と、日本語形態素解析に使うYahoo! JAPANのApp ID取得を取り上げます。

チャット・埋め込みプロバイダーのAPIキー（OpenAI、Anthropic、Google、Cohere）については[設定](config.md)を参照してください。

## Qdrant

FE Search AIは埋め込みベクトルをQdrantに保存します。プラグインは**コレクションを自動作成しません**。初回同期の前にコレクションを作成しておき、そのベクトルサイズは使用する埋め込みモデルと一致させる必要があります。

### 1. クラスターの作成（Qdrant Cloud）

1. [Qdrant Cloud](https://cloud.qdrant.io/) にサインインし、無料クラスターを作成します。
2. クラスターの詳細画面を開き、**エンドポイントURL**（例: `https://xxx-xxxx.ap-northeast-1-0.aws.cloud.qdrant.io`）を控えます。
3. クラスターのアクセス管理画面から **APIキー** を作成します。

> **注意:** Qdrant Cloudの無料クラスターは、一定期間アクセスがないとコレクションが削除されることがあります。しばらく使っていなかった後に同期・検索が突然失敗した場合は、まずクラスターを確認してください。

### 2. コレクションの作成

コレクションのベクトルサイズは埋め込みモデルと一致させます。

| 埋め込みプロバイダー | モデル                   | ベクトルサイズ |
| -------------------- | ------------------------ | -------------- |
| OpenAI               | `text-embedding-3-small` | 1536           |
| OpenAI               | `text-embedding-3-large` | 3072           |
| Google               | `text-embedding-004`     | 768            |

距離関数は `Cosine` を指定してください。作成はQdrantのWeb UI（`https://<エンドポイント>:6333/dashboard` を開きAPIキーでサインイン）から行えます。REST APIを使う場合は次の通りです。

```bash
curl -X PUT "https://<エンドポイント>/collections/fe_search_ai" \
  -H "api-key: <APIキー>" \
  -H "Content-Type: application/json" \
  -d '{
    "vectors": {
      "size": 1536,
      "distance": "Cosine"
    }
  }'
```

`fe_search_ai` の部分は任意のコレクション名に変更できます。プラグインの**コレクション名**フィールドには同じ名前を入力してください。

### 3. コレクション情報の確認

コレクションの存在とベクトルサイズが埋め込みモデルと一致しているかは、次のリクエストで確認できます。

```bash
curl "https://<エンドポイント>/collections/fe_search_ai" \
  -H "api-key: <APIキー>"
```

レスポンスには `config.params.vectors.size`、`distance`、`points_count` が含まれます。サイズが埋め込みモデルと一致しない場合は、コレクションを削除して作り直してください。次元数の不一致は同期エラーの原因になります（[トラブルシューティング](help.md)参照）。

### 4. プラグイン側の設定

**設定 → 詳細設定** で次を入力します。

- **Qdrant エンドポイント**: エンドポイントURL（`/collections/...` は含めない）
- **Qdrant API キー**: 作成したAPIキー
- **コレクション名**: 上で作成したコレクション名

## Yahoo! JAPAN日本語形態素解析API

Yahoo! JAPANの日本語形態素解析API（`MAService/V2/parse`）は、詳細設定の日本語トークナイザーで **Yahoo! Japanese MA API** を選択した場合に、日本語キーワードのトークン化に使われます。PHP 8.0未満（7.4系）では組み込みのTinySegmenterが使えないため、必須になります。

### 1. App IDの取得

1. Yahoo! JAPAN IDで[Yahoo!デベロッパーネットワーク](https://developer.yahoo.co.jp/)にサインインし、デベロッパー登録を完了します。
2. **アプリケーションの管理** から新しいアプリケーションを作成します。APIはサーバー間でApp IDを使って呼び出されるため、アプリケーションの種類はクライアントサイド等で構いません。
3. 表示される **Client ID**（アプリケーションID）をコピーします。これが「App ID」として使う値です。

### 2. プラグイン側の設定

いずれかの方法で設定します。

- **設定 → 詳細設定 → 日本語トークナイザー** の **Yahoo! App ID** に Client ID を貼り付ける（暗号化して保存）、または
- `wp-config.php` に定数を定義する（フィールドより優先）:

```php
define( 'FE_SEARCH_AI_YAHOO_APP_ID', 'your-client-id' );
```

トークナイザーを切り替えた後は **インデックスを再構築** を実行し、キーワードインデックスを新しいトークン化で再生成してください。
