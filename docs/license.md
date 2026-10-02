# License and Pro

FE Search AI works fully as a free plugin. FE Search AI Pro is a separate add-on plugin that unlocks advanced features through a license key.

## Installing Pro

1. Install and activate the free FE Search AI plugin.
2. Install the Pro add-on ZIP via **Plugins → Add New → Upload Plugin** and activate it.
3. A **License** tab appears in the FE Search AI settings screen.

## Activating a license

On the **License** tab:

1. Paste your license key into the **Pro License Key** field. Use the **Show** button to reveal the stored key.
2. Click activate — the key is validated against the FirstElement license server (`download.firstelement.co.jp`).

When active, the tab shows the license status, expiration date (with remaining days), and activation count. A **Deactivate** button releases the activation so the key can be moved to another site.

License keys are stored encrypted. License validation sends the site URL and key to the license server — no visitor data is involved. See [Privacy and Data Handling](privacy.md).

## What Pro adds

| Area      | Pro features                                                                                                                                     |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| Providers | Additional providers (DeepSeek, Qwen planned), custom OpenAI-compatible endpoints (local LLMs, BGE embeddings), failover (planned)               |
| Models    | Per-provider model selection, including embedding models                                                                                         |
| Sync      | Custom field indexing, stop-word editing                                                                                                         |
| Prompts   | Per-provider custom prompts                                                                                                                      |
| Display   | Fullscreen chat page, visitor consent for terms/privacy                                                                                          |
| Security  | Blocked-word masking, configurable rate limits and admin notifications                                                                           |
| Advanced  | [MCP server](mcp.md) for AI agents, API token management for external clients (`/query` endpoint), detailed conversation logging with CSV export |

When the license is active, extra **Models** and **Security** tabs appear in the settings screen, and Pro-only fields are injected into the existing tabs.

## Troubleshooting licenses

- **"Pro is not installed or activated"** — license management requires the Pro add-on plugin, not just a key.
- **Activation fails** — check outbound HTTPS access to `download.firstelement.co.jp` and that the key has remaining activations.
- **License shows inactive after server migration** — deactivate and reactivate the key on the new site.

[Get FE Search AI Pro](https://www.firstelement.co.jp/en/products/fe-search-ai-plugin/)
