# FE Search AI ドキュメント

> WordPressにAIによる自然言語検索とチャットUIを追加するプラグイン

FE Search AIは、WordPressサイト内の投稿、固定ページ、カスタム投稿タイプなどをインデックスし、ハイブリッド検索で関連コンテンツを取得したうえで、サイト内容に基づく自然な回答を生成します。

![FE Search AI](../assets/images/img-sns-banner-100.jpg)

## FE Search AIでできること

FE Search AIは、サイト訪問者が既存コンテンツからすばやく正確な回答を得られるようにするためのプラグインです。

- **AIチャット検索**: 訪問者の質問に自然な文章で回答します。
- **RAG構成**: 回答生成前に関連コンテンツを検索し、サイト内容に基づいて回答します。
- **ハイブリッド検索**: ベクトル検索とキーワード検索を組み合わせます。
- **複数AIプロバイダー対応**: OpenAI、Google Gemini、Anthropic Claudeを利用できます。
- **Embedding対応**: OpenAIまたはGoogleのEmbeddingモデルを利用できます。
- **Qdrant連携**: Qdrant CloudまたはセルフホストQdrantを利用できます。
- **日本語対応**: TinySegmenterまたはYahoo! JAPAN 日本語形態素解析APIを利用できます。
- **チャットUIのカスタマイズ**: ショートコードで埋め込み、またはフローティング表示できます。
- **開発者向けフック**: プロバイダー、プロンプト、同期、表示を拡張できます。

## おすすめの読み進め方

- [はじめに](ja/start.md): 基本的なセットアップの流れを確認します。
- [インストール](ja/install.md): 要件とインストール方法を確認します。
- [設定](ja/config.md): プロバイダー、Qdrant、同期、チャットUIを設定します。
- [検索UIの設置](ja/search.md): サイトにチャット検索UIを追加します。
- [同期システム](ja/sync.md): コンテンツインデックスを構築・維持します。
- [トラブルシューティング](ja/help.md): よくある設定・APIエラーを解決します。

## 基本的な流れ

1. FE Search AIをインストールして有効化します。
2. チャット、Embedding、必要に応じてRerank用のAPIキーを設定します。
3. ベクトル保存先としてQdrantを接続します。
4. 同期対象の投稿タイプを選び、初回同期を実行します。
5. ページに `[fe-search-ai]` を追加するか、フローティングチャットを有効化します。
6. 実際の質問でテストし、プロンプトや色、同期設定を調整します。

## 外部サービス

FE Search AIは、サイト管理者が選択した外部サービスと通信します。

| サービス | 用途 |
| --- | --- |
| OpenAI | チャット補完とEmbedding |
| Google | Geminiチャット補完とEmbedding |
| Anthropic | Claudeチャット補完 |
| Qdrant | ベクトル保存と検索 |
| Cohere | 任意のRerank |
| Yahoo! JAPAN 日本語形態素解析API | 任意の日本語トークナイズ |

有効化前に、各サービスの利用規約とプライバシーポリシーを確認してください。

## Pro版

FE Search AIは無料版だけでも動作します。FE Search AI Proを追加すると、業務利用向けの高度な機能を利用できます。

- 高度なモデル選択とモデル別プロンプト
- OpenAI互換カスタムエンドポイント
- カスタムフィールド同期
- フルスクリーンチャットモード
- ユーザー同意と強化されたプライバシー制御
- 外部APIトークン管理
- AIエージェント向けMCPサーバ連携
- 高度なログとエクスポート機能
- 優先サポートと自動更新

[FE Search AI Proについて詳しく見る](https://www.firstelement.co.jp/products/fe-search-ai-plugin/)

## サポート

- [GitHub Repository](https://github.com/firstelementjp/fe-search-ai)
- [GitHub Issues](https://github.com/firstelementjp/fe-search-ai/issues)
- [Documentation](https://firstelementjp.github.io/fe-search-ai/#/ja/)
- [Support](https://www.firstelement.co.jp/contact)
