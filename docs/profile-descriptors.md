# Reviewed host profile descriptors

Generic integrations use the same version 1 transport descriptors in the developer environment and the host. Internal operators own the authority. New ZIPs can submit release-scoped descriptors for internal approval using requirements contract version 2 below; older ZIPs request pre-registered profile names using version 1. A ZIP never grants itself authority or supplies secret values.

## Legacy named requirements (contract version 1)

An optional root `host-requirements.json` declares dependencies:

```json
{
  "contract_version": 1,
  "transport_profiles": ["aurora.identity", "aurora.orders"],
  "verification_profile": "aurora.account",
  "inbound_profiles": ["aurora.basic"]
}
```

The schema is `contracts/host-requirements-v1.schema.json`. Names are normalized strings up to 80 characters, with at most 32 entries per list. The file is limited to 16 KiB. Empty lists and a null verification profile are allowed. For contract version 1, unknown keys, duplicate names, URLs, secrets and profile definitions are rejected during intake.

UniScript stores these dependencies with the quarantined release and reports missing registrations. Independent build/certification may proceed, but approval, deployment and promotion require the requested profiles to be registered for the exact package. Removing a registration blocks later promotion. Packages without this optional file retain the older compatibility behavior; absence never grants vendor access.

## Trusted transport policy

After review, the host registers a descriptor under `pos-packages.host_services.profiles`, keyed by its profile name. This descriptor can be operator-registered for version 1 or submitted in the version 2 definitions map for internal review:

```json
{
  "profile_version": 1,
  "enabled": true,
  "package_ids": ["partner/aurora"],
  "operations": ["orders.submit"],
  "method": "POST",
  "base_url": "https://api.example.test",
  "path": "/orders",
  "account_key": "aurora-pos",
  "account_fields": {"site": "site_slug"},
  "config_headers": {"X-Site": "site_slug"},
  "authentication": {
    "scheme": "basic",
    "username_field": "account_user",
    "password_field": "account_secret"
  },
  "body_schema": {
    "type": "object",
    "additionalProperties": false,
    "required": ["order_id", "site"],
    "properties": {
      "order_id": {"type": "string", "maxLength": 100},
      "site": {"type": "string", "maxLength": 100}
    }
  },
  "body_account_bindings": {"site": "site_slug"},
  "success_path": "status",
  "success_value": "accepted",
  "reference_path": "order_id"
}
```

`body_schema` uses the bounded wire-schema mode supported by both SDK and host. Wire fields can retain vendor CamelCase or hyphenated names; ordinary stored-resource schemas keep their stricter lowercase names. Prototype-related keys are rejected. Unknown properties, invalid types and over-limit collections fail before HTTP. Existing host profiles may use Laravel `body_rules`; new portable descriptors should use `body_schema`.

Package calls provide only the profile name, matching operation/idempotency key, bounded body and an optional owned external-reference handle. The host chooses the URL and credentials. Only `{external_reference}` is a supported path substitution; the host resolves and encodes it from its own reference record. Configuration headers are explicit; version 1 profiles receive no automatic LifeFile numeric headers. Account values are exact strings unless the corresponding account name is included in `numeric_account_fields`.

### Fixed query parameters

Some vendors route every call through one endpoint and select the action in the query string. Declare those parameters in `query`; never put `?` in `path` or `base_url`.

```json
{
  "method": "GET",
  "path": "/dis.Main",
  "query": {
    "n": "API",
    "message": "{\"action\":\"getStatus\",\"orders\":[\"{external_reference}\"]}"
  }
}
```

- Up to 16 parameters. Names match `[A-Za-z_][A-Za-z0-9_.-]{0,79}`; each value is a fixed string of at most 2048 bytes, and names plus values total at most 4096 bytes.
- Values are reviewed with the release and are URL-encoded by the host (RFC 3986). Package code cannot add or change a parameter.
- `{external_reference}` is the only substitution. When it appears in `path` or in any query value, the call must carry `parameters.external_reference_handle`, and the host inserts the provider reference it recorded for that handle.
- A connection verification profile cannot use `{external_reference}`.

### Order documents in the request body

Vendors that take the prescription PDF inline as Base64 do not receive it from the package. Declare the body field in `body_document_bindings` on the `orders.submit` profile and the host fills it when it sends the request.

```json
{
  "method": "POST",
  "path": "/orders",
  "body_document_bindings": {
    "pdfScript": { "document": "prescription_pdf", "required": true }
  }
}
```

- `prescription_pdf` is the order's prescription PDF: the sender's attached PDF, or the document the platform generates when the destination requires one and the order allows it. The limit is 8 MiB before encoding.
- The package builds the rest of the body and leaves the bound field out. Supplying it is rejected. The field does not count against the package's request size limit, and it does not need a `body_schema` entry.
- With `required: true` the submission stops before any vendor request when no usable PDF exists (`package_prescription_pdf_unavailable`). With `required: false` the field is omitted.
- Bindings are accepted only on POST, PUT or PATCH profiles without a `success_policy`, and only for an order submission the host dispatched. Up to two fields may be bound.
- The stored request keeps a checksum marker in place of the content. `input.order.documents.host_bound` is `true` for packages whose release declares a binding.
- In the local sandbox the host inserts `DevelopmentSandboxTransport::SYNTHETIC_PRESCRIPTION_PDF`, a small valid PDF, so a mock vendor can assert the field arrives.

