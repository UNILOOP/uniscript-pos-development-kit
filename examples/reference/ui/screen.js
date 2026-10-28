import { PackageBridgeClient } from './bridge.js';

let client;
let binding;
const content = document.querySelector('#content');
const status = document.querySelector('#status');
function element(tag, text, className) { const value = document.createElement(tag); if (text !== undefined) value.textContent = String(text); if (className) value.className = className; return value; }
function resize() { if (binding) parent.postMessage({version:'1',kind:'uniscript.ui.resize',nonce:binding.nonce,session_id:binding.session_id,execution_revision:binding.execution_revision,height:Math.max(160,Math.min(10000,document.documentElement.scrollHeight))}, binding.host_origin); }
function failure() { status.textContent = 'This view needs a fresh host session. Refresh the section and try again.'; status.className = 'error'; resize(); }
function row(list, label, value) { const group = element('div'); group.append(element('dt',label),element('dd',value ?? 'Not recorded')); list.append(group); }
async function form(kind, supplied = null, extra = {}, target = content) {
    const response = supplied ?? await client.request('query',kind === 'config' ? 'config.read' : 'mapping.read',{});
    const form = element('form'); const fields = element('div',undefined,'fields'); const controls = new Map();
    for (const [name,definition] of Object.entries(response.schema?.properties ?? {})) {
        const label = element('label',definition.title ?? name.replaceAll('_',' '));
        let control;
        if (name === 'timezone_name') { control = element('select'); const zones=await client.request('query','reference.timezones',{}); for (const zone of zones.options ?? zones.timezones ?? zones) { const option=element('option',zone.label); option.value=zone.value; control.append(option); } }
        else if (definition.enum) { control=element('select'); for (const value of definition.enum) { const option=element('option',value); option.value=String(value); control.append(option); } }
        else { control=element('input'); control.type=definition.type==='integer'||definition.type==='number'?'number':'text'; if(definition.minLength)control.minLength=definition.minLength; if(definition.maxLength)control.maxLength=definition.maxLength; }
        control.name=name; control.required=(response.schema.required??[]).includes(name); control.value=response.values?.[name]??''; control.disabled=response.editable===false; controls.set(name,{control,definition}); label.append(control); if(definition.description)label.append(element('small',definition.description)); fields.append(label);
    }
    form.append(fields); const button=element('button',kind==='config'?'Save settings':'Save product mapping'); button.type='button'; button.disabled=response.editable===false; form.append(button);
    form.addEventListener('submit',event=>event.preventDefault());
    button.addEventListener('click',async()=>{if(!form.reportValidity())return;button.disabled=true; const values={}; for(const [name,{control,definition}]of controls){if(control.value!=='')values[name]=definition.type==='integer'||definition.type==='number'?Number(control.value):control.value;} try{await client.request('action',kind==='config'?'config.save':'mapping.save',kind==='config'?{config:values}:{...extra,values,expected_revision:response.revision??0}); status.textContent='Saved. Refresh this section to continue with the latest revision.';}catch{failure();button.disabled=false;} resize();});
    target.append(form); status.textContent=kind==='config'?'Configure this POS connection. Credentials and activation are managed by the host.':'Map this pharmacy product to its POS product.';
}
async function orders() {
    const order=await client.request('query','order.read',{}); const fulfillment=await client.request('query','fulfillments.read',{}); const details=element('dl');
    row(details,'Order status',order.status??order.order?.status);row(details,'POS delivery',order.delivery_status??order.delivery?.status??order.dispatch?.status??order.submission?.status??'Recorded');
    for(const reference of order.pos_order_references??order.external_references??order.references??[]){row(details,'POS reference',reference.fingerprint??reference.value_sha256??reference.handle);}
    content.append(details); const parcels=fulfillment.shipments??fulfillment.fulfillments??fulfillment.records??[];
    if(parcels.length===0){content.append(element('p','No shipments have been recorded yet.'));}
    for(const parcel of parcels){const section=element('section',undefined,'parcel');section.append(element('h2','Shipment'));const list=element('dl');row(list,'Status',parcel.status);row(list,'Carrier',parcel.carrier??parcel.carrier_name);row(list,'Tracking',parcel.tracking_present?'Recorded':'Not recorded');row(list,'Shipped',parcel.shipped_at_present??parcel.shipped?'Yes':'Not recorded');row(list,'Delivered',parcel.delivered_at_present??parcel.delivered?'Yes':'Not recorded');section.append(list);content.append(section);}
    status.textContent='POS order and fulfillment details.';
}
async function corrections() {
    const result = await client.request('query', 'order.corrections.read', {});
    if (result.needed !== true) return;
    const section = element('section');
    section.append(element('h2', 'Review POS delivery'), element('p', 'Save product corrections here. Saving never retries an order or changes a submitted prescription. Confirm uncertain delivery outcomes before another attempt.'));
    content.append(section);
    for (const mapping of result.mappings ?? []) {
        const panel = element('details');
        panel.append(element('summary', mapping.label || 'Product mapping'));
        panel.open = Boolean(mapping.reason_code && mapping.editable);
        section.append(panel);
        await form('mapping', mapping, { catalog_item_uuid: mapping.catalog_item_uuid }, panel);
    }
    const setup = element('button', 'Open secure connection settings');
    setup.type = 'button';
    setup.addEventListener('click', async () => {
        try { await client.request('action', 'order.setup.open', {}); }
        catch { failure(); }
    });
    section.append(setup);
    status.textContent = 'Review mappings or open the native connection controls. Credentials remain in the host.';
}
window.addEventListener('message',async event=>{
    if(client){client.receive(event);return;}
    const data=event.data;if(event.source!==parent||!data||data.kind!=='uniscript.ui.initialize'||data.version!=='1'||event.origin!==data.host_origin)return;
    try{const url=new URL(event.origin);if(url.protocol!=='https:'&&!(url.protocol==='http:'&&['127.0.0.1','localhost','[::1]'].includes(url.hostname)))return;
        binding=data;client=new PackageBridgeClient({hostOrigin:event.origin,peer:parent,nonce:data.nonce,sessionId:data.session_id,executionRevision:data.execution_revision,expectedRevision:data.expected_revision});document.documentElement.dataset.theme=data.theme?.mode??data.theme??'light';
        const context=await client.request('query','context.read',{});const surface=data.surface??context.surface;document.querySelector('#heading').textContent=surface==='catalog.item.mapping'?'Product mapping':surface==='connection.setup'?'Connection settings':'POS order details';
        if(surface==='connection.setup')await form('config');else if(surface==='catalog.item.mapping')await form('mapping');else {await orders();if(surface==='pharmacy.order.details')await corrections();}resize();
    }catch{failure();}
});
new ResizeObserver(resize).observe(document.documentElement);
