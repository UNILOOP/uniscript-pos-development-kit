function origin(value) {
    const parsed = new URL(value);
    if (parsed.origin !== value || (parsed.protocol !== 'https:' && !(parsed.protocol === 'http:' && ['127.0.0.1', 'localhost', '[::1]'].includes(parsed.hostname)))) {
        throw new Error('invalid_origin');
    }
    return parsed.origin;
}
function plain(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;
}
function safe(value, depth = 0) {
    if (depth > 16)
        throw new Error('message_depth');
    if (value === null || typeof value === 'string' || typeof value === 'boolean')
        return;
    if (typeof value === 'number' && Number.isFinite(value))
        return;
    if (Array.isArray(value)) {
        if (value.length > 1000)
            throw new Error('array_limit');
        value.forEach(child => safe(child, depth + 1));
        return;
    }
    if (!plain(value))
        throw new Error('json_value');
    for (const [key, child] of Object.entries(value)) {
        if (['__proto__', 'constructor', 'prototype'].includes(key))
            throw new Error('unsafe_key');
        safe(child, depth + 1);
    }
}
function freeze(value) {
    if (value && typeof value === 'object') {
        for (const child of Object.values(value))
            freeze(child);
        Object.freeze(value);
    }
}
function canonical(value) {
    if (Array.isArray(value))
        return '[' + value.map(canonical).join(',') + ']';
    if (plain(value))
        return '{' + Object.keys(value).sort().map(key => JSON.stringify(key) + ':' + canonical(value[key])).join(',') + '}';
    return JSON.stringify(value);
}
/** Host binding. Every handler must call a host endpoint that reauthorizes its session. */
export class PackageBridge {
    #options;
    #requests = new Map();
    #closed = false;
    constructor(options) {
        if (origin(options.hostOrigin) === origin(options.packageOrigin))
            throw new Error('separate_origin_required');
        if (!/^[a-f0-9]{32,128}$/.test(options.nonce))
            throw new Error('strong_nonce_required');
        for (const value of Object.values(options.context))
            if (typeof value !== 'string' || !value || value.length > 240)
                throw new Error('invalid_context');
        this.#options = { ...options, context: Object.freeze({ ...options.context }), queries: Object.freeze({ ...options.queries }), actions: Object.freeze({ ...options.actions }) };
    }
    close() {
        this.#closed = true;
        this.#requests.clear();
    }
    async receive(event) {
        const options = this.#options;
        if (this.#closed || event.origin !== options.packageOrigin || event.source !== options.peer)
            return false;
        const raw = event.data;
        if (!plain(raw))
            return false;
        const keys = ['version', 'id', 'nonce', 'session_id', 'execution_revision', 'expected_revision', 'kind', 'operation', 'arguments'];
        if (Object.keys(raw).length !== keys.length || Object.keys(raw).some(key => !keys.includes(key)))
            return false;
        if (raw.version !== '1' || typeof raw.id !== 'string' || !/^[a-zA-Z0-9_-]{1,100}$/.test(raw.id) || raw.nonce !== options.nonce || raw.session_id !== options.context.sessionId || raw.execution_revision !== options.context.executionRevision || raw.expected_revision !== options.context.expectedRevision || !['query', 'action'].includes(String(raw.kind)) || typeof raw.operation !== 'string' || !plain(raw.arguments))
            return false;
        const request = raw;
        try {
            safe(request);
            if (JSON.stringify(request).length > 65536)
                return false;
        }
        catch {
            return false;
        }
        const handlers = request.kind === 'query' ? options.queries : options.actions;
        if (!Object.hasOwn(handlers, request.operation))
            return false;
        // Snapshot before awaiting authority so callers cannot mutate an in-flight request.
        const snapshot = structuredClone(request);
        freeze(snapshot);
        try {
            if (!(await options.authorize(options.context, snapshot)))
                return false;
        }
        catch {
            return false;
        }
        if (this.#closed)
            return false;
        const fingerprint = canonical(snapshot);
        const previous = this.#requests.get(snapshot.id);
        if (previous && previous.fingerprint !== fingerprint)
            return false;
        if (!previous && this.#requests.size >= 1000)
            return false;
        const result = previous?.result ?? Promise.resolve().then(() => handlers[snapshot.operation](options.context, snapshot.arguments, snapshot.id));
        this.#requests.set(snapshot.id, { fingerprint, result });
        try {
            const data = await result;
            safe(data);
            if (JSON.stringify(data).length > 262144)
                throw new Error('result_limit');
            if (!this.#closed)
                options.peer.postMessage({ version: '1', id: snapshot.id, session_id: options.context.sessionId, execution_revision: options.context.executionRevision, ok: true, data }, options.packageOrigin);
        }
        catch {
            if (!this.#closed)
                options.peer.postMessage({ version: '1', id: snapshot.id, session_id: options.context.sessionId, execution_revision: options.context.executionRevision, ok: false, reason_code: 'bridge.action_failed' }, options.packageOrigin);
        }
        return true;
    }
}
export function createPackageFrame(document, options) {
    if (origin(options.hostOrigin) === origin(options.packageOrigin) || new URL(options.assetUrl).origin !== options.packageOrigin)
        throw new Error('untrusted_frame_origin');
    const frame = document.createElement('iframe');
    frame.src = options.assetUrl;
    frame.title = options.title;
    frame.referrerPolicy = 'no-referrer';
    frame.setAttribute('sandbox', 'allow-scripts allow-same-origin');
    frame.setAttribute('allow', "camera 'none'; microphone 'none'; geolocation 'none'; payment 'none'");
    return frame;
}
/** Package-side client. Host owns initial session creation and credential entry. */
export class PackageBridgeClient {
    #hostOrigin;
    #peer;
    #binding;
    #pending = new Map();
    #sequence = 0;
    #closed = false;
    constructor(options) {
        this.#hostOrigin = origin(options.hostOrigin);
        this.#peer = options.peer;
        if (!/^[a-f0-9]{32,128}$/.test(options.nonce))
            throw new Error('strong_nonce_required');
        this.#binding = { nonce: options.nonce, session_id: options.sessionId, execution_revision: options.executionRevision, expected_revision: options.expectedRevision };
    }
    request(kind, operation, args, timeoutMs = 5000) {
        if (this.#closed || !['query', 'action'].includes(kind) || !/^[a-z][a-z0-9_.]{1,100}$/.test(operation) || !plain(args) || !Number.isInteger(timeoutMs) || timeoutMs < 1 || timeoutMs > 30000)
            return Promise.reject(new Error('invalid_client_request'));
        try {
            safe(args);
        }
        catch {
            return Promise.reject(new Error('invalid_client_arguments'));
        }
        if (this.#pending.size >= 100)
            return Promise.reject(new Error('request_limit'));
        const id = 'ui-' + (++this.#sequence);
        const message = { version: '1', id, ...this.#binding, kind, operation, arguments: structuredClone(args) };
        if (JSON.stringify(message).length > 65536)
            return Promise.reject(new Error('request_limit'));
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => { this.#pending.delete(id); reject(new Error('bridge.timeout')); }, timeoutMs);
            this.#pending.set(id, { resolve, reject, timer });
            try {
                this.#peer.postMessage(message, this.#hostOrigin);
            }
            catch {
                clearTimeout(timer);
                this.#pending.delete(id);
                reject(new Error('bridge.transport_failed'));
            }
        });
    }
    receive(event) {
        if (this.#closed || event.origin !== this.#hostOrigin || event.source !== this.#peer || !plain(event.data))
            return false;
        const reply = event.data;
        if (reply.version !== '1' || typeof reply.id !== 'string' || reply.session_id !== this.#binding.session_id || reply.execution_revision !== this.#binding.execution_revision || typeof reply.ok !== 'boolean')
            return false;
        const keys = reply.ok ? ['version', 'id', 'session_id', 'execution_revision', 'ok', 'data'] : ['version', 'id', 'session_id', 'execution_revision', 'ok', 'reason_code'];
        if (Object.keys(reply).length !== keys.length || Object.keys(reply).some(key => !keys.includes(key)))
            return false;
        try {
            safe(reply);
            if (JSON.stringify(reply).length > 262144)
                return false;
        }
        catch {
            return false;
        }
        const pending = this.#pending.get(reply.id);
        if (!pending)
            return false;
        clearTimeout(pending.timer);
        this.#pending.delete(reply.id);
        if (reply.ok)
            pending.resolve(reply.data);
        else
            pending.reject(new Error('bridge.action_failed'));
        return true;
    }
    close() {
        this.#closed = true;
        for (const pending of this.#pending.values()) {
            clearTimeout(pending.timer);
            pending.reject(new Error('bridge.session_closed'));
        }
        this.#pending.clear();
    }
}
