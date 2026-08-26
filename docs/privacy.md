# Privacy and Data Handling

FE Search AI sends data to services selected by the site administrator. The **Privacy** settings tab shows the currently active recipients and storage settings.

> The text and settings provided by the plugin are general templates, not legal advice. Site administrators must review them for their actual configuration, users, industry, and applicable laws.

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

Diagnostic conversation summaries are separate from analytics. They are disabled by default and require both Debug Mode and the dedicated diagnostic setting. They store operational metadata such as lengths and context status, not question or answer text.

## Consent records

Pro stores an anonymous consent record containing a token hash, consent version, selected purposes, the displayed notice snapshot, and acceptance or revocation timestamps. It does not store IP addresses, User-Agent strings, session IDs, or conversation content in the consent table.

Revoked and obsolete records are deleted automatically after the configured retention period (180 days by default).

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
