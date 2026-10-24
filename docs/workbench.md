# Local developer workbench

The workbench is a standalone, vendor-neutral host for developing POS packages. It loads a package's manifest, configuration/credential/resource schemas, runtime handlers and static UI. The same functions exercise the generic reference integration and optional vendor compatibility fixtures. There is no LifeFile branch in the workbench engine.

Start with `./dev-kit up`, then open the loopback URL printed by that command. Use **Packages → examples/reference → Build and load** for the first workflow. Source builds use real isolated Docker package containers. The vendor responses and host business state are synthetic, and the screen identifies that scope throughout.

## A complete workflow

1. Load a package from an example, a configured workspace directory or an exported ZIP. Source files are snapshotted and archived before building; later source edits do not alter an already loaded execution.
2. Create a POS connection. Its form is generated from the package's configuration and credential schemas. Credentials are entered in host controls, encrypted in private state and omitted from all embedded views.
3. Run a health check and a connection test, then activate. The workbench invokes actual `health.probe`, `connection.test` and `configuration.validate` package handlers. It does not turn on an application scheduler or a general queue.
4. Open **Product mapping**. The active connection's custom view and schema fallback write the same optimistic mapping revision. Status and shipping resource fixture editors are explicitly local test data for `resources.read`; they do not invent new host bridge actions.
5. Optionally create a sender credential override, test it, then enable it. An order records the selected immutable credential revision. Editing or disabling an override does not change existing orders. A draft, disabled or revoked override blocks new orders for that sender; explicitly remove it to use connection credentials. Revoking an old revision also blocks outbound actions for orders pinned to it, while incoming callbacks can still update those orders.
6. Submit a synthetic order with an idempotency key. The host supplies the authoritative order UUID and published product mapping, invokes validation, then submission. Acceptance requires trusted broker evidence, not a package's assertion alone.
7. Create an incoming webhook URL with independent local Basic credentials. On the pharmacy order view, send **Shipped**, then **Delivered**, and replay either. These actions authenticate against the same receipt path, run the actual `inbound.decode` handler and apply the resulting scoped proposals. The sender view is read only.
8. Load a second compatible version and draft an upgrade. Confirm credential copying, run fresh checks and activate. Configuration, mappings and local resource fixtures remain; health/test evidence and enabled override status require fresh verification. The webhook URL stays stable. Old orders retain their original execution and views.

The local model creates no real patient records, bills, charges, payments, labels or shipments. A displayed parcel is a synthetic host projection. Independent internal review and real UniScript compatibility tests remain authoritative.

## Workspaces and fixtures

The server discovers only immediate package directories inside its configured `KIT_WORKSPACE` and built-in `examples` directory. Directory symlinks are excluded. The browser sends an opaque source identifier; it cannot request arbitrary filesystem paths. ZIP uploads pass the archive inspector before building.

Trusted offline fixture files under `fixtures/*.json` use this structure:

```json
{
  "label": "Example vendor offline fixture",
  "package_id": "example/vendor-pos",
  "configuration": {"account_id": "synthetic"},
  "credentials": {"api_key": "local-development-only"},
  "mapping": {"product_code": "synthetic-product"},
  "order": {"order_id": "synthetic-order"},
  "callback_account_fields": {"accountId": "account_id"},
  "profiles": {}
}
```

Fixture defaults are selected by package identity, and profile maps are merged only when their names do not conflict. `inbound_profile` describes reviewed reference/event/account field paths and is pinned to the created endpoint; see [Webhooks and upgrades](webhooks-and-upgrades.md). The older `callback_account_fields` map remains an offline compatibility fallback with `orderId` and `eventId` wire fields. Neither a fixture nor a manifest grants real network access. All fixture credentials are intentionally synthetic and publicly shareable. Keep live sandbox credentials out of these files.

For your own integration, place a companion JSON fixture in `dev-fixtures/` rather than changing kit source. Compose mounts that directory read only as `KIT_FIXTURES`; a directly started server accepts the same environment variable. A file must declare the package identity and contain data only. Reload the workbench after editing it. An external fixture with the same package identity replaces that package's built-in fixture; conflicting profile names across different fixtures are rejected. No PHP fixture code is loaded, and even a profile containing URL-looking text cannot make the offline adapter perform network I/O. The fixture is separate from the deliverable ZIP and provides no production host authorization.

Local source packages may supply `fixtures/defaults.json` and `fixtures/order.json` for authoring convenience, but the packager excludes development fixtures from deliverable archives. The kit's trusted fixture catalog supplies defaults for immutable loaded packages.

