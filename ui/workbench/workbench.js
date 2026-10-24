import { PackageBridge, createPackageFrame } from '/bridge.js';

const main = document.getElementById('main');
const message = document.getElementById('message');
const defaultNoticeText = document.querySelector('.notice').textContent;
let state;
let csrf;
let selectedConnection;
let selectedOrder;
let orderAudience = 'pharmacy';
let selectedCatalogImport;
let catalogMode = 'mapping';
let catalogPageView;
let operationOutput;
let frameCleanup = [];

function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined && text !== null) node.textContent = String(text);
    if (className) node.className = className;
    return node;
}

function human(value) {
    return String(value ?? '').replaceAll('_', ' ').replaceAll('.', ' · ').replace(/^./, (text) => text.toUpperCase());
}

function notify(text, error = false) {
    message.textContent = text;
    message.className = error ? 'error' : '';
}

async function request(path, input, form = false) {
    const response = await fetch(path, { method: 'POST', credentials: 'same-origin', mode: 'same-origin', redirect: 'error', headers: { 'X-CSRF-Token': csrf, ...(form ? {} : { 'Content-Type': 'application/json' }) }, body: form ? input : JSON.stringify(input) });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error ?? 'workbench_request_failed');
    return result;
}

async function refresh() {
    const response = await fetch('/api/state', { credentials: 'same-origin', mode: 'same-origin', redirect: 'error' });
    if (!response.ok) throw new Error('workbench_unavailable');
    state = await response.json();
    csrf = state.csrf;
    if (state.vendor_transport?.live_sandbox_enabled) {
        document.querySelector('.notice').textContent = `Live Sandbox vendor requests are enabled for ${state.vendor_transport.selected_package_id}. Host workflows remain simulated. Probes and order actions can contact the configured external Sandbox and create vendor test records.`;
    } else {
        document.querySelector('.notice').textContent = defaultNoticeText;
    }
    render();
}

async function action(name, arguments_, success = 'Saved.') {
    const result = await request('/api/action', { action: name, arguments: arguments_ });
    await refresh();
    const probe = result.result;
    notify(probe && !['success', 'remote_acceptance'].includes(probe.kind) ? `${human(probe.kind)}: ${human(probe.reason_code)}. Review activity for details.` : success, probe && !['success', 'remote_acceptance'].includes(probe.kind));
    return result;
}

function button(text, callback, className = '') {
    const node = element('button', text, className);
    node.type = 'button';
    node.addEventListener('click', async () => {
        const previous = node.textContent;
        node.disabled = true;
        node.textContent = 'Working…';
        try { await callback(); }
        catch (error) { notify(human(error.message) + '.', true); }
        finally { node.disabled = false; node.textContent = previous; }
    });
    return node;
}

function badge(text, positive = false) {
    return element('span', human(text), `badge${positive ? ' good' : ''}`);
}

function intro(title, description) {
    const section = element('div', undefined, 'intro');
    const heading = element('div');
    heading.append(element('h1', title), element('p', description));
    section.append(heading);
    main.append(section);
    return section;
}

function card(title, description, parent = main) {
    const section = element('section', undefined, 'card');
    if (title) section.append(element('h2', title));
    if (description) section.append(element('p', description, 'muted'));
    parent.append(section);
    return section;
}

function schemaFallback(title, description, collapsible) {
    if (!collapsible) return card(title, description);
    const details = element('details', undefined, 'card');
    details.append(element('summary', 'Schema fallback preview'));
    const section = element('section');
    section.append(element('h2', title), element('p', description, 'muted'));
    details.append(section); main.append(details);
    return section;
}

function packageView(connection, pkg, surface, parent, catalogItemId, orderId) {
    if (pkg.manifest.ui?.surfaces?.includes(surface)) {
        embedded(connection, surface, parent, catalogItemId, orderId);
        return true;
    }
    if (pkg.manifest.ui) {
        const legacy = !pkg.manifest.ui.surfaces;
        parent.append(element('p', legacy
            ? 'This package declares a legacy diagnostics screen. This page uses the host controls below. Declare this surface in ui.surfaces to add a custom view.'
            : 'This package does not declare a custom view for this page. The host controls and details are shown below.', 'hint'));
    }
    return false;
}

function empty(parent, title, description, control) {
    const node = element('div', undefined, 'empty');
    node.append(element('h3', title), element('p', description));
    if (control) node.append(control);
    parent.append(node);
}

function actions(parent, controls) {
    const row = element('div', undefined, 'actions');
    row.append(...controls);
    parent.append(row);
    return row;
}

function facts(parent, values) {
    const list = element('dl', undefined, 'facts');
    for (const [label, value] of values) {
        const cell = element('div');
        cell.append(element('dt', label), element('dd', value ?? 'Not recorded'));
        list.append(cell);
    }
    parent.append(list);
}

function inputField(title, value = '', type = 'text', description) {
    const label = element('label', undefined, 'field');
    label.append(element('span', title));
    const input = element(type === 'textarea' ? 'textarea' : 'input');
    if (type !== 'textarea') input.type = type;
    input.value = value;
    label.append(input);
    if (description) label.append(element('span', description, 'hint'));
    return { label, input };
}

function selectField(title, options, selected, description) {
    const label = element('label', undefined, 'field');
    label.append(element('span', title));
    const input = element('select');
    for (const [value, title_] of options) {
        const option = element('option', title_);
        option.value = value;
        input.append(option);
    }
    if (selected !== undefined) input.value = selected;
    label.append(input);
    if (description) label.append(element('span', description, 'hint'));
    return { label, input };
}

