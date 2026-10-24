# Optional live Sandbox transport

Offline synthetic vendor responses are the default. The kit can also send actual HTTP requests to a vendor Sandbox through a trusted host adapter while package containers remain networkless.

Live mode is opt in for one selected package. Probes and order actions can create real records in the vendor's Sandbox. Use the vendor's designated Sandbox endpoint and test credentials, plus synthetic order data. Local success does not authorize production endpoints or replace UniScript internal profile review.

## Enable it deliberately

1. Obtain the vendor's Sandbox endpoint, authentication requirements and permission to submit synthetic orders.
2. Put a reviewed JSON transport registry in `sandbox-profiles/profiles.json`, outside every package source directory and ZIP. The directory is a trusted operator mount; package code cannot edit it.
3. Use the same version 1 transport descriptors as UniScript. The registry contains endpoint and authentication **bindings**, not credential values.
4. Start the workbench with explicit selection:

```sh
POS_KIT_LIVE_SANDBOX=1 \
POS_KIT_LIVE_SANDBOX_PACKAGE=acme/example-pos \
./dev-kit up
```

Compose mounts the registry at `/sandbox-profiles/profiles.json`. A direct PHP server uses `POS_KIT_SANDBOX_PROFILES=/absolute/private/operator/profiles.json` as well. Enabling a flag without a valid registry or selected package does not grant any transport.

5. Confirm the workbench's live Sandbox banner names the intended package. Enter Sandbox credentials through the connection's host-owned credential controls, then run health and connection tests manually before activation.
6. Exercise only the vendor's permitted test workflow. The kit uses the original order's immutable credential and execution pins for later actions. Changing transport mode or registry invalidates current health/test and sender override proof; an existing order cannot be silently moved between synthetic and live transport.

Disable live mode and recreate the workbench when finished:

```sh
POS_KIT_LIVE_SANDBOX=0 ./dev-kit up
```

Ordinary `./dev-kit test` runs offline. The dedicated transport tests use local mocks and never contact a vendor. The package source and exported ZIP contain neither this operator registry nor credentials.

## Registry format

The outer document is:

```json
{
  "version": 1,
  "environment": "sandbox",
  "profiles": {
    "example.health": {
      "profile_version": 1,
      "enabled": true,
      "package_ids": ["acme/example-pos"],
      "operations": ["health.probe", "connection.test"],
      "method": "GET",
      "base_url": "https://sandbox.vendor.example/api",
      "path": "/identity",
      "account_key": "example-vendor",
      "account_fields": {"account": "account_id"},
      "config_headers": {"X-Account-ID": "account_id"},
      "authentication": {"scheme": "header_secret", "header": "X-Api-Key", "secret_field": "api_key"},
      "success_policy": "read_only_json",
      "success_path": "status",
      "success_value": "accepted"
    },
    "example.orders": {
      "profile_version": 1,
      "enabled": true,
      "package_ids": ["acme/example-pos"],
      "operations": ["orders.submit"],
      "method": "POST",
      "base_url": "https://sandbox.vendor.example/api",
      "path": "/orders",
      "account_key": "example-vendor",
      "account_fields": {"account": "account_id"},
      "config_headers": {"X-Account-ID": "account_id"},
      "authentication": {"scheme": "header_secret", "header": "X-Api-Key", "secret_field": "api_key"},
      "body_schema": {
        "type": "object",
        "properties": {
          "order_id": {"type": "string", "minLength": 1, "maxLength": 100},
          "account_id": {"type": "string", "minLength": 1, "maxLength": 100}
        },
        "required": ["order_id", "account_id"],
        "additionalProperties": false
      },
      "body_account_bindings": {"account_id": "account_id"},
      "success_path": "status",
      "success_value": "accepted",
      "reference_path": "id"
    }
  }
}
```

Replace example hosts, profile names and response bindings with the reviewed vendor contract. The package calls the profile name with its invocation-bound operation, idempotency key and body; it never supplies a URL, HTTP method, header or credential value.

