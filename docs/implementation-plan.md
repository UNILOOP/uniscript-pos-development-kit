# Public POS package developer environment

Status: implementation plan, 24 October 2026. The kit is vendor neutral. LifeFile v2 is a compatibility fixture, not its architecture.

## Outcome and repository

Create `pos-packages/developer-kit` as an independently committed Git submodule. Its future remote is `https://github.com/uniloop/uniscript-pos-development-kit.git`; keep all commits local until that repository exists and publishing is requested. License the kit under MIT. Start from the reviewed, dependency-free integration SDK, preserving its runtime isolation rather than creating another runtime.

A developer must be able to clone the kit without UniScript source, run a Docker-based environment, create or load any compatible integration, exercise package code and embedded views with synthetic data, export a deterministic ZIP, and submit that exact ZIP to internal administration. UniScript remains the authority for publisher ownership, requested vendor access, certification, approval, deployment and tenant permissions.

The kit is also UniScript's compatibility bridge. Public contracts, schemas, UI protocol, fixtures and conformance tests are versioned with the kit. Host changes must update these contracts and demonstrate compatibility. An emulator result must never be presented as evidence that private billing, clinical, tenant authorization or vendor production behavior has been certified.

## 1. Supported package contract

- Document and validate manifest versions, package/publisher namespace, integration key, SemVer, PHP/runtime/SDK/protocol compatibility, operations, required/optional capabilities, support metadata, schemas and UI assets.
- Match UniScript's archive paths, file/member/count/expansion/compression/time limits; reject symlinks, traversal, unsupported executable/assets, Composer hooks, repositories and dependencies outside the supported runtime profile.
- Supply machine-readable runtime request/result, error, resource, configuration, credential, mapping, webhook and bridge contracts, with synthetic examples and explicit extension points.
- Catalog every operation and host function, with inputs, outputs, required scope, idempotency, credential exposure, retry rules and failure behavior. Cover identity, configuration validation, health, connection tests, catalog/reference data, validation/submission/reconciliation, order actions and inbound decoding.
- Unknown optional vendor operations and host profiles must produce actionable compatibility errors. Local author-defined scenarios are tests, not final certification recipes.

## 2. Easy, isolated local setup

- Docker Compose is the primary setup; no private UniScript repository, AWS access, production database or live vendor credentials are required.
- Provide doctor, initialize, start, stop, status, logs, test, package and deliberate reset commands. Preserve state on normal restarts and require an explicit reset for deleting it.
- Bind operator/workbench ports to loopback. Generate development-only secrets into ignored/private state; never ship reusable inbound or OAuth credentials.
- Keep package execution networkless, nonroot, read-only, resource bounded and without host paths, Docker socket or host secrets. Only trusted build/control-plane services may use Docker.
- Support macOS Docker Desktop/Colima and Linux Docker. Document Windows/WSL prerequisites truthfully; advertise tested platforms separately.
- Provide a clean-checkout smoke test and environment diagnostics with understandable fixes for ports, Docker, image pulls, permissions and PHP extensions.

## 3. Generic developer workbench

- Load a workspace directory or ZIP through a trusted packager, choose a package, inspect its identity/schemas/surfaces/capabilities, build, certify locally and deploy locally.
- A vendor-neutral reference integration demonstrates every supported surface and host function. Include a minimal starter and a custom UI starter. Package selection must never default to LifeFile.
- Use manifest/schema-driven forms when custom UI is absent, with validation messages, safe credential inputs, help text and timezone choices from the core reference contract.
- Include pharmacy/location/environment and sender contexts with synthetic fixtures. Clearly identify simulated data and local evidence.
- Provide actionable runtime/invocation/receipt diagnostics without exposing credentials, clinical details or raw sensitive references.

## 4. Setup, connection lifecycle and overrides

- Create and edit connection configuration and separately stored credentials; maintain immutable revisions and invalidate stale health/test evidence on relevant changes.
- Run configuration validation, health, connection tests, test freshness, activation, pause and revoke under the same declared prerequisites as UniScript.
- Display health status and allow deterministic local scheduled-check simulation. Do not start UniScript's scheduler or general queue.
- Create/test/enable/disable/revoke sender credential overrides, select default versus sender credentials, and pin the chosen revision for each order. Never expose secrets through an embedded view.
- Test multiple integrations and connections, tenant/sender/environment separation, inactive/current selection and draft visibility rules.