function schemaForm(schema, values = {}, credentials = false) {
    const fields = element('div', undefined, 'fields');
    const getters = {};
    if (!Object.keys(schema?.properties ?? {}).length) {
        fields.append(element('p', credentials ? 'This package does not require API credentials.' : 'This schema does not require any fields.', 'hint'));
    }
    for (const [name, definition] of Object.entries(schema?.properties ?? {})) {
        let control;
        let get;
        const title = definition.title ?? human(name);
        if (definition.type === 'boolean') {
            control = inputField(title, '', 'checkbox', definition.description);
            control.input.checked = values[name] === true;
            get = () => control.input.checked;
        } else if (definition.enum) {
            control = selectField(title, [['', 'Choose a value'], ...definition.enum.map((value) => [String(value), human(value)])], values[name] === undefined ? '' : String(values[name]), definition.description);
            get = () => definition.enum.find((value) => String(value) === control.input.value);
        } else if (['object', 'array'].includes(definition.type)) {
            control = inputField(title, JSON.stringify(values[name] ?? (definition.type === 'array' ? [] : {}), null, 2), 'textarea', definition.description);
            control.label.classList.add('wide');
            get = () => JSON.parse(control.input.value);
        } else if (name === 'timezone_name') {
            const zones = state?.reference_timezones ?? [];
            const selected = values[name] ?? '';
            const options = [['', 'Choose a time zone'], ...zones.map(({ value, label }) => [value, label])];
            if (selected && !zones.some(({ value }) => value === selected)) options.push([selected, selected]);
            control = selectField(title, options, selected, definition.description);
            get = () => control.input.value;
        } else {
            const numeric = ['integer', 'number'].includes(definition.type);
            control = inputField(title, credentials ? '' : values[name] ?? '', credentials ? 'password' : numeric ? 'number' : 'text', definition.description);
            if (numeric) {
                control.input.step = definition.type === 'integer' ? '1' : 'any';
                if (definition.minimum !== undefined) control.input.min = definition.minimum;
                if (definition.maximum !== undefined) control.input.max = definition.maximum;
            }
            if (definition.minLength !== undefined) control.input.minLength = definition.minLength;
            if (definition.maxLength !== undefined) control.input.maxLength = definition.maxLength;
            get = () => numeric ? Number(control.input.value) : control.input.value;
        }
        control.input.name = name;
        control.input.required = (schema.required ?? []).includes(name);
        control.input.autocomplete = credentials ? 'new-password' : 'off';
        getters[name] = () => {
            if (control.input.value === '' && definition.type !== 'boolean' && !(schema.required ?? []).includes(name)) return undefined;
            return get();
        };
        fields.append(control.label);
    }
    return { fields, values: () => Object.fromEntries(Object.entries(getters).map(([name, get]) => [name, get()]).filter(([, value]) => value !== undefined)), valid: () => [...fields.querySelectorAll('input,select,textarea')].every((control) => control.reportValidity()) };
}

function table(parent, headings, rows) {
    const wrap = element('div', undefined, 'table-wrap');
    const node = element('table');
    const header = element('tr');
    header.append(...headings.map((heading) => element('th', heading)));
    const thead = element('thead'); thead.append(header);
    const tbody = element('tbody');
    for (const row of rows) {
        const tr = element('tr');
        for (const value of row) {
            const cell = element('td');
            if (value instanceof Node) cell.append(value);
            else cell.textContent = String(value ?? '—');
            tr.append(cell);
        }
        tbody.append(tr);
    }
    node.append(thead, tbody); wrap.append(node); parent.append(wrap);
}

function packageFor(connection) { return state.packages.find((value) => value.execution_revision === connection.execution_revision); }
function connectionLink(connection) { selectedConnection = connection.uuid; location.hash = 'connections'; render(); }
function detailsJson(parent, title, value) { const details = element('details'); details.append(element('summary', title), element('pre', JSON.stringify(value, null, 2))); parent.append(details); }

function packagesPage() {
    intro('Your POS packages', 'Start with a reference integration, load your own workspace, or test a ZIP before you submit it to UniScript.');
    const load = card('Load a package', 'Choose source code from the examples or configured workspace. Builds run in isolated Docker containers and can take a minute on first use.');
    const row = element('div', undefined, 'row');
    const source = selectField('Package source', [['', 'Choose a package'], ...state.sources.map((value) => [value.id, value.label])]);
    source.label.classList.add('grow');
    row.append(source.label, button('Build and load', async () => {
        if (!source.input.value) throw new Error('choose_a_package');
        const result = await request('/api/load', { source_id: source.input.value });
        await refresh(); notify(`Loaded ${result.manifest.display_name} ${result.manifest.version}.`);
    }));
    load.append(row, element('hr', undefined, 'divider'));
    const upload = element('div', undefined, 'file-upload');
    const file = element('input'); file.type = 'file'; file.accept = '.zip,application/zip'; file.setAttribute('aria-label', 'Package ZIP');
    upload.append(file, button('Load ZIP', async () => {
        if (!file.files[0]) throw new Error('package_zip_required');
        const data = new FormData(); data.append('package', file.files[0]);
        await request('/api/upload', data, true); await refresh(); notify('Package ZIP inspected, built and loaded.');
    }, 'secondary'));
    load.append(upload);
    if (!state.packages.length) return empty(main, 'No packages loaded', 'The generic reference package covers setup, product mapping, orders, managed services and all four embedded views. Select examples/reference to get started.');
    const list = card('Loaded versions', 'Development builds are not independent certification or approval. Your exported ZIP still goes through UniScript internal review.');
    table(list, ['Package', 'Version', 'Local build', 'Actions'], state.packages.map((pkg) => {
        const name = element('div', pkg.manifest.display_name); name.append(element('small', pkg.manifest.package_id));
        return [name, pkg.manifest.version, badge('Ready for testing', true), button('Set up connection', () => { selectedConnection = undefined; location.hash = `connections?package=${pkg.execution_revision}`; render(); }, 'secondary small')];
    }));
}

function connectionCreate(packageId) {
    const pkg = state.packages.find((value) => value.execution_revision === packageId) ?? state.packages[0];
    if (!pkg) return empty(main, 'Load a package first', 'A connection uses the configuration and credential schemas in your package.', button('Open packages', () => { location.hash = 'packages'; }));
    const section = card('New POS connection', 'Saved values stay in your private local state. Credentials are host owned and never sent to package views.');
    const selector = selectField('Integration and version', state.packages.map((value) => [value.execution_revision, `${value.manifest.display_name} · ${value.manifest.version}`]), pkg.execution_revision);
    selector.input.addEventListener('change', () => { location.hash = `connections?package=${selector.input.value}`; render(); });
    section.append(selector.label, element('hr', undefined, 'divider'), element('h3', 'Connection settings'));
    const defaults = pkg.fixtures.defaults ?? {};
    const config = schemaForm(pkg.configuration_schema, defaults.config ?? defaults.configuration ?? {});
    const credentials = schemaForm(pkg.credential_schema, {}, true);
    section.append(config.fields, element('hr', undefined, 'divider'), element('h3', 'API credentials'), credentials.fields, element('p', 'Enter development-only credentials. Nothing in this environment grants production vendor access.', 'form-note'));
    actions(section, [button('Create connection', async () => {
        if (!config.valid() || !credentials.valid()) return;
        const result = await action('connection.create', { execution_revision: pkg.execution_revision, config: config.values(), credentials: credentials.values() }, 'Connection saved. Run health and connection tests next.');
        selectedConnection = result.uuid; location.hash = 'connections'; render();
    })]);
    if (defaults.credentials) {
        actions(section, [button('Use synthetic fixture credentials', () => {
            for (const [key, value] of Object.entries(defaults.credentials)) {
                const input = credentials.fields.querySelector(`[name="${CSS.escape(key)}"]`);
                if (input) input.value = value;
            }
        }, 'subtle')]);
    }
}

