# AJMCP WordPress Connector

WordPress plugin **v0.1.2** for secure communication with [AJMCP Server](https://github.com/ssnanda/ajmcp-server), including optional, off-by-default automatic replies to **AJCore Live Chat**.

The plugin runs on the WordPress server, not in a visitor's browser. The AJMCP Server keeps OpenAI/Anthropic API keys, models and per-site system prompts. This connector only needs its own site bearer token.

## What works in v0.1.0

- **AJMCP settings** in **Settings → AJMCP**: enable/disable connector, HTTPS gateway URL, site bearer credential, AJCore site UUID, opt-in AI chat toggle, AJCore service-user ID, and **Test Server Connection**.
- **Per-site authentication** via the gateway's existing `GET /v1/capabilities` and `POST /v1/ajcore/reply` endpoints. Credentials are never exposed in browser JavaScript.
- **AJCore visitor chat integration** without modifying AJCore code: observe the successful `POST /ajcore/v1/chat/sessions/{id}/messages` REST result, enqueue a WP-Cron event, retrieve the current chat from AJCore's portal DB, and ask AJMCP for a reply.
- **AJCore delivery** through the existing protected `POST /ops/chat/sessions/{id}/auto-reply` route using a dedicated local WordPress user with AJCore OPS permission. This preserves AJCore's chat history and AJOps real-time WebSocket fanout.
- **Human priority**: skip AI when a conversation is closed, claimed by staff, automation-muted, from another website, or has a newer chat message; check again after the AI network call to stop stale replies.
- **Retry/deduplication**: bounded retries, short-lived per-message locks, and 7-day completed-message deduplication keys.

## Installation

1. Download the `ajmcp-wp-0.1.0.zip` WordPress installation package. In WordPress go to **Plugins → Add New → Upload Plugin**, then activate.
2. In your AJMCP Server's `SITES_JSON` or `sites.json`, register a **unique site ID and random 32+ character site token**, plus its desired AI provider, model, and system instructions. Never reuse your administrator token for WordPress.
3. In WordPress go to **Settings → AJMCP** and enter the **HTTPS URL** of the OpenShift Route (for example, `https://mcp.example.com`) and the **site token**. Alternatively, define the constant `AJMCP_WP_SITE_TOKEN` in `wp-config.php` to keep the token out of the WordPress options database.
4. Click **Save AJMCP Settings** and **Test Server Connection**. A successful connection does not enable AI chat.
5. Ensure AJCore Live Chat is configured and confirm the **AJCore site UUID** matches this WordPress site. For autonomous replies, create a dedicated WordPress service user with AJCore OPS write permissions and enter its user ID.
6. Enable **AI auto-replies for AJCore Live Chat**, then test on a staging website before enabling it on production.
7. Configure **real WP-Cron** on the WordPress host (e.g. run `wp cron event run --due-now` once per minute from a system scheduler) for more reliable processing; normal WP-Cron runs only when WordPress receives requests.

## Architecture

Visitor → AJCore widget / AJOps → AJCore REST visitor message persisted → AJMCP WordPress plugin observes response → WP-Cron job → AJMCP Server `/v1/ajcore/reply` → WordPress invokes AJCore `auto-reply` API → AJCore publishes reply back to AJOps WebSocket → visitor.

AJMCP does **not** write directly to AJCore tables to create replies and does **not** send visitor messages to CLI agents. Only the OpenAI/Anthropic model API route is used for customer chat.

## Important v0.1 limitations

- **AJCore master only for chat events:** AJCore REST chat messages are received at the master API host. Install/activate the connector on that WordPress instance. A connector installed only on a non-master participant website won't see those REST calls. This connector only replies for a matching local `site_uuid`, preventing cross-site leakage. Multi-site master routing with individual credentials for many site UUIDs is a future feature.
- Existing **AJOps rule-based replies** may be sent first. This connector deliberately skips chat messages when AJOps has already replied. For a consistent AI-first experience, configure or disable overlapping AJOps reply rules.
- Replies are queued through **WordPress cron** and can be delayed on low-traffic sites. Real cron or a future durable queue is recommended for production.
- There is a small race between the final human-takeover check and the AJCore write; for strict handoff enforcement, an atomic eligibility check must be implemented inside AJCore's auto-reply handler.
- No live deployment, hosting, server keys, or provider billing are included. Website chat content is sent to the selected external model API through AJMCP; ensure your privacy notice and retention policies cover this.
- Server's existing `/mcp` endpoint is for third-party MCP clients; the WordPress plugin uses the scoped HTTPS REST gateway instead.

## Security

The default configuration leaves the plugin and AI replies **disabled**. Enforce TLS for server URL; site token stays server-side and has no agent execution privileges. Never expose the AJMCP administrator token to the WordPress plugin. The plugin does not provide public REST endpoints of its own, and no visitor can select the provider, model, URL, or command. Limit the configured WordPress service user's privileges to what is needed for AJCore automated chat replies.

If you suspect the site token has leaked, rotate it on AJMCP Server and reconfigure WordPress. Do not commit secrets to GitHub or container images.

## Development

Run local PHP syntax checks and integration-contract tests:

- `php -l ajmcp-wp.php` and `php -l includes/*.php` (lint each file separately)
- `php tests/run.php`

GitHub Actions runs these checks and produces an installable plugin ZIP artifact.

## Compatibility

- WordPress 6.3+ and PHP 8.0+
- AJMCP Server v0.1.0 API
- Current AJCore REST API with `aj_portal_chat_sessions`, `aj_portal_chat_messages`, `automation_muted`, and the protected `auto-reply` route

**Status:** Source, PHP syntax and mocked contract tests are validated. End-to-end browser chat, site-specific WordPress deployment and OpenShift connectivity have **not yet** been tested.