### Response paths and list positions

`success_path` and `reference_path` are dot paths into the decoded JSON response. A numeric segment (0-999) selects a list position, so `orders.0.externalOrderId` reads the first order of `{"orders": [{"externalOrderId": "..."}]}`. Up to eight segments; wildcards are not supported.

When a profile addresses an owned reference and does not use `existing_reference_on_2xx`, the response must satisfy `success_path` and return the same reference at `reference_path`. A missing reference is reported as `managed_acceptance_unknown` and a different one as `managed_reference_mismatch`. Use this for status and reconciliation endpoints that answer "ok" even for orders they do not know: point `reference_path` at the order entry the vendor echoes back.

`body_invocation_projection` governs non-reconcile order action payloads. It maps up to 16 reviewed body target paths to `{ "source": "input.relative.path", "field_map": { "sourceField": "vendorField" } }`; `source` is relative to invocation `input`, and `field_map` is optional and limited to 32 fields. The host compares the entire request body with the approved invocation projection before sending it. Packages cannot add fields or change approved action values.

Mutation success creates durable host-owned acceptance evidence and an opaque reference handle. Unknown remote outcomes remain unknown; the host does not blindly repeat a write. `success_policy: "existing_reference_on_2xx"` supports approved amendments against an already owned reference.

For non-order read operations, a GET profile can use `success_policy: "read_only_json"`. It returns bounded JSON in `{status: "accepted", data: ...}` after HTTP/optional success-field validation. This does not create an order reference or write-acceptance record and cannot be used for `orders.*` operations. Health and identity probes must not invent order acceptance evidence.

## Authentication bindings

Secrets come from the host vault or the immutable sender-credential revision selected for the invocation. No credential value appears in this descriptor or an iframe projection.

| Scheme | Required authentication fields |
| --- | --- |
| `basic` | `username_field`, `password_field` |
| `bearer` | `token_field` |
| `header_secret` | `header`, `secret_field` |
| `oauth2_client_credentials` | `token_url`, `client_id_field`, `client_secret_field`, `client_authentication`, `scopes` |

OAuth client authentication is `basic` or `body`. The token URL and scope list are fixed reviewed policy. Token responses are bounded, must return a Bearer token and are never returned to the package. Tokens are acquired in memory for the request; do not depend on a shared persistent token cache. Redirects, proxies, private/reserved resolved destinations and expired deadlines are rejected. Explicit local synthetic profiles may use loopback HTTP only in the local/testing host environment.

Reserved headers, such as Host, Authorization, cookies, content length and idempotency headers, cannot be supplied through configuration bindings. Use the dedicated authentication descriptor for authorization.

## Connection verification

The host registers a separate descriptor under `pos-packages.connection_verification_profiles`:

```json
{
  "profile_version": 1,
  "enabled": true,
  "package_ids": ["partner/aurora"],
  "transport_profile": "aurora.identity",
  "principal_path": "user.id",
  "success_path": "authenticated",
  "success_value": true,
  "account_response_bindings": {"site": "site"},
  "synthetic_only": false
}
```

The referenced transport must be a fixed GET endpoint, authorize `connection.test`, and use the same reviewed account/authentication bindings. Every declared account field must be proved by a matching response field. A stable authenticated principal plus account/endpoint/environment becomes the ownership identity. Configuration or profile changes invalidate the corresponding evidence. A successful offline package health result alone cannot establish account ownership.

A reviewed policy for `partner/aurora` then sets `self_service: true` and `verification_profile: "aurora.account"`. This policy is host controlled. When a package requests a verification profile, approval and promotion also require that exact package policy to enable self service and select the requested profile. Missing or mismatched policies appear as an actionable activation-policy requirement; registering an identity transport alone does not enable pharmacy activation. Pharmacy setup remains health → test → activate after the package release is approved and promoted. Sender overrides are tested through the same identity endpoint using their own saved credentials; orders pin the selected revision.

Local synthetic verification sets `synthetic_only: true`, enables the transport's `allow_local_synthetic`, and can declare `challenge_path: "challenge"`. The host sends a fresh `X-UniScript-Challenge`; the response must echo it and identify itself as synthetic. These proofs are local test evidence.

## Inbound profile

A reviewed callback profile has a version, label, package allowlist, reference/event field paths and account-field bindings. Authentication can be Basic, raw-body HMAC SHA-256 (`x-hmac-signature`) or a secret `X-API-Key` header; the profile selects exactly one mode. An optional `prescription_reference_field` additionally binds a callback to an accepted prescription in the same order. It belongs to the host inbound registry, separately from decoder code. Endpoint credentials are independently registered per connection, and their URL persists across compatible upgrades. Adding a requested profile name to a ZIP does not register an endpoint or configure it with the vendor.

