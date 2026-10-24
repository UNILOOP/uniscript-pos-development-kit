import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

function workbenchUi() {
    class Element {
        constructor(tag) { this.tag = tag; this.children = []; this.textContent = ''; }
        append(...children) { this.children.push(...children); }
        querySelectorAll() { return []; }
    }
    const main = new Element('main');
    const notice = new Element('p');
    const document = {getElementById: () => main, querySelector: () => notice, createElement: tag => new Element(tag)};
    const source = readFileSync(new URL('../ui/workbench/workbench.js', import.meta.url), 'utf8')
        .replace(/^import .*?;\n/, '')
        .split("document.getElementById('refresh').addEventListener")[0];
    const context = vm.createContext({document});
    vm.runInContext(source, context);
    const frames = [];
    context.embedded = (...args) => frames.push(args);
    return {context, frames, Element};
}

test('legacy diagnostics uses an explicit host fallback on every modern surface', () => {
    const app = workbenchUi();
    for (const surface of ['connection.setup', 'catalog.item.mapping', 'pharmacy.order.details', 'sender.order.details']) {
        const parent = new app.Element('main');
        const custom = app.context.packageView({}, {manifest: {ui: {profile: 'static-v1', entrypoint: 'ui/index.html'}}}, surface, parent);
        assert.equal(custom, false);
        assert.equal(app.frames.length, 0);
        assert.match(parent.children[0].textContent, /legacy diagnostics screen/);
        assert.match(parent.children[0].textContent, /host controls/);
    }
});

test('only a declared custom surface opens a package frame', () => {
    const app = workbenchUi();
    const pkg = {manifest: {ui: {profile: 'static-v1', entrypoint: 'ui/index.html', surfaces: ['connection.setup']}}};
    const parent = new app.Element('main');
    assert.equal(app.context.packageView({uuid: 'connection'}, pkg, 'connection.setup', parent), true);
    assert.equal(app.frames.length, 1);
    assert.equal(app.frames[0][1], 'connection.setup');
    assert.equal(parent.children.length, 0);
    assert.equal(app.context.packageView({}, pkg, 'catalog.item.mapping', parent), false);
    assert.equal(app.frames.length, 1);
    assert.match(parent.children[0].textContent, /does not declare a custom view/);
});

test('packages without UI use native controls and empty schemas are explained', () => {
    const app = workbenchUi();
    const parent = new app.Element('main');
    assert.equal(app.context.packageView({}, {manifest: {}}, 'connection.setup', parent), false);
    assert.equal(parent.children.length, 0);
    const settings = app.context.schemaForm({type: 'object', properties: {}, required: []});
    assert.equal(settings.fields.children[0].textContent, 'This schema does not require any fields.');
    assert.equal(JSON.stringify(settings.values()), '{}');
    assert.equal(settings.valid(), true);
    const credentials = app.context.schemaForm({type: 'object', properties: {}, required: []}, {}, true);
    assert.equal(credentials.fields.children[0].textContent, 'This package does not require API credentials.');
});