`body_schema` is the portable closed-object wire schema. Unsupported schema keywords fail before sending. Server-specific Laravel `body_rules` alone are not a portable live-kit request contract: provide the shared wire schema. Account body bindings must match the connection's saved account fields. `numeric_account_fields` normalizes only explicitly declared numeric identities; the kit never invents LifeFile-style account headers.

Fixed paths may contain `{external_reference}` for an accepted reference handle owned by the original connection/account. Arbitrary paths, query strings, URLs and unscoped provider references from package code are rejected. `existing_reference_on_2xx` can acknowledge a mutation of that existing reference. `read_only_json` is restricted to GET operations that cannot provide order acceptance.

## Authentication

| Scheme | Trusted descriptor fields |
| --- | --- |
| Basic | `scheme:"basic"`, `username_field`, `password_field` |
| Bearer token | `scheme:"bearer"`, `token_field` |
| Secret header | `scheme:"header_secret"`, `header`, `secret_field` |
| OAuth 2 client credentials | `scheme:"oauth2_client_credentials"`, `token_url`, `client_id_field`, `client_secret_field`, `client_authentication:"basic"|"body"`, fixed `scopes:[]` |

Bindings refer to encrypted stored credentials. The host obtains OAuth tokens itself with a bounded request and keeps tokens out of package responses. Tokens are requested when needed; this developer adapter does not share or persist an OAuth token cache. Credential-looking response fields and reflected credential/token values are removed before returning vendor data to package code.

The adapter intentionally does not accept executable auth plugins, arbitrary request signing scripts, browser login flows, mutual TLS configuration or package-defined token URLs. Existing vendor-specific multiplexed profiles, such as a profile that takes an `operation_id` selector, require an explicit matching adapter. They do not silently fall back to synthetic success in live mode. LifeFile v2's bundled offline compatibility fixture remains available independently.

## Network and outcome boundaries

- HTTPS with certificate verification is required. DNS must resolve entirely to public addresses; loopback, private/link-local/reserved space, metadata destinations, mixed public/private answers and unsupported literal destinations are rejected.
- The resolved address is pinned for the request using [libcurl's `CURLOPT_RESOLVE`](https://curl.se/libcurl/c/CURLOPT_RESOLVE.html); the connected peer is checked. Protocols are restricted, proxies are disabled, redirects are not followed, and no cookie jar is used.
- HTTP work is bounded by the original invocation deadline, a maximum 3-second connection timeout, a maximum 10-second vendor request, 16 KiB response headers and at most 256 KiB response body. OAuth requests have a maximum 5-second timeout and 16 KiB response limit. Arguments default to 128 KiB; larger payloads require the exact governed package payload profile. The ordinary invocation deadline is 20 seconds.
- Mutations are claimed durably before sending. A concurrent or repeated logical mutation cannot dispatch another request. The same key with a different immutable request/account/profile/credential revision is rejected.
- Any unverified claimed managed operation remains an unknown outcome, including authentication errors and vendor rejection responses. No automatic retry is performed. Use a reviewed reconciliation operation or operator review. Read-only identity profiles reject unusable responses without pretending acceptance.
- Cached responses and raw accepted references are encrypted in private local state. Package code receives an opaque reference handle; only trusted host evidence carries the raw reference.

For local HTTP tests only, the constructor has a separate loopback test gate requiring CLI execution, `APP_ENV=testing`, `POS_KIT_TRANSPORT_LOOPBACK_TEST=1` and an explicit trusted constructor option. The environment factory and workbench never enable that option. Setting `allow_local_synthetic` in a profile cannot bypass live HTTPS/public-address rules.

## Verify without contacting a vendor

```sh
php tests/development-sandbox-test.php
php tests/development-sandbox-http-test.php
php tests/development-sandbox-http-test.php --docker
```

The first checks policy, DNS restrictions, auth bindings, credential redaction, encrypted durable claims and concurrent/lost-response behavior using injected test transport. The second performs actual HTTP against a temporary loopback mock, including OAuth, redirect rejection and response limits. With `--docker`, the real networkless reference package invokes the host adapter and proves that duplicate package invocations still produce one vendor mutation. No vendor or production API is called by these tests.
