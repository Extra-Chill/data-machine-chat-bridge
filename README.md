# Data Machine Chat Bridge

External chat bridge support for Data Machine.

This plugin is the **WordPress side** of the Beeper / Matrix integration. It does not talk to Beeper directly. Instead, it provides the generic bridge primitives that any external chat client can use:

- browser-based PKCE agent authorization
- auth code exchange for agent bearer tokens
- bridge registration
- outbound message queueing
- poll + webhook delivery
- token/login-scoped routing

## What lives where

```text
Data Machine core
├─ generic agent auth
├─ token runtime context
└─ chat sessions

data-machine-chat-bridge
├─ bridge registration
├─ auth code exchange
├─ queueing / pending / ack
└─ token-scoped delivery

mautrix-data-machine
└─ Beeper / Matrix client and login UX
```

## Why this exists

The chat bridge lets external chat apps talk to Data Machine agents **without** handing raw bearer tokens to non-technical users.

For the Beeper flow, the user signs in through a browser approval screen in WordPress, authorizes an agent like **Roadie**, and the bridge exchanges that approval for a scoped agent token behind the scenes.

## New user flow

If you are a brand new user adding this to Beeper, the experience should look like this:

### 1. Start login in Beeper

In Beeper, you start the Data Machine login flow.

### 2. Open the approval link

The bridge shows a QR code or browser link.

That link opens a WordPress approval screen like:

- `https://studio.extrachill.com/wp-json/datamachine/v1/agent/authorize?...`

### 3. Sign in to WordPress

If you are not already signed in, WordPress asks you to log in normally.

### 4. Approve the agent

You see a consent screen for the agent, for example:

- **Roadie**

You approve it in the browser.

### 5. Bridge receives the callback

WordPress redirects back to the bridge callback URL with a short-lived auth code.

### 6. Bridge exchanges the code

The bridge sends:

- `code`
- `code_verifier`
- `redirect_uri`

to the token endpoint and receives a Data Machine bearer token.

### 7. Beeper room becomes your active chat

The bridge registers itself for that token/login and starts talking to the agent.

By default:

```text
one Beeper room
→ one bridge login
→ one active Data Machine chat session
```

## Token/login scoping

Bridge delivery is scoped by:

```text
agent_id + token_id
```

This matters because multiple people — or multiple devices — can authenticate to the same agent.

Without token scoping, two Beeper logins using the same agent could receive each other's messages.

## REST contract

All authenticated endpoints require a Data Machine agent bearer token.

### `POST /wp-json/chat-bridge/v1/register`

Registers or refreshes a bridge callback URL for the authenticated token login.

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
  "token_id": 123,
  "bridge_id": "mautrix-datamachine",
  "callback_url": "https://bridge.example.com/callback",
  "poll_endpoint": "https://example.com/wp-json/chat-bridge/v1/pending"
}
```

### `GET /wp-json/chat-bridge/v1/pending`

Returns pending outbound messages for the authenticated token login.

Query params:

- `limit` — optional integer, default `50`
- `session_ids` — optional array or comma-separated list used to scope polling to known chat sessions

### `POST /wp-json/chat-bridge/v1/ack`

Acknowledges delivered queue items for the authenticated token login.

Canonical request:

```json
{
  "message_ids": ["uuid-1", "uuid-2"]
}
```

Legacy compatibility is also kept for:

```json
{
  "ids": ["uuid-1", "uuid-2"]
}
```

### `GET /wp-json/chat-bridge/v1/identity`

Returns metadata for the authenticated token, including:

- `agent_id`
- `token_id`
- `agent_slug`
- `agent_name`
- `site_url`

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

Response includes:

- `access_token`
- `token_id`
- `agent_id`
- `agent_slug`
- `agent_name`

## Delivery semantics

- outbound messages are always inserted into the pending queue first
- webhook delivery is best-effort and does **not** remove a queue item
- the bridge client must call `/ack` after accepting the message
- polling remains the recovery path if webhook delivery fails

## Homeboy

This repo is a Homeboy component.

- component id: `data-machine-chat-bridge`
- remote path: `wp-content/plugins/data-machine-chat-bridge`

## Notes for operators

- the plugin now runs schema upgrades automatically on version change
- bridge routing depends on `token_id` support in Data Machine core
- Roadie-specific access policy belongs in `extrachill-roadie`, not here
