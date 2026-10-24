# Mapping, orders and actions

## Catalog mapping

`catalog.preview` returns proposals; the host owns catalog imports. Typed resource schemas describe order/product, status and shipping mappings. The host validates values, creates immutable revisions and detects stale edits using expected revisions.

The current active routing connection supplies the drug item's mapping view. Draft, replaced and retired connection versions must not create duplicate mapping panels. Existing orders retain their original mapping, configuration, execution and credential pins.

Test invalid product IDs, missing required mappings, unsupported resource fields, stale saves and cross-connection access. Changing a schema during an upgrade must report incompatible values rather than silently discard them.

## Canonical orders

Start with synthetic fixtures in `fixtures/`, not real patient exports. Package input is a host snapshot, not an arbitrary database model. Validate patient/prescriber/contact/address/medication/product/shipping/quantity fields as required by the vendor. Do not invent defaults that change clinical meaning.

The flow is validation → immutable dispatch → governed HTTP request → recorded remote outcome → host acceptance or recovery. Reusing the same command/idempotency identity must not create another vendor order. If acceptance may have occurred before a timeout, reconcile; do not blindly retry submission.

The workbench uses the generic reference integration first. Vendor-specific fixtures can exercise a different package without modifying workbench UI or adding vendor conditionals. LifeFile compatibility is a separate fixture run, not a prerequisite for a developer's own package.

## Documents and controlled prescriptions

Documents are bounded scoped resources, not filesystem paths. Enforce declared MIME/size/authorization requirements and avoid logging base64 document payloads. A local test file does not certify antivirus scanning or clinical validity.

Controlled prescriptions remain blocked unless the original verified EPCS signed payload and signature are available. Attestation hashes or metadata cannot reconstruct a valid signed request. Test rejection paths as well as supported non-controlled orders.

## Actions and views

Status/shipping or vendor-extension actions require declared handlers, typed input, host authorization/capabilities and accepted-order reference scope. Order screens are read-only projections. An action button in an iframe cannot introduce an undeclared mutation.

Show accepted/rejected/pending/unknown outcomes accurately. Fulfillment views project current-route parcels with safe carrier/status/presence fields. Pharmacy and sender views share these safe facts; neither receives credentials, clinical snapshots or raw provider/tracking identifiers.
