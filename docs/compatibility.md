# Compatibility bridge

`contracts/host-v1.json` is the public snapshot of UniScript's package contract. Its schema version and SHA-256 identify the supported protocol, limits, UI surfaces/operations/projections and supported profile. `ui/bridge.ts` is canonical source; `ui/bridge.js` is its distributable static module.

After changing the canonical TypeScript, regenerate its browser module with `node bin/build-ui-bridge.mjs` (Node 22.13 or later) and run `node --test tests/*.test.mjs`. Generation removes trailing whitespace consistently; the contract test verifies the exact compiled bytes and the host-pinned source hash.

UniScript keeps a safe own snapshot so ordinary builds/tests do not require initialized package submodules. Its contract export/check command compares that snapshot to the actual host registry. Optional conformance jobs compare the kit snapshot/bridge and inspect real ZIPs. A drift failure must be resolved as a deliberate compatibility change, not ignored by updating arbitrary hashes.

## Changing a contract

1. Identify whether the change is compatible or requires a new protocol/profile/version.
2. Update definitions, examples, docs and positive/negative tests together.
3. Update the actual host implementation and public snapshot.
4. Run kit tests and real UniScript conformance against identical artifacts.
5. Test current and previous package versions, especially upgrade retention and old-order pins.
6. Commit the kit, then record its Git submodule commit in UniScript.

Do not export private host classes, database models, tenant data, secrets or internal deployment configuration. Synthetic DTO fixtures and declarative contracts are the public boundary.

## Opt-in host compatibility CI

UniScript's `.github/workflows/pos-package-conformance.yml` runs only through **Run workflow** (`workflow_dispatch`). Ordinary deployment and application test workflows do not check out package repositories. The compatibility job separately checks out the published `uniloop/uniscript-pos-development-kit` repository at the selected `kit_ref`, checks both contract snapshots, runs standalone Docker/UI conformance and tests the generic reference package against the actual host.

Select a published, reviewed commit or tag when verifying a release. Use the same local checks when the host workflow is not yet available in GitHub Actions. **Include LifeFile** is off by default; enabling it separately checks out its repository and adds synthetic LifeFile runtime and UI ZIP-installation tests. If that repository is private, configure the host repository's optional `POS_PACKAGE_REPOSITORIES_TOKEN` secret with read-only access to it.

Every full host run uses a fresh MySQL 8.4 service with a database named `uniscript_pos_packages_test_ci`, exposed only at loopback port 33306. ZIP compatibility and installation checks use SQLite memory databases. Sandbox POS responses are synthetic, Stripe uses its fake driver, and no scheduler or general queue worker is started. The runtime database guard rejects non-test database names, non-loopback hosts, URL/socket overrides and cached application configuration.

## Repository and publication

The public repository is [uniloop/uniscript-pos-development-kit](https://github.com/uniloop/uniscript-pos-development-kit), with `main` as its primary branch. UniScript pins a reviewed kit commit through its Git submodule.

For an authorized release, review source/history, push the kit's `main` branch first, then push UniScript's recorded submodule pointer. Never push a parent pointer whose referenced commit is unavailable to intended developers.

## Glossary

Execution: immutable built source/runtime identity. Connection: pharmacy/location/environment setup. Revision: immutable settings/credential/mapping version. Grant: bounded invocation authority. Surface: declared iframe placement. Profile: host-reviewed transport/runtime policy. Receipt: authenticated inbound input, not proof of application. Proposal: package-requested canonical change awaiting host validation. Conformance: independent evidence against declared contracts, distinct from approval.
