# Testing and submission

## Test layers

| Layer | Proves |
| --- | --- |
| Source/schema/archive | Supported definitions, safe paths, deterministic bytes and lock consistency |
| SDK/runtime tests | Protocol bounds, grants, resources, failures and real container isolation |
| Workbench workflow | Synthetic configuration/probes/activation, overrides, mappings, orders, callbacks and upgrades |
| Browser | Real compiled iframe screens, bridge, resize/theme, errors and sender read-only behavior |
| UniScript conformance | Identical ZIP against actual host validators/services/permissions/routing/application |
| Vendor sandbox | Actual external API behavior, separately authorized/configured |

Run `./dev-kit test` and `./dev-kit test --docker`. Changing TypeScript additionally requires Node bridge tests: `node --test tests/ui-bridge.test.mjs`. Browser QA must use the real package assets; a screenshot of a mock component is not sufficient.

LifeFile is an optional second compatibility fixture. Keep its repository separate. For the Docker-only test, point `POS_KIT_WORKSPACE` at a directory containing that checkout and set `UNISCRIPT_LIFEFILE_PACKAGE=/workspace/lifefile-v2` before `./dev-kit test --docker`. For host PHP, set that variable to the actual absolute checkout path. The test runner reports whether this optional fixture was requested; normal public CI has no LifeFile/private-repository dependency.

Exercise negative cases: expired sessions/grants, spoofed origins/nonces, stale revisions, wrong connection/sender/environment, missing mappings, disabled overrides, unsupported actions, malformed/oversized payloads, unknown acceptance and callback replay. Confirm restart/upgrade retention and old-order pins.

## Debugging

Use `./dev-kit logs` and safe invocation/receipt summaries. Distinguish source inspection, build, local readiness, runtime result and canonical application. A receipt can be accepted by HTTP and fail decoding later. A local probe can succeed while hosted evidence is stale.

Never log credentials, clinical snapshots, OAuth tokens, raw vendor references or document contents. Use request IDs, reason codes, opaque handles and hashes. Keep private local state out of support attachments.

## Export

```sh
./dev-kit package my-pos my-pos-1.0.0.zip
```

This creates `dist/my-pos-1.0.0.zip` and `dist/my-pos-1.0.0.zip.report.json`. Export performs two deterministic packages and a real safe archive inspection. It does not pretend to run runtime/host installation tests: those fields remain explicitly not-run in an export-only report.

Submit the exact ZIP with its hash, contract version/hash, test results, requested capabilities/profiles and upgrade notes. Do not re-ZIP a containing directory or include the development kit, `workspace`, `dist`, Git metadata or secrets.

Internal administration performs authoritative inspection, independent certification, approval and promotion. Verify the artifact hash matches your report. Do not claim production compatibility from an offline fixture run alone.

## Host parity jobs

UniScript's opt-in `external-package` suite uses this submodule or an explicit `POS_PACKAGE_KIT_PATH`. Real runtime tests require an explicitly disposable `uniscript_pos_packages_test_*` MySQL database and opt-in flags. Never point them at a developer's working database, dev or production. Ordinary application deployment remains independent of package repositories.

From a UniScript checkout, use an explicitly disposable MySQL database with an ephemeral loopback port. Wait for that database to become ready, then run the generic fixture before the separately checked-out LifeFile fixture:

```sh
APP_ENV=testing \
APP_CONFIG_CACHE=/tmp/uniscript-package-qa-no-config.php \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$POS_KIT_TEST_DB_PORT" \
DB_DATABASE=uniscript_pos_packages_test_local \
DB_USERNAME="$POS_KIT_TEST_DB_USER" DB_PASSWORD="$POS_KIT_TEST_DB_PASSWORD" \
DB_URL= DB_SOCKET= CACHE_STORE=file SESSION_DRIVER=array \
QUEUE_CONNECTION=sync MAIL_MAILER=array STRIPE_DRIVER=fake \
AWS_EC2_METADATA_DISABLED=true POS_PACKAGE_FULL_RUNTIME_QA=1 \
vendor/bin/pest --configuration=phpunit.packages.xml \
  tests/Packages/ReferencePackageEndToEndTest.php \
  tests/Packages/LifeFileV2PackageEndToEndTest.php
```

Set the three `POS_KIT_TEST_DB_*` variables to the disposable database's connection details. The cache path must be absolute and nonexistent. Explicit empty `DB_URL` and `DB_SOCKET` prevent hidden connection overrides; the guard rejects a remote host, working database name, existing config cache or implicit credentials before destructive test setup. These suites create real local package containers and consume only the dedicated test `pos-packages` queue. They never start the scheduler or general queue. Remove only the dedicated database container when finished.