function connectionsPage() {
    const query = new URLSearchParams((location.hash.split('?')[1] ?? ''));
    const connection = state.connections.find((value) => value.uuid === selectedConnection);
    const header = intro(connection ? connection.display_name : 'POS connections', connection ? 'Manage connection settings, verify access and test the complete pharmacy setup workflow.' : 'Connect a synthetic pharmacy to any integration you are developing. Only active current connections appear in catalog mapping.');
    header.append(button('New connection', () => { selectedConnection = undefined; location.hash = 'connections?package='; render(); }, 'secondary'));
    if (query.has('package') || (!connection && !state.connections.length)) return connectionCreate(query.get('package'));
    if (!connection) {
        const list = card('Connections');
        table(list, ['Integration', 'Version', 'Status', 'Health', 'Actions'], state.connections.filter((value) => !state.connections.some((other) => other.upgraded_from === value.uuid && other.status === 'active')).map((value) => [value.display_name, value.package_version, badge(value.status, value.status === 'active'), badge(value.health_current ? 'Healthy' : 'Needs test', value.health_current), button('Manage connection', () => connectionLink(value), 'secondary small')]));
        return;
    }
    const pkg = packageFor(connection);
    const choose = selectField('Connection', state.connections.map((value) => [value.uuid, `${value.display_name} ${value.package_version} · ${human(value.status)}`]), connection.uuid);
    choose.input.addEventListener('change', () => { selectedConnection = choose.input.value; render(); });
    main.append(choose.label);
    const steps = element('ol', undefined, 'step-list');
    for (const [index, title, done] of [[1, 'Save settings', true], [2, 'Check health', connection.health_current], [3, 'Test access', connection.test_current], [4, 'Activate', connection.status === 'active']]) {
        const item = element('li', undefined, done ? 'done' : ''); item.append(element('span', done ? '✓' : index, 'check'), document.createTextNode(title)); steps.append(item);
    }
    main.append(steps);
    const health = card('Connection status', 'Health and access evidence expires after the configured interval. This workbench runs checks only when you request them.');
    facts(health, [['Status', human(connection.status)], ['Health', connection.health_current ? 'Healthy' : 'Check required'], ['Connection test', connection.test_current ? 'Passed' : 'Test required'], ['Package version', connection.package_version], ['Environment', 'Local sandbox'], ['Settings revision', connection.version]]);
    actions(health, [button('Run health check', () => action('connection.health', { connection_id: connection.uuid }, 'Health check completed.')), button('Test connection', () => action('connection.test', { connection_id: connection.uuid }, 'Connection test completed.'), 'secondary'), button('Activate connection', () => action('connection.activate', { connection_id: connection.uuid }, 'Connection is active. Product mapping is ready.'), 'secondary'), ...(connection.status === 'active' ? [button('Pause', () => action('connection.pause', { connection_id: connection.uuid }, 'Connection paused.'), 'secondary')] : [])]);
    const schedule = selectField('Health interval', [[5, 'Every 5 minutes'], [15, 'Every 15 minutes'], [30, 'Every 30 minutes'], [60, 'Every hour'], [1440, 'Every day']], String(connection.health_interval_minutes));
    const scheduling = element('details'); scheduling.append(element('summary', 'Health schedule simulation'), schedule.label);
    actions(scheduling, [button('Save interval', () => action('health.schedule', { connection_id: connection.uuid, interval_minutes: Number(schedule.input.value) }), 'secondary small'), button('Simulate a scheduled check', () => action('health.schedule', { connection_id: connection.uuid, interval_minutes: Number(schedule.input.value), run_due_now: true }, 'Scheduled check simulated.'), 'secondary small')]); health.append(element('hr', undefined, 'divider'), scheduling);
    const customSetup = packageView(connection, pkg, 'connection.setup', main);
    const settings = schemaFallback('Connection settings', customSetup ? 'Compare this schema-generated form with the package’s custom screen. Both use the same saved settings and revision rules.' : 'Fields declared by this package’s configuration schema. Saving creates the same settings revision used by custom views.', customSetup);
    const config = schemaForm(pkg.configuration_schema, connection.config);
    settings.append(config.fields);
    actions(settings, [button('Save settings', () => {
        if (!config.valid()) return;
        return action('connection.save', { connection_id: connection.uuid, expected_version: connection.version, config: config.values() }, 'Settings saved. Run health and connection tests again.');
    })]);
    const credentialSection = card('API credentials', 'Credentials are saved securely in the local host. Package views can never read or replace them.');
    credentialSection.append(badge('Credentials saved', true));
    const credentials = schemaForm(pkg.credential_schema, {}, true);
    const rotation = element('details'); rotation.append(element('summary', 'Replace API credentials'), credentials.fields);
    actions(rotation, [button('Replace credentials', () => {
        if (!credentials.valid()) return;
        return action('connection.save', { connection_id: connection.uuid, expected_version: connection.version, config: connection.config, credentials: credentials.values() }, 'Credentials replaced. Run health and connection tests again.');
    }, 'secondary')]);
    credentialSection.append(element('hr', undefined, 'divider'), rotation);
    senderOverrides(connection, pkg);
    webhooks(connection);
    upgrades(connection, pkg);
}

