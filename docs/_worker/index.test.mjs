// Tests of the MCP server (index.js) against the generated index; run after build.mjs:
// node --test _worker/index.test.mjs

import assert from 'node:assert/strict';
import { test } from 'node:test';
import worker from './index.js';

const env = { ASSETS: { fetch: async () => new Response('asset') } };

async function post(body, path = '/mcp') {
    const response = await worker.fetch(new Request(`https://docs.quittio.dev${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' },
        body: typeof body === 'string' ? body : JSON.stringify(body),
    }), env);

    return { status: response.status, body: response.status === 202 ? null : await response.json() };
}

async function call(name, args = {}) {
    const { body } = await post({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name, arguments: args } });

    return body.result ?? body.error;
}

test('initialize negotiates the protocol version', async () => {
    const known = await post({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'test', version: '1' } } });
    const unknown = await post({ jsonrpc: '2.0', id: 2, method: 'initialize', params: { protocolVersion: '1999-01-01' } });

    assert.equal(known.status, 200);
    assert.equal(known.body.result.protocolVersion, '2025-06-18');
    assert.equal(known.body.result.serverInfo.name, 'quittio-docs');
    assert.deepEqual(known.body.result.capabilities, { tools: { listChanged: false } });
    assert.equal(unknown.body.result.protocolVersion, '2025-11-25');
});

test('notifications are accepted without a body', async () => {
    assert.equal((await post({ jsonrpc: '2.0', method: 'notifications/initialized' })).status, 202);
});

test('lists the three read-only tools', async () => {
    const { body } = await post({ jsonrpc: '2.0', id: 1, method: 'tools/list' });

    assert.deepEqual(body.result.tools.map((t) => t.name), ['search_docs', 'get_page', 'list_pages']);
    assert.ok(body.result.tools.every((t) => t.annotations.readOnlyHint));
});

test('search_docs finds the matching sections', async () => {
    const rule = await call('search_docs', { query: 'DE-USTG-14' });
    const cancel = await call('search_docs', { query: 'cancel an invoice', limit: 3 });

    assert.equal(rule.isError, false);
    assert.match(rule.content[0].text, /^\d+ sections for "DE-USTG-14"/);
    assert.match(rule.content[0].text, /## 1\. Country packs/);
    assert.match(cancel.content[0].text, /get_page: page="[a-z-]+", section="[^"]*cancel/);
    assert.equal(cancel.content[0].text.match(/^## \d+\./gm).length, 3);
});

test('get_page returns a page, a section or the outline of a long page', async () => {
    const page = await call('get_page', { page: 'getting-started' });
    const section = await call('get_page', { page: 'https://docs.quittio.dev/country-packs#tax-presets' });
    const outline = await call('get_page', { page: 'managed' });

    assert.match(page.content[0].text, /^# Getting started\n/);
    assert.match(section.content[0].text, /^### Tax presets\n/);
    assert.ok(!section.content[0].text.includes('### UStG rules'));
    assert.match(outline.content[0].text, /The page is long/);
});

test('get_page reports unknown pages and sections as tool errors', async () => {
    assert.equal((await call('get_page', { page: 'nope' })).isError, true);
    assert.equal((await call('get_page', { page: 'formats', section: 'nope' })).isError, true);
});

test('list_pages lists every page', async () => {
    const text = (await call('list_pages')).content[0].text;

    assert.match(text, /^- index: Overview/);
    assert.match(text, /- ai: AI assistants/);
});

test('protocol errors use JSON-RPC error codes', async () => {
    assert.equal((await call('nope')).code, -32602);
    assert.equal((await post({ jsonrpc: '2.0', id: 1, method: 'resources/list' })).body.error.code, -32601);
    const parse = await post('{');
    assert.equal(parse.status, 400);
    assert.equal(parse.body.error.code, -32700);
});

test('GET is not supported, other paths are static assets', async () => {
    const get = await worker.fetch(new Request('https://docs.quittio.dev/mcp'), env);
    const asset = await worker.fetch(new Request('https://docs.quittio.dev/getting-started'), env);

    assert.equal(get.status, 405);
    assert.equal(await asset.text(), 'asset');
});
