# Architecture

The environment separates three responsibilities:

1. Authoring tools inspect source, validate schemas and create deterministic ZIPs. They never run package PHP on the developer's host.
2. The trusted local host owns synthetic connection, credential, mapping, order, receipt and shipment state. It grants bounded services and projects safe data into package screens.
3. Package code runs in a disposable nonroot, read-only Docker container without network, bind mounts or Docker socket. It accesses scoped services through the SDK protocol.

The workbench and control plane are trusted tools with Docker access, which is powerful host access. Bind them to loopback and review modifications. Package containers never receive this access.

The trusted base-image step installs the published PHP extension set from the official PHP image and Alpine repositories before any package source is copied. Its small build context contains only the SDK's base Dockerfile. Subsequent package builds use `--network none`, record the exact base/image digests and independently verify base-layer ancestry during certification. First setup may need public image/package downloads; cached bases are reused. A custom `PACKAGE_BASE_IMAGE` must be explicitly provided by the operator, have an immutable repository digest, and satisfy the same PHP 8.4/extension profile.

## Flows

`Workspace → deterministic ZIP → inspection → immutable build → isolated invocation → scoped services → result`

`Host browser → isolated package iframe → shared bridge → session authorization → redacted result`

`Callback → endpoint authentication → receipt → pinned package decoder → proposals → host validation → shipment/order state`

The package proposes; the host applies canonical changes. An accepted-looking package result alone is not durable evidence that a vendor accepted an order.

## Storage

Normal stop/start retains state in the Compose `workbench-data` volume. Workspace source and `dist/` artifacts are separate bind mounts, excluded from the tool image. Credentials belong in private ignored state, never package source, fixtures, ZIPs, screenshots or reports.

Reset deletes this project's volumes only, retaining source and exported artifacts. Version control protects source, not transient local workflow data.

## Boundaries

Default scenarios are synthetic and offline. No prescription is sent and no shipping label or payment is purchased. Local success is neither independent certification nor production approval. Real vendor sandbox testing and UniScript conformance testing are separate gates, each with its own evidence.
