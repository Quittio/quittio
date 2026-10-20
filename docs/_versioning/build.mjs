// Builds the versioned documentation website into docs/_site:
//
// - /          the latest release (the highest tag vX.Y.Z without a pre-release suffix)
// - /next/     the development version (the working tree, or --next <ref>)
// - /X.Y/      every older minor version at its newest patch release
//
// Each version is built from its own docs/ (git archive of the tag) with the Gemfile, the theme and
// the version switcher (_includes/header_custom.html) of this checkout. /versions.json lists the
// versions for the switcher, _headers keeps /next/ and old versions out of search engines.
// llms.txt and the MCP index of the root (_worker/docs.generated.json) describe the latest release;
// every version gets its own llms.txt as well. With --api, every version also gets the API
// reference of its public classes under api/ (phpDocumentor, tools/docs/phpdoc.sh downloads it).
//
// Usage (in docs/): node _versioning/build.mjs [--url https://docs.quittio.dev] [--only-next] [--config _config.ci.yml] [--api <phpDocumentor.phar>]

import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';

const docs = resolve(join(dirname(fileURLToPath(import.meta.url)), '..'));
const repository = resolve(docs, '..');
const { values: options } = parseArgs({
    options: {
        url: { type: 'string', default: process.env.DOCS_URL || 'https://docs.quittio.dev' },
        next: { type: 'string' },
        'only-next': { type: 'boolean', default: false },
        config: { type: 'string', multiple: true, default: [] },
        api: { type: 'string' },
    },
});
const url = options.url.replace(/\/+$/, '');
const site = join(docs, '_site');
const work = join(repository, 'build', 'docs-versions');

const releases = tags();
const latest = releases.at(-1) ?? null;
const versions = [];
if (!options['only-next'] && latest !== null) {
    versions.push({ name: minor(latest), label: `${minor(latest)} (latest)`, ref: latest, prefix: '', channel: 'latest' });
}
versions.push({ name: 'next', label: 'next (unreleased)', ref: options.next ?? null, prefix: 'next', channel: 'next' });
if (!options['only-next']) {
    for (const tag of olderMinors(releases, latest)) {
        versions.push({ name: minor(tag), label: minor(tag), ref: tag, prefix: minor(tag), channel: 'old' });
    }
}

rmSync(site, { recursive: true, force: true });
rmSync(work, { recursive: true, force: true });
mkdirSync(site, { recursive: true });

for (const version of versions) {
    const source = sources(version);
    const destination = version.prefix === '' ? site : join(site, version.prefix);
    const baseurl = version.prefix === '' ? '' : `/${version.prefix}`;
    writeFileSync(join(source, '_config.version.yml'), [
        `baseurl: "${baseurl}"`,
        `url: "${url}"`,
        `docs_version: "${version.name}"`,
        `docs_channel: "${version.channel}"`,
        '',
    ].join('\n'));
    const configs = [join(source, '_config.yml'), ...options.config.map((c) => resolve(docs, c)), join(source, '_config.version.yml')];
    run('bundle', ['exec', 'jekyll', 'build', '--source', source, '--destination', destination, '--config', configs.join(',')]);
    // The AI files of every version; the MCP index (Worker) only for the root.
    run('node', [join(docs, '_worker', 'build.mjs'), '--docs', source, '--site', destination, '--base', `${url}${baseurl}`,
        '--index', version.prefix === '' || versions.length === 1 ? join(docs, '_worker', 'docs.generated.json') : 'none', '--mcp', `${url}/mcp`]);
    if (options.api) {
        api(version, destination);
    }
    console.log(`${version.label}: ${version.ref ?? 'working tree'} → /${version.prefix}`);
}

if (!existsSync(join(site, 'index.html'))) {
    // Only /next/ was built (pull requests): the root leads there.
    writeFileSync(join(site, 'index.html'), '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0; url=/next/"><title>Quittio</title><a href="/next/">Quittio documentation</a>\n');
}
writeFileSync(join(site, 'versions.json'), JSON.stringify(versions.map(({ name, label, prefix }) => ({ name, label, path: prefix === '' ? '/' : `/${prefix}/` })), null, 2) + '\n');
writeFileSync(join(site, '_headers'), versions.filter((v) => v.prefix !== '').map((v) => `/${v.prefix}/*\n  X-Robots-Tag: noindex\n`).join('\n'));

