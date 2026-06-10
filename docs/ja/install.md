# インストール

## 動作要件

- WordPress 6.6以上
- PHP 7.4以上
- MySQL 5.7以上
- OpenSSL拡張
- 対応AIプロバイダーのAPIキー
- Qdrant CloudまたはセルフホストQdrant

## WordPress.orgからインストール

1. WordPress管理画面で **プラグイン → 新規追加** を開きます。
2. **FE Search AI** を検索します。
3. **今すぐインストール** をクリックします。
4. プラグインを有効化します。
5. 管理メニューの **FE Search AI** を開きます。

## 手動インストール

1. GitHubから最新リリースZIPをダウンロードします。
2. **プラグイン → 新規追加 → プラグインのアップロード** を開きます。
3. ZIPファイルをアップロードします。
4. プラグインを有効化します。

[最新リリースをダウンロード](https://github.com/firstelementjp/fe-search-ai/releases)

## 有効化後に行うこと

最初に以下の項目を設定してください。

- チャットプロバイダーのAPIキー
- EmbeddingプロバイダーのAPIキー
- QdrantのエンドポイントとAPIキー
- 同期対象の投稿タイプ
- チャットUIの表示方法

## Proアドオン

FE Search AI Proは別プラグインとしてインストールします。まず無料版をインストールして有効化し、必要に応じてPro版を追加してください。
