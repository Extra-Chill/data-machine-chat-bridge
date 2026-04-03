# Changelog

## Unreleased

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
