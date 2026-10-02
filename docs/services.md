# External Service Setup

This page explains how to prepare the external services used by FE Search AI: creating a Qdrant collection for vector storage and obtaining a Yahoo! JAPAN App ID for Japanese morphological analysis.

For chat and embedding provider API keys (OpenAI, Anthropic, Google, Cohere), see [Configuration](config.md).

## Qdrant

FE Search AI stores embedding vectors in Qdrant. The plugin **does not create collections automatically** — the collection must exist before the first sync, and its vector size must match the embedding model you configure.

### 1. Create a cluster (Qdrant Cloud)

1. Sign in to [Qdrant Cloud](https://cloud.qdrant.io/) and create a free cluster.
2. Open the cluster details and note the **endpoint URL** (for example `https://xxx-xxxx.ap-northeast-1-0.aws.cloud.qdrant.io`).
3. Create an **API key** from the cluster's access management screen.

> **Note:** Qdrant Cloud free clusters can delete collections after a period of inactivity. If sync or search suddenly fails after a while, check the cluster first.

### 2. Create a collection

The collection's vector size must match the embedding model:

| Embedding provider | Model                    | Vector size |
| ------------------ | ------------------------ | ----------- |
| OpenAI             | `text-embedding-3-small` | 1536        |
| OpenAI             | `text-embedding-3-large` | 3072        |
| Google             | `text-embedding-004`     | 768         |

Create the collection with `Cosine` distance. You can do this from the Qdrant Web UI (open `https://<your-endpoint>:6333/dashboard` and sign in with the API key), or with the REST API:

```bash
curl -X PUT "https://<your-endpoint>/collections/fe_search_ai" \
  -H "api-key: <your-api-key>" \
  -H "Content-Type: application/json" \
  -d '{
    "vectors": {
      "size": 1536,
      "distance": "Cosine"
    }
  }'
```

Replace `fe_search_ai` with any collection name you like — enter the same name in the plugin's **Collection Name** field.

### 3. Check collection information

To verify the collection exists and that the vector size matches your embedding model:

```bash
curl "https://<your-endpoint>/collections/fe_search_ai" \
  -H "api-key: <your-api-key>"
```

The response shows `config.params.vectors.size`, `distance`, and `points_count`. If the size does not match your embedding model, delete the collection and recreate it — a dimension mismatch causes sync errors (see [Troubleshooting](help.md)).

### 4. Configure the plugin

In **Settings → Advanced settings**, enter:

- **Qdrant Endpoint**: the endpoint URL (without `/collections/...`)
- **Qdrant API Key**: the API key
- **Collection Name**: the collection created above

## Yahoo! JAPAN Japanese MA API

The Yahoo! JAPAN Japanese morphological analysis API (`MAService/V2/parse`) is used for Japanese keyword tokenization when **Yahoo! Japanese MA API** is selected as the tokenizer in Advanced settings. On PHP 7.4 (below 8.0), it is required because the built-in TinySegmenter cannot be used.

### 1. Obtain an App ID

1. Sign in to [Yahoo! JAPAN Developer Network](https://developer.yahoo.co.jp/) with a Yahoo! JAPAN ID and complete developer registration.
2. Open **アプリケーションの管理** (Manage applications) and create a new application. An application type such as **クライアントサイド** is sufficient — the MA API is called server-to-server with the ID.
3. Copy the displayed **Client ID** (アプリケーションID). This is the value used as the "App ID".

### 2. Configure the plugin

Either:

- Paste the Client ID into **Yahoo! App ID** in **Settings → Advanced settings → Japanese Tokenizer** (stored encrypted), or
- Define the constant in `wp-config.php` (takes priority over the field):

```php
define( 'FE_SEARCH_AI_YAHOO_APP_ID', 'your-client-id' );
```

After switching the tokenizer, run **Rebuild Index** so the keyword index is regenerated with the new tokenization.