function senderOverrides(connection, pkg) {
    const section = card('Sender credentials', 'Use a sender-issued credential set for future orders. Draft, disabled or revoked overrides block new orders. Remove an override explicitly to restore connection credentials. Revoking a revision also stops outbound actions on orders pinned to it.');
    const sender = selectField('Sender', [[state.fixtures.sender_id, 'Synthetic sender A'], ['00000000-0000-4000-8000-000000000005', 'Synthetic sender B']]);
    const credentials = schemaForm(pkg.credential_schema, {}, true);
    section.append(sender.label, element('hr', undefined, 'divider'), credentials.fields);
    actions(section, [button('Save sender credentials', () => {
        if (!credentials.valid()) return;
        return action('override.save', { connection_id: connection.uuid, sender_id: sender.input.value, credentials: credentials.values() }, 'Sender credentials saved. Test before enabling.');
    })]);
    const rows = Object.entries(connection.sender_overrides ?? {}).map(([id, override]) => {
        const controls = element('div', undefined, 'row');
        for (const [name, label] of [['test', 'Test'], [override.enabled ? 'disable' : 'enable', override.enabled ? 'Disable' : 'Enable'], ['remove', 'Use connection credentials'], ['revoke', 'Revoke revision']]) {
            const control = button(label, () => action(`override.${name}`, { connection_id: connection.uuid, sender_id: id }, `Sender credentials ${name === 'test' ? 'tested' : name + 'd'}.`), 'secondary small');
            control.disabled = (override.revoked && name !== 'remove') || (override.removed && name === 'remove');
            controls.append(control);
        }
        if (override.history?.length) {
            const history = element('details'); history.append(element('summary', 'Earlier credential revisions'));
            for (const revision of override.history) {
                const row = element('div', undefined, 'row'); row.append(element('code', revision.revision.slice(0, 8)), badge(revision.revoked ? 'Revoked' : 'Historical'));
                if (!revision.revoked) row.append(button('Revoke earlier revision', () => action('override.revoke', { connection_id: connection.uuid, sender_id: id, revision: revision.revision }, 'Earlier credential revision revoked.'), 'secondary small'));
                history.append(row);
            }
            controls.append(history);
        }
        return [id === state.fixtures.sender_id ? 'Synthetic sender A' : 'Synthetic sender B', badge(override.state ?? (override.revoked ? 'Revoked' : override.enabled ? 'Enabled' : 'Draft'), override.enabled), override.tested ? 'Passed' : 'Required', controls];
    });
    if (rows.length) { section.append(element('hr', undefined, 'divider')); table(section, ['Sender', 'Status', 'Test', 'Actions'], rows); }
}

function webhooks(connection) {
    const section = card('Incoming order updates', 'Each connection lineage keeps one stable callback URL through package upgrades. API credentials and incoming callback credentials are independent.');
    if (connection.webhooks.length) {
        for (const endpoint of connection.webhooks) {
            const url = location.origin + endpoint.path;
            const row = element('div', undefined, 'endpoint'); row.append(element('code', url), button('Copy URL', () => navigator.clipboard.writeText(url).then(() => notify('Webhook URL copied.')), 'secondary small')); section.append(row);
        }
        section.append(element('p', 'Use the order view to send authenticated synthetic Shipped and Delivered callbacks through the normal receiver, or use curl with your saved incoming credentials.', 'form-note'));
        return;
    }
    const fields = element('div', undefined, 'fields');
    const username = inputField('Incoming username'); const password = inputField('Incoming password', '', 'password', 'Use an independent local password of at least 24 characters.'); password.input.minLength = 24; username.input.required = true; password.input.required = true; password.input.autocomplete = 'new-password';
    fields.append(username.label, password.label); section.append(fields);
    actions(section, [button('Create webhook URL', () => {
        if (!username.input.reportValidity() || !password.input.reportValidity()) return;
        return action('webhook.create', { connection_id: connection.uuid, username: username.input.value, password: password.input.value }, 'Stable webhook URL created. Keep the incoming credentials locally.');
    })]);
}

function upgrades(connection) {
    const candidates = state.packages.filter((value) => value.manifest.package_id === connection.package_id && value.execution_revision !== connection.execution_revision);
    if (!candidates.length) return;
    const section = card('Test a package upgrade', 'The active connection keeps serving orders while you prepare the new version. Compatible settings, mappings and sender overrides are retained; health and test evidence must be refreshed.');
    const target = selectField('Upgrade target', candidates.map((value) => [value.execution_revision, `${value.manifest.display_name} ${value.manifest.version}`])); section.append(target.label);
    const consent = inputField('Copy saved credentials into the upgrade draft', '', 'checkbox'); section.append(consent.label);
    actions(section, [button('Create upgrade draft', async () => {
        if (!consent.input.checked) throw new Error('confirm_credential_copy_first');
        const result = await action('connection.upgrade', { connection_id: connection.uuid, execution_revision: target.input.value, copy_credentials: true }, 'Upgrade drafted. Test and activate when ready.');
        selectedConnection = result.uuid; render();
    }, 'secondary')]);
}

