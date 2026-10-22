# UniScript integration kit

Standalone PHP 8.4 SDK, synthetic examples, and trusted **local** build/certification/runtime tools. There are no Laravel dependencies or UniScript model imports. Package code executes in separate Docker containers. A PHP child process by itself is not isolation.

## Container-only local quickstart

A clean SDK checkout needs Docker Engine with Compose v2 on Linux, or Docker Desktop using Linux containers on macOS/Windows. The reviewed tool image contains PHP 8.4, the standalone SDK and examples. The workflow uses no private UniScript source.

These commands work in Linux/macOS shells and Windows PowerShell:

```sh
docker compose -f compose.dev.yaml up --build -d
docker compose -f compose.dev.yaml exec -T operator php bin/dev-workflow.php --example demo
```

Open the **Local operator** at `http://127.0.0.1:19090`. Its starter/demo buttons perform real ZIP inspection, Docker image build, independent conformance, deployment/readiness and runtime invocation. Demo 1.0.1 uses manifest v2: required positive numeric-string provider identity fields, optional mock mode, username/password credential schemas and declared typed reference storage. The trusted local fixture supplies synthetic IDs and generates private mock credentials; package code receives neither reusable secrets nor control-plane tokens.

The demo makes real HTTP calls through scoped grants to the synthetic vendor, verifies exactly two new orders despite duplicate/unknown-outcome requests, reconciles acceptance, and writes a declared typed reference resource. A separate configured synthetic callback fixture verifies authentication, encrypted receipt storage and scoped replay preparation. That callback fixture does not claim an automatic callback from the just-submitted order, and replay remains a proposal.

The Docker socket is mounted only into the trusted initializer/control-plane/worker. Broker, vendor, callback receiver and operator use separate private config/data volumes, run nonroot/read-only and have no Docker socket or host source mounts. Package images remain separate networkless, read-only, nonroot containers with no mounts or platform credentials. Only the operator has a loopback published port; the other tool APIs use the internal synthetic network. The operator's extra access network enables Docker Desktop loopback publishing and must not be mistaken for package network access.

The build context explicitly excludes `.git`, `.env`, `var` and application source. Private generated settings live only in named volumes. Docker builds pull public PHP/Docker CLI base images and compile the ZIP extension, so a fresh tool build needs internet access. Package containers still have no network. On this Mac's app-shell environment, Docker Desktop's credential helper requires `export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"`; ordinary terminals may already have it configured.

Choose a different UI port with `SDK_OPERATOR_PORT=19190 docker compose -f compose.dev.yaml up --build -d` on Linux/macOS, or `$env:SDK_OPERATOR_PORT="19190"` followed by the same Compose command in PowerShell. Use Docker Desktop's Linux engine; Windows container mode is unsupported. The automated clean workflow currently runs against a Linux Docker engine on macOS; separate Windows/Linux-host CI remains a portability gate.

After editing package source, rebuild the tool image and recreate services with `docker compose -f compose.dev.yaml up --build -d`. Copy generated ZIPs with `docker compose -f compose.dev.yaml cp operator:/artifacts/. ./dist`. The native SDK/packager below remains available when PHP is installed locally.

Stop local tools while preserving their private state:

```sh
docker compose -f compose.dev.yaml exec -T worker php bin/dev-cleanup.php
docker compose -f compose.dev.yaml down
```

Add `--volumes` to `down` for a deliberate synthetic reset. Run cleanup before deleting volumes so package deployment containers are removed as well. This affects only that Compose project's named state; it does not manage UniScript's environments. The local control plane, generated credentials and JSON/SQLite ledgers are developer tools, not hosted or production infrastructure.

Maintainers can run `php tests/clean-workflow.php` to build a fresh tagged image/project, exercise the real flow and UI protections, inspect isolation, and clean its own containers/volumes. This optional outer QA harness requires PHP on the host; the normal developer workflow above does not. Component checks include `php tests/local-synthetic-broker.php`, `php tests/resources.php`, and `php tests/resource-certification-docker.php`.

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

