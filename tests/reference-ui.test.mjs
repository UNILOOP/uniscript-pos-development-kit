import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

function screen({valid = true, rejectSave = false, surface = 'catalog.item.mapping', needed = true} = {}) {
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
            if (operation === 'context.read') return {surface};
            if (operation === 'order.read') return {status:'queued_for_pos', delivery_status:'failed',pos_order_references:[]};
            if (operation === 'fulfillments.read') return {fulfillments:[]};
            if (operation === 'order.corrections.read') return {needed, editable:true,reason_code:'fixture.mapping_invalid',field_errors:[],mappings:[{catalog_item_uuid:'order-item',label:'Synthetic product',schema:{properties:{product_code:{type:'string'}},required:['product_code']},values:{product_code:'saved'},revision:7,editable:true}]};
            if (operation === 'order.setup.open') return {host_navigation:'/#connections'};
            if (operation === 'mapping.read') return {schema: {properties: {product_code: {type:'string',title:'Product'}, schedule_code: {type:'string',enum:['O','L']}},required:['product_code']},values:{product_code:'product-a',schedule_code:'O'},revision:3};
            if (operation === 'mapping.save') { if (rejectSave) throw new Error('stale'); return {host_reload_required:true}; }
            throw new Error('Unexpected bridge operation');
        }
        receive() {}
    }
    const source = readFileSync(new URL('../examples/reference/ui/screen.js', import.meta.url),'utf8').replace(/^import .*?;\n/, '');
    const document = {querySelector: key => selectors[key], createElement: tag => new Element(tag), documentElement: {dataset:{},scrollHeight:500}};
    vm.runInNewContext(source, {PackageBridgeClient:Client, document, parent, URL, ResizeObserver:class {observe() {}}, window:{addEventListener:(name, callback)=>listeners.set(name,callback)}});
    const initialize = () => listeners.get('message')({source:parent,origin:'http://127.0.0.1:19100',data:{kind:'uniscript.ui.initialize',version:'1',host_origin:'http://127.0.0.1:19100',nonce:'a'.repeat(64),session_id:'session',execution_revision:'execution',expected_revision:'revision',surface}});
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

test('reference pharmacy order demonstrates scoped mapping corrections and native setup without submitting an order', async () => {
    const app = screen({surface:'pharmacy.order.details'});
    await app.initialize();
    assert.equal(app.calls.some(call=>call.kind==='action'),false);
    const mapping = app.nodes.find(node=>node.tag==='button' && node.textContent==='Save product mapping');
    await mapping.listeners.get('click')();
    assert.deepEqual(JSON.parse(JSON.stringify(app.calls.find(call=>call.operation==='mapping.save').args)),{catalog_item_uuid:'order-item', values:{product_code:'saved'},expected_revision:7});
    const settings = app.nodes.find(node=>node.tag==='button' && node.textContent==='Open secure connection settings');
    await settings.listeners.get('click')();
    assert.ok(app.calls.some(call=>call.operation==='order.setup.open'));
    assert.equal(app.calls.some(call=>call.operation.includes('submit') || call.operation.includes('credentials')),false);
});

test('reference sender and unneeded pharmacy sections never render correction forms', async () => {
    for (const options of [{surface:'sender.order.details'},{surface:'pharmacy.order.details',needed:false}]) {
        const app=screen(options);await app.initialize();
        assert.equal(app.nodes.some(node=>node.tag==='form'),false);
        assert.equal(app.nodes.some(node=>node.textContent==='Open secure connection settings'),false);
    }
});
