# UniScript POS development kit

Build a POS integration, exercise its backend and embedded screens locally, and export the source ZIP that UniScript internal administrators install. This kit is vendor neutral; no LifeFile account, private UniScript checkout, AWS account or payment provider is required.

The kit contains an SDK, isolated PHP runtime, trusted build tools, generic browser workbench, synthetic fixtures, UI bridge and versioned UniScript contract. Package code runs in a networkless container and requests explicitly scoped host services.

## Quickstart

Install Git and Docker with Compose. Docker Desktop or Colima works on macOS; use Docker Engine with the Compose plugin on Linux. Start Docker first.

```sh
git clone https://github.com/uniloop/uniscript-pos-development-kit.git
cd uniscript-pos-development-kit
./dev-kit doctor
./dev-kit new acme/example-pos example-pos
./dev-kit up
```

Open **http://127.0.0.1:19100**. Select a reference or workspace package, create a connection, save test settings/credentials, run health and connection tests, activate, map a catalog item and submit a synthetic order. Exercise callbacks and inspect pharmacy/sender views.

The GitHub repository may not exist during local preparation. Inside UniScript, start with `cd pos-packages/developer-kit` instead. The public kit runs without private application source.

```sh
./dev-kit test
./dev-kit test --docker
./dev-kit package example-pos example-pos-1.0.0.zip
```

Artifacts appear in `dist/`. Upload the ZIP through internal POS package administration. Its separate `.report.json` identifies checks actually run. Publisher/profile registration, independent certification, internal approval and promotion remain UniScript's responsibility.

## Commands

| Command | Purpose |
| --- | --- |
| `./dev-kit doctor` | Verify Docker, Compose and configuration |
| `./dev-kit up` | Build/start the local workbench |
| `./dev-kit down` | Stop while retaining state |
| `./dev-kit status` | Inspect services |
| `./dev-kit logs` | Last 100 log lines |
| `./dev-kit new acme/my-pos my-pos [template]` | Scaffold in `workspace/my-pos`; templates: reference, starter, custom-ui |
| `./dev-kit package my-pos my-pos.zip` | Inspect and deterministically export |
| `./dev-kit test [--docker]` | Run checks, optionally real container execution |
| `./dev-kit reset --confirm-local-data-loss` | Explicitly remove this project's private volumes |

Use `POS_KIT_PORT` to change port 19100, or `POS_KIT_WORKSPACE` for another absolute source workspace. Keep settings consistent between commands. Keep secrets out of source workspaces.

Host PHP is optional. With PHP 8.4+, Zip and Docker, use `php bin/dev-kit.php` for authoring and `php bin/check.php --docker` for tests. Node is needed only to change/test the TypeScript bridge, not to use compiled package screens.

## Guides

1. [Architecture](docs/architecture.md)
2. [Authoring and manifest](docs/authoring.md)
3. [Runtime operations and host functions](docs/runtime.md)
4. [Connections and sender overrides](docs/connections.md)
5. [Every UI surface and bridge function](docs/ui.md)
6. [Mapping, orders, documents and actions](docs/orders-and-mapping.md)
7. [Webhooks and upgrades](docs/webhooks-and-upgrades.md)
8. [Vendor profiles and onboarding](docs/vendor-onboarding.md)
9. [Testing and submission](docs/testing-and-submission.md)
10. [Troubleshooting](docs/troubleshooting.md)
11. [Compatibility](docs/compatibility.md)
12. [Reviewed profile descriptors](docs/profile-descriptors.md)
13. [Workbench action API](docs/workbench.md)
14. [Optional external sandbox transport](docs/sandbox-transport.md)
15. [Catalog staging, import and rollback](docs/catalog-imports.md)

Machine-readable definitions live in `contracts/host-v1.json`; runnable packages in `examples/`; synthetic data in `fixtures/`. The kit itself is never part of a package ZIP.

## Scope

The workbench simulates host workflow state with synthetic data while exercising real package execution and iframe messaging. It does not certify private tenant authorization, clinical eligibility, billing, prescribing, payment collection or dispensing. UniScript conformance tests validate the identical archive against real host services before release.

MIT licensed. See [LICENSE](LICENSE) and [CONTRIBUTING.md](CONTRIBUTING.md).
