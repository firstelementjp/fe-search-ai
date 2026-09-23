# Privacy and Data Handling

FE Search AI sends data to services selected by the site administrator. The **Privacy** settings tab shows the currently active recipients and storage settings.

> The text and settings provided by the plugin are general templates, not legal advice. Site administrators must review them for their actual configuration, users, industry, and applicable laws.

## Zero Data Retention (ZDR) posture

FE Search AI is designed so that the WordPress site itself does not retain visitor conversation content:

- Questions and answers are streamed to the visitor and are **not written to the database** in the default configuration (Free and Pro).
- Server-side records are limited to operational metadata: message lengths, timestamps, hashed identifiers, ranking scores, and error codes. Log payload keys that could carry prompt or response text are stripped before storage.
- Every retained record class has a configurable retention period enforced by a daily cron, an administrator delete action, and is removed on uninstall when **Delete Data on Uninstall** is enabled.
- The same masking and preprocessing pipeline applies to the chat UI, the Pro REST `/query` endpoint, and the Pro MCP endpoint.

ZDR on the **provider side** (for example, Anthropic or OpenAI not storing prompts and completions) is a contractual arrangement between the site operator and the provider. FE Search AI only uses request features that are eligible for such arrangements (the standard Messages / Chat Completions APIs with streaming; no batch, file, or stateful agent APIs), but enabling ZDR with the provider is the operator's responsibility. Some Anthropic models (Covered Models such as Claude Fable 5.1 / 5 and Claude Mythos 5.1 / 5) require 30-day retention and are not ZDR-eligible; choose an eligible model if you rely on a ZDR agreement.

## Data processing map

| Record                  | Where                                             | Contains                                                                  | Default          | Retention                                                                 | Delete                             |
| ----------------------- | ------------------------------------------------- | ------------------------------------------------------------------------- | ---------------- | ------------------------------------------------------------------------- | ---------------------------------- |
| Conversation content    | Not stored server-side                            | —                                                                         | —                | —                                                                         | —                                  |
| Browser session         | `sessionStorage`                                  | session ID, conversation history, feedback log IDs                        | On               | Until tab closes / clear history                                          | Chat menu                          |
| Consent token (Pro)     | `localStorage`                                    | token, version, purposes                                                  | Off              | Until withdrawn or version changes                                        | Chat privacy menu                  |
| System diagnostic logs  | `{prefix}fe_search_ai_system_logs`                | level, message, metadata (no prompt/response text)                        | Off (Debug Mode) | `Log Retention (days)`, default 30                                        | Advanced → Delete System Logs      |
| Retrieval traces        | `{prefix}fe_search_ai_retrieval_traces`, `_items` | SHA-256 of query, length, post IDs, scores                                | Off              | `Retrieval Trace Persistence`, default 30 days                            | Advanced → Delete Retrieval Traces |
| Conversation logs (Pro) | `{prefix}fe_search_ai_logs`                       | lengths and status (diagnostic) or masked text (visitor opt-in analytics) | Off              | Pro Privacy → Conversation log retention, default 7 days                  | Pro → Delete Conversation Logs     |
| Consent records (Pro)   | `{prefix}fe_search_ai_consents`                   | token hash, version, purposes, notice snapshot, timestamps                | Off              | Pro Privacy → consent record retention, default 180 days after revocation | Pro → Delete Consent Records       |
| Rate-limit counters     | transients                                        | HMAC-SHA256 of IP (keyed with site salt), request count                   | On               | 1 hour (per IP), 1 day (global)                                           | Automatic                          |

## External endpoints

| Service                                            | Endpoint                                 | Receives visitor input     | When                                                                     |
| -------------------------------------------------- | ---------------------------------------- | -------------------------- | ------------------------------------------------------------------------ |
| OpenAI                                             | `api.openai.com`                         | Yes                        | Chat / embedding provider selected                                       |
| Google Gemini                                      | `generativelanguage.googleapis.com`      | Yes                        | Chat / embedding provider selected                                       |
| Anthropic Claude                                   | `api.anthropic.com`                      | Yes                        | Chat provider selected                                                   |
| Cohere Rerank                                      | `api.cohere.com`                         | Yes                        | Reranking enabled                                                        |
| Yahoo! JAPAN MA API                                | `jlp.yahooapis.jp`                       | Yes (query tokenization)   | Yahoo MA tokenizer selected                                              |
| Qdrant                                             | operator-configured host                 | Query vector only          | Qdrant vector store enabled                                              |
| GitHub API                                         | `api.github.com`                         | No                         | Update check (can be disabled with `fe_search_ai_enable_github_updates`) |
| DeepSeek / custom OpenAI-compatible endpoint (Pro) | `api.deepseek.com` / operator-configured | Yes                        | Pro chat provider selected                                               |
| FirstElement license server (Pro)                  | `download.firstelement.co.jp`            | No (site URL, license key) | Pro license validation / updates                                         |

## Required processing

To generate a response, the plugin may send the visitor's question, recent conversation history, and selected site context to the configured chat provider. Search processing may also use an embedding provider, Yahoo! JAPAN Japanese MA API, Cohere Rerank, or Qdrant when enabled.

Free displays this processing as a persistent notice without blocking chat use. Pro can require acceptance of the site's Terms of Service before chat use.

## Browser storage

The frontend uses browser storage for:

- a random session identifier;
- conversation history for the current browser session;
- feedback log identifiers;
- a versioned consent token when Pro consent is enabled.

Visitors can clear local chat history from the chat settings menu. Pro visitors can also withdraw consent.

## Optional conversation analytics

Pro administrators can offer a separate, optional service-improvement purpose. It is disabled by default and must not be preselected. Refusing it does not prevent chat use.

When enabled by both the administrator and visitor, only PII- and forbidden-word-masked question and answer text may be stored. The plugin's masking is best-effort and does not replace an appropriate privacy policy or access controls.

## Diagnostic conversation summaries

Diagnostic conversation summaries are separate from analytics. They are disabled by default and require both Debug Mode and the dedicated diagnostic setting. They store operational metadata such as lengths and context status, not question or answer text. They are deleted after the configured conversation log retention period (7 days by default).

## Consent records

Pro stores an anonymous consent record containing a token hash, consent version, selected purposes, the displayed notice snapshot, and acceptance or revocation timestamps. It does not store IP addresses, User-Agent strings, session IDs, or conversation content in the consent table.

Revoked and obsolete records are deleted automatically after the configured retention period (180 days by default). Conversation logs stored under diagnostic or analytics modes follow the separate conversation log retention setting (7 days by default).

## Suggested privacy-policy topics

A site-specific policy should identify:

1. the site operator and contact details;
2. enabled AI, embedding, tokenization, reranking, and vector services;
3. the data sent to each service and the purpose;
4. browser storage and server-side logging;
5. retention and deletion periods;
6. international transfers and processor terms;
7. how visitors can withdraw consent or request deletion;
8. restrictions on entering personal or third-party confidential information.