function catalogPage() {
    intro('Product mapping', 'Map the synthetic product to the active POS connection. Published mapping revisions are pinned when an order is submitted.');
    const active = state.connections.filter((value) => value.status === 'active');
    if (!active.length) return empty(main, 'Activate a connection first', 'Run health and connection tests, then activate a connection to test product mapping.', button('Open POS connections', () => { location.hash = 'connections'; }));
    const connection = active.find((value) => value.uuid === selectedConnection) ?? active[0]; selectedConnection = connection.uuid;
    const pkg = packageFor(connection); const itemId = state.fixtures.catalog_item_id;
    const overview = card('Synthetic product', 'A non-controlled product fixture for development. No real prescription or catalog data is used.');
    const selector = selectField('Active POS connection', active.map((value) => [value.uuid, `${value.display_name} ${value.package_version}`]), connection.uuid); selector.input.addEventListener('change', () => { selectedConnection = selector.input.value; render(); }); overview.append(selector.label);
    facts(overview, [['Product', 'Synthetic demonstration medication'], ['Catalog item', itemId], ['Integration version', pkg.manifest.version]]);
    const modes = element('div', undefined, 'tabs'); modes.setAttribute('role', 'tablist'); modes.setAttribute('aria-label', 'Catalog workspace');
    for (const [mode, title] of [['mapping', 'Product mapping'], ['imports', 'Catalog imports']]) {
        const control = button(title, () => { catalogMode = mode; render(); }); control.setAttribute('role', 'tab'); control.setAttribute('aria-selected', String(catalogMode === mode)); modes.append(control);
    }
    main.append(modes);
    if (catalogMode === 'imports') { catalogImports(connection, pkg); return; }
    const customMapping = packageView(connection, pkg, 'catalog.item.mapping', main, itemId);
    const section = schemaFallback('Product identifiers', customMapping ? 'These fields come from the package’s order_mapping schema. Both forms write the same immutable mapping revision.' : 'These fields come from the package’s order_mapping schema. Saving creates an immutable mapping revision for future orders.', customMapping);
    const mapping = connection.mappings[itemId];
    const fields = schemaForm(pkg.resource_schemas.order_mapping, mapping?.values ?? pkg.fixtures.defaults?.mapping ?? {});
    if (!pkg.resource_schemas.order_mapping) {
        empty(section, 'No product mapping schema', 'This package does not declare an order_mapping resource.');
        return;
    }
    section.append(fields.fields);
    actions(section, [button('Save product mapping', () => {
        if (!fields.valid()) return;
        return action('mapping.save', { connection_id: connection.uuid, catalog_item_id: itemId, expected_revision: mapping?.revision ?? 0, values: fields.values() }, 'Product mapping saved. Future orders use this revision.');
    })]);
    if (mapping) facts(section, [['Mapping revision', mapping.revision], ['Status', human(mapping.status)]]);
    for (const type of ['status_mapping', 'shipping_mapping']) {
        if (!pkg.resource_schemas[type]) continue;
        const section = card(`${human(type)} fixtures`, 'Local resource fixtures let your package exercise typed resources.read responses. They are developer test data; they do not create or authorize a new UniScript host action.');
        const key = inputField('Fixture key', 'default');
        const old = connection.resource_fixtures?.[type]?.default;
        const form = schemaForm(pkg.resource_schemas[type], old?.values ?? {});
        section.append(key.label, form.fields);
        actions(section, [button('Save resource fixture', () => {
            if (!form.valid()) return;
            const revision = connection.resource_fixtures?.[type]?.[key.input.value]?.revision ?? 0;
            return action('resource.fixture.save', { connection_id: connection.uuid, resource_type: type, resource_key: key.input.value, expected_revision: revision, values: form.values() }, 'Local resource fixture saved.');
        }, 'secondary')]);
    }
    const advanced = card('Catalog and reference operations', 'Run declared operations below to preview catalog data or refresh package-owned reference storage. Proposals require host review before application.');
    operationRunner(advanced, connection, ['catalog.preview', 'reference.refresh', 'reference.inspect']);
}

function catalogImports(connection, pkg) {
    const section = card('Import a POS catalog', 'Stage a CSV preview with the actual package handler. Applying creates new synthetic items that still need canonical catalog mapping. Existing, duplicate and warned items are preserved for review.');
    if (!pkg.manifest.operations.some((operation) => operation.key === 'catalog.preview')) {
        return section.append(element('p', 'This package does not declare catalog.preview. Catalog import is unavailable.', 'muted'));
    }
    const records = pkg.fixtures.defaults?.catalog ?? [];
    const csvValue = (value) => { const text = String(value ?? ''); return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text; };
    const headers = records[0] ? Object.keys(records[0]) : ['source_key', 'display_name', 'product_code', 'schedule_code'];
    const csv = records.length ? [headers.join(','), ...records.map((record) => headers.map((key) => csvValue(record[key])).join(','))].join('\n') + '\n' : headers.join(',') + '\n';
    const source = inputField('Catalog CSV (synthetic data)', csv, 'textarea', 'UTF-8 CSV, up to 512 KiB and 1,000 rows. Package-specific column names come from the integration’s catalog contract.');
    source.input.rows = 6;
    const file = inputField('Load a local CSV file', '', 'file'); file.input.accept = '.csv,text/csv,text/plain';
    file.input.addEventListener('change', async () => {
        const selected = file.input.files[0]; if (!selected) return;
        if (selected.size > 524288) return notify('Catalog CSV must be at most 512 KiB.', true);
        try { source.input.value = new TextDecoder('utf-8', { fatal: true }).decode(await selected.arrayBuffer()); }
        catch { notify('Catalog CSV must contain valid UTF-8 text.', true); }
    });
    const confirmed = inputField('I reviewed this synthetic source and want to stage an import preview.', '', 'checkbox');
    section.append(file.label, source.label, confirmed.label);
    actions(section, [button('Stage import preview', async () => {
        if (!confirmed.input.checked) throw new Error('confirm_catalog_source_review');
        const result = await action('catalog.stage', { connection_id: connection.uuid, expected_version: connection.version, confirmed: true, source_csv: source.input.value }, 'Catalog preview staged. Review every row page before applying.');
        selectedCatalogImport = result.uuid; catalogPageView = result; render();
    }, 'secondary')]);
    const batches = state.catalog?.imports?.[connection.uuid] ?? [];
    if (batches.length) {
        const history = card('Catalog import history', 'Each source is pinned to its connection configuration and execution. Normal restarts retain review and checkpoint progress.');
        table(history, ['Created', 'Status', 'Rows', 'Checkpoint', 'Actions'], batches.map((batch) => [new Date(batch.created_at).toLocaleString(), badge(batch.status, batch.status === 'completed'), batch.row_count, batch.checkpoint, button('Review import', () => { selectedCatalogImport = batch.uuid; catalogPageView = batch; render(); }, 'secondary small')]));
    }
    const batchSnapshot = batches.find((value) => value.uuid === selectedCatalogImport);
    const batch = batchSnapshot && catalogPageView?.uuid === batchSnapshot.uuid && catalogPageView.revision === batchSnapshot.revision ? catalogPageView : batchSnapshot;
    if (batch) {
        const review = card('Review catalog proposals', 'Only pending rows are ingested. Warnings and existing identities stay in review. Applying never approves a canonical clinical mapping or publishes the proposed POS mapping values.');
        facts(review, [['Import status', human(batch.status)], ['Revision', batch.revision], ['Checkpoint', `${batch.checkpoint} / ${batch.row_count}`]]);
        table(review, ['Row', 'Source key', 'Product', 'Outcome', 'Proposal'], batch.rows.map((row) => {
            const details = element('details'); details.append(element('summary', 'View proposal'), element('pre', JSON.stringify(row.proposal, null, 2)));
            const status = element('div'); status.append(badge(row.status, row.status === 'applied')); if (row.reason_code) status.append(element('small', human(row.reason_code)));
            return [row.row_number, row.proposal.source_key, row.proposal.display_name, status, details];
        }));
        const pageControls = [];
        if ((batch.rows[0]?.row_number ?? 1) > 1) pageControls.push(button('First row page', () => { catalogPageView = batchSnapshot; render(); }, 'secondary small'));
        if (batch.next_cursor !== null) pageControls.push(button('Next row page', async () => {
            catalogPageView = await request('/api/action', { action: 'catalog.read', arguments: { connection_id: connection.uuid, import_uuid: batch.uuid, after_row: batch.next_cursor } }); render();
        }, 'secondary small'));
        actions(review, pageControls);
        const consent = inputField('I reviewed the import rows and the effects of this checkpoint action.', '', 'checkbox'); review.append(element('hr', undefined, 'divider'), consent.label);
        const mutate = async (name) => {
            if (!consent.input.checked) throw new Error('confirm_catalog_checkpoint_review');
            const result = await action(`catalog.${name}`, { connection_id: connection.uuid, expected_version: connection.version, confirmed: true, import_uuid: batch.uuid, expected_revision: batch.revision }, `Catalog ${name} checkpoint processed.`);
            catalogPageView = result; render();
        };
        const controls = [];
        if (['staged', 'applying'].includes(batch.status)) controls.push(button('Apply next 25 rows', () => mutate('apply')), button('Cancel pending work', () => mutate('cancel'), 'secondary'));
        if (['completed', 'cancelled', 'rollback_pending'].includes(batch.status)) controls.push(button('Roll back next 25 items', () => mutate('rollback'), 'secondary'));
        actions(review, controls);
        review.append(element('p', 'Rollback archives only unchanged, unmapped and unused items created by this import. Edited or used items remain for explicit review.', 'form-note'));
    }
    const items = (state.catalog?.items ?? []).filter((item) => item.connection_id === connection.uuid);
    if (items.length) {
        const imported = card('Imported synthetic items', 'Canonical clinical catalog mapping belongs to UniScript and is not approved by this local simulator. These records let you verify ingestion, review and safe rollback behavior.');
        table(imported, ['Product', 'Source key', 'Status', 'Version'], items.map((item) => [item.display_name, item.source_key, badge(item.status), item.version]));
    }
}