/**
 * The docs/ of a version: the working tree for "next" without a ref, otherwise the tree of the
 * tag; the version switcher of this checkout is added either way.
 */
function sources(version) {
    const target = join(work, version.name);
    mkdirSync(target, { recursive: true });
    if (version.ref === null) {
        cpSync(docs, target, { recursive: true, filter: (path) => !/\/(_site|vendor|\.bundle|\.jekyll-cache|_versioning)(\/|$)/.test(path.slice(docs.length)) });
    } else {
        // The subtree, because .gitattributes excludes /docs from archives of the whole tree.
        const archive = execFileSync('git', ['-C', repository, 'archive', `${version.ref}:docs`], { maxBuffer: 256 * 1024 * 1024 });
        execFileSync('tar', ['-xf', '-', '-C', target], { input: archive });
    }
    mkdirSync(join(target, '_includes'), { recursive: true });
    cpSync(join(docs, '_includes', 'header_custom.html'), join(target, '_includes', 'header_custom.html'));

    return target;
}

/**
 * The API reference of the public classes of a version (@internal is left out) under api/.
 */
function api(version, destination) {
    let packages = join(repository, 'packages');
    if (version.ref !== null) {
        // Outside the repository: phpDocumentor skips paths that git ignores (build/).
        packages = mkdtempSync(join(tmpdir(), `quittio-api-${version.name}-`));
        mkdirSync(packages, { recursive: true });
        const archive = execFileSync('git', ['-C', repository, 'archive', `${version.ref}:packages`], { maxBuffer: 256 * 1024 * 1024 });
        execFileSync('tar', ['-xf', '-', '-C', packages], { input: archive });
    }
    const cache = mkdtempSync(join(tmpdir(), 'quittio-api-cache-'));
    const label = version.channel === 'next' ? 'next' : version.name;
    // Relative directories: phpDocumentor reads absolute ones as separate documentation sets.
    execFileSync('php', [resolve(docs, options.api), 'run', '--quiet',
        '-d', 'core/src', '-d', 'laravel/src', '-d', 'country-de/src', '-d', 'country-at/src', '-d', 'country-be/src', '-d', 'country-ch/src', '-d', 'country-fr/src',
        '-t', join(destination, 'api'), '--cache-folder', cache,
        '--title', `Quittio ${label} API`, '--visibility=public,protected', '--defaultpackagename=Quittio'], { cwd: packages, stdio: 'inherit' });
}

/**
 * Release tags vX.Y.Z (no pre-releases), oldest first.
 */
function tags() {
    const output = execFileSync('git', ['-C', repository, 'tag', '--list', 'v*'], { encoding: 'utf8' });

    return output.split('\n').map((t) => t.trim()).filter((t) => /^v\d+\.\d+\.\d+$/.test(t)).sort(compare);
}

/**
 * The newest patch release of every minor version below the latest one, newest first.
 */
function olderMinors(releases, latest) {
    const newest = new Map();
    for (const tag of releases) {
        newest.set(minor(tag), tag);
    }
    newest.delete(latest === null ? '' : minor(latest));

    return [...newest.values()].sort(compare).reverse();
}

function minor(tag) {
    return tag.replace(/^v/, '').split('.').slice(0, 2).join('.');
}

function compare(a, b) {
    const pa = a.replace(/^v/, '').split('.').map(Number);
    const pb = b.replace(/^v/, '').split('.').map(Number);
    for (let i = 0; i < 3; i++) {
        if (pa[i] !== pb[i]) {
            return pa[i] - pb[i];
        }
    }

    return 0;
}

function run(command, args) {
    execFileSync(command, args, { cwd: docs, stdio: 'inherit', env: { ...process.env, JEKYLL_ENV: process.env.JEKYLL_ENV || 'production' } });
}
