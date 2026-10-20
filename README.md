# UniScript integration kit

Standalone PHP 8.4 SDK, synthetic examples, and trusted **local** build/certification/runtime tools. There are no Laravel dependencies or UniScript model imports. Package code executes in separate Docker containers. A PHP child process by itself is not isolation.

## Run the examples

Requires PHP 8.4+ with ZIP for packaging, Docker Desktop with its Linux engine, and the PHP base image pulled by the trusted operator:

```sh
docker pull php:8.4-cli-alpine
php tests/conformance.php
php tests/http.php
php tests/protocol-schema.php
node --test tests/ui-bridge.test.mjs
php tests/docker.php
php bin/package.php examples/starter /tmp/starter.zip
php bin/package.php examples/demo /tmp/demo.zip
```

On macOS the Docker credential helper may require `/Applications/Docker.app/Contents/Resources/bin` in `PATH`. Packaging sorts files, fixes timestamps and UNIX permissions, and uses stored ZIP entries for repeatable bytes. The host and independent builder still revalidate every uploaded archive.

The starter exercises identity, health, configuration validation, order validation and deterministic synthetic acceptance. The demo adds managed synthetic HTTP, idempotent submission, acceptance followed by connection loss, reconciliation, authenticated receipt decoding into proposals, catalog previews and optimistic reference storage. These are synthetic learning examples; no real patients or vendor credentials are included.

## Trusted local control plane

Set four distinct random bearer tokens (at least 32 characters each), a private state directory, and an optional fixed host service broker URL. Keep these in a private environment file outside package source. Both server and worker use the same `PLATFORM_STATE`; package containers receive none of these tokens or storage credentials.

```sh
export PLATFORM_STATE=/tmp/uniscript-package-platform
export BUILDER_TOKEN=... CERTIFIER_TOKEN=... DEPLOYER_TOKEN=... RUNTIME_TOKEN=...
export HOST_SERVICES_URL=http://127.0.0.1:8000/internal/package-runtime/services
php -d upload_max_filesize=20M -d post_max_size=22M -S 127.0.0.1:19081 bin/control-plane.php
# A second terminal, same environment:
php bin/worker.php
```

The fixed broker URL must be configured by the operator; packages cannot override it. Its host must authenticate invocation grants, bind all scope to its own records, enforce budgets/revisions and authorize each operation. Local simulator grants are random in-memory handles and are not a production grant issuer.

| Endpoint | Bearer role | Request |
| --- | --- | --- |
| `POST /v1/builds` | Builder | Multipart `execution_uuid`, `source_sha256`, `package_id`, `package_version`, `archive` ZIP |
| `GET /v1/builds/{id}` | Builder | Build state and immutable digests |
| `POST /v1/certifications` | Certifier | JSON `execution_uuid`, `build_id`, `source_sha256`, `digests` |
| `GET /v1/certifications/{id}` | Certifier | Independently tested operations and observed service grants |
| `POST /v1/deployments` | Deployer | JSON `deployment_uuid`, `execution_uuid`, `certification_id`, `environment`, `digests` |
| `GET /v1/deployments/{id}` | Deployer | Readiness and immutable image identity |
| `POST /v1/invocations` | Runtime | Invocation envelope below |

Lifecycle POSTs enqueue durable local jobs. Run `php bin/worker.php --once` to drain queued phases once. Retrying the same execution/deployment UUID with different content fails. A worker restart retries interrupted local jobs. Internal diagnostics remain in private state records; ordinary API responses omit them.

A successful build returns seven lowercase 64-character SHA-256 digests: lockfile, dependencies, SDK, supervisor, base image, executable image and UI. The local Docker image content ID pins the executable artifact. The source ZIP hash remains separate. Certification independently inspects the source and image and runs fixed platform-owned positive and negative scenarios for every declared operation in a networkless container through a trusted external harness; unknown operations without a trusted recipe fail closed; the package never submits a passing attestation. `certification_summary.operations` lists tested handlers; `host_services` lists services actually observed by that harness. This evidence is preliminary protocol conformance, not a malware review or LifeFile parity certification. Independent human approval remains host-owned.

The current local build profile supports **dependency-free** PHP packages. Third-party Composer dependencies, frontend bundles, native extensions not present in the PHP base, production deployments and arbitrary hooks fail closed or remain unavailable. An empty UI digest means no UI bundle was built. Packages needing a dependency resolver require the later approved-mirror build profile.

## Invocation protocol 1