function ordersPage() {
    intro('Orders & fulfillment', 'Validate and submit synthetic orders, exercise callbacks and compare the pharmacy and sender embedded views.');
    const active = state.connections.filter((value) => value.status === 'active');
    const connection = active.find((value) => value.uuid === selectedConnection) ?? active[0];
    if (!connection && !state.orders.length) return empty(main, 'No orders yet', 'Activate a connection and save a product mapping before submitting a synthetic order.', button('Open POS connections', () => { location.hash = 'connections'; }));
    if (selectedOrder) {
        const order = state.orders.find((value) => value.uuid === selectedOrder);
        if (order) return orderDetails(order);
    }
    if (connection) {
        const section = card('Submit a synthetic order', 'The package runs orders.validate and orders.submit in isolated containers. Duplicate idempotency keys return the original order; unknown remote outcomes require reconciliation.');
        const select = selectField('Active connection', active.map((value) => [value.uuid, `${value.display_name} ${value.package_version}`]), connection.uuid); select.input.addEventListener('change', () => { selectedConnection = select.input.value; render(); });
        const pkg = packageFor(connection);
        const fixtures = pkg.fixtures.order ?? { order_id: crypto.randomUUID(), scenario: 'accepted' };
        const input = inputField('Canonical order input (JSON)', JSON.stringify(fixtures, null, 2), 'textarea', 'Use synthetic patient and prescriber details only. Package validation still applies.'); input.input.rows = 8;
        const sender = selectField('Sender', [[state.fixtures.sender_id, 'Synthetic sender A'], ['00000000-0000-4000-8000-000000000005', 'Synthetic sender B']]);
        const key = inputField('Idempotency key', crypto.randomUUID());
        const fields = element('div', undefined, 'fields'); fields.append(select.label, sender.label, key.label); input.label.classList.add('wide'); fields.append(input.label); section.append(fields);
        actions(section, [button('Validate and submit order', async () => {
            const result = await action('order.submit', { connection_id: connection.uuid, catalog_item_id: state.fixtures.catalog_item_id, sender_id: sender.input.value, idempotency_key: key.input.value, input: JSON.parse(input.input.value) }, 'Order processed. Review acceptance and fulfillment details.');
            selectedOrder = result.uuid; render();
        })]);
    }
    if (state.orders.length) {
        const section = card('Recent orders');
        table(section, ['Order', 'Package version', 'Status', 'Credentials', 'Actions'], [...state.orders].reverse().map((order) => [order.uuid.slice(0, 8), order.package_version, badge(order.status, order.status === 'completed'), order.sender_override ? 'Sender override' : 'Connection default', button('View order', () => { selectedOrder = order.uuid; render(); }, 'secondary small')]));
    }
}

function orderDetails(order) {
    const connection = state.connections.find((value) => value.uuid === order.connection_id);
    const pkg = state.packages.find((value) => value.execution_revision === order.execution_revision);
    main.append(button('← All orders', () => { selectedOrder = undefined; render(); }, 'subtle'));
    const section = card(`Order ${order.uuid.slice(0, 8)}`, 'This order retains its original package execution, mapping and credential revisions even after an upgrade.');
    facts(section, [['Status', human(order.status)], ['Package version', order.package_version], ['Delivery', human(order.delivery_status)], ['Credentials', order.sender_override ? 'Sender override' : 'Connection default'], ['Mapping revision', order.mapping_revision], ['Environment', 'Local sandbox']]);
    const tabs = element('div', undefined, 'tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Order audience');
    for (const audience of ['pharmacy', 'sender']) {
        const control = button(`${human(audience)} view`, () => { orderAudience = audience; render(); }); control.setAttribute('role', 'tab'); control.setAttribute('aria-selected', String(orderAudience === audience)); tabs.append(control);
    }
    main.append(tabs);
    const surface = `${orderAudience}.order.details`;
    packageView(connection, pkg, surface, main, undefined, order.uuid);
    const fulfillment = card('Shipments', orderAudience === 'sender' ? 'Read-only fulfillment details visible to the sender.' : 'These local canonical shipment projections come from authenticated package callback proposals.');
    if (!order.fulfillments.length) empty(fulfillment, 'No shipment yet', 'Create a webhook endpoint on the connection, then send a synthetic callback below.');
    else table(fulfillment, ['Parcel', 'Status', 'Carrier', 'Tracking', 'Shipped', 'Delivered'], order.fulfillments.map((shipment) => [shipment.uuid.slice(0, 8), badge(shipment.status, shipment.delivered), shipment.carrier, shipment.tracking_present ? 'Recorded' : 'Not recorded', shipment.shipped ? 'Yes' : 'No', shipment.delivered ? 'Yes' : 'No']));
    if (orderAudience === 'pharmacy') {
        const callbacks = card('Test incoming updates', 'The local simulator authenticates with the saved incoming credentials and calls the same receipt/decode/application workflow. No request is sent to an external vendor.');
        actions(callbacks, ['Shipped', 'Delivered'].map((status) => button(`Send ${status} callback`, async () => {
            const result = await request('/api/callback', { order_id: order.uuid, status }); await refresh(); notify(`${status} callback: ${human(result.status)}${result.duplicate ? ' (duplicate receipt reused)' : ''}.`);
        }, status === 'Delivered' ? 'secondary' : '')));
        const actions_ = card('Order actions', 'Actions run against the immutable original order execution. Sender embedded screens never receive mutation controls.');
        operationRunner(actions_, connection, ['orders.reconcile', 'orders.status.update', 'orders.shipping.update'], order);
    }
    detailsJson(main, 'Safe host projection', order);
}

