=== AJMCP WordPress Connector ===
Contributors: ajmcp
Tags: ai, mcp, ajcore, live-chat, openai, claude
Requires at least: 6.3
Requires PHP: 8.0
Stable tag: 0.1.4
License: GPLv2 or later

Connect WordPress and AJCore Live Chat to a private AJMCP Server supporting OpenAI and Claude models.

== Description ==

AJMCP WordPress Connector authenticates with your centrally hosted AJMCP Server and can optionally answer AJCore Live Chat messages. The chat feature is disabled by default, with safeguards for human-agent takeover and site isolation. API keys for OpenAI and Anthropic remain on the central server.

== Installation ==

1. Install and activate the plugin.
2. Open Settings → AJMCP and enter the server HTTPS URL plus this site's token.
3. Save and test the connection.
4. Configure a dedicated AJCore operations service user and site UUID.
5. Opt in to live-chat auto replies only after staging verification.

== Changelog ==

= 0.1.0 =
* Initial server connection, safe AJCore Live Chat integration, permission-aware reply delivery, and bounded background jobs.
