# Connections and sender overrides

The host owns pharmacy/location/environment connections. Configuration schemas describe safe values; credential schemas describe separate secret inputs. Package screens never receive saved passwords, OAuth secrets or inbound credentials.

The schema form is the fallback. A `connection.setup` screen can improve layout, but credentials, probes, activation and webhooks remain host controls. Use pharmacy-facing wording instead of runtime terminology.

## Sequence

1. Choose package/environment and create a draft.
2. Save configuration and test-only credentials.
3. Run validation, health and connection tests.
4. Resolve errors or stale evidence.
5. Activate the tested revision for routing.

Configuration and credential edits create immutable revisions and invalidate mismatched evidence. A saved setting is not automatically healthy. Core timezone choices come from `reference.timezones`; persist `value`, not the label.

## Sender overrides

Default vendor credentials and pharmacy-issued sender credentials are separate. The host creates, tests, enables, disables and revokes overrides explicitly. A new order pins the chosen enabled credential revision plus account/configuration/execution identity. Rotating current credentials must not change an old order's reconcile/amendment context. A saved draft, disabled or revoked sender override blocks new orders rather than silently using connection credentials. Explicit removal permits connection credentials again. Revoking a current or historical credential revision blocks outbound actions on its pinned orders; incoming authenticated callbacks remain independent.

Local health simulation does not start UniScript's scheduler. Hosted scheduling, intervals, freshness and queue selection remain UniScript-owned. Package release approval is separate from pharmacy self-service setup; custom screens cannot bypass either policy.

Live sandbox tests also obtain independent trusted HTTP identity evidence. The configured verification descriptor proves the authenticated principal and all reviewed account fields; a package reporting success is insufficient. Settings, credentials, execution or reviewed transport changes invalidate incompatible tests. Offline fixture success remains synthetic.

A verified live account fingerprint can have only one authoritative connection. A different pharmacy, location or unrelated connection cannot claim it. A same-package upgrade can transfer ownership only from its recorded active source in the same pharmacy/location/environment and lineage; the old connection stops new work while its original orders keep their outbound pins and incoming callback routing. Offline fixtures do not invent verified vendor account authority.