## HTTP API and session rules

The API is an authoring tool, not a production API. Main-origin requests use the configured exact `http://127.0.0.1:PORT` Host header. The session cookie is HTTP only, SameSite Strict and host only. POST requests require `X-CSRF-Token` from `GET /api/state`; cross-origin and cross-site mutations are rejected. Responses use `Cache-Control: no-store`.

| Route | Method | Purpose |
| --- | --- | --- |
| `/api/state` | GET | Safe package/connection/order projections, fixture source identifiers and CSRF token |
| `/api/load` | POST | `{source_id}` from the discovered source list; snapshot and real Docker build |
| `/api/upload` | POST | Multipart `package` ZIP; inspected before building |
| `/api/action` | POST | `{action, arguments}` using the actions below |
| `/api/session` | POST | Open `{connection_id,surface,catalog_item_id?,order_id?}` |
| `/api/bridge/{session}` | POST | A version 1 bridge envelope; reauthorized for every request |
| `/api/callback` | POST | `{order_id,status:"Shipped"|"Delivered"}`; local authenticated callback simulation |
| `/pos-webhooks/{endpoint}` | POST | Basic-authenticated callback JSON; independent of the operator cookie |

There is no credential-reveal endpoint. The callback simulator uses the saved incoming credentials in server memory and returns only receipt metadata. Failed authentication returns 401 before receipt persistence. Invalid JSON, unowned references, account mismatches and oversized payloads are rejected.

## Host-owned actions

Unless stated otherwise, arguments require `connection_id`. Optional `organization_id`, `location_id` and `environment` must match the connection; the environment is always `sandbox`. Unknown actions fail closed. Arrays shown as objects below must be JSON objects, not lists.

| Action | Additional arguments | Result and rules |
| --- | --- | --- |
| `connection.create` | `execution_revision`, `config`, `credentials`; optional synthetic organization/location IDs | Draft connection; validates both schemas and seals credentials |
| `connection.save` | `expected_version`, `config`, optional replacement `credentials` | New configuration/credential revision; historical values retained; invalidates health, test and UI sessions |
| `connection.health` | None | Actual `health.probe` result summary and evidence pinned to current revisions |
| `connection.test` | None | Actual `connection.test` result summary and pinned evidence |
| `connection.activate` | None | Requires fresh successful health/test and configuration validation; pauses the replaced connection in the same upgrade lineage |
| `connection.pause` | None | Pauses routing and invalidates open screens |
| `connection.revoke` | None | Revokes future host actions and open screens |
| `connection.upgrade` | Target `execution_revision`, `copy_credentials:true` | New draft of same package identity; validates retained schemas and preserves webhook lineage; old connection stays active until replacement activation |
| `connection.invoke` | Declared `operation`, object `input` | Safe result summary for a declared operation; submission/decoding require their dedicated workflows |
| `health.schedule` | Integer `interval_minutes` 5–1440; optional `run_due_now:true` | Saves freshness interval; optionally simulates one scheduled health check. No background scheduler starts |
| `mapping.save` | `catalog_item_id`, integer `expected_revision`, object `values` | Validated `order_mapping`; new immutable revision; stale writes fail |
| `resource.fixture.save` | `resource_type`, `resource_key`, integer `expected_revision`, object `values` | Validates a declared non-order resource schema and saves local `resources.read` fixture data. This is a developer fixture tool, not a production host action |
| `override.save` | `sender_id`, object `credentials` | New disabled immutable sender credential revision |
| `override.test` | `sender_id` | Actual connection test using that sender credential revision |
| `override.enable` | `sender_id` | Requires successful test of exactly the current sender revision |
| `override.disable` | `sender_id` | Blocks new orders for that sender without changing old credential pins |
| `override.remove` | `sender_id` | Explicitly selects connection credentials for new orders; historical revocation remains enforced |
| `override.revoke` | `sender_id`, optional historical `revision` | Blocks the selected revision, including outbound actions on old orders; authenticated incoming callbacks remain independent |
| `webhook.create` | `username`, `password` at least 24 characters | Stable endpoint UUID/path for the connection lineage; repeated creation returns the same URL |
| `order.submit` | `sender_id`, `catalog_item_id`, `idempotency_key`, object `input` | Requires active connection, fresh evidence and published mapping; validates and runs the real package; pins configuration, mapping, credential and execution |
| `order.action` | `order_id`, `operation`, integer `expected_revision`, UUID `idempotency_key`; optional `canonical_code`, object `shipping`; `audience:"pharmacy"` | Original execution and credential pins; canonical choices resolve published vendor mappings. Host injects the owned reference and mapped shipping service. No caller-supplied vendor input. Replays return one immutable receipt; unknown mutations require review; sender mutations denied |

