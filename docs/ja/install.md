# インストール

## 動作要件

- WordPress 6.6以上
- PHP 7.4以上
- MySQL 5.7以上
- OpenSSL拡張
- 対応AIプロバイダーのAPIキー
- Qdrant CloudまたはセルフホストQdrant

## GitHub Releasesからインストール

1. [GitHub Releasesページ](https://github.com/firstelementjp/fe-search-ai/releases) から最新の安定版ZIPをダウンロードします。
2. WordPress管理画面で **プラグイン → 新規追加 → プラグインのアップロード** を開きます。
3. ZIPファイルをアップロードします。
4. プラグインを有効化します。
5. 管理メニューの **FE Search AI** を開きます。

バージョン1.0.0では、WordPress.org外で配布されるインストール向けにGitHub Releasesベースの更新確認に対応しています。

## WordPress.orgからインストール

WordPress.org Plugin Directoryで公開された後は、**プラグイン → 新規追加** で **FE Search AI** を検索してインストールできます。

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
