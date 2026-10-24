# Troubleshooting

| Symptom | Check |
| --- | --- |
| Cannot connect to Docker | Start Docker Desktop/Colima and verify `docker context ls` plus `docker version` |
| Missing Compose | Install the Docker Compose plugin; verify `docker compose version` |
| Image pull/build fails | Initial setup needs access to public base images; inspect proxy/DNS and disk capacity |
| Port already used | Set `POS_KIT_PORT=19101` consistently before commands |
| Source not listed | Source must be under `workspace/` or configured `POS_KIT_WORKSPACE`, with `integration.json` at package root |
| Stale screen/assets | Rebuild kit for SDK changes, reload package for source changes; released executions are immutable |
| Package iframe cannot resolve | Allow `*.localhost` loopback resolution; check browser/network policy rather than changing to same-origin assets |
| Configuration not active | Save required fields/credentials, rerun health/test for the current revision, then activate |
| ZIP rejected | Inspect manifest/lock, unsafe paths/files, supported extensions/dependencies and archive limits |
| Unknown profile/operation | Request host onboarding/contract support; local fixtures do not grant production access |
| Mapping save conflicts | Reload current revision, review changes, then save; do not overwrite blindly |
| Callback received but no update | Inspect decoding/application, reference/account ownership, event identity and order's execution pin |

Stop with `./dev-kit down`; state remains. Delete it only with the explicit reset command. Never use system-wide Docker prune as kit cleanup.

## Platforms

The primary runtime is Linux containers. macOS Docker Desktop/Colima and Linux Docker are intended entrypoints. Windows developers should use WSL2 with Linux containers and a POSIX shell. Native Windows PowerShell commands and every browser/architecture combination are not automatically certified; check release validation evidence for the tested combinations.

The Docker-only route avoids requiring host PHP/Composer/Node. Changing runtime code locally outside Docker requires PHP 8.4+ and relevant extensions. A working browser view does not prove every runtime extension or vendor feature is supported.