The iframe helper requires a separate origin, `sandbox="allow-scripts allow-same-origin"`, and no-referrer policy. The host must additionally serve package assets with a restrictive CSP (including restricted `connect-src`, `form-action`, `frame-ancestors`) and govern immutable asset URLs and backend session lifetime. The host installs its generic screen shell once; package uploads supply immutable frontend bundles. The bridge never grants credential access.

## Manifest version 2

Version 1 remains unchanged. Version 2 additionally requires `configuration_schema` and `credential_schema` packaged JSON file paths and permits an optional `resource_schemas` object mapping normalized resource names to packaged schema paths. Use names such as `schemas/credential.json`; the source policy deliberately rejects `credentials.*` files as potentially embedded credentials.

Configuration and credential schemas use the host-compatible scalar profile: root `type: "object"`, actual JSON-object `properties`, explicit unique `required` array and `additionalProperties: false`. Configuration fields support string, boolean, integer and finite number; credential fields support strings only. Fields support bounded strict typed enums, string lengths (0–4096), finite ordered numeric bounds, and plain-text titles/descriptions. Nested values, nulls, defaults, references, regexes and composition keywords reject the configuration/credential profile. Resource schemas use a separate closed-object profile with nested objects, bounded arrays (`items` plus `maxItems` 1–1000), and the same scalar rules. Resource definitions are limited to depth six, 200 total nodes and 50 properties per object; they remain closed to arbitrary references/composition. The certifier exercises version-2 storage writes against these declared resource names and value schemas. `ConfigurationSchema::validateValues` mirrors host value validation. Secrets remain vault-owned; the schema does not carry credential values.

Run `php tests/manifest-v2.php` for profile checks and `php tests/manifest-v2-docker.php` for actual version-2 build/certification/readiness. The existing LifeFile synthetic recipe remains manifest version 1. Its package ZIP can be checked with `php tests/lifefile-docker.php /path/to/lifefile.zip`.

## Synthetic callbacks and replay

`bin/callback-receiver.php` is a separate localhost simulator for host-owned callback responsibilities. Configure `SYNTHETIC_CALLBACK_CONFIG` to a private JSON file outside package source. It contains a private `state` directory, distinct 64-hex `payload_key_hex` and `replay_key_hex`, and an `endpoints` map keyed by random 32-hex endpoint IDs. Each endpoint has a random `signing_key` of at least 32 characters, `package_id`, organization/location/connection/environment IDs, `provider_account_handle`, `execution_revision`, and a trusted `references` map. Reference entries are lists of exact originating scope plus `order_handle`, `attempt_handle` and `execution_revision`. Production environments are rejected.

```sh
SYNTHETIC_CALLBACK_CONFIG=/private/path/callbacks.json \
  php -S 127.0.0.1:19083 bin/callback-receiver.php
php bin/callback-simulator.php --config /private/path/callbacks.json \
  --endpoint ENDPOINT_ID --url http://127.0.0.1:19083 \
  --delivery synthetic-delivery-a --file /private/path/synthetic-event.json
php tests/callbacks.php
php tests/callbacks-http.php
```

The synthetic body is `{ "external_reference": "synthetic-reference", "events": [...] }`. The simulator signs timestamp, delivery ID and exact bytes with per-endpoint HMAC. Timestamp skew, body limits and JSON shape are bounded. The receiver persists encrypted bodies and safe metadata, deduplicates stable account/environment deliveries across restarts and releases, and preserves the original order, attempt and decoder revision. Unmatched or ambiguous references remain held; payload-provided tenant identifiers cannot change scope.

Replay uses `SyntheticReplayAuthority` to issue short-lived `callback.replay` grants, signed with the separate local replay key. Claims bind actor, receipt, package, organization, location, connection, environment and expected receipt revision. `POST /receipts/{receipt}/replay` accepts that bearer grant once per revision and returns approved decode input plus lineage, with `applied: false`. It never invokes a domain mutation. The issuer is a trusted test utility: the production host must check actor permission and scope before issuing replay authority. No public grant-issuance endpoint exists.

