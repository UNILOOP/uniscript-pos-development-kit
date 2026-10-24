# Mapping, orders and actions

## Catalog mapping

`catalog.preview` returns proposals; the host owns catalog imports. Typed resource schemas describe order/product, status and shipping mappings. The host validates values, creates immutable revisions and detects stale edits using expected revisions.

The current active routing connection supplies the drug item's mapping view. Draft, replaced and retired connection versions must not create duplicate mapping panels. Existing orders retain their original mapping, configuration, execution and credential pins.

Test invalid product IDs, missing required mappings, unsupported resource fields, stale saves and cross-connection access. Changing a schema during an upgrade must report incompatible values rather than silently discard them.

Mapping field rules are exported from the actual host as `manifest.mapping_schema_policy` in `contracts/host-v1.json`. They apply recursively to `order_mapping`, `status_mapping` and `shipping_mapping`, including array item objects. Mapping schemas cannot declare fields reserved for host controls (`approval_status`, `version`, `reference_handle`, `reference_type`, `mapping_handle`), credential-looking fields such as `api_key` or `client_secret`, or any exact field name from the package's credential schema. Product/service identifiers such as `mapped_id` and `vendor_product_key` remain valid when they are not credential fields. Put vendor secrets in `credential_schema`; an embedded mapping screen must use host credential controls rather than saving secrets as mapping values.

The kit rejects an unsafe mapping schema before building or exporting, and the internal ZIP inspector rejects it before release processing. Errors include the offending field path, such as `order_mapping.products[].api_key`, and explain how to move secrets to the correct control. Ordinary package storage/reference schemas and vendor wire schemas keep their separate validation rules; these mapping restrictions do not reinterpret a vendor's CamelCase HTTP fields.

## Canonical orders

Start with synthetic fixtures in `fixtures/`, not real patient exports. Package input is a host snapshot, not an arbitrary database model. Validate patient/prescriber/contact/address/medication/product/shipping/quantity fields as required by the vendor. Do not invent defaults that change clinical meaning.

The flow is validation → immutable dispatch → governed HTTP request → recorded remote outcome → host acceptance or recovery. Reusing the same command/idempotency identity must not create another vendor order. If acceptance may have occurred before a timeout, reconcile; do not blindly retry submission.

The workbench uses the generic reference integration first. Vendor-specific fixtures can exercise a different package without modifying workbench UI or adding vendor conditionals. LifeFile compatibility is a separate fixture run, not a prerequisite for a developer's own package.

The embedded `order.read` DTO separates canonical order state from runtime delivery. Its `status` uses the host's exported `ui.order_statuses` enum; `delivery_status` is the original invocation status, derived from `runtime.result_statuses`, rather than a result kind. Nonacceptance leaves the synthetic canonical order `queued_for_pos`: validation rejection has delivery `rejected`, temporary/permanent failure has delivery `failed`, and an unknown remote outcome has delivery `unknown`. Host-owned diagnostics retain the result kind and reason separately. A successful reconciliation creates its own action receipt; it does not rewrite the original submission outcome, advance a queued order automatically or authorize delivery callbacks for an unaccepted source. This mirrors UniScript's review boundary without reproducing its private clinical workflow.

## Documents and controlled prescriptions

Documents are bounded scoped resources, not filesystem paths. Enforce declared MIME/size/authorization requirements and avoid logging base64 document payloads. A local test file does not certify antivirus scanning or clinical validity.

The mini-host currently blocks controlled submissions before invoking any package, including packages that ignore controlled flags. It has no host-owned EPCS verification contract. An empty array, signed-looking payload/signature, attestation hashes or caller-supplied verification metadata cannot claim that verification has happened. Declared controlled flags, controlled medication schedules and controlled host-selected mappings are rejected; `verified_signed_payload` claims are unsupported even when a controlled flag is omitted. Use non-controlled synthetic fixtures for end-to-end workflows and test controlled rejection paths. A future verified controlled workflow must be introduced through an actual host-owned contract rather than EPCS emulation in this kit.

## Actions and views

Status/shipping or vendor-extension actions require declared handlers, typed input, host authorization/capabilities and accepted-order reference scope. Canonical status/shipping choices come from published `status_mapping` and `shipping_mapping` resources, keyed by canonical code with a `vendor_code` value. The host supplies the owned order reference, applies the declared vendor input schema and injects the mapped service; callers cannot supply a raw service or reference. Mutations use an expected action revision and UUID idempotency key, return immutable receipts, and block new mutations after an unknown outcome until reviewed. Vendor acceptance does not itself change the canonical lifecycle. Order screens are read-only projections. An action button in an iframe cannot introduce an undeclared mutation.

Show accepted/rejected/pending/unknown outcomes accurately. Fulfillment views project current-route parcels with safe carrier/status/presence fields. Pharmacy and sender views share these safe facts; neither receives credentials, clinical snapshots or raw provider/tracking identifiers.
