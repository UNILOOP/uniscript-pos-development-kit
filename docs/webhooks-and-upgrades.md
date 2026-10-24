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