These are local receipt/auth/replay demonstrations, not replacement production webhooks. Multi-file JSON/object writes are not crash-atomic, and replay preparation has no transactional dispatch outbox. Crash reconciliation, retention, vendor-specific authentication, mixed/out-of-order batches, callback routing between legacy and package implementations, decoder availability and real host application remain delivery gates.

## Remaining acceptance gates

| Gate | Local evidence | Still required |
| --- | --- | --- |
| Source and protocol | Deterministic ZIPs, strict DTO/schema tests, container conformance | Approved dependency mirrors/resolution, full canonical contracts and compatibility policy |
| Isolation | Nonroot/networkless/read-only disposable containers; real timeout and worker-loss cleanup tests | Production IAM/egress boundary, runtime-pool fairness, scale, OOM and restore drills |
| Release control | Local immutable image build/certification/deployment with separate role tokens | Durable distributed jobs/reconciliation, independent production approver and promotion/rollback controls |
| Scoped services | Synthetic grants and broker request protocol | Real host authorization/transport/ownership evidence; credential primitives and governed resource workflows |
| Callbacks | Local HMAC, encrypted receipts, scope, cross-release dedupe and replay lineage | Vendor authentication, durable receipt/outbox transactions, callback ownership cutover and real host work-item/review flows |
| UI | Host/client origin/window/nonce/session/release/revision tests | Immutable separate-origin asset publishing, CSP/browser QA and backend-bound session authorization |
| LifeFile | Reviewed synthetic eleven-operation certification recipe | Full characterization parity inventory, vendor sandbox verification, import/mapping workflows and operational recovery |
| Developer delivery | No private application dependencies; runnable local examples | Clean external-developer installation, versioned references, hosted synthetic sandbox and support/deprecation policy |

The production exit gate in the proposal remains broader than these local demonstrations. Installation and certification never imply pharmacy opt-in or vendor readiness.

## Real host/runtime fixture client

`HostRuntimeClient` is reusable trusted PHP test/host code. Its runtime URL and bearer token come from operator configuration, while the complete invocation—including a real host-issued active service grant—comes from the host. It validates the invocation/result protocol, verifies TLS for HTTPS, disables redirects, bounds bodies/deadlines and sends exactly one request. Transport loss or an invalid response becomes an explicit unknown outcome without resubmission.

```sh
# Export PACKAGE_RUNTIME_URL and PACKAGE_RUNTIME_TOKEN from a private environment.
# The prepared input file must be private and its service grant must already exist in the host ledger.
php bin/runtime-check.php --expect remote_acceptance < /private/path/invocation.json
```

The CLI prints only kind, reason code and an optional opaque external-reference handle. It never echoes the invocation, service grant, runtime token or arbitrary result/vendor bodies. It issues no grants and does not retry.

`php tests/host-runtime-client.php` tests the actual HTTP client/CLI, response validation, accepted-then-lost transport behavior and absence of retries. A full Laravel-host integration proof additionally requires a host-created connection, exact execution binding and active scoped invocation in the same database used by the host's service endpoint. Run the dispatch from a CLI/queue process; a single-threaded PHP development server cannot synchronously dispatch and serve its own broker callback. SQLite `:memory:` in a separate process cannot supply that shared ledger. Use an isolated shared MySQL test database or the authorized local fixture database, and verify the path container → trusted dispatcher → host grant broker → synthetic HTTP vendor → opaque ownership record.

### Optional static package screens (local synthetic profile)

Manifest v2 may declare `"ui":{"profile":"static-v1","entrypoint":"ui/index.html","surfaces":["connection.setup","catalog.item.mapping","pharmacy.order.details","sender.order.details"]}`. `surfaces` is an optional distinct list of one to four supported placements; omitting it retains legacy diagnostics only. All files below `ui/` must be precompiled HTML, JavaScript or CSS (100 files / 2 MiB total). The builder and independent certifier hash the sorted relative path → SHA-256 map; old packages retain the canonical empty UI digest. No uploaded code runs in the host and uploading a screen does not rebuild the host frontend. `examples/custom-ui` demonstrates the public bridge with only nonsensitive diagnostics.