## 5. Generic transport and vendor onboarding

- Describe supported managed HTTP, account identity, credential binding, authentication, response mapping, limits, timeouts, idempotency and unknown-outcome behavior in a versioned contract.
- Provide a generic deterministic vendor simulator and local fixture profiles. Include success, credential rejection, validation failure, rate limiting, timeout, malformed output, remote-accepted-response-lost and reconciliation scenarios.
- A package cannot grant itself network access or declare itself certified. Requested production endpoints/authentication/account/webhook/certification profiles must be reviewed through the internal onboarding path.
- Remove accidental LifeFile-only assumptions from general package workflow policy and developer tooling while preserving existing approved integrations.
- Local profiles must be clearly marked test-only. Export unmet publisher/profile/capability requirements in the submission report; the host intake must report the same incompatibilities rather than fail later during activation.
- External sandbox testing is explicit and separate from the default offline synthetic workflow. Do not contact a live POS or create real orders during ordinary kit tests.

## 6. Shared embedded UI system

- Reuse one canonical bridge implementation and a versioned registry of supported surfaces/queries/actions. Verify any checked-in generated copies automatically.
- Support `connection.setup`, `catalog.item.mapping`, `pharmacy.order.details` and `sender.order.details` with the actual package HTML/JS/CSS.
- Reproduce initialization, theme tokens, loading/error/empty states, bounded resize, session expiry, refresh after mutation, revision checks, request replay handling and separate package execution origins.
- Setup bridge: `context.read`, `config.read`, `reference.timezones`, `config.save`. Mapping bridge: `context.read`, `mapping.read`, `mapping.save`. Both order bridges expose `context.read`, `order.read`, `fulfillments.read`. Pharmacy-only correction extensions now also provide `order.corrections.read`, safe configuration/timezone reads, order-bound `mapping.save`, draft-only `config.save`, and `order.setup.open`; these never resend an order. Sender views remain read-only. Document legacy diagnostics separately.
- Keep credential entry, probes, activation, sender overrides, webhook registration and order action controls host owned. Do not invent bridge functions unsupported in UniScript.
- Match CSP, exact origin/window/nonce/session/execution checks, bounded JSON, unsafe key rejection and session reauthorization. No iframe network, nested frames or arbitrary host navigation.
- Order views use redacted handles/fingerprints and shipment booleans; no patient/prescriber data, configuration, credentials, raw tracking or provider references leak to either audience.
- Include native-style UI examples, accessibility guidance, keyboard interaction, narrow-screen layouts, dark mode and guidance against nested scroll traps.

## 7. Catalog and mapping

- Exercise catalog preview/import, reference refresh, product identification and typed order/status/shipping mappings according to supported schemas.
- Use optimistic revision checks and immutable mapping revisions. Show validation, stale revision and unsupported mapping errors.
- Render drug mapping only for the currently routed active connection; orders retain the mapping and execution originally pinned to them.
- Test schema evolution and mapping retention across compatible upgrades, including explicit incompatibility reports without silent data loss.

## 8. Orders, actions and fulfillment

- Supply canonical synthetic patient, prescriber, medication, address, shipping, payment-context and document fixtures, including missing/invalid edge cases.
- Run real package validation/submission through the isolated runtime and scoped host service broker. Prove duplicate submission is idempotent and unknown acceptance is reconciled rather than blindly retried.
- Exercise declared status/shipping actions and vendor extensions with explicit capability/host authorization checks.
- Test documents through bounded scoped resources. Controlled orders remain rejected unless the verified original signed payload is actually supplied; metadata/hashes are insufficient.
- Show accepted, rejected, pending, unknown and recovered order states, then shipment and delivery in pharmacy and sender views.
- Keep simulated charges/bills separate from actual payment collection; the public kit does not implement or claim Stripe/clinical/dispensing parity.

## 9. Webhooks and upgrades

