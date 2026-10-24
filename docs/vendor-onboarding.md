# Vendor profiles and onboarding

The package runtime has no direct vendor network access. `http.request` selects a trusted host profile; it does not accept an arbitrary URL or authorization header. A manifest is a request for capabilities, not permission to create them.

## Information to prepare

Provide your assigned publisher/package identity, vendor/environment base URLs, allowed methods/path templates, operation mapping, account identifiers, authentication scheme and secret field bindings, request/response schemas, response acceptance/reference extraction, timeouts/body limits/idempotency behavior, reconciliation procedure, and callback authentication/account/reference/event fields.

Describe which API calls are required for health versus optional features. A health probe should require only the access needed for the integration's essential behavior; an unrelated note/shipping listing privilege should not block the connection by accident.

## Three distinct approvals

1. Publisher ownership assigns a namespace.
2. Host profile review registers endpoint/authentication/account/inbound/certification policies.
3. Release review approves the exact source/build/certification/deployment artifacts.

Pharmacy self-service setup after a reviewed release is separate. Do not require pharmacy-side approval for a workflow whose host policy explicitly allows self-service; do not bypass release review in package JavaScript.

## Generic local testing

Use developer-owned synthetic fixtures to model success, auth failure, validation rejection, rate limiting, malformed responses, timeout, accepted-then-lost and reconciliation. Fixture profiles are local-only and cannot authorize production egress or constitute independent certification. Keep fixtures independent from live credentials.

The current host registry remains governed. A structurally valid ZIP can still have unmet publisher/profile requirements. The export report calls these out; resolve them with UniScript before deployment. If an integration needs an unsupported authentication/protocol/operation, extend the versioned host contract and conformance tests instead of shipping a private workaround.

External sandbox requests require explicit configuration and authorization. Default kit tests never contact a real vendor, process a payment or create a real prescription.
