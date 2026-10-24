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

## Local Git preparation

The planned public remote is `https://github.com/uniloop/uniscript-pos-development-kit.git`. While that repository does not exist, the attached local submodule remains usable and commits stay local. Preserve this checkout or a local Git bundle: a future remote cannot serve unpushed commits.

When publishing is separately requested, create the public repository, review source/history, push the kit's main branch first, then push UniScript's recorded submodule pointer. Never push a parent pointer whose referenced commit is unavailable to intended developers.

## Glossary

Execution: immutable built source/runtime identity. Connection: pharmacy/location/environment setup. Revision: immutable settings/credential/mapping version. Grant: bounded invocation authority. Surface: declared iframe placement. Profile: host-reviewed transport/runtime policy. Receipt: authenticated inbound input, not proof of application. Proposal: package-requested canonical change awaiting host validation. Conformance: independent evidence against declared contracts, distinct from approval.
