# Catalog preview, review and import

The workbench runs the actual package's `catalog.preview` handler and models UniScript's reviewed import checkpoints. It does not approve clinical catalog mappings or turn vendor products into dispensable medications.

Open **Product mapping → Catalog imports**. Use the selected package's synthetic CSV fixture or load a UTF-8 CSV from disk. The schema is vendor-specific: the generic reference accepts `source_key,display_name,product_code,schedule_code`; another integration may require different identifiers. The workbench does not rename columns or infer missing clinical values.

## Source and proposal contract

- CSV: at most 512 KiB, 50 unambiguous headers, 1,000 rows, scalar/null cells and 1,000 bytes per string cell. Normalized duplicate headers, invalid UTF-8 and inconsistent row widths are rejected.
- Records: at most 768 KiB encoded. The source fingerprint includes the exact execution, configuration/credential context, connection version and parsed records. Repeated staging of the same immutable source returns the same batch.
- The package returns exactly one proposal per source record. The host keeps only `source_key`, `display_name`, nullable `canonical_item_handle`, nullable typed `mapping_values` and bounded warning codes.
- Mapping proposals must satisfy the declared `order_mapping` schema. Unknown result fields are not shown or applied. A catalog function cannot bypass host validation by returning extra actions or credentials.
- Duplicate source keys and rows with warnings become `needs_review`. A canonical item handle or an existing source identity requires explicit review and is never overwritten automatically.

Review all row pages before selecting a checkpoint action. Read responses contain at most 100 rows and an explicit `next_cursor`; source CSV and arbitrary vendor response data are not included.

## Checkpoint behavior

**Stage** runs actual package code and persists immutable encrypted proposal evidence. **Apply** processes at most 25 rows after the current checkpoint, creates only new synthetic items in `needs_mapping`, and advances the batch to `applying` or `completed`. It never approves canonical catalog targets or publishes package mapping values automatically. Warned/existing rows remain in review.

**Cancel** stops remaining work in a staged/applying batch. Items already created remain available. **Rollback** processes at most 25 previously applied rows, archiving only unchanged, unmapped and unused items created by that exact connection/import. Items edited, mapped or used by an order remain intact with `rollback_blocked`; the batch becomes `rollback_requires_review` when appropriate.

Every mutation requires an explicit confirmation, current connection version and current import revision. The batch retains its original configuration/execution binding. A changed configuration, wrong connection, stale revision, paused/revoked context or altered proposal fingerprint prevents further application. Compatible upgrade copies do not transfer an in-progress import to another connection.

Canonical drug concepts, clinical matching, catalog review permissions and real pharmacy activation belong to UniScript. The local item table deliberately shows `needs_mapping`; it does not invent approval of those private host decisions. The separate synthetic product fixture still supports package product-mapping/order tests. Run the real UniScript package conformance suite for authoritative catalog and clinical boundary verification.

## Developer API

Send `{action,arguments}` to the CSRF-protected `/api/action`. All actions require `connection_id`. Writes additionally require `expected_version` and `confirmed:true`.

| Action | Additional arguments | Result |
| --- | --- | --- |
| `catalog.stage` | `source_csv` string | Typed preview batch, revision 1 |
| `catalog.read` | `import_uuid`, optional integer `after_row` | Up to 100 rows with `next_cursor`; no mutation confirmation required |
| `catalog.apply` | `import_uuid`, `expected_revision` | Next bounded apply checkpoint; requires current setup evidence |
| `catalog.cancel` | `import_uuid`, `expected_revision` | Stop remaining pending work |
| `catalog.rollback` | `import_uuid`, `expected_revision` | Next bounded rollback checkpoint |

The workbench returns the same principal batch fields as UniScript: UUID, status, revision, checkpoint, row count, creation time, next cursor, typed rows and `automatic_mapping_approval:false`.

`connection.invoke` exposes catalog proposal data only after typed projection validation. It exposes a `reference.inspect` record only when it satisfies the package's declared reference schema. Other function results stay metadata-only; the console is not a credential, clinical payload or raw vendor response viewer. All order operations use the dedicated scoped order workflow.

## Checks

```sh
php tests/workbench-catalog-test.php
php tests/workbench-catalog-runtime-test.php
php tests/workbench-catalog-runtime-test.php /path/to/package /path/to/fixture.json
```

The first suite tests confirmation, source/CAS guards, deduplication, safe projections, pagination, checkpoints and rollback protection with a fixture runtime. The latter commands run the actual package in Docker, then stage/apply/rollback its typed synthetic proposals. The optional vendor fixture must supply a matching package identity and scalar `catalog` rows. No vendor or production API is contacted by these default offline tests.
