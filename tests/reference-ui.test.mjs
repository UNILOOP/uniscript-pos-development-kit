import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

function screen({valid = true, rejectSave = false} = {}) {
    const calls = [];
    const nodes = [];
    const listeners = new Map();
    const parent = {postMessage() {}};
    const selectors = {};
    class Element {
        constructor(tag) { this.tag = tag; this.children = []; this.listeners = new Map(); this.value = ''; this.disabled = false; nodes.push(this); }
        append(...children) { this.children.push(...children); }
        addEventListener(name, callback) { this.listeners.set(name, callback); }
        reportValidity() { return valid; }
    }
    for (const name of ['#content', '#status', '#heading']) selectors[name] = new Element('div');
    class Client {
        async request(kind, operation, args) {
            calls.push({kind, operation, args});
            if (operation === 'context.read') return {surface: 'catalog.item.mapping'};
            if (operation === 'mapping.read') return {schema: {properties: {product_code: {type:'string',title:'Product'}, schedule_code: {type:'string',enum:['O','L']}},required:['product_code']},values:{product_code:'product-a',schedule_code:'O'},revision:3};
            if (operation === 'mapping.save') { if (rejectSave) throw new Error('stale'); return {host_reload_required:true}; }
            throw new Error('Unexpected bridge operation');
        }
        receive() {}
    }
    const source = readFileSync(new URL('../examples/reference/ui/screen.js', import.meta.url),'utf8').replace(/^import .*?;\n/, '');
    const document = {querySelector: key => selectors[key], createElement: tag => new Element(tag), documentElement: {dataset:{},scrollHeight:500}};
    vm.runInNewContext(source, {PackageBridgeClient:Client, document, parent, URL, ResizeObserver:class {observe() {}}, window:{addEventListener:(name, callback)=>listeners.set(name,callback)}});
    const initialize = () => listeners.get('message')({source:parent,origin:'http://127.0.0.1:19100',data:{kind:'uniscript.ui.initialize',version:'1',host_origin:'http://127.0.0.1:19100',nonce:'a'.repeat(64),session_id:'session',execution_revision:'execution',expected_revision:'revision',surface:'catalog.item.mapping'}});
    return {calls,nodes,selectors,initialize};
}

test('mapping saves through an explicit bridge click when native sandbox form submission is unavailable',async()=>{
    const app = screen();
    await app.initialize();
    const input = app.nodes.find(node=>node.name==='product_code');
    const button = app.nodes.find(node=>node.tag==='button');
    assert.equal(button.type,'button');
    input.value='product-b';
    await button.listeners.get('click')();
    const save = app.calls.find(call=>call.operation==='mapping.save');
    assert.equal(JSON.stringify(save.args),JSON.stringify({values:{product_code:'product-b',schedule_code:'O'},expected_revision:3}));
    assert.match(app.selectors['#status'].textContent,/Saved/);
});

test('invalid fields do not send actions and stale saves allow a fresh-session retry',async()=>{
    const invalid = screen({valid:false}); await invalid.initialize();
    await invalid.nodes.find(node=>node.tag==='button').listeners.get('click')();
    assert.equal(invalid.calls.some(call=>call.kind==='action'),false);
    const stale = screen({rejectSave:true}); await stale.initialize();
    const button = stale.nodes.find(node=>node.tag==='button'); await button.listeners.get('click')();
    assert.equal(button.disabled,false);
    assert.match(stale.selectors['#status'].textContent,/fresh host session/);
});
