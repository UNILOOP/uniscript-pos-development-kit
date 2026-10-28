import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { stripTypeScriptTypes } from 'node:module';
import { createHash } from 'node:crypto';
import { PackageBridgeClient, operationHandlers } from '../ui/bridge.js';

const contract = JSON.parse(readFileSync(new URL('../contracts/host-v1.json', import.meta.url), 'utf8'));
const source = readFileSync(new URL('../ui/bridge.ts', import.meta.url), 'utf8');
test('distributed browser bridge comes from the host-pinned canonical source', () => {
    assert.equal(createHash('sha256').update(source).digest('hex'), contract.ui.bridge_sha256);
    assert.equal(readFileSync(new URL('../ui/bridge.js', import.meta.url), 'utf8'),
        '// Generated from bridge.ts using node:module stripTypeScriptTypes.\n' + stripTypeScriptTypes(source).replace(/[ \t]+$/gm, ''));
    assert.equal(typeof PackageBridgeClient, 'function');
});
test('advertised handler bindings never grant more than the selected surface', () => {
    for (const [surface, value] of Object.entries(contract.ui.surfaces)) {
        const actions = operationHandlers(value.actions, async () => ({}));
        assert.deepEqual(Object.keys(actions), value.actions);
        if (surface === 'sender.order.details') assert.deepEqual(actions, {});
    }
});
