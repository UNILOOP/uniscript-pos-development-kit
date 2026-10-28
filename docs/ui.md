# Embedded views and bridge reference

Declare `ui.profile: static-v1`, one static entrypoint and supported surfaces in the manifest. Select content from the initialized surface. The same compiled assets are used locally and in the ZIP.

| Surface | Queries | Actions |
| --- | --- | --- |
| `connection.setup` | `context.read`, `config.read`, `reference.timezones` | `config.save` |
| `catalog.item.mapping` | `context.read`, `mapping.read` | `mapping.save` |
| `pharmacy.order.details` | `context.read`, `order.read`, `fulfillments.read`, `order.corrections.read`, `config.read`, `reference.timezones` | `mapping.save`, `config.save`, `order.setup.open` |
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
- `order.corrections.read`: pharmacy-only `needed` visibility flag, safe `reason_code`, structured `field_errors`, `editable`, and order-bound `mappings`. Each mapping includes `catalog_item_uuid`, label, schema, values, revision, status, reason code and editability. The host can expose this surface before an invocation exists for an order blocked by missing mapping.
- `reference.timezones`: core `{value,label}` choices.

## Actions

- `config.save`: `{ "config": { ...safeValues } }`. Host validation creates a new revision. Refresh and obtain a new session after success.
- `mapping.save`: `{ "values": { ...typedMapping }, "expected_revision": 0 }`. Use the current revision; stale saves fail.

Do not retain sessions after mutation, expiry, replacement or resource changes. The sender order surface remains read-only. Pharmacy mapping corrections create future mapping revisions; they never rewrite a submitted snapshot or automatically queue/retry an order. Credentials/probes/activation/overrides/webhook registration/order actions are host controls, not additional iframe functions.

## Correcting setup and mappings from an order

Keep vendor-specific error classification and guidance in your package. Emit stable reason codes and `{path, reason_code}` field errors; do not copy vendor messages, credentials or clinical values into diagnostics. Unknown remote outcomes still require reconciliation. A correction action is not permission to resend an order.

On `pharmacy.order.details`, read `order.corrections.read` and only show correction UI when `needed` is true. The selected connection must be active and usable for this order; accepted orders, draft/paused connections and unrelated integrations must not show correction controls. Render a form for each eligible mapping. Respect each record's `editable` flag and use its schema, values and current revision. The host derives eligible item handles from the authorized order and rejects unrelated items, stale revisions, accepted deliveries, in-progress attempts and historical execution mismatches. Never substitute an arbitrary item from your own state.

```javascript
const corrections = await client.request('query', 'order.corrections.read', {});
const mapping = corrections.mappings.find(item => item.editable);
// On an explicit user click, after validating your form:
await client.request('action', 'mapping.save', {
    catalog_item_uuid: mapping.catalog_item_uuid,
    expected_revision: mapping.revision,
    values: validatedFormValues,
});
```

The extra `catalog_item_uuid` is required on the pharmacy order surface and prohibited on the single-item catalog surface. Successful saves invalidate the session: refresh before another action. Mapping saves from an order deliberately suppress automatic blocked-order recovery; use host delivery controls after reviewing the corrected state. Older orders keep their original execution and mapping snapshots.

For safe configuration fields, `config.read` reports editability. `config.save` only edits a current draft; it cannot mutate an active account or bypass health/test/activation. To open credentials, sender overrides, health tests and activation in the native host dialog from a package button:

```javascript
await client.request('action', 'order.setup.open', {});
```

The server binds this request to the authorized order connection. UniScript opens the host-owned controls in a dialog without leaving the order. The package cannot supply a navigation URL, receive credentials, invoke activation, or retry an order through this action. The local workbench opens its native connection controls instead. Opening settings is not a configuration mutation and does not expire the session; later configuration changes still make old bindings stale. Keep all secret fields outside the package iframe.

The reference package demonstrates the generic mapping editor and setup action. Use `tests/workbench-order-projection-test.php` to verify negative outcomes, order-bound correction authority and original mapping retention. `contracts/projections/order.corrections.read.json` and `projection-examples.json` contain the validated public data shape.

## Isolation

Use canonical `ui/bridge.ts` or generated `ui/bridge.js` and example initialization. Host supplies protocol, nonce, session/execution/expected revision, exact origin, surface, allowed operations and theme. Messages require exact peer/origin/session/nonce/revision; unsafe JSON/prototype keys, changed-argument replay and expired sessions fail.

Each execution uses a separate origin, locally `pkg-EXECUTION_UUID.localhost:19100`, while the host uses `127.0.0.1:19100`. Production origins are isolated HTTPS assets. Package-controlled URL parameters cannot grant a trusted origin.

CSP blocks vendor fetch, external scripts/fonts/images, nested frames, workers, forms and top navigation. Host operations use the bridge. Credentials, clinical data, raw tracking and vendor references never appear in order projections.

Native form submission is disabled by the sandbox. Use an explicit `type="button"` click handler, call `form.reportValidity()` when useful, and send `config.save`/`mapping.save` through the bridge. Do not depend on the browser firing a native submit event or add `allow-forms` to work around this restriction.

## Native UI

Use the examples' design tokens and theme, labeled controls, keyboard/focus states and clear loading/error/empty/stale states. Let content grow and send bounded resize messages for the current session. Avoid fixed-height nested scrolling. Propose a tested contract extension when a needed field/action is absent; do not invent a private bridge call.
