# ライセンスと外部サービス

## ライセンス

FE Search AIはGPL-2.0 or laterでライセンスされています。

[GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html)

## 外部サービス

FE Search AIは、サイト管理者が選択した外部サービスと通信します。

| サービス | 送信されるデータ | 用途 |
| --- | --- | --- |
| OpenAI | 質問、選択されたコンテキスト、Embedding対象コンテンツ | チャット補完とEmbedding |
| Google | 質問、選択されたコンテキスト、Embedding対象コンテンツ | Geminiチャット補完とEmbedding |
| Anthropic | 質問と選択されたコンテキスト | Claudeチャット補完 |
| Qdrant | ベクトルデータとメタデータ | ベクトル保存と検索 |
| Cohere | 取得されたコンテンツチャンク | 任意のRerank |
| Yahoo! JAPAN 日本語形態素解析API | トークナイズ対象テキスト | 任意の日本語トークナイズ |

## 管理者の責任

各サービスを有効化する前に、利用規約、プライバシーポリシー、課金、データ保持、地域ごとのコンプライアンス要件を確認してください。

## Pro版のライセンス認証

FE Search AI Proは、ライセンス検証、アクティベーション、停止、更新確認のためにFirstElementのライセンスAPIと通信する場合があります。サイトコンテンツはライセンスサービスには送信されません。
