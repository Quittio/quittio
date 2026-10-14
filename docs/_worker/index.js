// Cloudflare Worker of the documentation website: serves the static site (_site) and, under
// /mcp, a read-only MCP server (Streamable HTTP, stateless, JSON responses) with the tools
// search_docs, get_page and list_pages. The index comes from build.mjs.

import docs from './docs.generated.json' with { type: 'json' };

const SERVER = { name: 'quittio-docs', title: 'Quittio documentation', version: '1.0.0' };
const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
const MAX_BODY = 100_000;
// Larger pages are returned as an outline; about 15,000 tokens.
const MAX_PAGE = 60_000;

const INSTRUCTIONS = `Quittio is an e-invoicing library for Laravel and PHP (EN 16931, ZUGFeRD/Factur-X, XRechnung, UBL, Peppol BIS; country pack Germany; commercial Managed layer).
Use search_docs to find the relevant sections, then get_page with the page and the section anchor to read them in full. list_pages lists all pages.
Code examples in the documentation are authoritative for method names and signatures.`;

const STOPWORDS = new Set(['a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'can', 'do', 'does', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'of', 'on', 'or', 'the', 'to', 'what', 'when', 'which', 'with', 'der', 'die', 'das', 'ein', 'eine', 'und', 'oder', 'wie', 'mit', 'für', 'ist', 'im', 'in', 'zu', 'von']);

const TOOLS = [
    {
        name: 'search_docs',
        title: 'Search the Quittio documentation',
        description: 'Full-text search over all sections of the Quittio documentation. Returns the best matching sections with their page, anchor, URL and an excerpt.',
        inputSchema: {
            type: 'object',
            properties: {
                query: { type: 'string', description: 'Search terms, e.g. "cancel an invoice", "VatPreset reverse charge" or "DE-USTG-14".' },
                limit: { type: 'integer', minimum: 1, maximum: 20, default: 5, description: 'Number of sections to return.' },
            },
            required: ['query'],
            additionalProperties: false,
        },
        annotations: { readOnlyHint: true, openWorldHint: false },
    },
    {
        name: 'get_page',
        title: 'Read a documentation page',
        description: 'Returns a page of the Quittio documentation as Markdown, or one section of it with its subsections. Large pages without a section return their outline.',
        inputSchema: {
            type: 'object',
            properties: {
                page: { type: 'string', description: 'Page name as in list_pages (e.g. "getting-started"), a page title or a documentation URL.' },
                section: { type: 'string', description: 'Optional: anchor (e.g. "tax-presets") or heading text of a section.' },
            },
            required: ['page'],
            additionalProperties: false,
        },
        annotations: { readOnlyHint: true, openWorldHint: false },
    },
    {
        name: 'list_pages',
        title: 'List the documentation pages',
        description: 'Lists all pages of the Quittio documentation with their name, title, URL and a short description.',
        inputSchema: { type: 'object', properties: {}, additionalProperties: false },
        annotations: { readOnlyHint: true, openWorldHint: false },
    },
];

// Section texts in lower case for the search, prepared once per isolate.
const SECTIONS = docs.pages.flatMap((page) => page.sections.map((section) => {
    const text = page.markdown.slice(section.start, section.end);

    return { page, section, text, lower: text.toLowerCase(), heading: section.heading.toLowerCase() };
}));

export default {
    async fetch(request, env) {
        const { pathname } = new URL(request.url);
        if (pathname === '/mcp' || pathname === '/mcp/') {
            return mcp(request);
        }

        return env.ASSETS.fetch(request);
    },
};

async function mcp(request) {
    const headers = {
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type, Accept, Authorization, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID',
        'Access-Control-Max-Age': '86400',
    };
    if (request.method === 'OPTIONS') {
        return new Response(null, { status: 204, headers });
    }
    if (request.method !== 'POST') {
        // No server-initiated stream: GET and DELETE are not supported (Streamable HTTP).
        return json(error(null, -32000, `This is the MCP endpoint of the Quittio documentation (Streamable HTTP, POST only). Add it to your MCP client, e.g.: claude mcp add --transport http quittio-docs ${docs.base}/mcp`), 405, { ...headers, Allow: 'POST, OPTIONS' });
    }

    const body = await request.text();
    if (body.length > MAX_BODY) {
        return json(error(null, -32600, 'Request too large.'), 413, headers);
    }
    let message;
    try {
        message = JSON.parse(body);
    } catch {
        return json(error(null, -32700, 'Parse error: the body is not valid JSON.'), 400, headers);
    }

    const batch = Array.isArray(message);
    const responses = (batch ? message : [message]).map(handle).filter((response) => response !== null);
    if (responses.length === 0) {
        // Only notifications or responses.
        return new Response(null, { status: 202, headers });
    }

    return json(batch ? responses : responses[0], 200, headers);
}

/**
 * Handles one JSON-RPC message; null for notifications and responses.
 */
function handle(message) {
    if (message === null || typeof message !== 'object' || message.jsonrpc !== '2.0') {
        return error(message?.id ?? null, -32600, 'Invalid request: expected a JSON-RPC 2.0 message.');
    }
    if (typeof message.method !== 'string') {
        return null;
    }
    const id = message.id;
    if (id === undefined) {
        return null;
    }
    const params = message.params ?? {};

    switch (message.method) {
        case 'initialize': {
            const requested = params.protocolVersion;

            return result(id, {
                protocolVersion: PROTOCOL_VERSIONS.includes(requested) ? requested : PROTOCOL_VERSIONS[0],
                capabilities: { tools: { listChanged: false } },
                serverInfo: SERVER,
                instructions: INSTRUCTIONS,
            });
        }
        case 'ping':
            return result(id, {});
        case 'tools/list':
            return result(id, { tools: TOOLS });
        case 'tools/call':
            return callTool(id, params);
        default:
            return error(id, -32601, `Method not found: ${message.method}`);
    }
}

function callTool(id, params) {
    const args = params.arguments ?? {};
    const text = (value) => result(id, { content: [{ type: 'text', text: value }], isError: false });
    const failure = (value) => result(id, { content: [{ type: 'text', text: value }], isError: true });

    switch (params.name) {
        case 'search_docs': {
            const query = typeof args.query === 'string' ? args.query.trim().slice(0, 500) : '';
            if (query === '') {
                return failure('The argument "query" is required.');
            }
            const limit = Math.min(20, Math.max(1, Number.isInteger(args.limit) ? args.limit : 5));

            return text(search(query, limit));
        }
        case 'get_page': {
            const page = findPage(typeof args.page === 'string' ? args.page : '');
            if (page === null) {
                return failure(`Unknown page "${args.page}". Pages: ${docs.pages.map((p) => p.slug).join(', ')}.`);
            }
            const section = typeof args.section === 'string' && args.section.trim() !== '' ? args.section : page.anchor;

            return section ? readSection(page.page, section, text, failure) : text(readPage(page.page));
        }
        case 'list_pages':
            return text(listPages());
        default:
            return error(id, -32602, `Unknown tool: ${params.name}`);
    }
}

function search(query, limit) {
    const lowerQuery = query.toLowerCase();
    const terms = [...new Set(lowerQuery.match(/[\p{L}\p{N}_][\p{L}\p{N}_\-:\\.]*[\p{L}\p{N}_]|[\p{L}\p{N}]/gu) ?? [])]
        .filter((term) => !STOPWORDS.has(term) && (term.length > 1 || /\d/.test(term)));
    if (terms.length === 0) {
        return `No search terms in "${query}".`;
    }

    const total = SECTIONS.length;
    const weights = terms.map((term) => {
        const df = SECTIONS.reduce((n, s) => n + (s.lower.includes(term) ? 1 : 0), 0);

        return df === 0 ? 0 : Math.log(1 + total / df);
    });

    const scored = [];
    for (const s of SECTIONS) {
        let score = 0;
        let matched = 0;
        terms.forEach((term, i) => {
            const count = occurrences(s.lower, term, 20);
            if (count === 0) {
                return;
            }
            matched++;
            score += weights[i] * (Math.log(1 + count) + (s.heading.includes(term) ? 3 : 0));
        });
        if (matched === 0) {
            continue;
        }
        score *= matched / terms.length;
        if (terms.length > 1 && s.lower.includes(lowerQuery)) {
            score += 5;
        }
        if (s.heading.includes(lowerQuery)) {
            score += 10;
        }
        scored.push({ s, score });
    }
    scored.sort((a, b) => b.score - a.score);

    const best = terms.slice().sort((a, b) => weights[terms.indexOf(b)] - weights[terms.indexOf(a)]);
    const hits = scored.slice(0, limit);
    if (hits.length === 0) {
        return `No sections found for "${query}". Try other terms or list_pages.`;
    }

    return [
        `${hits.length} sections for "${query}" (read one in full with get_page and its page and section):`,
        ...hits.map(({ s }, i) => {
            const crumbs = [s.page.title, ...s.section.path, ...(s.section.level > 1 ? [s.section.heading] : [])].join(' › ');
            const url = s.section.anchor ? `${s.page.url}#${s.section.anchor}` : s.page.url;

            return `\n## ${i + 1}. ${crumbs}\nURL: ${url}\nget_page: page="${s.page.slug}"${s.section.anchor ? `, section="${s.section.anchor}"` : ''}\n\n${excerpt(s, best)}`;
        }),
    ].join('\n');
}

function occurrences(haystack, needle, max) {
    let count = 0;
    for (let at = haystack.indexOf(needle); at !== -1 && count < max; at = haystack.indexOf(needle, at + needle.length)) {
        count++;
    }

    return count;
}

/**
 * About 700 characters of the section around the first occurrence of the rarest term.
 */
function excerpt(s, terms) {
    const at = terms.map((t) => s.lower.indexOf(t)).find((i) => i !== -1) ?? 0;
    let start = Math.max(0, at - 200);
    let end = Math.min(s.text.length, start + 700);
    if (start > 0) {
        const line = s.text.lastIndexOf('\n', start);
        start = line !== -1 && at - line < 400 ? line + 1 : start;
    }
    if (end < s.text.length) {
        const line = s.text.indexOf('\n', end);
        end = line !== -1 && line - end < 200 ? line : end;
    }

    return `${start > 0 ? '…\n' : ''}${s.text.slice(start, end).trim()}${end < s.text.length ? '\n…' : ''}`;
}

/**
 * Page by name, file name, title or URL; a URL may carry the section as #anchor.
 */
function findPage(value) {
    let name = value.trim();
    let anchor = '';
    try {
        const url = new URL(name);
        name = url.pathname.replace(/^\/+|\/+$/g, '') || 'index';
        anchor = decodeURIComponent(url.hash.replace(/^#/, ''));
    } catch {
        [name, anchor = ''] = name.split('#');
    }
    name = name.replace(/\.(md|html)$/, '').toLowerCase();
    const page = docs.pages.find((p) => p.slug === name || p.title.toLowerCase() === name)
        ?? (name === '' || name === 'home' ? docs.pages.find((p) => p.slug === 'index') : undefined);

    return page ? { page, anchor } : null;
}

function readPage(page) {
    if (page.markdown.length <= MAX_PAGE) {
        return `${page.markdown}\n\nURL: ${page.url}`;
    }
    const intro = page.sections.find((s) => s.level === 1);
    const outline = page.sections
        .filter((s) => s.level > 1 && s.level < 4)
        .map((s) => `${'  '.repeat(s.level - 2)}- ${s.heading} (section="${s.anchor}")`);

    return [
        `# ${page.title}`,
        `URL: ${page.url}`,
        '',
        intro ? page.markdown.slice(intro.start, intro.end).trim() : '',
        '',
        `The page is long (${Math.round(page.markdown.length / 1000)}k characters). Read a section with get_page(page="${page.slug}", section=…):`,
        '',
        ...outline,
    ].join('\n');
}

function readSection(page, wanted, text, failure) {
    const value = wanted.trim().replace(/^#/, '').toLowerCase();
    const index = [
        (s) => s.anchor === value,
        (s) => s.heading.toLowerCase() === value,
        (s) => s.heading.toLowerCase().includes(value),
    ].reduce((found, test) => (found !== -1 ? found : page.sections.findIndex(test)), -1);
    if (index === -1) {
        return failure(`No section "${wanted}" on page "${page.slug}". Sections: ${page.sections.filter((s) => s.anchor).map((s) => s.anchor).join(', ')}.`);
    }

    const section = page.sections[index];
    const next = page.sections.findIndex((s, i) => i > index && s.level <= section.level);
    const end = next === -1 ? page.markdown.length : page.sections[next].start;
    let content = page.markdown.slice(section.start, end).trim();
    if (content.length > MAX_PAGE) {
        const subsections = page.sections.slice(index + 1, next === -1 ? undefined : next).map((s) => `- ${s.heading} (section="${s.anchor}")`);
        content = `${content.slice(0, MAX_PAGE)}\n\n… (shortened; read a subsection instead:)\n${subsections.join('\n')}`;
    }

    return text(`${content}\n\nURL: ${page.url}${section.anchor ? `#${section.anchor}` : ''}`);
}

function listPages() {
    return docs.pages
        .map((p) => `- ${p.slug}: ${p.title}${p.optional ? ' (reference)' : ''} – ${p.description}\n  ${p.url}`)
        .join('\n');
}

function result(id, value) {
    return { jsonrpc: '2.0', id, result: value };
}

function error(id, code, message) {
    return { jsonrpc: '2.0', id, error: { code, message } };
}

function json(value, status, headers) {
    return new Response(JSON.stringify(value), { status, headers: { ...headers, 'Content-Type': 'application/json' } });
}