Required strings: `invocation_id`, `command_id`, `attempt_id`, `correlation_id`, `package_id`, `execution_revision`, `connection_id`, `organization_id`, `location_id`, `environment`, `actor_id`, `operation`, `schema_version`, `routing_revision`, `configuration_revision`, `mapping_revision`, `credential_revision`, `idempotency_key`, `deadline` (UTC RFC3339 ending `Z`), `service_grant`. Also `protocol_version: "1"`, integer `request_budget` (0–100), and immutable `input` snapshot object. Optional `deployment_environment` (`local` or `dev`) chooses the runtime environment separately from the pharmacy connection/vendor environment (`sandbox`, for example). The local engine always rejects production deployment targets.

Result: `{protocol_version, kind, reason_code, data, field_errors, recovery}`. Kinds: `success`, `remote_acceptance`, `validation_rejection`, `unsupported_operation`, `temporary_failure`, `permanent_failure`, `unknown_remote_outcome`; partial batches are blocked until separately supported. An unknown outcome always requires reconciliation/operator review; the host must never blindly resubmit. Runtime transport loss also produces an explicit unknown result.

The SDK sends scoped requests `{invocation_id, grant, service, action, arguments}` through stdio to the trusted supervisor. Allowed families are storage reads/writes, canonical resource reads, guarded command proposals and managed HTTP. SDK argument checks are convenience checks: the host broker is authoritative. Packages receive opaque credential handles rather than reusable credentials. Runtime image and environment are pinned by deployment records, not caller URLs.

## Isolation and remaining milestones

Each runtime is nonroot, read-only, without Linux capabilities, host mounts, Docker socket, core source, secrets or a network interface. Memory/CPU/PID/tmpfs limits apply. Readiness requires a bounded health operation. Every invocation uses a fresh disposable networkless container pinned to that image. The trusted supervisor removes the invocation container on success, timeout or malformed output, so killing a Docker client cannot leave the worker or its temporary files behind. Deadline enforcement also runs inside the container. Reusable runtime pools remain a later optimization with separate concurrency and isolation validation. The trusted dispatcher relays scoped service requests to the single configured broker. Package output is untrusted and never authorizes a service or core mutation. Containers serving multiple connections remain a reviewed-package trust boundary, as in the proposal.

This kit is a local vertical slice. Hosted developer sandboxes, separate production identities/IAM and build infrastructure, full canonical domain DTOs, governed credential HTTP primitives, durable vendor transport/ownership ledgers, integrated custom UI hosting and bridge sessions, catalog import application, schema/data migrations, rollback floors and real LifeFile v2 parity remain separate delivery gates. Local JSON job files and synchronous worker control are not a production distributed control plane. The host retains approval, pharmacy opt-in, routing, clinical policy and audit.

## Schema validation

The runtime validates invocation input against its declared input schema and the entire result envelope against its output schema. This implementation supports an explicit bounded subset: `type`, `enum`, `const`, `required`, `properties`, `additionalProperties`, `items`, array/string length bounds, numeric bounds, `$defs`, `definitions`, `$ref`, and descriptive `title`/`description` metadata. Remote references, path traversal, cyclic references, unsupported drafts and every unsupported keyword fail closed. References may resolve within the same schema or another file inside the package; the host intake contract may impose stricter same-document reference rules. No network schema resolution occurs. Duplicate JSON keys reject validation.

PHP represents an empty JSON object and empty JSON array as the same empty array after associative decoding; empty PHP arrays are accepted at object boundaries. Nonempty list inputs/results at object boundaries are rejected. DTO validation rejects unknown envelope fields, malformed result variants, ill-typed field errors/recovery, non-JSON values, impossible calendar dates and unsafe unknown-outcome retry guidance.

## UI bridge module

`ui/bridge.ts` provides `PackageBridge`, `PackageBridgeClient`, and `createPackageFrame` without frontend dependencies. Node 24 can run its synthetic host/client tests directly with type stripping. Bind `PackageBridge.receive` to the browser's message event and close it whenever actor/context/release changes. Create sessions and random nonces in trusted host code; send the initial binding only to the frame's exact window and separate immutable asset origin. Never obtain authority from a package-supplied actor or tenant value.

The bridge validates origin, source window, nonce, session, protocol, execution revision, expected revision, operation class, message shape, JSON size and replay identity. Every request rechecks host authorization, including cached responses. Host handlers receive immutable host-owned context. Duplicate requests execute once in the UI session; durable domain idempotency and authorization still belong to the backend. Generic failures conceal internal diagnostics. The client validates response origins and bindings and has bounded pending requests/timeouts.

The iframe helper requires a separate origin, `sandbox="allow-scripts allow-same-origin"`, and no-referrer policy. The host must additionally serve package assets with a restrictive CSP (including restricted `connect-src`, `form-action`, `frame-ancestors`) and govern immutable asset URLs and backend session lifetime. This module does not yet install custom screens in UniScript, publish frontend bundles or grant any credential access.
