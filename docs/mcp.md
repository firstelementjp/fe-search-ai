# MCP Integration

FE Search AI Pro provides an MCP (Model Context Protocol) server that lets external AI agents — LM Studio, Cursor, Windsurf, Claude Desktop, and others — search your WordPress site's content as a tool.

> **Pro feature.** Requires FE Search AI Pro with an active license. See [License and Pro](license.md).

## Overview

The plugin exposes an MCP-compatible HTTP endpoint:

```text
/wp-json/fesai/v1/mcp
```

It implements **MCP Streamable HTTP transport** with dual-era protocol support: legacy clients (initialize handshake, protocol versions 2025-06-18 / 2025-11-25) and modern clients (per-request `_meta` metadata, protocol version 2026-07-28+). The endpoint accepts `GET`, `POST`, and `DELETE`, and requires Bearer token authentication.

Through this endpoint, MCP clients can discover and call the **`site_search`** tool, which performs semantic search over your indexed content and returns relevant chunks with source URLs.

## 1. Generate an API token

1. Open **FE Search AI → Settings → Advanced settings** in WordPress admin. With Pro active, the **API Token Management** section appears at the bottom of the tab.
2. Click **Generate New Token**.
3. Enter a descriptive name for the token (e.g. "LM Studio").
4. Copy the generated token (format: `fesai_tk_...`).

Tokens can be revoked at any time from the same section. Never share them publicly.

## 2. Configure your MCP client

The **MCP Integration** section in the Advanced settings tab shows a ready-to-paste connection JSON for your site.

### Streamable HTTP clients (LM Studio, Cursor, Windsurf, ...)

Paste this into your client's MCP server configuration and replace `<YOUR_TOKEN>` with your API token:

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

### Claude Desktop (mcp-remote bridge)

`claude_desktop_config.json` only accepts stdio servers, so the Pro settings page also generates a bridge configuration that uses the `mcp-remote` npm package as a stdio-to-HTTP bridge. Paste it into `~/Library/Application Support/Claude/claude_desktop_config.json`:

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

`NODE_TLS_REJECT_UNAUTHORIZED=0` is included for local development with self-signed certificates — remove it on production sites with valid TLS.

## 3. Test the connection

1. Enable the MCP server in your client.
2. Start a new chat and ask a question that requires site-specific information.
3. The agent should call the `site_search` tool automatically and answer with content retrieved from your site.

## Available tools

### site_search

Searches the internal content of your WordPress site. The tool description sent to clients includes your configured AI-visible site name and purpose (see [Prompts](config.md)), which helps the agent decide when to call it.

| Parameter | Type   | Required | Description                                                       |
| --------- | ------ | -------- | ----------------------------------------------------------------- |
| `query`   | string | Yes      | A specific and concise search query based on the user's question. |

```json
{
	"name": "site_search",
	"arguments": {
		"query": "backend engineer job openings"
	}
}
```

Clients can discover the tool list by calling `tools/list` on the endpoint.

## Privacy

MCP search queries pass through the same PII masking and forbidden-word preprocessing (`fe_search_ai_preprocess_user_question`) as the chat UI. No query text is persisted server-side; only operational metadata may be retained under the diagnostic settings described in [Privacy and Data Handling](privacy.md).

## Security notes

- The endpoint requires Bearer token authentication (`401` for invalid/missing tokens).
- Revoke tokens from the WordPress admin at any time; use descriptive names so each client is identifiable.
- If you use a security plugin such as WP Cerber, whitelist the REST endpoint or it may block requests with `403`.

## Troubleshooting

### 403 Forbidden

- Verify the `Authorization` header carries a valid Bearer token.
- Check that the token has not been revoked.
- Whitelist `/wp-json/fesai/v1/mcp` in security plugins/firewalls.

### 401 Unauthorized

- The token is invalid or missing. Verify the `fesai_tk_...` format and that the token still exists in **API Token Management**.
- Regenerate the token if necessary.

### No search results

- Confirm the site has been synced (**FE Search AI → Sync**).
- Check that the target content is published (not draft or private).
- Make the query more specific.