function operationRunner(parent, connection, preferred, order) {
    const pkg = order ? state.packages.find((value) => value.execution_revision === order.execution_revision) : packageFor(connection);
    const operations = pkg.manifest.operations.map((value) => value.key).filter((key) => preferred.includes(key));
    if (!operations.length) return parent.append(element('p', 'No matching operations declared by this package.', 'muted'));
    if (order) {
        const ready = ['submitted_to_pos', 'accepted', 'in_progress', 'ready'].includes(order.status);
        const allowed = operations.filter((operation) => ready || operation === 'orders.reconcile');
        const select = selectField('Action', allowed.map((operation) => [operation, human(operation)]));
        const fields = element('div');
        const key = inputField('Action idempotency key', crypto.randomUUID(), 'text', 'Reusing this UUID with identical parameters returns the original action. A different action needs a new key.');
        key.input.required = true;
        let parameters = () => ({});
        let valid = () => true;
        const renderFields = () => {
            fields.replaceChildren();
            if (select.input.value === 'orders.reconcile') {
                fields.append(element('p', 'Reconciliation uses the order’s original owned POS reference and credential revision. It does not resubmit the order.', 'muted'));
                parameters = () => ({}); valid = () => true;
                return;
            }
            const shipping = select.input.value === 'orders.shipping.update';
            const mappings = connection.resource_fixtures?.[shipping ? 'shipping_mapping' : 'status_mapping'] ?? {};
            const code = selectField(shipping ? 'Canonical shipping service' : 'Canonical status', [['', 'Choose a published mapping'], ...Object.entries(mappings).map(([name, mapping]) => [name, `${human(name)} → ${mapping.values.vendor_code}`])]);
            code.input.required = true;
            fields.append(code.label);
            let form;
            if (shipping) {
                const definition = pkg.resource_schemas.order_shipping_update?.properties?.shipping;
                if (definition) {
                    const schema = { ...definition, properties: Object.fromEntries(Object.entries(definition.properties ?? {}).filter(([field]) => field !== 'service')), required: (definition.required ?? []).filter((field) => field !== 'service') };
                    form = schemaForm(schema, {});
                    fields.append(form.fields);
                }
            }
            if (!Object.keys(mappings).length) fields.append(element('p', 'Save the corresponding typed status or shipping mapping in Product mapping first. Vendor values are selected by the host.', 'form-note'));
            parameters = () => ({ canonical_code: code.input.value, ...(shipping ? { shipping: form?.values() ?? {} } : {}) });
            valid = () => code.input.reportValidity() && (!form || form.valid());
        };
        select.input.addEventListener('change', renderFields);
        parent.append(select.label, fields, key.label);
        facts(parent, [['Current action revision', order.action_revision ?? 0]]);
        renderFields();
        actions(parent, [button('Run action', () => {
            if (!valid() || !key.input.reportValidity()) return;
            return action('order.action', { order_id: order.uuid, audience: 'pharmacy', operation: select.input.value, expected_revision: order.action_revision ?? 0, idempotency_key: key.input.value, ...parameters() }, 'Action processed. Review its recorded outcome.');
        }, 'secondary')]);
        if (order.actions?.length) table(parent, ['Revision', 'Action', 'Status', 'Result'], order.actions.map((value) => [value.revision, human(value.operation), human(value.status), human(value.result_kind)]));
        return;
    }
    const select = selectField('Operation', operations.map((value) => [value, value]));
    const payload = inputField('Operation input (JSON)', JSON.stringify(order?.pos_order_references[0] ? { external_reference_handle: order.pos_order_references[0].handle } : {}, null, 2), 'textarea');
    parent.append(select.label, payload.label);
    actions(parent, [button('Run operation', async () => {
        const operation = select.input.value;
        const result = await action('connection.invoke', { connection_id: connection.uuid, operation, input: JSON.parse(payload.input.value) }, 'Operation completed.');
        operationOutput = { connection_id: connection.uuid, operation, result }; render();
    }, 'secondary')]);
    if (operationOutput?.connection_id === connection.uuid && operations.includes(operationOutput.operation)) {
        detailsJson(parent, `Result: ${operationOutput.operation}`, operationOutput.result);
    }
}

