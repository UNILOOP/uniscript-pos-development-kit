# Embedded views and bridge reference

Declare `ui.profile: static-v1`, one static entrypoint and supported surfaces in the manifest. Select content from the initialized surface. The same compiled assets are used locally and in the ZIP.

| Surface | Queries | Actions |
| --- | --- | --- |
| `connection.setup` | `context.read`, `config.read`, `reference.timezones` | `config.save` |
| `catalog.item.mapping` | `context.read`, `mapping.read` | `mapping.save` |
| `pharmacy.order.details` | `context.read`, `order.read`, `fulfillments.read` | None |
| `sender.order.details` | `context.read`, `order.read`, `fulfillments.read` | None |

Legacy diagnostics has `context.read` and `diagnostics.refresh`. The shared contract registry is authoritative. Unsupported operation names fail.

A legacy declaration without `ui.surfaces` does not provide a setup, mapping or order screen. The workbench identifies it explicitly and uses the host's schema controls and order details. Empty schemas display a no-fields message. Declare each modern surface you implement to preview its custom screen.

Static assets must be below `ui/`, with HTML, JavaScript or CSS suffixes and a total path of at most 240 ASCII bytes. Each path segment starts with a letter or digit; letters, digits, underscores and hyphens are accepted, with dots separating nonempty parts. Framework bundles such as `ui/assets/screen.abc123.js` and `ui/assets/theme.123abc.css` are supported. Hidden files, double dots, trailing dots, encoded paths, query strings, fragments and filename case collisions are rejected. The exported `ui.asset_path_pattern` and `ui.asset_path_max_bytes` define the same grammar for the host, SDK and asset servers. Assets must also belong to the exact immutable UI digest; a valid-looking name alone never makes a file public.

## Queries

- `context.read`: package/version/execution, environment, safe connection status/freshness, current surface/resource IDs, `sensitive_operations_available: false`.
- `config.read`: filtered schema, safe values, editability, `credentials_host_owned: true`.
- `mapping.read`: order-mapping schema/values, integer revision and status.
- `order.read`: order UUID/status/route version, invocation delivery state, opaque accepted-reference handles/fingerprints and safe fulfillment projection.
- `fulfillments.read`: current-route shipment UUID, status, carrier, tracking/shipped/delivered presence flags.
- `reference.timezones`: core `{value,label}` choices.

## Actions

- `config.save`: `{ "config": { ...safeValues } }`. Host validation creates a new revision. Refresh and obtain a new session after success.
- `mapping.save`: `{ "values": { ...typedMapping }, "expected_revision": 0 }`. Use the current revision; stale saves fail.

Do not retain sessions after mutation, expiry, replacement or resource changes. Neither order surface has mutation authority. Credentials/probes/activation/overrides/webhook registration/order actions are host controls, not additional iframe functions.

## Isolation

Use canonical `ui/bridge.ts` or generated `ui/bridge.js` and example initialization. Host supplies protocol, nonce, session/execution/expected revision, exact origin, surface, allowed operations and theme. Messages require exact peer/origin/session/nonce/revision; unsafe JSON/prototype keys, changed-argument replay and expired sessions fail.

Each execution uses a separate origin, locally `pkg-EXECUTION_UUID.localhost:19100`, while the host uses `127.0.0.1:19100`. Production origins are isolated HTTPS assets. Package-controlled URL parameters cannot grant a trusted origin.

CSP blocks vendor fetch, external scripts/fonts/images, nested frames, workers, forms and top navigation. Host operations use the bridge. Credentials, clinical data, raw tracking and vendor references never appear in order projections.

Native form submission is disabled by the sandbox. Use an explicit `type="button"` click handler, call `form.reportValidity()` when useful, and send `config.save`/`mapping.save` through the bridge. Do not depend on the browser firing a native submit event or add `allow-forms` to work around this restriction.

## Native UI

Use the examples' design tokens and theme, labeled controls, keyboard/focus states and clear loading/error/empty/stale states. Let content grow and send bounded resize messages for the current session. Avoid fixed-height nested scrolling. Propose a tested contract extension when a needed field/action is absent; do not invent a private bridge call.
