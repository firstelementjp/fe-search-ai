# 開発者向けフック

FE Search AIは、WordPressのフィルターとアクションで拡張できるよう設計されています。

## 主な拡張ポイント

- プロバイダー一覧
- プロンプト生成
- コンテキスト取得
- 同期対象の選択
- フロントエンド出力
- APIキー行や設定UI
- Proアドオン連携

## 開発方針

- プレフィックス付きのフック名を使用します。
- UI出力ではエスケープを行います。
- 外部入力はサニタイズします。
- プロバイダーAPIキーはサーバー側で扱います。
- 代表的なコンテンツで変更をテストします。

## 例

### システムプロンプトを変更する

```php
add_filter( 'fe_search_ai_system_prompt', function ( $prompt ) {
    return $prompt . "\nAnswer concisely and cite relevant site content when possible.";
} );
```

### フロントエンド出力を拡張する

```php
add_filter( 'fe_search_ai_chat_ui_html', function ( $html ) {
    return $html;
} );
```

## Pro連携

FE Search AI Proは、無料版にPro専用の実装を含めず、フックを通じて高度な設定や機能を追加します。

## ソースコード

利用可能なフックの正確な一覧は、プラグインのソースコードを確認してください。

[GitHub Repository](https://github.com/firstelementjp/fe-search-ai)