async function embedded(connection, surface, parent, catalogItemId, orderId) {
    const section = element('section', undefined, 'card embedded');
    const head = element('div', undefined, 'embed-header'); head.append(element('h2', 'Integration details'), badge(surface.includes('sender') ? 'Read only' : 'Package view'));
    const status = element('p', 'Loading the package screen…', 'embed-status'); section.append(head, status); parent.append(section);
    let bridge;
    let listener;
    let frame;
    let closed = false;
    const cleanup = () => { closed = true; bridge?.close(); if (listener) window.removeEventListener('message', listener); };
    frameCleanup.push(cleanup);
    try {
        const binding = await request('/api/session', { connection_id: connection.uuid, surface, ...(catalogItemId ? { catalog_item_id: catalogItemId } : {}), ...(orderId ? { order_id: orderId } : {}) });
        if (closed) return;
        frame = createPackageFrame(document, { assetUrl: binding.asset_url, packageOrigin: binding.package_origin, hostOrigin: binding.host_origin, title: `${connection.display_name} ${human(surface)}` });
        const handlers = (names, kind) => Object.fromEntries(names.map((operation) => [operation, async (_context, arguments_, id) => {
            const result = await request(binding.bridge_url, { version: '1', id, nonce: binding.nonce, session_id: binding.session_id, execution_revision: binding.execution_revision, expected_revision: binding.expected_revision, kind, operation, arguments: arguments_ });
            if (result.host_reload_required) {
                status.textContent = 'Saved. Refresh these details to continue.'; status.hidden = false;
            }
            return result;
        }]));
        listener = (event) => {
            if (event.origin === binding.package_origin && event.source === frame.contentWindow && event.data?.kind === 'uniscript.ui.resize' && event.data.version === '1' && event.data.nonce === binding.nonce && event.data.session_id === binding.session_id && Number.isInteger(event.data.height) && event.data.height >= 160 && event.data.height <= 10000) {
                frame.style.height = `${event.data.height}px`; return;
            }
            void bridge.receive(event);
        };
        window.addEventListener('message', listener);
        frame.addEventListener('load', () => {
            frame.contentWindow.postMessage({ kind: 'uniscript.ui.initialize', version: '1', ...binding, theme: document.documentElement.dataset.theme ?? 'light' }, binding.package_origin);
            status.hidden = true;
        });
        section.append(frame);
        // contentWindow becomes available only after the iframe is connected to the document.
        bridge = new PackageBridge({ hostOrigin: binding.host_origin, packageOrigin: binding.package_origin, peer: frame.contentWindow, nonce: binding.nonce, context: binding.context, queries: handlers(binding.queries, 'query'), actions: handlers(binding.actions, 'action'), authorize: async () => !closed && Date.now() < Date.parse(binding.expires_at) });
        head.append(button('Refresh details', async () => { cleanup(); section.remove(); await refresh(); }, 'secondary small'));
        const expiry = setTimeout(() => { bridge.close(); status.textContent = 'Session expired. Refresh these details to continue.'; status.hidden = false; }, Math.max(1, Date.parse(binding.expires_at) - Date.now()));
        frameCleanup.push(() => clearTimeout(expiry));
    } catch (error) {
        status.textContent = `Package screen unavailable: ${human(error.message)}. The host controls remain available.`;
    }
}

function diagnosticsPage() {
    intro('Activity & diagnostics', 'Safe local events for package builds, probes, revision changes, invocations and webhook application. Credentials and raw clinical payloads are excluded.');
    const section = card('Recent activity');
    if (!state.events.length) return empty(section, 'No activity yet', 'Load a package to start a local workflow.');
    table(section, ['When', 'Event', 'Safe context'], [...state.events].reverse().map((event) => [new Date(event.at).toLocaleString(), human(event.type), element('code', JSON.stringify(event.data))]));
    const operations = card('Declared package operations', 'Only operations declared in the manifest can be invoked. Unsupported operations fail closed.');
    for (const pkg of state.packages) { operations.append(element('h3', `${pkg.manifest.display_name} ${pkg.manifest.version}`)); detailsJson(operations, 'Operations and capabilities', { operations: pkg.manifest.operations, capabilities: pkg.manifest.capabilities }); }
    if (state.connections.length) {
        const connection = state.connections.find((value) => value.uuid === selectedConnection) ?? state.connections[0];
        const runner = card('Package operation console', 'Invoke declared operations with explicit synthetic inputs. Order submission and callback decoding use their dedicated scoped workflows.');
        const selector = selectField('Connection', state.connections.map((value) => [value.uuid, `${value.display_name} ${value.package_version}`]), connection.uuid);
        selector.input.addEventListener('change', () => { selectedConnection = selector.input.value; render(); }); runner.append(selector.label);
        operationRunner(runner, connection, packageFor(connection).manifest.operations.map((value) => value.key).filter((value) => !value.startsWith('orders.') && value !== 'inbound.decode'));
    }
}

function guidePage() {
    intro('Build a POS integration', 'The workbench is generic. Packages define their own configuration, credentials, typed mappings, runtime handlers and optional embedded views.');
    const start = card('A complete local workflow');
    const list = element('ol', undefined, 'help-list');
    for (const text of ['Load examples/reference, or place your package under the configured workspace directory and load it.', 'Create a POS connection. Use synthetic fixture credentials or credentials belonging to your local simulator.', 'Run a health check and connection test, then activate the connection.', 'Save a product mapping. Both schema fallback and custom embedded screens use the same revision contract.', 'Optionally save, test and enable a sender credential override.', 'Submit a synthetic order and inspect its immutable package, mapping and credential pins.', 'Create a stable incoming webhook URL. Send Shipped and Delivered fixture callbacks, replay them and compare both audiences.', 'Load a new version and create an upgrade draft. Activate it after fresh checks; verify the old order still uses its original views.', 'Run the kit test/conformance commands, export a deterministic ZIP and report, then submit the artifact for independent UniScript internal review.']) list.append(element('li', text));
    start.append(list);
    const boundaries = card('What local success means', 'Real package code and static UI run under the package protocol. Host workflows are a synthetic local model. Publisher ownership, outbound transport profiles, certification and release approval remain UniScript responsibilities.');
    boundaries.append(element('p', 'The supported runtime profile uses dependency-free PHP 8.4. Packages cannot install arbitrary Composer dependencies or grant themselves network access. The bridge exposes only the documented safe projections and actions. Clinical validation, dispensing, real payments, carrier purchases and tenant authorization are outside the public mini-host.'));
    detailsJson(main, 'Available UI surfaces', ['connection.setup', 'catalog.item.mapping', 'pharmacy.order.details', 'sender.order.details']);
    const commands = card('Useful commands'); commands.append(element('pre', './dev-kit doctor\n./dev-kit up\n./dev-kit test\n./dev-kit package my-integration my-integration.zip\n./dev-kit down'));
}

function render() {
    for (const cleanup of frameCleanup) cleanup(); frameCleanup = [];
    main.replaceChildren();
    const page = (location.hash.slice(1).split('?')[0] || 'packages');
    for (const link of document.querySelectorAll('nav [data-page]')) {
        if (link.dataset.page === page) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
    }
    ({ packages: packagesPage, connections: connectionsPage, catalog: catalogPage, orders: ordersPage, diagnostics: diagnosticsPage, guide: guidePage }[page] ?? packagesPage)();
}

document.getElementById('refresh').addEventListener('click', () => refresh().catch((error) => notify(human(error.message), true)));
document.getElementById('theme').addEventListener('click', () => {
    document.documentElement.dataset.theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    render();
});
window.addEventListener('hashchange', () => { message.textContent = ''; if (state) render(); });
refresh().catch((error) => { main.textContent = 'The local workbench could not load. Check its logs and use Refresh to try again.'; notify(human(error.message), true); });
