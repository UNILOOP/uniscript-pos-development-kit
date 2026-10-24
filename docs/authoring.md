# Author a package

Run `./dev-kit new acme/my-pos my-pos`. Edit `workspace/my-pos`. Use `src/` for runtime PHP, `schemas/` for JSON contracts and `ui/` for compiled static screens. The reference template is generic; replace its synthetic implementation through approved managed services.

## Files

- `integration.json`: identity/version/runtime, operations, schema references and optional UI declaration.
- `composer.json` plus `composer.lock`: reproducible supported graph. The local standard profile currently supports dependency-free PHP with `require.php: ^8.4`, no third-party packages, install hooks or repositories.
- `src/runtime.php`: an operation-to-handler map. Handlers accept `Invocation` and `Services`, returning `Result`.
- `schemas/*.json`: supported input/output, configuration, credential and typed resource definitions.
- `ui/`: optional static HTML/JS/CSS. Build frontend source before export; do not ship `node_modules` or a frontend server.

## Manifest fields

| Field | Meaning |
| --- | --- |
| `manifest_version` | Shape version; v2 adds configuration, credential, resource and UI declarations |
| `package_id`, `publisher` | Assigned `publisher/package` identity; namespace must agree |
| `integration_key`, `vendor_family` | Stable selector and vendor grouping; avoid core reserved identifiers |
| `version` | Immutable release version; changed bytes require a new version |
| `sdk_version`, `protocol_version` | Compatible SDK and invocation protocol |
| `runtime` | Entrypoint, PHP, extensions and execution profile |
| `operations` | Unique key, handler descriptor, input/output schema paths and capabilities |
| `capabilities` | Requested required/optional governed capabilities; declarations do not grant authority |
| `configuration_schema` | Safe nonsecret connection values |
| `credential_schema` | Host-owned secret inputs |
| `resource_schemas` | Typed mapping/action resources |
| `ui` | Static-v1 entrypoint and supported surfaces |
| `support` | Support email and documentation URL |

Use the checked-in contract and examples for exact shapes and limits. The supported schema subset is deliberately bounded; arbitrary JSON Schema references/keywords are not guaranteed. Validate definitions before building forms around them.

Never embed `.env`, credentials, Git metadata, binaries or unsupported dependencies. Export outside the source directory. The packager rejects unsafe content rather than silently producing an installable-looking artifact. The scaffolder generates a matching lock content hash; manual dependency/identity changes must retain a correct lock.

Development directories (`tests`, `test`, `fixtures`, `dev-fixtures`, `var`, `dist`, `coverage`, `node_modules`) and Git/editor/CI metadata are excluded. Frontend package-manager manifests/locks and export reports are also excluded. Put runtime-required lookup JSON under `src/` or `resources/`, not an excluded development directory. Declaring an excluded path as an entrypoint/schema fails export. `.env` is refused rather than packaged.

The reference scaffolder also creates a matching offline companion fixture in `dev-fixtures/`. Edit it to model your vendor; it stays outside the source ZIP. Restart/reload the workbench to read changed trusted fixture data.