- Create independent per-connection callback endpoints and credentials, validate authentication before receipt persistence, and retain an endpoint across package upgrades.
- Provide an authenticated local callback sender plus malformed/auth-failed/duplicate/replay/out-of-order/reference-mismatch/tenant-mismatch scenarios.
- Decode with the actual package and apply only valid scoped proposals. Preserve original decoder/execution pins for old orders while new orders use the activated version.
- Prove Shipped then Delivered updates exactly one parcel and duplicate events do not duplicate state, shipment or simulated charges.
- Upgrade compatible configuration, credentials with explicit local choice, mappings and overrides; do not copy fresh health/test evidence. Preserve data and routing until the new connection is valid and activated.

## 10. Documentation and authoring tools

- Write a task-oriented README with a single quickstart and a complete documentation index.
- Guides: architecture/trust boundaries; creating an integration; manifest/runtime; every operation and host service; configuration/credentials; generic transport/onboarding; catalog/mapping; all UI surfaces and bridge calls; orders/documents/actions; webhooks; upgrades; testing/debugging; ZIP submission; troubleshooting; compatibility/versioning; contribution and security reporting.
- Include complete runnable examples, expected results, a glossary, supported-versus-simulated matrix and exact limitations. Avoid stale internal deployment history, tenant identifiers and private infrastructure information.
- Create package scaffolding, validation and deterministic export commands. ZIP only deliverable source/schema/UI/lock files; exclude `.git`, `.env`, local data, credentials, caches, reports and development tools.
- Produce a separate machine-readable report with package identity, artifact hash, contract version/hash, tests run, simulated/live scope and unmet onboarding requirements.

## 11. UniScript bridge and regression testing

- Pin the kit submodule in UniScript. Normal application deployment/tests must remain functional without initializing optional package submodules.
- Use the submodule as the preferred local SDK location; support an explicit override for standalone compatibility jobs. Remove accidental sibling-directory assumptions.
- Export/check the host contract without private classes or data. Require drift checks for validators, operations, projections, bridge, limits and compatibility metadata.
- Validate the exact exported generic reference ZIP with the actual host archive inspector and normal internal upload/approval/promotion path in an isolated local database.
- Run the same connection/mapping/override/order/webhook/upgrade scenarios against the mini-host and real host services. Normalize only nonsemantic random IDs/timestamps; preserve meaningful differences.
- Repeat with the existing LifeFile v2 package and a local vendor simulator. Do not require or embed LifeFile credentials or package source in the public kit.
- Add dedicated opt-in package compatibility CI, separate from ordinary application deployment. Every run uses disposable state and cannot touch dev/production databases, queues or schedulers.

## 12. Release and public readiness

- Audit tracked source and exported artifacts for secrets, private paths/data, internal docs and unintended dependencies; add MIT license and provenance.
- Keep local Git commits in the kit and host, using the configured default author and no co-author trailers. Record submodule commits after their tests pass.
- Do not create/push the future GitHub repository in this task. Keep a recoverable local origin until it exists and document the later remote switch/push order.
- Deliver setup commands, local browser URL, generated generic and LifeFile ZIP/report paths, checks performed, screenshots and known limitations.

## Implementation sequence and acceptance gates

1. **Foundation:** create and license the submodule, retain runtime/build tests, add the plan and compatibility inventory. Gate: clean SDK tests and safe local Git linkage.
2. **Contract bridge:** canonical UI/runtime contracts and host drift tests; fix known manifest/version/path mismatches. Gate: actual host validates generic and LifeFile ZIPs from the new location; ordinary CI stays package independent.
3. **Workbench:** durable generic mini-host, schema forms, real custom UI surfaces, host-owned controls and typed resources. Gate: reference package completes setup, mapping, override and order workflow with no vendor-specific UI logic.
4. **Execution/integration:** connect isolated runtime/broker, generic simulator, callbacks, actions and upgrade lineage. Gate: real package execution, authenticated callback progression, duplicate/unknown-outcome checks and preservation tests.
5. **Authoring/docs/submission:** scaffolder, doctor, deterministic export and reports, complete guides. Gate: fresh-checkout documented quickstart works without private sources.
6. **Host/LifeFile parity:** isolated real UniScript install/activate/order/webhook/upgrade suite and browser checks for all four surfaces. Gate: exact artifact and contract comparisons pass; no scheduler/general queue/payment jobs run.
7. **Final readiness:** security/public-content review, all focused and full kit checks, local commits and clean working trees. Record anything that still requires assigned publisher/vendor access or independent internal approval.

Completion means every gate has evidence. A working scaffold or simulated happy path alone is not completion.
