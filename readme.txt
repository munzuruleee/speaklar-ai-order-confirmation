=== Speaklar AI Order Confirmation for WooCommerce ===
Contributors: speaklar
Tags: woocommerce, ai call, order confirmation, voice agent
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Speaklar AI Order Confirmation sends WooCommerce orders to a Speaklar AI voice agent and updates the WooCommerce order after Speaklar posts the call result webhook.

== Installation ==

1. Upload the speaklar-ai-order-confirmation folder or ZIP from Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Open WooCommerce > Speaklar AI.
4. Enter your Speaklar URL and API key.
5. Save settings. The plugin will fetch AI voice agents from Speaklar.
6. Select the AI voice agent that should confirm orders.
7. Copy the generated webhook URL into Speaklar so Speaklar can send call results back to WordPress.

== Speaklar API Contract ==

The default agent list endpoint is:

GET {Speaklar URL}/api/wordpress/agents

The default start-call endpoint is:

POST {Speaklar URL}/api/wordpress/order-confirmation/calls

Both endpoint paths can be changed in the plugin's Advanced API Paths settings.

The plugin sends the API key in both:

Authorization: Bearer {api_key}
X-Speaklar-Api-Key: {api_key}

== Call Result Webhook ==

Speaklar should POST JSON to the generated webhook URL:

{
  "order_id": 123,
  "call_id": "call_abc123",
  "result": "confirmed",
  "summary": "Customer confirmed the order.",
  "transcript": "AI: ... Customer: yes confirm it"
}

Supported result values include confirmed, cancelled, later, no_answer, busy, unreachable, wrong_number, callback, reschedule, and address_change.

The plugin validates the webhook secret from the generated webhook URL. It also supports:

X-Speaklar-Webhook-Secret: {secret}
X-Speaklar-Signature: sha256={hmac_sha256_raw_body}

