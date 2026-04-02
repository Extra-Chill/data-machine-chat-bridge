# Data Machine Chat Bridge

External chat bridge support for Data Machine.

This plugin provides the WordPress side of the Beeper / Matrix bridge flow:

- browser-based PKCE agent authorization
- auth code exchange for bearer tokens
- bridge registration
- outbound message queueing
- poll and webhook delivery

## REST contract

All authenticated endpoints require a Data Machine agent bearer token.

### `POST /wp-json/chat-bridge/v1/register`

Registers or refreshes a bridge callback URL for the authenticated agent.

Request:

```json
{
  "callback_url": "https://bridge.example.com/callback",
  "bridge_id": "mautrix-datamachine"
}
```

Response:

```json
{
  "success": true,
  "registration_id": "uuid",
  "agent_id": 7,
  "bridge_id": "mautrix-datamachine",
  "callback_url": "https://bridge.example.com/callback",
  "poll_endpoint": "https://example.com/wp-json/chat-bridge/v1/pending"
}
```

### `GET /wp-json/chat-bridge/v1/pending`

Returns pending outbound messages for the authenticated agent.

Query params:

- `limit` — optional integer, default `50`
- `session_ids` — optional array or comma-separated list used to scope polling to known chat sessions

Response:

```json
{
  "success": true,
  "messages": [
    {
      "queue_id": "uuid",
      "session_id": "session-123",
      "agent_id": 7,
      "user_id": 38,
      "role": "assistant",
      "content": "Hello from Roadie",
      "completed": true,
      "timestamp": "2026-04-02T21:00:00Z",
      "metadata": {
        "turn_number": 1,
        "max_turns": 10
      },
      "created_at": "2026-04-02 21:00:00"
    }
  ],
  "count": 1
}
```

### `POST /wp-json/chat-bridge/v1/ack`

Acknowledges delivered queue items for the authenticated agent.

Canonical request:

```json
{
  "message_ids": ["uuid-1", "uuid-2"]
}
```

Legacy compatibility is kept for:

```json
{
  "ids": ["uuid-1", "uuid-2"]
}
```

Response:

```json
{
  "success": true,
  "acknowledged": 2
}
```

### `GET /wp-json/chat-bridge/v1/identity`

Returns metadata for the authenticated agent token.

Response:

```json
{
  "success": true,
  "data": {
    "agent_id": 7,
    "agent_slug": "roadie",
    "agent_name": "Roadie",
    "status": "active",
    "site_url": "https://studio.extrachill.com",
    "site_name": "Extra Chill Studio",
    "site_host": "studio.extrachill.com"
  },
  "agent_id": 7,
  "agent_slug": "roadie",
  "agent_name": "Roadie",
  "status": "active",
  "site_url": "https://studio.extrachill.com",
  "site_name": "Extra Chill Studio",
  "site_host": "studio.extrachill.com"
}
```

### `POST /wp-json/chat-bridge/v1/token`

Exchanges a PKCE authorization code for a new agent bearer token.

Request:

```json
{
  "code": "auth-code",
  "code_verifier": "verifier",
  "redirect_uri": "https://bridge.example.com/callback"
}
```

Response:

```json
{
  "success": true,
  "access_token": "datamachine_roadie_...",
  "token_type": "Bearer",
  "token_id": 123,
  "token_label": "beeper-roadie",
  "agent_id": 7,
  "agent_slug": "roadie",
  "agent_name": "Roadie",
  "site_url": "https://studio.extrachill.com",
  "site_host": "studio.extrachill.com"
}
```

## Delivery semantics

- Outbound messages are always inserted into the pending queue first.
- Webhook delivery is best-effort and does **not** remove a queue item.
- The bridge client must call `/ack` after it has accepted the message.
- Polling remains the canonical recovery path if webhook delivery fails.
