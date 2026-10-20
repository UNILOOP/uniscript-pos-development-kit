import test from 'node:test';
import assert from 'node:assert/strict';
import { PackageBridge, createPackageFrame } from '../ui/bridge.ts';

const context = { sessionId: 'session-a', executionRevision: 'revision-a', expectedRevision: '7', actorId: 'actor-a', organizationId: 'organization-a', locationId: 'location-a', connectionId: 'connection-a', environment: 'sandbox' };
const nonce = 'a'.repeat(64);
function setup(overrides = {}) {
    const messages = []; let calls = 0; let authorizations = 0;
    const peer = { postMessage: (...args) => messages.push(args) };
    const options = { hostOrigin: 'https://host.example', packageOrigin: 'https://packages.example', peer, nonce, context, queries: { 'reference.list': async () => ({ records: [] }) }, actions: { 'reference.refresh': async (scope, args, id) => { calls++; return { connection: scope.connectionId, id, expectedRevision: scope.expectedRevision }; } }, authorize: async () => { authorizations++; return true; }, ...overrides };
    const bridge = new PackageBridge(options);
    const request = { version: '1', id: 'request-a', nonce, session_id: context.sessionId, execution_revision: context.executionRevision, expected_revision: '7', kind: 'action', operation: 'reference.refresh', arguments: {} };
    const send = (data = request, event = {}) => bridge.receive({ origin: options.packageOrigin, source: peer, data, ...event });
    return { bridge, request, send, messages, peer, calls: () => calls, authorizations: () => authorizations };
}

test('authorized action uses immutable host scope and exact target origin', async () => {
    const item = setup(); assert.equal(await item.send(), true); assert.equal(item.calls(), 1);
    assert.equal(item.messages[0][1], 'https://packages.example');
    assert.equal(item.messages[0][0].data.connection, 'connection-a');
});
for (const [name, change, event] of [
    ['origin', {}, { origin: 'https://evil.example' }], ['window', {}, { source: {} }],
    ['nonce', { nonce: 'b'.repeat(64) }], ['release', { execution_revision: 'other' }], ['stale revision', { expected_revision: '6' }],
    ['session', { session_id: 'other' }], ['unknown field', { actor_id: 'other' }],
    ['action class', { kind: 'query' }], ['unknown handler', { operation: 'credentials.reveal' }], ['array arguments', { arguments: [] }],
    ['version', { version: '2' }], ['prototype key', { arguments: JSON.parse('{"__proto__":{"admin":true}}') }],
]) {
    test(`rejects ${name} spoofing`, async () => {
        const item = setup(); assert.equal(await item.send({ ...item.request, ...change }, event), false); assert.equal(item.calls(), 0); assert.deepEqual(item.messages, []);
    });
}
test('duplicate action executes once while each request rechecks authority', async () => {
    const item = setup(); await Promise.all([item.send(), item.send()]); assert.equal(item.calls(), 1); assert.equal(item.authorizations(), 2); assert.equal(item.messages.length, 2);
    assert.equal(await item.send({ ...item.request, arguments: { different: true } }), false); assert.equal(item.calls(), 1);
});
test('revoked session does not execute an action or return cached data', async () => {
    const item = setup({ authorize: async () => false }); assert.equal(await item.send(), false); assert.equal(item.calls(), 0); assert.equal(item.messages.length, 0);
});
test('closing a bridge rejects stale tabs', async () => { const item = setup(); item.bridge.close(); assert.equal(await item.send(), false); });
test('same-origin frames and weak nonces are rejected', () => {
    assert.throws(() => setup({ packageOrigin: 'https://host.example' }), /separate_origin/);
    assert.throws(() => setup({ nonce: 'short' }), /strong_nonce/);
});
test('frame uses sandbox on a separate pinned asset origin', () => {
    const frame = { attributes: {}, setAttribute(key, value) { this.attributes[key] = value; } };
    const document = { createElement: tag => { assert.equal(tag, 'iframe'); return frame; } };
    const result = createPackageFrame(document, { assetUrl: 'https://packages.example/sha256-revision/screen.html', packageOrigin: 'https://packages.example', hostOrigin: 'https://host.example', title: 'Package screen' });
    assert.equal(result.referrerPolicy, 'no-referrer'); assert.equal(result.attributes.sandbox, 'allow-scripts allow-same-origin');
    assert.throws(() => createPackageFrame(document, { assetUrl: 'https://evil.example/x', packageOrigin: 'https://packages.example', hostOrigin: 'https://host.example', title: 'Package' }), /untrusted_frame/);
});

test('authorization failure is handled without exposing internal errors', async () => {
    const item = setup({ authorize: async () => { throw new Error('private diagnostic'); } });
    assert.equal(await item.send(), false); assert.deepEqual(item.messages, []);
});

test('package client and host shell complete an authorized request', async () => {
    const { PackageBridgeClient } = await import('../ui/bridge.ts');
    let client; let host;
    const hostPeer = { postMessage: message => { void host.receive({ origin: 'https://packages.example', source: packagePeer, data: message }); } };
    const packagePeer = { postMessage: message => client.receive({ origin: 'https://host.example', source: hostPeer, data: message }) };
    host = new PackageBridge({ hostOrigin: 'https://host.example', packageOrigin: 'https://packages.example', peer: packagePeer, nonce, context, queries: { 'reference.list': async () => ({ records: [{ id: 'opaque-reference' }] }) }, actions: {}, authorize: async () => true });
    client = new PackageBridgeClient({ hostOrigin: 'https://host.example', peer: hostPeer, nonce, sessionId: context.sessionId, executionRevision: context.executionRevision, expectedRevision: context.expectedRevision });
    assert.deepEqual(await client.request('query', 'reference.list', {}), { records: [{ id: 'opaque-reference' }] });
    client.close(); host.close();
});

test('package client rejects spoofed responses and times out safely', async () => {
    const { PackageBridgeClient } = await import('../ui/bridge.ts');
    let sent; const peer = { postMessage: value => { sent = value; } };
    const client = new PackageBridgeClient({ hostOrigin: 'https://host.example', peer, nonce, sessionId: context.sessionId, executionRevision: context.executionRevision, expectedRevision: context.expectedRevision });
    const promise = client.request('query', 'reference.list', {}, 10);
    const reply = { version: '1', id: sent.id, session_id: context.sessionId, execution_revision: context.executionRevision, ok: true, data: {} };
    assert.equal(client.receive({ origin: 'https://evil.example', source: peer, data: reply }), false);
    assert.equal(client.receive({ origin: 'https://host.example', source: {}, data: reply }), false);
    assert.equal(client.receive({ origin: 'https://host.example', source: peer, data: { ...reply, execution_revision: 'other' } }), false);
    await assert.rejects(promise, /bridge.timeout/); client.close();
});