After the host approves/deploys an exact execution, run `php artisan pos:package-ui-assets EXECUTION_UUID` in the host. It exports verified static bytes only. Start one trusted static server per execution, using a different port for each:

```sh
mkdir -p /tmp/uniscript-empty-ui-root
env -i PATH="$PATH" PACKAGE_UI_ROOT=/absolute/exported/execution/digest \
PACKAGE_UI_ORIGIN=http://pkg-11111111-1111-4111-8111-111111111111.localhost:19110 \
PACKAGE_UI_HOST_ORIGIN=http://127.0.0.1:8000 \
php -S 127.0.0.1:19110 -t /tmp/uniscript-empty-ui-root bin/ui-assets.php
```

On Windows PowerShell, launch PHP through a `System.Diagnostics.ProcessStartInfo` whose `Environment` is cleared, then add only PATH and those three PACKAGE_UI variables; use an empty local directory for `-t`. Do not start the asset process from an environment containing application credentials. Use the exact execution UUID in the `pkg-<execution UUID>.localhost` hostname; modern browsers resolve this reserved localhost name to loopback. No loopback aliases are added. The asset process needs only these variables and the exported files, and must not inherit host credentials. The router never bootstraps Laravel, serves PHP, or sets cookies. Host configuration requires `POS_PACKAGE_UI_ENABLED=true`, a `POS_PACKAGE_UI_ORIGINS` JSON map from exact execution UUID to its origin, and a null/empty session cookie domain. The host app must use `127.0.0.1`; plain localhost assets are rejected because historical application cookies may exist there. Differing ports alone do not separate cookies. Each execution must have its own exact UUID hostname and cannot share an origin. The trusted asset server rejects every request with a nonempty Cookie header before serving package bytes, including historical parent-domain cookies.

Host sessions stay on the authenticated CSRF-protected host origin; asset URLs contain no session or nonce. Every bridge query/action reauthorizes actor, organization, connection revisions, exact approved/certified artifacts, active deployment and static hashes. Every surface supports `context.read`, which identifies its bound surface and resource. Setup adds `config.read` and draft-only `config.save` with `{config}`. Catalog item mapping adds `mapping.read` and `mapping.save` with `{values,expected_revision}`; the item and `order_mapping` resource are fixed by the host session. Order surfaces add read-only `order.read` and `fulfillments.read`; sender sessions cannot mutate pharmacy settings or mappings. Legacy diagnostics retain only `context.read` and `diagnostics.refresh`.

Configuration reads expose only fields in the public pharmacy schema. Credentials, internal fields, patient records, raw vendor payloads, vendor reference values, tracking numbers and detailed fulfillment timestamps never enter the frame. Fulfillment projections provide carrier/status and `tracking_present`, `shipped`, and `delivered` booleans; full tracking stays on the host order page. The host reuses its normal actor/resource authorization, schema validation and optimistic revisions for writes. A successful save invalidates the screen session and returns `host_reload_required:true`; refresh host props before reopening. Order sessions use the current route's original dispatch/execution, not whichever package release is newest. Missing, undeclared or failed views retain standard host/schema controls. Host frontend bundling happens once when installing the generic shell; subsequent package uploads need no host route generation or frontend build.

CSP and iframe sandbox block fetch, images, forms, child frames, workers, popups and top navigation. Package save controls must use an explicit `type="button"` click handler, validate inputs in script, and call the host bridge; native form submission is intentionally unavailable. Keep secret input and lifecycle controls in the host UI. Browsers do not reliably enforce CSP `navigate-to` for a scripted frame's own navigation. This profile therefore does **not** claim complete browser egress isolation and must not receive sensitive data. Hosted origins, stronger browser egress controls and sensitive custom-screen capabilities remain acceptance gates.

Verification: `php tests/static-ui.php`, `php tests/static-ui-http.php`, and `php tests/static-ui-docker.php /absolute/custom-ui.zip` (Docker test). The real HTTP fixture confirms Host/CSP/hash/path guards; the Docker fixture independently rejects forged UI digest evidence.
