# Changelog

## [Unreleased]

### Added
- accept `attachments` array on `POST /bridge/send` (mirrors `/chat` schema) so bridge clients can forward multimodal messages. Pass-through to `datamachine/send-message` ability; no core changes required. (#8)

### Changed
- **AgentMode migration**: migrate to `AgentModeRegistry` and `datamachine_agent_modes` action. Aligns with data-machine core #1129. (#6)

## [0.3.1] - 2026-04-03

### Fixed
- use datamachine/send-message ability instead of rest_do_request

## [0.3.0] - 2026-04-03

### Added
- add onboarding metadata endpoint and bridge /send inbound message API
- implement PKCE authorize hook, agent-scoped ack, and cleanup cron

### Changed
- migrate from chat-bridge/v1 to datamachine/v1/bridge namespace
- Scaffold data-machine-chat-bridge extension plugin

### Fixed
- run bridge token schema upgrades automatically
- scope chat bridge delivery by token login
- align bridge contract with the Beeper client
- Fix BridgeEndpoints: correct handle_register, add missing handle_pending

## [0.2.0] - 2026-04-03

### Added
- `GET /bridge/onboarding` endpoint for first-run UX metadata
- `POST /bridge/send` endpoint for bridge inbound messages
- `datamachine_bridge_onboarding_config` filter for platform-specific onboarding

### Changed
- Migrated REST namespace from `chat-bridge/v1` to `datamachine/v1/bridge`

## [0.1.1] - 2026-04-01

### Added
- PKCE authorize hook and auth code exchange
- Token-scoped bridge registration and delivery
- Agent-scoped message acknowledgment
- Daily cleanup cron for expired codes and stale registrations

## [0.1.0] - 2026-03-31

### Added
- Initial scaffold: bridge registration, message queue, webhook delivery
- REST endpoints: register, pending, ack, identity, token
