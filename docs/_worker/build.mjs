// Builds the files for AI tools from the Markdown sources in docs/, after `jekyll build`:
//
// - _site/llms.txt, _site/llms-full.txt (https://llmstxt.org) and every page as _site/{page}.md
// - _worker/docs.generated.json, the pages and sections the MCP server (index.js) searches
//
// Preview pages of unreleased packages (PREVIEW) and the legal pages (LEGAL) are left out of all of them.
//
// Usage (in docs/): DOCS_URL=https://docs.quittio.dev node _worker/build.mjs

import { mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const docs = join(dirname(fileURLToPath(import.meta.url)), '..');
const site = join(docs, '_site');
const base = (process.env.DOCS_URL || 'https://docs.quittio.dev').replace(/\/+$/, '');

// Pages that AI tools may skip (llms.txt section "Optional").
const OPTIONAL = new Set(['field-reference']);

// Pages of unreleased packages: on the website as a preview, but not in llms.txt, llms-full.txt and
// the MCP index, so that assistants do not write code for a package that cannot be installed.
const PREVIEW = new Set(['managed']);

// Legal pages (Impressum, Datenschutzerklärung): on the website only, nothing for AI tools.
const LEGAL = new Set(['impressum', 'datenschutz']);

const pages = navigation().filter(({ file }) => !PREVIEW.has(file.replace(/\.md$/, '')) && !LEGAL.has(file.replace(/\.md$/, ''))).map(({ file, title, order }) => {
    const slug = file.replace(/\.md$/, '');
    const markdown = readFileSync(join(docs, file), 'utf8')
        .split('\n')
        .filter((line) => !/^\{:.*\}\s*$/.test(line)) // kramdown attribute lists such as {: .fs-9 }
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim() + '\n';

    return {
        slug,
        title: slug === 'index' ? 'Overview' : title,
        order,
        url: slug === 'index' ? `${base}/` : `${base}/${slug}`,
        markdownUrl: `${base}/${file}`,
        description: description(markdown),
        optional: OPTIONAL.has(slug),
        markdown,
        sections: sections(markdown, title),
    };
});

mkdirSync(site, { recursive: true });
for (const page of pages) {
    writeFileSync(join(site, `${page.slug}.md`), page.markdown);
}
writeFileSync(join(site, 'llms.txt'), llmsTxt(pages));
writeFileSync(join(site, 'llms-full.txt'), llmsFullTxt(pages));
writeFileSync(
    join(docs, '_worker', 'docs.generated.json'),
    JSON.stringify({
        base,
        pages,
    }),
);

console.log(`${pages.length} pages, ${pages.reduce((n, p) => n + p.sections.length, 0)} sections, base ${base}`);

/**
 * The pages in navigation order, with the titles of _config.yml.
 */
function navigation() {
    const config = readFileSync(join(docs, '_config.yml'), 'utf8');
    const entries = [...config.matchAll(/path: "([^"]+\.md)" \}\s*\n\s*values: \{ title: ([^,]+), nav_order: (\d+)/g)]
        .map(([, file, title, order]) => ({ file, title: title.trim(), order: Number(order) }));
    const known = new Set(entries.map((e) => e.file));
    const missing = readdirSync(docs).filter((f) => f.endsWith('.md') && !known.has(f));
    if (missing.length > 0) {
        throw new Error(`Pages without a navigation entry in _config.yml: ${missing.join(', ')}`);
    }

    return entries.sort((a, b) => a.order - b.order);
}

/**
 * The first paragraph after the title, as plain text, shortened to about 200 characters.
 */
function description(markdown) {
    const paragraph = markdown
        .replace(/^(`{3,}|~{3,})[^\n]*\n[\s\S]*?^\1\s*$/gm, '')
        .split(/\n\s*\n/)
        .map((block) => block.trim())
        .find((block) => block !== '' && !/^[#\[>|<]/.test(block) && !block.endsWith(':'));
    const text = plain(paragraph ?? '').replace(/\s+/g, ' ');
    if (text.length <= 220) {
        return text;
    }
    const cut = text.slice(0, 220);
    const sentence = cut.lastIndexOf('. ');

    return sentence > 80 ? cut.slice(0, sentence + 1) : cut.slice(0, cut.lastIndexOf(' ')) + ' …';
}

/**
 * Sections at the headings of levels 2 to 4 (outside code blocks), as offsets into the page.
 * The part before the first heading is the introduction (level 1, no anchor).
 */
function sections(markdown, title) {
    const result = [];
    const counter = new Map();
    const stack = [];
    let fence = null;
    let offset = 0;
    let current = { heading: title, level: 1, anchor: '', path: [], start: 0 };

    for (const line of markdown.split('\n')) {
        const marker = line.match(/^\s*(`{3,}|~{3,})/);
        if (marker) {
            fence = fence === null ? marker[1][0] : marker[1][0] === fence ? null : fence;
        }
        const heading = fence === null && !marker ? line.match(/^(#{2,4})\s+(.+?)\s*#*\s*$/) : null;
        if (heading) {
            result.push({ ...current, end: offset });
            const level = heading[1].length;
            const text = plain(heading[2]);
            while (stack.length > 0 && stack[stack.length - 1].level >= level) {
                stack.pop();
            }
            current = { heading: text, level, anchor: anchor(text, counter), path: stack.map((s) => s.heading), start: offset };
            stack.push({ level, heading: text });
        }
        offset += line.length + 1;
    }
    result.push({ ...current, end: markdown.length });

    return result.filter((s) => markdown.slice(s.start, s.end).trim() !== '');
}

/**
 * Header id as kramdown generates it for GFM input: lower case, everything but word
 * characters, hyphens and spaces removed, spaces replaced by hyphens, "-1", "-2" … for repeats.
 */
function anchor(text, counter) {
    const id = text.toLowerCase().replace(/[^\p{L}\p{M}\p{N}\p{Pc}\- ]/gu, '').replace(/[ \t]/g, '-');
    const seen = counter.has(id) ? counter.get(id) + 1 : 0;
    counter.set(id, seen);

    return seen > 0 ? `${id}-${seen}` : id;
}

/**
 * Markdown inline syntax removed: links keep their text, code spans their content.
 */
function plain(markdown) {
    return markdown
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/`([^`]*)`/g, '$1')
        .replace(/\*\*([^*]+)\*\*/g, '$1')
        .replace(/(^|\W)\*([^*]+)\*(?=\W|$)/g, '$1$2')
        .replace(/\\([\\`*_{}\[\]()#+\-.!|])/g, '$1')
        .trim();
}

function llmsTxt(pages) {
    const line = (p) => `- [${p.title}](${p.markdownUrl}): ${p.description}`;

    return [
        '# Quittio',
        '',
        '> E-invoicing for Laravel and PHP: create, validate, read and render EN 16931 invoices (ZUGFeRD/Factur-X, XRechnung, UBL, Peppol BIS), with the country pack for Germany.',
        '',
        'Install with `composer require quittio/quittio` (core, Laravel integration and country pack Germany) or `quittio/core` without Laravel. '
            + `Every page below is plain Markdown; ${base}/llms-full.txt contains all of them. `
            + `The MCP server ${base}/mcp (Streamable HTTP, no authentication) searches this documentation.`,
        '',
        '## Docs',
        '',
        ...pages.filter((p) => !p.optional).map(line),
        '',
        '## Optional',
        '',
        ...pages.filter((p) => p.optional).map(line),
        '',
    ].join('\n');
}

function llmsFullTxt(pages) {
    return pages
        .map((p) => `<!-- Source: ${p.url} -->\n\n${p.markdown}`)
        .join('\n\n');
}