The **Activity & diagnostics** operation console exposes declared package functions with explicit synthetic JSON. Order creation and decoding use dedicated workflows to preserve their scope and evidence.

A status or shipping action uses a typed resource fixture with the canonical choice as its key and `{"vendor_code":"..."}` as its values. For example, a `shipping_mapping` named `ground` may map to `GROUND-STD` for a generic string service schema, or `12` for an integer service schema. The host translates and validates the declared type, injects `shipping.service` and an opaque reference belonging to the original order, and never accepts those fields from the caller. Successful vendor actions do not directly change the canonical order lifecycle; inbound proposals remain authoritative. Orders expose only safe action receipts and a current `action_revision`.

Live sandbox checks require an independent host-controlled identity verification request with authenticated principal and account bindings. Uploaded package success alone cannot activate a live connection or enable sender credentials. Proofs bind the reviewed transport policy before and after each test; changing that policy makes prior evidence stale. Offline health and sender tests remain clearly marked synthetic.

## Embedded views

The workbench uses the canonical bridge and projection schemas under `contracts/`. It creates five-minute sessions bound to browser session, connection, exact execution, expected revision, resource and nonce. Package assets are immutable and served on `pkg-EXECUTION_UUID.localhost:PORT`, separately from the host's `127.0.0.1` origin.

| Surface | Queries | Actions |
| --- | --- | --- |
| `connection.setup` | `context.read`, `config.read`, `reference.timezones` | `config.save` |
| `catalog.item.mapping` | `context.read`, `mapping.read` | `mapping.save` |
| `pharmacy.order.details` | `context.read`, `order.read`, `fulfillments.read` | None |
| `sender.order.details` | `context.read`, `order.read`, `fulfillments.read` | None |

Credentials, activation, probes, sender overrides and webhook registration are host controls outside the iframe. Configuration and mapping saves expire the session and require refresh. Order views expose opaque reference handles and 12-character fingerprints, shipment status/carrier and boolean tracking/shipped/delivered indicators. They do not expose raw references, tracking, credentials, configuration, patient or prescriber data.

Static package CSP blocks network access, nested frames, workers, plugins, forms, external styles/fonts/images and host access. Frames permit scripts and same-origin within their isolated package origin. Exact source window/origin, nonce, session, execution and revision are checked; resize is presentation-only and bounded to 160–10000 integer pixels.

## Persistence, retries and failures

State uses an exclusive lock and atomic replacement. Credentials, raw accepted references, order input and callback bodies are sealed with libsodium; the private vault key is never mounted into package containers. Normal restarts preserve this state. Resetting it is a separate deliberate command.

- Reusing an idempotency key with the same original submission returns the original order. Different input under that key produces `order_idempotency_conflict`.
- Unknown remote acceptance remains `unknown`; it never silently resubmits. Use the order reconciliation action and a proven reference to recover it.
- Exact duplicate callbacks reuse their receipt. Vendor event identities deduplicate proposal application. An older Shipped callback cannot regress Delivered.
- Callback ownership is scoped to a stable connection lineage and accepted reference. Old orders decode under their original execution after an upgrade.
- `connection_revision_conflict`, `mapping_revision_conflict` and `resource_revision_conflict` mean reload before editing.
- `fresh_health_and_test_required` means run both probes against current settings.
- `bridge_session_denied` or `bridge_session_stale` means open a new screen; sessions cannot be transferred to another browser.
- `development_profile_not_registered` means register an explicit offline fixture profile. It does not authorize a production profile.

## Verification

```sh
php tests/workbench-test.php
php tests/workbench-http-test.php
php tests/workbench-runtime-test.php
```

The first suite tests state/revision/override/upgrade/callback isolation with an injected fixture runtime, not executable package code. The HTTP suite starts a temporary loopback server and verifies CSRF, cookies, DNS-rebinding rejection, isolated origins and CSP. The runtime suite performs actual Docker package execution with offline vendor responses and checks every UI projection against the shared schemas.

For an optional external package, supply its source and a matching trusted offline fixture:

```sh
php tests/workbench-runtime-test.php /path/to/package /path/to/fixture.json
```

The runtime test prints its private synthetic state path for inspection. This is separate from final certification. Run the UniScript host conformance suite against the exact exported ZIP to test authoritative tenant permissions, installation and host service behavior. Use only disposable databases and package-specific test queues; keep dev and production schedulers, general queues and payments untouched.
