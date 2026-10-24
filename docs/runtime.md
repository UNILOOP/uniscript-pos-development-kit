# Runtime and host functions

The entrypoint returns handlers receiving immutable `Invocation` and scoped `Services`. Read `input()`, `operation()` and `idempotencyKey()`. Return `Result`. Do not write protocol output to stdout, access host files or connect directly to vendors.

## Operation catalog

| Operation | Responsibility |
| --- | --- |
| `identity.describe` | Describe adapter without vendor access |
| `configuration.validate` | Validate settings without side effects |
| `health.probe` | Bounded auth/access/health check |
| `connection.test` | Verify required access without creating a prescription |
| `catalog.preview` | Propose rows for host import |
| `reference.refresh` | Refresh versioned reference data |
| `orders.validate` | Validate canonical order before submission |
| `orders.submit` | One governed submission with durable acceptance semantics |
| `orders.reconcile` | Resolve uncertainty without blindly resubmitting |
| `orders.status.update` | Authorized status action for an accepted order |
| `orders.shipping.update` | Authorized shipping action for an accepted order |
| `inbound.decode` | Decode authenticated receipt into proposals, not direct mutations |

Declare only implemented operations. Vendor extensions require schemas, fixtures and independent trusted certification recipes. A runtime operation does not automatically become an iframe action.

## Envelope and results

Protocol 1 binds invocation/command/attempt/correlation, package/execution/connection/organization/location/environment/actor, operation, schema/routing/configuration/mapping/credential revisions, idempotency key, deadline, request budget, JSON input and opaque service grant. Scope is host-owned; package arguments cannot widen it. Every service call consumes the finite budget and must finish before deadline.

Host-supported result kinds: `success`, `remote_acceptance`, `validation_rejection`, `unsupported_operation`, `temporary_failure`, `permanent_failure`, `unknown_remote_outcome`. Use stable reason codes and path-based validation errors without sensitive values.

Unknown remote outcomes must prevent automatic resubmission and provide reconciliation. A timeout is not a confirmed rejection. A result does not authorize billing, canonical mutation or broader grants.

## All host functions

Use `Services::request($service, $action, $arguments)`:

| Function | Contract |
| --- | --- |
| `storage.read` | Read allowed scoped key |
| `storage.write` | Bounded JSON write with expected revision; stale writes fail |
| `resources.read` | Authorized typed resource/opaque handle from current invocation |
| `commands.propose` | Typed catalog/inbound proposals for host validation |
| `http.request` | Registered profile plus allowed operation, bounded parameters/body; host owns auth and egress |

Use runnable examples and contract tests for exact argument/results. Arbitrary URLs, scope IDs, raw credentials and authorization headers are not accepted. A local fixture response does not register a production endpoint.

Payload/frame limits are finite. Larger document allowances are separately governed host profiles, not a default permission for all packages.
