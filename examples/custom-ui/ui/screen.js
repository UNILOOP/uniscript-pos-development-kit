import { PackageBridgeClient } from './bridge.js';
let client;
const status = document.getElementById('status');
const result = document.getElementById('result');
const refresh = document.getElementById('refresh');
function show(value) { result.textContent = JSON.stringify(value, null, 2); }
window.addEventListener('message', event => {
    if (client) { client.receive(event); return; }
    const data = event.data;
    if (event.source !== parent || !data || data.kind !== 'uniscript.ui.initialize' || data.version !== '1' || event.origin !== data.host_origin || !/^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(event.origin)) return;
    client = new PackageBridgeClient({ hostOrigin: event.origin, peer: parent, nonce: data.nonce, sessionId: data.session_id, executionRevision: data.execution_revision, expectedRevision: data.expected_revision });
    status.textContent = 'Nonsensitive sandbox diagnostics only.';
    refresh.disabled = false;
    client.request('query', 'context.read', {}).then(show).catch(() => { status.textContent = 'Host session unavailable.'; });
});
refresh.addEventListener('click', () => client?.request('action', 'diagnostics.refresh', {}).then(show).catch(() => { status.textContent = 'Host session unavailable or expired.'; }));