## Extending the contract

An unsupported authentication, response protocol, canonical operation or identity proof needs a reviewed host/SDK change with conformance tests. Do not hide a vendor-specific network workaround in package code. Public local scenarios demonstrate behavior; independent certification and internal approval remain authoritative.

## Normalized callback proposal contract

The exported `host-v1.json.command_proposals.normalized_event` registry is generated from the validator used by UniScript. `CommandProposals::normalizedEvent()` validates that same registry before the local broker records a proposal. Shared positive and negative examples cover unknown fields, types, nullable values, string lengths and the 32 KiB encoded JSON limit. Authentication and receipt ownership remain host responsibilities. A successful proposal returns `proposal_handle`, `applied: false`, `receipt_verified: true` and `status: "pending_review"`; it does not apply an order update by itself.

Supported nullable string event fields include `rx_status`, `normalized_status`, `order_status`, event/reference identifiers, timestamps, carrier and tracking details. `tracking_present` and `status_upgraded_by_tracking` use the exported Laravel-compatible boolean values. `shipment_status` is not part of the command. Older orders still resolve through their pinned package execution and connection lineage.


## Release-scoped declarations (contract version 2)

New integrations can include reviewed descriptors directly in `host-requirements.json` without a UniScript config or Terraform edit. Set `contract_version` to `2`, add the exact `package_id`, and add `definitions` with `transport`, `verification`, `inbound`, and `policy` maps. Keep the requested profile-name lists. A complete synthetic declaration is in `tests/fixtures/release-host-requirements.json`.

Each profile must belong only to that package. Transport profiles use `profile_version: 1`, fixed HTTPS destinations, explicit operations, supported host-managed authentication, bounded portable `body_schema` for writes, account bindings, and explicit response evidence. Verification uses an authenticated GET identity endpoint and must prove every account field. The policy selects that verification profile and declares `self_service`. Inbound profiles use Basic, HMAC SHA-256, or a secret header and declare the reference, event, and account field paths. Secrets are stored by the host, never in the ZIP. Local synthetic exemptions and Laravel `body_rules` are not portable release declarations.

The internal uploader assigns the normalized publisher namespace. The quarantined release displays all declarations for internal review; build, certification, approval and promotion are still required. Uploading or running local scenarios alone grants no vendor access. Approved declarations are immutable and scoped to their original release, so upgrades and historical orders cannot silently acquire another package's access. Existing version 1 named registrations remain supported.

The kit accepts version 2 metadata for linting, deterministic ZIP creation and isolated execution. Live local sandbox traffic still requires the operator-owned sandbox profile registry: declarations in an unapproved ZIP do not automatically enable network access. UniScript performs the complete authority validation at ZIP intake and approval.


## Independent certification through internal admin

Release declarations do not replace independent certification. Before building a new version 2 release, an authorized internal package approver uploads a **separate** reviewed synthetic certification JSON from the release card. It is not read from a package file. The reviewer confirms synthetic-only data, exact ZIP digest and production scope. After the profile is recorded, use the normal build, certification, execution approval, deployment and promotion controls.

The JSON has exactly these keys:

- `version`: `1`.
- `package_id`, `package_version`, `source_sha256`: match the quarantined release exactly; copy the digest shown in internal admin.
- `readiness_operation`: `identity.describe`.
- `production_enabled`: explicit boolean reviewed by the operator; `false` cannot authorize a production deployment.
- `scenarios`: a map covering exactly every declared operation. Each operation has 1–100 synthetic cases with `input`, `kind`, and an exact `expected_calls` list. Every operation needs a successful case. Configuration validation, order validation/submission and inbound decoding also need rejection cases. Accepted writes must exercise `http.request`, and successful inbound decoding must exercise `commands.propose`. The first identity case uses empty input, succeeds, and calls no services.

The maximum file size is 256 KiB. Optional assertion fields are `expected_event`, `expected_events`, `expected_http_body`, `expected_http_by_operation`, `expected_http_operations`, `expected_http_projection`, `expected_proposals`, `expected_result_projection`, `services`, and `servicesByOperation`. They use the same scenario format as the existing trusted conformance fixtures. See `tests/reviewed-conformance-docker.php` for a complete synthetic build/certification/deployment example with a new namespace.

The certifier receives the reviewed profile over its authenticated host channel, checks its exact source scope and every declared handler against the isolated image, and returns a profile fingerprint with the normal image digests. The host verifies that fingerprint. The private profile stays outside package containers. Each new review has its own immutable fingerprint; it does not change old registry fingerprints or invalidate other vendors' certifications. Reviews cannot be replaced after a build; upload a corrected new release if the source or scenarios change. Existing baked-in reviewed profiles remain compatible.
