# Reviewed host profile descriptors

Generic integrations use the same version 1 descriptors in the developer environment and the host. These are operator-owned policies outside the package. A ZIP can request profile names; it cannot supply authoritative URLs, secrets, authentication policies or approvals.

## What belongs in the ZIP

An optional root `host-requirements.json` declares dependencies:

```json
{
  "contract_version": 1,
  "transport_profiles": ["aurora.identity", "aurora.orders"],
  "verification_profile": "aurora.account",
  "inbound_profiles": ["aurora.basic"]
}
```

The schema is `contracts/host-requirements-v1.schema.json`. Names are normalized strings up to 80 characters, with at most 32 entries per list. The file is limited to 16 KiB. Empty lists and a null verification profile are allowed. Unknown keys, duplicate names, URLs, secrets and profile definitions are rejected during intake.

UniScript stores these dependencies with the quarantined release and reports missing registrations. Independent build/certification may proceed, but approval, deployment and promotion require the requested profiles to be registered for the exact package. Removing a registration blocks later promotion. Packages without this optional file retain the older compatibility behavior; absence never grants vendor access.

## Trusted transport policy

After review, the host registers a descriptor under `pos-packages.host_services.profiles`, keyed by its profile name. This example is policy documentation, not a declaration a package may install:

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

A reviewed Basic callback profile has a version, label, package allowlist, reference/event field paths and account-field bindings. It belongs to the host inbound registry, separately from decoder code. Endpoint credentials are independently registered per connection, and their URL persists across compatible upgrades. Adding a requested profile name to a ZIP does not register an endpoint or configure it with the vendor.

## Extending the contract

An unsupported authentication, response protocol, canonical operation or identity proof needs a reviewed host/SDK change with conformance tests. Do not hide a vendor-specific network workaround in package code. Public local scenarios demonstrate behavior; independent certification and internal approval remain authoritative.

## Normalized callback proposal contract

The exported `host-v1.json.command_proposals.normalized_event` registry is generated from the validator used by UniScript. `CommandProposals::normalizedEvent()` validates that same registry before the local broker records a proposal. Shared positive and negative examples cover unknown fields, types, nullable values, string lengths and the 32 KiB encoded JSON limit. Authentication and receipt ownership remain host responsibilities. A successful proposal returns `proposal_handle`, `applied: false`, `receipt_verified: true` and `status: "pending_review"`; it does not apply an order update by itself.

Supported nullable string event fields include `rx_status`, `normalized_status`, `order_status`, event/reference identifiers, timestamps, carrier and tracking details. `tracking_present` and `status_upgraded_by_tracking` use the exported Laravel-compatible boolean values. `shipment_status` is not part of the command. Older orders still resolve through their pinned package execution and connection lineage.
