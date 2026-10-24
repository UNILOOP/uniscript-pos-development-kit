# Webhooks and upgrades

The pharmacy registers a host-owned endpoint with independent inbound credentials. Some vendors configure callbacks manually: share the URL and credentials securely with their team. Registration in UniScript does not mean the vendor has configured it.

## Callback flow

1. Authenticate before creating a receipt.
2. Persist the immutable bounded body and deduplicate exact replay.
3. Resolve account/external-reference ownership and the order's pinned execution.
4. Run `inbound.decode` in the package runtime.
5. Validate proposals against host scope, event identity and state rules.
6. Apply canonical status/fulfillment changes idempotently.

Test invalid authentication, malformed bodies, duplicate event IDs, out-of-order events, unknown references, wrong accounts, wrong environments and revoked endpoints. A successful HTTP receipt response is not proof that decoding/application succeeded; inspect the receipt result.

Shipped followed by Delivered should progress the same parcel. Replayed callbacks must not create another shipment or duplicate simulated charge. Never substitute direct order status edits for callback testing.

## Generic callback wire profiles

An operator-owned fixture may declare `inbound_profile` using `contracts/inbound-profile-v1.schema.json`. The fields match UniScript's reviewed inbound descriptor:

```json
{
  "version": 1,
  "authentication": "basic",
  "label": "Example Sandbox callbacks",
  "package_ids": ["example/vendor-pos"],
  "reference_field": "payload.order.providerId",
  "event_id_field": "meta.eventId",
  "account_fields": {"tenant.scope": "account_id"}
}
```

`reference_field` and `event_id_field` identify fields in incoming JSON, using dot paths. Each `account_fields` entry maps a wire field to the original order's pinned configuration field. Authentication, owned reference and account checks happen before invoking the decoder. The endpoint snapshots the profile at creation; upgrading a package does not change its callback URL or reinterpret old incoming identities. The package's decoder remains responsible for translating its vendor status and shipment fields into a valid normalized proposal. A local fixture grants no hosted endpoint or vendor access; internal review still approves the real descriptor.

Only canonical `normalized_status` values control the local application: `received` and `queued` advance to accepted, `in_progress` advances in progress, `ready_for_pickup` and `shipped` advance ready, and `delivered` completes the order. Allowed metadata fields such as `rx_status` and `order_status` do not replace `normalized_status`. Cancelled, rejected, on-hold, unknown and unrecognized values require review rather than a guessed transition.

A parcel requires a nonempty `tracking_number` in the normalized proposal. Different tracking numbers create distinct parcels; shipped/delivered updates for the same normalized tracking value keep the parcel UUID and never regress delivery. Source event IDs are immutable: an identical replay is harmless, while changed content with the same event ID requires review. Safe views expose parcel status and tracking presence, never the raw tracking value or private deduplication keys.

Callback bodies may be one object, a nonempty JSON list, or `{"events":[...]}`, with at most 100 object sources and a 64 KiB body. Every source must resolve to an owned order using the same pinned decoder connection/execution; batches mixing old/new decoder versions require separate requests. Every account is checked before decoding. Each normalized proposal must match exactly one source reference and, when supplied, its event ID. An omitted/null proposal event ID remains supported: the host still derives identity from the original source ID or canonical source body, so omitting an ID cannot evade replay protection. A failed decoder result never applies emitted proposals.

## Stable endpoints

A connection lineage retains a stable callback URL across compatible package upgrades. New orders use the activated execution; old orders retain their original decoder/reference ownership. Vendors should not need a new URL for each package version.

## Upgrade checklist

- Use a new source/version and inspect the new ZIP.
- Preview compatibility for configuration, typed resources and mappings.
- Copy credentials/overrides only through the explicit host upgrade choice.
- Preserve old revisions and orders; do not rewrite snapshots.
- Do not copy fresh health/test evidence to the changed execution.
- Activate only after the new revision passes requirements.
- Verify settings/mappings remain, the callback URL is stable and both old/new orders decode under their original pins.

Incompatible data requires a migration plan or a clear validation error. Silently dropping mappings or credentials is not an upgrade strategy.

## Authentication and prescription correlation

A reviewed inbound profile can select `basic`, `hmac_sha256` or `header_secret`. Basic uses the registered independent inbound username/password. HMAC verifies the exact raw request bytes using the registered independent secret and the hexadecimal `x-hmac-signature` header. Secret-header authentication compares `X-API-Key` with that independent secret. The native password control stores the secret for all three modes; the username has no authentication role for HMAC or secret-header mode. A configured mode never falls back to another mode, and authentication happens before receipt persistence or decoding.

The optional `prescription_reference_field` identifies an additional owned prescription reference in a callback. The workbench checks that it belongs to the same accepted order before decoding. It can also correlate a prescription-only callback when the main order-reference field is absent. Prescription delivery is tracked independently: a delivered parcel does not complete a multi-prescription order until all of its accepted prescription references have delivered. The production host must enforce the same rule for the reviewed integration.
