# AI assistants

Coding assistants such as Claude Code, Cursor or GitHub Copilot can read this documentation in three ways: as plain Markdown files, through an MCP server, and through the guidelines and the skill that Quittio ships for [Laravel Boost](https://laravel.com/docs/boost).

## Markdown and llms.txt

Every page of this documentation is also available as plain Markdown: add `.md` to its address, e.g. [`https://docs.quittio.dev/getting-started.md`](https://docs.quittio.dev/getting-started.md).

| File | Content |
|---|---|
| [`/llms.txt`](https://docs.quittio.dev/llms.txt) | Index of all pages with a short description ([llms.txt](https://llmstxt.org) format) |
| [`/llms-full.txt`](https://docs.quittio.dev/llms-full.txt) | All pages in one file |

## MCP server

`https://docs.quittio.dev/mcp` is a read-only [MCP](https://modelcontextprotocol.io) server for this documentation (Streamable HTTP, no authentication). It always answers with the documentation of the published website.

| Tool | Arguments | Returns |
|---|---|---|
| `search_docs` | `query`, optional `limit` (1–20, default 5) | The best matching sections with page, anchor, URL and an excerpt |
| `get_page` | `page` (name such as `getting-started`, a title or a URL), optional `section` (anchor or heading) | The page or section as Markdown; long pages without a section return their outline |
| `list_pages` | – | All pages with name, title, URL and description |

Claude Code:

```bash
claude mcp add --transport http quittio-docs https://docs.quittio.dev/mcp
```

Cursor (`.cursor/mcp.json`):

```json
{
    "mcpServers": {
        "quittio-docs": { "url": "https://docs.quittio.dev/mcp" }
    }
}
```

VS Code with GitHub Copilot (`.vscode/mcp.json`):

```json
{
    "servers": {
        "quittio-docs": { "type": "http", "url": "https://docs.quittio.dev/mcp" }
    }
}
```

Claude Desktop and claude.ai: Settings → Connectors → Add custom connector, URL `https://docs.quittio.dev/mcp`.

## Laravel Boost

[Laravel Boost](https://laravel.com/docs/boost) loads AI guidelines and skills from the packages of an application. Quittio ships both in `quittio/quittio` and `quittio/laravel`:

| Path in the package | Content |
|---|---|
| `resources/boost/guidelines/core.blade.php` | Short guidelines that are always loaded: what Quittio is, the main rules (builder, tax presets, strings for amounts), a rendering example |
| `resources/boost/skills/quittio-development/SKILL.md` | The skill `quittio-development`, loaded when an assistant works with invoices: building, rendering, validating, cancelling, reading and testing |

Boost only reads packages that the application requires directly in its `composer.json`. After installing or updating Quittio, run:

```bash
php artisan boost:install
```

Both files point the assistant to this documentation and to the MCP server above for details.
